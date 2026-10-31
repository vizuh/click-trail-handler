// CMP decision-transition checks (specs/002-cmp-decision-transitions).
//
// Runs the real consent bridge and attribution scripts in a vm with a fake
// Cookiebot that, like production Cookiebot, wipes unclassified storage
// before firing its decision events. Also diffs every storage key the scripts
// write against the inventory table in docs/guides/SECURITY-PRIVACY.md.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.join(__dirname, '..', '..');
const SCRIPTS = ['clicutcl-consent-bridge.js', 'clicutcl-attribution.js', 'clicutcl-events.js'].map((file) => ({
  file,
  source: fs.readFileSync(path.join(ROOT, 'assets/js', file), 'utf8'),
}));
// Keys that may remain after a rejection: the consent decision itself.
const CONSENT_KEYS = ['cookie:ct_consent_state', 'local:ct_consent_v1'];

class Bus {
  constructor() { this.listeners = Object.create(null); }
  addEventListener(type, fn, options) {
    if (typeof fn !== 'function') return;
    (this.listeners[type] || (this.listeners[type] = [])).push({ fn, once: !!(options && options.once) });
  }
  removeEventListener(type, fn) {
    this.listeners[type] = (this.listeners[type] || []).filter((entry) => entry.fn !== fn);
  }
  dispatchEvent(event) {
    (this.listeners[event.type] || []).slice().forEach((entry) => {
      if (entry.once) this.removeEventListener(event.type, entry.fn);
      entry.fn.call(this, event);
    });
    return true;
  }
}

class FakeStorage {
  constructor(medium, writes) { this.medium = medium; this.writes = writes; this.values = Object.create(null); }
  getItem(key) { return key in this.values ? this.values[key] : null; }
  setItem(key, value) { this.writes.add(this.medium + ':' + key); this.values[key] = String(value); }
  removeItem(key) { delete this.values[key]; }
  key(index) { return Object.keys(this.values)[index] || null; }
  get length() { return Object.keys(this.values).length; }
}

// Minimal element: attributes plus a value; enough for the decorator and the hidden-field injector.
function element(tag, attrs = {}, value = '') {
  const at = Object.assign({}, attrs);
  const el = {
    tagName: tag.toUpperCase(),
    nodeType: 1,
    name: at.name || '',
    value,
    dataset: {},
    getAttribute: (name) => (name in at ? at[name] : null),
    setAttribute: (name, v) => { at[name] = String(v); },
    hasAttribute: (name) => name in at,
    removeAttribute: (name) => { delete at[name]; },
    dispatchEvent() {},
    closest: (selector) => (selector === 'form' ? null : el), // the fixture elements sit outside any form
  };
  // Like the DOM, the href property and attribute are one value.
  Object.defineProperty(el, 'href', { get: () => at.href, set: (v) => { at.href = String(v); } });
  return el;
}

// Supports the selectors the runtime uses on these elements: anchors it decorated and inputs by name.
function selectorFor(elements) {
  return (selector) => elements.filter((el) => selector.split(',').some((part) => {
    if (/^\s*a\[data-clicutcl-href\]\s*$/.test(part)) return el.tagName === 'A' && el.hasAttribute('data-clicutcl-href');
    const name = part.match(/^\s*input\[name="([^"]+)"\]/);
    return !!name && el.tagName === 'INPUT' && el.name === name[1];
  }));
}

function boot({ search = '?gclid=G1&utm_source=google', cookiebot, seed = {}, config = {}, eventsConfig = {}, elements = [], calendly } = {}) {
  const writes = new Set();
  // Deferred work and network sends are recorded, never run implicitly, so a test can fire them on purpose.
  const timers = [];
  const sent = [];
  const jar = Object.create(null);
  const document = new Bus();
  Object.assign(document, {
    readyState: 'complete',
    referrer: 'https://www.google.com/',
    title: 'Landing',
    visibilityState: 'visible',
    body: { appendChild() {} },
    head: { appendChild() {} },
    documentElement: {},
    querySelectorAll: selectorFor(elements),
    querySelector: () => null,
    getElementsByTagName: () => [],
    createElement: () => ({ setAttribute() {}, appendChild() {}, style: {} }),
  });
  Object.defineProperty(document, 'cookie', {
    get: () => Object.keys(jar).map((name) => name + '=' + jar[name]).join('; '),
    set: (raw) => {
      const [pair, ...attrs] = String(raw).split(';');
      const split = pair.indexOf('=');
      if (split === -1) return;
      const name = pair.slice(0, split).trim();
      const value = pair.slice(split + 1);
      if (value === '' || /max-age=0|expires=thu, 01 jan 1970/i.test(attrs.join(';'))) {
        delete jar[name];
        return;
      }
      jar[name] = value;
      writes.add('cookie:' + name);
    },
  });

  const window = new Bus();
  const url = new URL('https://example.test/landing' + search);
  Object.assign(window, {
    document,
    localStorage: new FakeStorage('local', writes),
    sessionStorage: new FakeStorage('session', writes),
    location: { href: url.href, search: url.search, protocol: url.protocol, hostname: url.hostname, origin: url.origin, pathname: url.pathname },
    navigator: {
      userAgent: 'Mozilla/5.0 Chrome/140',
      webdriver: false,
      language: 'en',
      sendBeacon: (...args) => { sent.push(['beacon', JSON.stringify(args)]); return true; },
    },
    screen: { width: 1280, height: 800 },
    innerWidth: 1280,
    innerHeight: 800,
    crypto: require('crypto').webcrypto,
    dataLayer: [],
    console: { log() {}, warn() {}, error() {} },
    setTimeout: (fn) => { timers.push(fn); return timers.length; },
    clearTimeout() {},
    setInterval: (fn) => { timers.push(fn); return timers.length; },
    clearInterval() {},
    fetch: (...args) => { sent.push(['fetch', JSON.stringify(args)]); return Promise.resolve({ ok: true, json: () => ({}) }); },
    CSS: { escape: (value) => String(value) },
    CustomEvent: class CustomEvent {
      constructor(type, options) { this.type = type; this.detail = options && options.detail; }
    },
    URL,
    URLSearchParams,
    Event: class Event { constructor(type) { this.type = type; } },
    TextEncoder,
    btoa,
    atob,
    clicutcl_config: Object.assign({ cookieName: 'attribution', cookieDays: 90, requireConsent: true }, config),
    // Thank-you matcher on the landing path exercises the events script's session marker.
    clicutclEventsConfig: Object.assign({ enabled: true, thankYouMatchers: ['/landing'] }, eventsConfig),
    ctConsentBridgeConfig: {
      enabled: true,
      cookieName: 'ct_consent',
      serverCookieName: 'ct_consent_state',
      cmpSource: 'cookiebot',
      timeout: 3000,
      fallbackGranted: false,
    },
  });
  window.window = window;
  window.self = window;
  if (cookiebot) window.Cookiebot = cookiebot;
  if (calendly) window.Calendly = calendly;
  Object.entries(seed).forEach(([key, value]) => { window.localStorage.values[key] = value; });

  const context = vm.createContext(window);
  SCRIPTS.forEach(({ file, source }) => vm.runInContext(source, context, { filename: file }));

  const present = () => [
    ...Object.keys(jar).map((key) => 'cookie:' + key),
    ...Object.keys(window.localStorage.values).map((key) => 'local:' + key),
    ...Object.keys(window.sessionStorage.values).map((key) => 'session:' + key),
  ].sort();

  // Mirrors Cookiebot: unclassified storage is wiped before the decision events fire.
  const decide = (marketing, events) => {
    Object.keys(jar).forEach((key) => { delete jar[key]; });
    window.localStorage.values = Object.create(null);
    window.sessionStorage.values = Object.create(null);
    window.Cookiebot.hasResponse = true;
    window.Cookiebot.consent = { marketing, statistics: marketing, preferences: marketing, necessary: true };
    events.forEach((type) => window.dispatchEvent(new window.CustomEvent(type)));
  };

  const data = () => JSON.stringify((window.ClickTrail && window.ClickTrail.getData()) || {});
  return { window, document, jar, writes, present, decide, data, timers, sent };
}

const pendingCookiebot = () => ({ hasResponse: false, consent: { marketing: false, statistics: false } });
const grantedCookiebot = () => ({ hasResponse: true, consent: { marketing: true, statistics: true } });

function inventoryKeys() {
  const doc = fs.readFileSync(path.join(ROOT, 'docs/guides/SECURITY-PRIVACY.md'), 'utf8');
  const match = doc.match(/<!-- storage-inventory:start -->([\s\S]*?)<!-- storage-inventory:end -->/);
  assert.ok(match, 'SECURITY-PRIVACY.md must contain the storage inventory markers');
  const keys = new Set();
  match[1].split('\n').forEach((line) => {
    const cells = line.split('|').map((cell) => cell.trim());
    if (cells.length < 4 || !/^`/.test(cells[1])) return;
    const key = cells[1].replace(/`/g, '');
    cells[2].split(',').map((medium) => medium.trim()).forEach((medium) => {
      if (medium) keys.add(medium + ':' + key);
    });
  });
  return keys;
}

// `ct_thankyou_lead_<path>` in the table matches any key with that prefix.
function isListed(listed, written) {
  return [...listed].some((entry) => {
    const hole = entry.indexOf('<');
    return hole === -1 ? entry === written : written.startsWith(entry.slice(0, hole));
  });
}

const tests = {
  'pending decision writes nothing'() {
    const page = boot({ cookiebot: pendingCookiebot() });
    assert.deepStrictEqual([...page.writes], []);
    assert.deepStrictEqual(page.present(), []);
  },

  'accept after a decision-time wipe keeps the landing-page click id'() {
    const page = boot({ cookiebot: pendingCookiebot() });
    page.decide(true, ['CookiebotOnConsentReady', 'CookiebotOnAccept']);
    assert.ok(page.data().includes('G1'), 'gclid must survive the CMP wipe');
    assert.ok(page.present().includes('cookie:attribution'), 'attribution cookie must be written after the wipe');
  },

  'reject leaves only the consent decision'() {
    const page = boot({ cookiebot: pendingCookiebot() });
    page.decide(false, ['CookiebotOnConsentReady', 'CookiebotOnDecline']);
    assert.deepStrictEqual(page.present().filter((key) => !CONSENT_KEYS.includes(key)), []);
  },

  'in-page withdrawal after an earlier accept clears attribution'() {
    const page = boot({ cookiebot: pendingCookiebot() });
    page.decide(true, ['CookiebotOnConsentReady', 'CookiebotOnAccept']);
    page.window.Cookiebot.consent = { marketing: false, statistics: false };
    page.window.dispatchEvent(new page.window.CustomEvent('CookiebotOnDecline'));
    assert.deepStrictEqual(page.present().filter((key) => !CONSENT_KEYS.includes(key)), []);
    assert.ok(!page.data().includes('G1'));
  },

  'withdrawal is observed when Cookiebot already had a response at load'() {
    const page = boot({ cookiebot: grantedCookiebot() });
    assert.ok(page.data().includes('G1'), 'returning granted visitor is captured at load');
    page.window.Cookiebot.consent = { marketing: false, statistics: false };
    page.window.dispatchEvent(new page.window.CustomEvent('CookiebotOnDecline'));
    assert.deepStrictEqual(page.present().filter((key) => !CONSENT_KEYS.includes(key)), []);
  },

  'denial removes the legacy clicutcl_js_last_seen key'() {
    // Returning visitor whose earlier refusal predates the key's removal.
    const deniedCookiebot = { hasResponse: true, consent: { marketing: false, statistics: false } };
    const page = boot({ cookiebot: deniedCookiebot, seed: { clicutcl_js_last_seen: '1' } });
    assert.ok(!page.present().includes('local:clicutcl_js_last_seen'));
  },

  'same-page grant, withdrawal and re-grant: links and filled fields follow live consent'() {
    const original = 'https://apply.example.test/form?ref=site';
    const link = element('a', { href: original });
    const ctField = element('input', { name: 'ct_gclid' });
    // Rendered server-side from the previous visit's cookie (CF7 hidden fields).
    const renderedField = element('input', { name: 'ct_lt_source' }, 'previous-visit');
    const siteField = element('input', { name: 'utm_source' }, 'site-supplied');
    const page = boot({
      cookiebot: pendingCookiebot(),
      config: { linkDecorateEnabled: true, injectEnabled: true },
      elements: [link, ctField, renderedField, siteField],
    });
    const click = () => {
      page.document.dispatchEvent({ type: 'mousedown', target: link });
      return new URL(link.getAttribute('href')).searchParams;
    };

    page.decide(true, ['CookiebotOnConsentReady', 'CookiebotOnAccept']);
    let params = click();
    assert.strictEqual(params.get('gclid'), 'G1', 'granted: link carries the click ID');
    assert.strictEqual(params.get('ref'), 'site', 'site parameters are kept');
    assert.strictEqual(ctField.value, 'G1', 'granted: ClickTrail fills its field');
    assert.strictEqual(renderedField.value, 'google', 'a ct_* field rendered one touch behind is refreshed');
    assert.strictEqual(siteField.value, 'site-supplied', 'site-supplied value is never overwritten');

    page.window.Cookiebot.consent = { marketing: false, statistics: false };
    page.window.dispatchEvent(new page.window.CustomEvent('CookiebotOnDecline'));
    assert.strictEqual(link.getAttribute('href'), original, 'withdrawal restores the original URL');
    assert.strictEqual(click().get('gclid'), null, 'a click after withdrawal adds no click ID');
    assert.strictEqual(link.getAttribute('href'), original);
    assert.strictEqual(ctField.value, '', 'withdrawal clears the ClickTrail field');
    assert.strictEqual(siteField.value, 'site-supplied', 'withdrawal keeps the site-supplied value');
    page.document.dispatchEvent({ type: 'submit' });
    assert.deepStrictEqual(page.present().filter((key) => !CONSENT_KEYS.includes(key)), [], 'a submit after withdrawal writes nothing');
    assert.strictEqual(ctField.value, '', 'a submit after withdrawal refills nothing');

    page.window.Cookiebot.consent = { marketing: true, statistics: true };
    page.window.dispatchEvent(new page.window.CustomEvent('CookiebotOnAccept'));
    assert.strictEqual(click().get('gclid'), 'G1', 're-grant decorates again');

    // Another tab captured a newer click: the already-decorated link must follow it.
    const stored = JSON.parse(decodeURIComponent(page.jar.attribution));
    page.jar.attribution = encodeURIComponent(JSON.stringify(Object.assign(stored, { gclid: 'G2', lt_gclid: 'G2' })));
    params = click();
    assert.strictEqual(params.get('gclid'), 'G2', 'a decorated link is rebuilt from the latest touch');
    assert.strictEqual(params.getAll('gclid').length, 1);
    assert.strictEqual(params.get('ref'), 'site');
    page.document.dispatchEvent({ type: 'submit' });
    assert.strictEqual(ctField.value, 'G2', 'submit refreshes the field ClickTrail filled');
    assert.strictEqual(siteField.value, 'site-supplied', 'submit keeps the site-supplied value');
  },

  'site edits to a decorated link survive; only ClickTrail params are replaced or removed'() {
    const link = element('a', { href: 'https://apply.example.test/form' });
    const local = element('a', { href: '/pricing' });
    const page = boot({ cookiebot: grantedCookiebot(), config: { linkDecorateEnabled: true }, elements: [link, local] });
    const click = (a) => {
      page.document.dispatchEvent({ type: 'mousedown', target: a });
      return new URL(a.getAttribute('href'), 'https://example.test/').searchParams;
    };

    assert.strictEqual(click(link).get('gclid'), 'G1');
    click(local);
    assert.strictEqual(local.getAttribute('href'), '/pricing', 'same-origin link untouched');
    assert.ok(!local.hasAttribute('data-clicutcl-href'), 'an anchor ClickTrail did not decorate is not tracked');

    link.setAttribute('href', link.getAttribute('href') + '&site=1');
    const stored = JSON.parse(decodeURIComponent(page.jar.attribution));
    page.jar.attribution = encodeURIComponent(JSON.stringify(Object.assign(stored, { gclid: 'G2', lt_gclid: 'G2' })));
    let params = click(link);
    assert.strictEqual(params.get('gclid'), 'G2', 'a newer touch replaces the old click on a site-edited link');
    assert.strictEqual(params.get('site'), '1', 'the site edit is kept');

    link.setAttribute('href', link.getAttribute('href') + '&step=2');
    page.window.Cookiebot.consent = { marketing: false, statistics: false };
    page.window.dispatchEvent(new page.window.CustomEvent('CookiebotOnDecline'));
    params = new URL(link.getAttribute('href')).searchParams;
    assert.strictEqual(params.get('gclid'), null, 'withdrawal removes the click ID from a site-edited link');
    assert.strictEqual(params.get('utm_source'), null, 'withdrawal removes ClickTrail UTMs');
    assert.strictEqual(params.get('site'), '1');
    assert.strictEqual(params.get('step'), '2', 'withdrawal keeps later site edits');
  },

  'a tampered stored original never puts a non-http URL into an href'() {
    const href = 'https://apply.example.test/form?gclid=G1';
    const link = element('a', { href, 'data-clicutcl-href': 'javascript:alert(1)', 'data-clicutcl-decorated': href });
    const page = boot({ cookiebot: grantedCookiebot(), config: { linkDecorateEnabled: true }, elements: [link] });
    page.document.dispatchEvent({ type: 'mousedown', target: link });
    assert.ok(!/^javascript:/i.test(link.getAttribute('href')), 'decoration does not write javascript:');
    link.setAttribute('data-clicutcl-href', 'javascript:alert(1)');
    link.setAttribute('data-clicutcl-decorated', link.getAttribute('href'));
    page.window.Cookiebot.consent = { marketing: false, statistics: false };
    page.window.dispatchEvent(new page.window.CustomEvent('CookiebotOnDecline'));
    assert.ok(!/^javascript:/i.test(link.getAttribute('href')), 'restore does not write javascript:');
  },

  'WhatsApp links carry one CT payload and are restored on withdrawal'() {
    const original = 'https://wa.me/5511999999999?text=Hi';
    const link = element('a', { href: original });
    const page = boot({
      cookiebot: grantedCookiebot(),
      config: { enableWhatsapp: true, whatsappAppendAttribution: true },
      elements: [link],
    });
    const click = () => {
      page.document.dispatchEvent({ type: 'click', target: link });
      return new URL(link.getAttribute('href')).searchParams.get('text');
    };
    assert.ok(click().includes('CT:'), 'granted: WhatsApp text carries attribution');
    assert.strictEqual(click().split('CT:').length, 2, 'a second click does not stack another CT payload');

    page.window.Cookiebot.consent = { marketing: false, statistics: false };
    page.window.dispatchEvent(new page.window.CustomEvent('CookiebotOnDecline'));
    assert.strictEqual(link.getAttribute('href'), original, 'withdrawal restores the WhatsApp link');
    assert.strictEqual(click(), 'Hi', 'a click after withdrawal adds nothing');
  },

  'after withdrawal no event path stores, sends or pushes identifiers'() {
    const popupUrls = [];
    const calendly = { initPopupWidget: (options) => { popupUrls.push(options.url); } };
    const link = element('a', { href: 'https://apply.example.test/form' });
    const page = boot({
      cookiebot: grantedCookiebot(),
      config: { linkDecorateEnabled: true, linkAllowedDomains: ['calendly.com'], injectEnabled: true },
      // Browser event transport on, so "no sends after withdrawal" can fail.
      eventsConfig: { transportEnabled: true, eventsBatchUrl: 'https://example.test/wp-json/clicutcl/v2/events/batch', eventsToken: 'test-token' },
      elements: [link],
      calendly,
    });

    // Everything a page can still trigger: pending timers, user events, lifecycle, a Calendly popup.
    const errors = [];
    const attempt = (fn) => { try { fn(); } catch (e) { errors.push(String(e && e.message)); } };
    const fireAll = () => {
      page.timers.splice(0).forEach((fn) => attempt(() => typeof fn === 'function' && fn()));
      ['mousedown', 'click', 'touchstart', 'submit', 'visibilitychange', 'scroll', 'ct_ready'].forEach((type) => {
        attempt(() => page.document.dispatchEvent({ type, target: link }));
      });
      ['scroll', 'pagehide', 'beforeunload', 'load', 'focus', 'blur'].forEach((type) => {
        attempt(() => page.window.dispatchEvent(new page.window.CustomEvent(type)));
      });
      page.window.Calendly.initPopupWidget({ url: 'https://calendly.com/acme/intro' });
      page.timers.splice(0).forEach((fn) => attempt(() => typeof fn === 'function' && fn()));
    };

    fireAll();
    assert.ok(popupUrls[0].includes('salesforce_uuid'), 'granted: popup URL carries the stamp');
    assert.ok(page.sent.length > 0, 'control: with consent the same triggers do send events');

    page.window.Cookiebot.consent = { marketing: false, statistics: false };
    page.window.dispatchEvent(new page.window.CustomEvent('CookiebotOnDecline'));
    // Timers queued while consent was granted still fire below: a flush after the decision must send nothing.
    const layerBefore = page.window.dataLayer.length;
    const sentBefore = page.sent.length;
    const writesBefore = new Set(page.writes);
    const popupsBefore = popupUrls.length;

    fireAll();

    assert.deepStrictEqual(page.present().filter((key) => !CONSENT_KEYS.includes(key)), [], 'nothing stored after withdrawal');
    assert.deepStrictEqual([...page.writes].filter((key) => !writesBefore.has(key) && !CONSENT_KEYS.includes(key)), [], 'no new storage writes');
    assert.deepStrictEqual(page.sent.slice(sentBefore), [], 'no network sends after withdrawal');
    const pushed = JSON.stringify(page.window.dataLayer.slice(layerBefore));
    assert.ok(!/G1|visitor_id|session_id|ct_attribution|click_ids/.test(pushed), 'no identifiers pushed after withdrawal: ' + pushed.slice(0, 200));
    assert.strictEqual(popupUrls[popupsBefore], 'https://calendly.com/acme/intro', 'a popup opened after withdrawal gets the site URL only');
    assert.strictEqual(link.getAttribute('href'), 'https://apply.example.test/form');
    assert.deepStrictEqual(errors, [], 'no handler threw');
  },

  'every written storage key is listed in the inventory'() {
    const page = boot({ cookiebot: pendingCookiebot() });
    page.decide(true, ['CookiebotOnConsentReady', 'CookiebotOnAccept']);
    page.decide(false, ['CookiebotOnDecline']);
    const listed = inventoryKeys();
    const unlisted = [...page.writes].filter((key) => !isListed(listed, key));
    assert.deepStrictEqual(unlisted, [], 'add these keys to the storage inventory in SECURITY-PRIVACY.md');
  },
};

let failed = 0;
Object.entries(tests).forEach(([name, run]) => {
  try {
    run();
    console.log('ok - ' + name);
  } catch (error) {
    failed += 1;
    console.log('not ok - ' + name + '\n  ' + String(error && error.message).split('\n').join('\n  '));
  }
});
if (failed) {
  console.error(failed + ' CMP transition check(s) failed.');
  process.exit(1);
}
console.log('CMP transition checks passed.');
