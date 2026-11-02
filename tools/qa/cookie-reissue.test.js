// Attribution cookie restore and server re-issue checks (specs/009-itp-cookie-reissue, #141).
//
// Runs the real clicutcl-attribution.js in a vm with a cookie jar that records every write and
// expiry, a localStorage mirror and a fetch recorder, and checks: the cookie is restored from the
// mirror only after live consent allows capture (and with the same domain attribute and expiry
// bound as every other plugin write); a pending marker is written only after a persisted script
// write with consent, drives exactly one POST to the re-issue route, and is cleared or kept by the
// outcome; nothing is written before consent or when the feature is off.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.join(__dirname, '..', '..');
const read = (file) => fs.readFileSync(path.join(ROOT, 'assets/js', file), 'utf8');
const SOURCES = {
  bridge: read('clicutcl-consent-bridge.js'),
  attribution: read('clicutcl-attribution.js'),
};

class Bus {
  constructor() { this.listeners = Object.create(null); }
  addEventListener(type, fn, options) {
    if (typeof fn !== 'function') return;
    (this.listeners[type] || (this.listeners[type] = [])).push({ fn, once: !!(options && options.once) });
  }
  removeEventListener(type, fn) { this.listeners[type] = (this.listeners[type] || []).filter((e) => e.fn !== fn); }
  dispatchEvent(event) {
    (this.listeners[event.type] || []).slice().forEach((e) => {
      if (e.once) this.removeEventListener(event.type, e.fn);
      e.fn.call(this, event);
    });
    return true;
  }
}

class FakeStorage {
  constructor() { this.values = Object.create(null); }
  getItem(key) { return key in this.values ? this.values[key] : null; }
  setItem(key, value) { this.values[key] = String(value); }
  removeItem(key) { delete this.values[key]; }
  key(index) { return Object.keys(this.values)[index] || null; }
  get length() { return Object.keys(this.values).length; }
}

// Jar keyed by name + domain, like a browser: a host-only cookie and a domain cookie are distinct.
function makeJar(seed, options = {}) {
  const entries = new Map();
  const log = [];
  const probes = [];
  Object.entries(seed || {}).forEach(([key, value]) => {
    const [name, domain = ''] = key.split('@');
    entries.set(name + '@' + domain, value);
  });
  return {
    entries,
    log,
    probes,
    names: () => [...entries.keys()].sort(),
    get: () => [...entries.entries()].map(([k, v]) => k.split('@')[0] + '=' + v).join('; '),
    set(raw) {
      const parts = String(raw).split(';').map((p) => p.trim());
      const i = parts[0].indexOf('=');
      const name = parts[0].slice(0, i);
      const value = parts[0].slice(i + 1);
      const domainPart = parts.find((p) => /^domain=/i.test(p));
      const domain = domainPart ? domainPart.slice(7).toLowerCase() : '';
      const expired = /max-age=0/i.test(raw) || /expires=thu, 01 jan 1970/i.test(raw);
      if (name === 'ct_scope_probe') { probes.push({ domain, expired }); } else { log.push({ name, domain, expired, raw: String(raw) }); }
      // A browser drops a cookie whose domain attribute is a public suffix (or otherwise refused).
      if (options.rejectDomain && domain) return;
      const key = name + '@' + domain;
      if (expired) entries.delete(key); else entries.set(key, value);
    },
  };
}

function boot({ globals = {}, scripts, hostname = 'www.example.com', cookieDomain = '', jar = makeJar(), local = {}, search = '', configs = {} }) {
  const document = new Bus();
  Object.assign(document, {
    readyState: 'complete', referrer: '', title: 'Page', visibilityState: 'visible',
    body: { appendChild() {} }, head: { appendChild() {} }, documentElement: {},
    querySelectorAll: () => [], querySelector: () => null, getElementsByTagName: () => [],
    getElementById: () => null,
    createElement: () => ({ setAttribute() {}, appendChild() {}, addEventListener() {}, style: {}, remove() {} }),
  });
  Object.defineProperty(document, 'cookie', { get: () => jar.get(), set: (raw) => jar.set(raw) });
  const window = new Bus();
  const storage = new FakeStorage();
  Object.entries(local).forEach(([k, v]) => { storage.values[k] = v; });
  Object.assign(window, {
    document,
    location: { href: 'https://' + hostname + '/' + search, search, protocol: 'https:', hostname, origin: 'https://' + hostname, pathname: '/' },
    navigator: { userAgent: 'Mozilla/5.0 Chrome/140', webdriver: false, language: 'en', maxTouchPoints: 0, sendBeacon: () => true },
    screen: { width: 1280, height: 800 }, innerWidth: 1280, innerHeight: 800,
    crypto: require('crypto').webcrypto,
    localStorage: storage, sessionStorage: new FakeStorage(),
    dataLayer: [], console: { log() {}, warn() {}, error() {} },
    setTimeout: () => 0, clearTimeout() {}, setInterval: () => 0, clearInterval() {},
    fetch: () => Promise.resolve({ ok: true, json: () => ({}) }),
    CSS: { escape: (v) => String(v) },
    CustomEvent: class CustomEvent { constructor(type, o) { this.type = type; this.detail = o && o.detail; } },
    URL, URLSearchParams, Event: class Event { constructor(type) { this.type = type; } }, TextEncoder, btoa, atob,
    ctConsentBridgeConfig: Object.assign({ enabled: true, cookieName: 'ct_consent', serverCookieName: 'ct_consent_state', cmpSource: 'plugin', timeout: 3000, fallbackGranted: false, cookieDomain }, configs.bridge),
    clicutcl_config: Object.assign({ cookieName: 'attribution', cookieDays: 90, consentCookieName: 'ct_consent', requireConsent: false, cookieDomain }, configs.attribution),
    clicutclEventsConfig: Object.assign({ enabled: true, cookieDomain }, configs.events),
    clicutclConsentL10n: Object.assign({ cookieName: 'ct_consent', cookieDomain }, configs.consent),
  });
  Object.assign(window, globals);
  window.window = window;
  window.self = window;
  const context = vm.createContext(window);
  scripts.forEach((name) => vm.runInContext(SOURCES[name], context, { filename: name + '.js' }));
  return { window, document, jar };
}


const NOW = Date.now();
const DAY = 24 * 60 * 60 * 1000;
const MIRROR_DATA = { gclid: 'G1', ft_source: 'google', ft_medium: 'cpc', lt_source: 'google', lt_medium: 'cpc', lt_campaign: 'brand', click_id_history: JSON.stringify([{ key: 'gclid', value: 'G1', at: 1 }]) };
const mirror = (overrides = {}) => JSON.stringify(Object.assign({ v: 1, savedAt: NOW - 3 * DAY, expiresAt: NOW + 87 * DAY, data: MIRROR_DATA }, overrides));
const REISSUE_URL = 'https://www.example.com/wp-json/clicutcl/v2/cookie-refresh';
const GRANTED = encodeURIComponent(JSON.stringify({ marketing: true, analytics: true }));
const DENIED = encodeURIComponent(JSON.stringify({ marketing: false, analytics: false }));
const flush = () => new Promise((resolve) => setImmediate(resolve));

function page(options = {}) {
  const calls = [];
  const respond = options.respond || (() => ({ ok: true, status: 200 }));
  const config = Object.assign({ cookieReissueUrl: REISSUE_URL, eventsToken: 'tok.sig' }, options.config);
  const out = boot({
    scripts: ['attribution'],
    hostname: options.hostname || 'www.example.com',
    cookieDomain: options.cookieDomain || '',
    jar: options.jar || makeJar(),
    local: options.local || {},
    search: options.search || '',
    configs: { attribution: config },
    globals: Object.assign({ fetch: (url, init) => { calls.push({ url, init }); return Promise.resolve(respond(calls.length)); } }, options.globals),
  });
  out.calls = calls;
  return out;
}

const attributionWrites = (jar) => jar.log.filter((w) => w.name === 'attribution' && !w.expired);
const cookieJson = (jar, domain = '') => JSON.parse(decodeURIComponent(jar.entries.get('attribution@' + domain)));

const tests = {
  'restores the cookie from an unexpired mirror, without click_id_history, bounded by the mirror expiry'() {
    const { jar, window } = page({ local: { attribution: mirror() } });
    const writes = attributionWrites(jar);
    assert.strictEqual(writes.length, 1, 'exactly one attribution write');
    const stored = cookieJson(jar);
    assert.strictEqual(stored.gclid, 'G1');
    assert.strictEqual(stored.lt_campaign, 'brand');
    assert.ok(!('click_id_history' in stored), 'the cookie never carries the browser-only history');
    const expires = Date.parse(/expires=([^;]+)/.exec(writes[0].raw)[1]);
    const bound = NOW + 87 * DAY;
    assert.ok(expires <= bound + 1000 && expires >= bound - 2000, 'cookie expiry equals the mirror expiresAt (' + (expires - bound) + ' ms off)');
    assert.ok(window.localStorage.getItem('attribution'), 'the mirror stays');
  },

  'the restore happens before the injector and the page-view push, then the marker is set'() {
    const jar = makeJar();
    const seen = [];
    const dataLayer = { push: (entry) => { seen.push({ event: entry.event, cookieAlreadyThere: /(^|; )attribution=/.test(jar.get()) }); return 1; } };
    const { window } = page({ jar, local: { attribution: mirror() }, globals: { dataLayer } });
    const view = seen.find((e) => e.event === 'ct_page_view');
    assert.ok(view, 'the page view was pushed');
    assert.strictEqual(view.cookieAlreadyThere, true, 'the cookie existed before Injector and the page-view push');
    assert.ok(window.localStorage.getItem('ct_cookie_reissue'), 'pending marker written after the persisted script write');
  },

  'a mirror that outlives the configured retention is bounded by cookieDays'() {
    const { jar } = page({ local: { attribution: mirror({ expiresAt: NOW + 400 * DAY }) }, config: { cookieDays: 90 } });
    const expires = Date.parse(/expires=([^;]+)/.exec(attributionWrites(jar)[0].raw)[1]);
    assert.ok(expires <= NOW + 90 * DAY + 1000, 'capped at cookieDays');
  },

  'nothing is written before consent: required and pending'() {
    const { jar, window, calls } = page({ local: { attribution: mirror() }, config: { requireConsent: true } });
    assert.deepStrictEqual(jar.log, [], 'no cookie write or expiry');
    assert.deepStrictEqual(jar.probes, [], 'no probe cookie either');
    assert.strictEqual(window.localStorage.getItem('ct_cookie_reissue'), null);
    assert.strictEqual(calls.length, 0);
  },

  'consent denied: no restore, mirror cleared, no marker, no request'() {
    const jar = makeJar({ ct_consent: DENIED });
    const { window, calls } = page({ jar, local: { attribution: mirror() }, config: { requireConsent: true } });
    assert.strictEqual(attributionWrites(jar).length, 0);
    assert.strictEqual(window.localStorage.getItem('attribution'), null, 'denial clears the mirror');
    assert.strictEqual(window.localStorage.getItem('ct_cookie_reissue'), null);
    assert.strictEqual(calls.length, 0);
  },

  'a grant after load restores then, not before'() {
    const jar = makeJar();
    const { window, document } = page({ jar, local: { attribution: mirror() }, config: { requireConsent: true } });
    assert.strictEqual(attributionWrites(jar).length, 0);
    jar.entries.set('ct_consent@', GRANTED);
    document.dispatchEvent(new window.CustomEvent('ct:consentResolved', { detail: { granted: true } }));
    assert.strictEqual(attributionWrites(jar).length, 1, 'restored once consent is granted');
    assert.strictEqual(cookieJson(jar).gclid, 'G1');
  },

  'consent already granted: restored on boot'() {
    const jar = makeJar({ ct_consent: GRANTED });
    page({ jar, local: { attribution: mirror() }, config: { requireConsent: true } });
    assert.strictEqual(attributionWrites(jar).length, 1);
  },

  'an expired mirror is cleared and nothing is written'() {
    const { jar, window, calls } = page({ local: { attribution: mirror({ expiresAt: NOW - 1000 }) } });
    assert.strictEqual(attributionWrites(jar).length, 0);
    assert.strictEqual(window.localStorage.getItem('attribution'), null);
    assert.strictEqual(window.localStorage.getItem('ct_cookie_reissue'), null);
    assert.strictEqual(calls.length, 0);
  },

  'a legacy mirror without retention metadata is dropped, never restored'() {
    const { jar, window } = page({ local: { attribution: JSON.stringify({ gclid: 'OLD' }) } });
    assert.strictEqual(attributionWrites(jar).length, 0);
    assert.strictEqual(window.localStorage.getItem('attribution'), null);
  },

  'an existing cookie is never overwritten from the mirror'() {
    const jar = makeJar({ attribution: encodeURIComponent(JSON.stringify({ gclid: 'COOKIE' })) });
    page({ jar, local: { attribution: mirror() } });
    assert.strictEqual(JSON.parse(decodeURIComponent(jar.entries.get('attribution@'))).gclid, 'COOKIE');
    assert.ok(!attributionWrites(jar).some((w) => /G1/.test(decodeURIComponent(w.raw))), 'mirror value was not written');
  },

  'the legacy ct_attribution cookie counts as present'() {
    const jar = makeJar({ ct_attribution: encodeURIComponent(JSON.stringify({ gclid: 'LEGACY' })) });
    page({ jar, local: { attribution: mirror() } });
    assert.ok(!attributionWrites(jar).some((w) => /G1/.test(decodeURIComponent(w.raw))));
  },

  'admin QA mode restores nothing'() {
    const jar = makeJar({ clicutcl_admin_qa: '1' });
    const { window } = page({ jar, local: { attribution: mirror() } });
    assert.strictEqual(attributionWrites(jar).length, 0);
    assert.strictEqual(window.localStorage.getItem('ct_cookie_reissue'), null);
  },

  'scope registrable: the restored cookie carries the same domain attribute and leaves no second cookie'() {
    const { jar } = page({ cookieDomain: 'example.com', local: { attribution: mirror() } });
    const writes = attributionWrites(jar);
    assert.strictEqual(writes.length, 1);
    assert.ok(/; domain=example\.com; path=\/; SameSite=Lax/.test(writes[0].raw), writes[0].raw);
    assert.deepStrictEqual(jar.names().filter((n) => n.startsWith('attribution')), ['attribution@example.com']);
    assert.ok(jar.probes.length >= 1 && jar.probes.every((p) => p.domain === 'example.com'), 'the ct_scope_probe rule ran for the same domain');
  },

  'scope registrable, browser refuses the domain: host-only restore'() {
    const { jar } = page({ cookieDomain: 'example.com', jar: makeJar({}, { rejectDomain: true }), local: { attribution: mirror() } });
    assert.strictEqual(attributionWrites(jar).length, 1);
    assert.ok(jar.log.filter((w) => !w.expired).every((w) => w.domain === ''), 'every write is host-only');
    assert.deepStrictEqual(jar.names().filter((n) => n.startsWith('attribution')), ['attribution@']);
  },

  'scope host: no domain attribute on the restored cookie'() {
    const { jar } = page({ local: { attribution: mirror() } });
    assert.ok(!/domain=/i.test(attributionWrites(jar)[0].raw));
  },

  // ----- re-issue request -----

  'a restore sends one authenticated POST and clears the marker on success': async function () {
    const { calls, window } = page({ local: { attribution: mirror() } });
    assert.strictEqual(calls.length, 1);
    assert.strictEqual(calls[0].url, REISSUE_URL);
    assert.strictEqual(calls[0].init.method, 'POST');
    assert.strictEqual(calls[0].init.credentials, 'same-origin');
    assert.strictEqual(calls[0].init.headers['X-Clicutcl-Token'], 'tok.sig');
    assert.deepStrictEqual(JSON.parse(calls[0].init.body), { token: 'tok.sig' });
    await flush();
    assert.strictEqual(window.localStorage.getItem('ct_cookie_reissue'), null, 'success clears the marker');
  },

  'a landing with a click ID writes the cookie, marks it pending and re-issues once': async function () {
    const { calls, jar, window } = page({ search: '?gclid=G9&utm_source=google&utm_medium=cpc' });
    assert.strictEqual(attributionWrites(jar).length >= 1, true);
    assert.strictEqual(calls.length, 1, 'one request per page load');
    await flush();
    assert.strictEqual(window.localStorage.getItem('ct_cookie_reissue'), null);
  },

  'a returning visitor with a cookie and no marker costs no request'() {
    const jar = makeJar({ attribution: encodeURIComponent(JSON.stringify({ gclid: 'COOKIE', ft_source: 'google' })) });
    const { calls } = page({ jar });
    assert.strictEqual(calls.length, 0);
  },

  'feature off (no URL): no marker, no request, restore still works'() {
    const { calls, window, jar } = page({ local: { attribution: mirror() }, config: { cookieReissueUrl: '' } });
    assert.strictEqual(attributionWrites(jar).length, 1);
    assert.strictEqual(window.localStorage.getItem('ct_cookie_reissue'), null);
    assert.strictEqual(calls.length, 0);
  },

  'a re-issue URL on another origin is never called'() {
    const { calls, window } = page({ local: { attribution: mirror() }, config: { cookieReissueUrl: 'https://other.test/wp-json/clicutcl/v2/cookie-refresh' } });
    assert.strictEqual(calls.length, 0);
    assert.ok(window.localStorage.getItem('ct_cookie_reissue'), 'marker stays: nothing was sent');
  },

  'no client token: no request'() {
    const { calls } = page({ local: { attribution: mirror() }, config: { eventsToken: '' } });
    assert.strictEqual(calls.length, 0);
  },

  'a 401 keeps the marker and is not retried inside an hour': async function () {
    const first = page({ local: { attribution: mirror() }, respond: () => ({ ok: false, status: 401 }) });
    await flush();
    const marker = JSON.parse(first.window.localStorage.getItem('ct_cookie_reissue'));
    assert.ok(marker && marker.w > 0 && marker.t > 0, 'marker kept with the attempt time');

    const cookie = first.jar.entries.get('attribution@');
    const again = page({ jar: makeJar({ attribution: cookie }), local: { attribution: mirror(), ct_cookie_reissue: JSON.stringify(marker) } });
    assert.strictEqual(again.calls.length, 0, 'within the retry floor');

    const stale = Object.assign({}, marker, { t: Date.now() - 2 * 60 * 60 * 1000 });
    const later = page({ jar: makeJar({ attribution: cookie }), local: { attribution: mirror(), ct_cookie_reissue: JSON.stringify(stale) } });
    assert.strictEqual(later.calls.length, 1, 'retried after the floor');
  },

  'a 403 (consent or setting) clears the marker; a 500 and a network error keep it': async function () {
    const denied = page({ local: { attribution: mirror() }, respond: () => ({ ok: false, status: 403 }) });
    await flush();
    assert.strictEqual(denied.window.localStorage.getItem('ct_cookie_reissue'), null);

    const broken = page({ local: { attribution: mirror() }, respond: () => ({ ok: false, status: 500 }) });
    await flush();
    assert.ok(broken.window.localStorage.getItem('ct_cookie_reissue'));

    const offline = boot({
      scripts: ['attribution'], local: { attribution: mirror() },
      configs: { attribution: { cookieReissueUrl: REISSUE_URL, eventsToken: 'tok.sig' } },
      globals: { fetch: () => Promise.reject(new Error('offline')) },
    });
    await flush();
    assert.ok(offline.window.localStorage.getItem('ct_cookie_reissue'));
  },

  'consent withdrawal removes the marker with the cookie and the mirror': async function () {
    const jar = makeJar();
    const { window, document } = page({ jar, local: { attribution: mirror() }, respond: () => ({ ok: false, status: 500 }) });
    await flush();
    assert.ok(window.localStorage.getItem('ct_cookie_reissue'));
    document.dispatchEvent(new window.CustomEvent('ct:consentResolved', { detail: { granted: false } }));
    assert.strictEqual(window.localStorage.getItem('ct_cookie_reissue'), null);
    assert.strictEqual(window.localStorage.getItem('attribution'), null);
    assert.ok(!jar.names().some((n) => n.startsWith('attribution@')), 'cookie expired');
  },

  'a request cannot start while consent is required and undecided even with a stored marker'() {
    const { calls } = page({ local: { ct_cookie_reissue: JSON.stringify({ w: 1, t: 0 }) }, jar: makeJar({ attribution: encodeURIComponent('{"gclid":"X"}') }), config: { requireConsent: true } });
    assert.strictEqual(calls.length, 0);
  },
};

(async () => {
  let failed = 0;
  for (const [name, fn] of Object.entries(tests)) {
    try {
      await fn();
      console.log('ok   ' + name);
    } catch (error) {
      failed += 1;
      console.error('FAIL ' + name + '\n     ' + String(error && error.stack || error).split('\n').slice(0, 4).join('\n     '));
    }
  }
  if (failed) {
    console.error(failed + ' cookie-reissue check(s) failed');
    process.exit(1);
  }
  console.log('cookie-reissue: ' + Object.keys(tests).length + ' checks passed');
})();
