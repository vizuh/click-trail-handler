// WhatsApp handoff (specs/008-whatsapp-handoff, #145).
//
// Runs the real consent bridge, attribution and events scripts in a vm with a fake Cookiebot, a
// fake fetch that plays the whatsapp-ref route, and anchors to WhatsApp. Asserts the
// `contact_whatsapp_start` event (consent, dedupe, payload), the `Ref: CT-<8 base32>` reference
// (format, registration, collision retry, no base64 blob) and that withdrawal restores the link.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.join(__dirname, '..', '..');
const SCRIPTS = ['clicutcl-consent-bridge.js', 'clicutcl-attribution.js', 'clicutcl-events.js'].map((file) => ({
  file,
  source: fs.readFileSync(path.join(ROOT, 'assets/js', file), 'utf8'),
}));
const REF_URL = 'https://example.test/wp-json/clicutcl/v2/whatsapp-ref';
const BATCH_URL = 'https://example.test/wp-json/clicutcl/v2/events/batch';
const REF_TEXT = /Ref: CT-[A-Z2-7]{8}$/;

// Capture listeners fire before bubble listeners, like a real document.
class Bus {
  constructor() { this.capture = Object.create(null); this.bubble = Object.create(null); }
  addEventListener(type, fn, options) {
    if (typeof fn !== 'function') return;
    const useCapture = options === true || !!(options && options.capture);
    const map = useCapture ? this.capture : this.bubble;
    (map[type] || (map[type] = [])).push({ fn, once: !!(options && options.once) });
  }
  removeEventListener(type, fn) {
    [this.capture, this.bubble].forEach((map) => { map[type] = (map[type] || []).filter((entry) => entry.fn !== fn); });
  }
  dispatchEvent(event) {
    [this.capture, this.bubble].forEach((map) => {
      (map[event.type] || []).slice().forEach((entry) => {
        if (entry.once) this.removeEventListener(event.type, entry.fn);
        entry.fn.call(this, event);
      });
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

function anchor(href, text = 'Chat with us') {
  const at = { href };
  const el = {
    tagName: 'A',
    nodeType: 1,
    textContent: text,
    getAttribute: (name) => (name in at ? at[name] : null),
    setAttribute: (name, v) => { at[name] = String(v); },
    hasAttribute: (name) => name in at,
    removeAttribute: (name) => { delete at[name]; },
    closest: (selector) => (selector === 'form' ? null : el),
  };
  return el;
}

const flush = () => new Promise((resolve) => setImmediate(resolve));

// `refReplies`: statuses the fake route answers with, in order (default 200 for every call).
function boot({ granted = true, config = {}, eventsConfig = {}, elements = [], refReplies = [], search = '?gclid=G1&utm_source=google', referrer = 'https://www.google.com/', clock, sessionSeed } = {}) {
  const refCalls = [];
  const batchCalls = [];
  const jar = Object.create(null);
  const document = new Bus();
  Object.assign(document, {
    readyState: 'complete',
    referrer,
    title: 'Landing',
    visibilityState: 'visible',
    body: { appendChild() {} },
    head: { appendChild() {} },
    documentElement: {},
    querySelectorAll: (selector) => {
      if (/a\[data-clicutcl-href\]/.test(selector)) return elements.filter((el) => el.hasAttribute('data-clicutcl-href'));
      if (/^a\[href/.test(selector)) return elements.filter((el) => el.hasAttribute('href'));
      return [];
    },
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
      if (value === '' || /max-age=0|expires=thu, 01 jan 1970/i.test(attrs.join(';'))) { delete jar[name]; return; }
      jar[name] = value;
    },
  });

  const replies = refReplies.slice();
  const fetch = (url, options) => {
    const body = JSON.parse(options.body);
    if (url === REF_URL) {
      refCalls.push({ url, headers: options.headers, body });
      const status = replies.length ? replies.shift() : 200;
      return Promise.resolve({ ok: status >= 200 && status < 300, status, json: () => ({}) });
    }
    batchCalls.push({ url, body });
    return Promise.resolve({ ok: true, status: 200, json: () => ({}) });
  };

  const url = new URL('https://example.test/landing' + search);
  const window = new Bus();
  Object.assign(window, {
    document,
    localStorage: new FakeStorage(),
    sessionStorage: new FakeStorage(),
    location: { href: url.href, search: url.search, protocol: url.protocol, hostname: url.hostname, origin: url.origin, pathname: url.pathname },
    navigator: { userAgent: 'Mozilla/5.0 Chrome/140', webdriver: false, language: 'en', maxTouchPoints: 0, sendBeacon: () => true },
    screen: { width: 1280, height: 800 },
    innerWidth: 1280,
    innerHeight: 800,
    crypto: require('crypto').webcrypto,
    dataLayer: [],
    console: { log() {}, warn() {}, error() {} },
    setTimeout: () => 0,
    clearTimeout() {},
    setInterval: () => 0,
    clearInterval() {},
    fetch,
    CSS: { escape: (value) => String(value) },
    CustomEvent: class CustomEvent { constructor(type, options) { this.type = type; this.detail = options && options.detail; } },
    URL,
    URLSearchParams,
    Event: class Event { constructor(type) { this.type = type; } },
    TextEncoder,
    btoa,
    atob,
    clicutcl_config: Object.assign({
      cookieName: 'attribution',
      cookieDays: 90,
      requireConsent: true,
      enableWhatsapp: true,
      whatsappAppendAttribution: true,
      eventsToken: 'tok.en',
      whatsappRefUrl: REF_URL,
    }, config),
    clicutclEventsConfig: Object.assign({
      enabled: true,
      transportEnabled: true,
      eventsBatchUrl: BATCH_URL,
      eventsToken: 'tok.en',
    }, eventsConfig),
    ctConsentBridgeConfig: {
      enabled: true,
      cookieName: 'ct_consent',
      serverCookieName: 'ct_consent_state',
      cmpSource: 'cookiebot',
      timeout: 3000,
      fallbackGranted: false,
    },
    Date: clock ? class extends Date { static now() { return clock.now; } } : Date,
    Cookiebot: { hasResponse: true, consent: { marketing: granted, statistics: granted } },
  });
  window.window = window;
  window.self = window;
  if (sessionSeed) Object.assign(window.sessionStorage.values, sessionSeed);

  const context = vm.createContext(window);
  SCRIPTS.forEach(({ file, source }) => vm.runInContext(source, context, { filename: file }));

  const click = (el) => document.dispatchEvent({ type: 'click', target: el });
  const hover = (el) => document.dispatchEvent({ type: 'mouseover', target: el });
  const events = (name) => window.dataLayer.filter((entry) => entry.event === name);
  const withdraw = () => {
    window.Cookiebot.consent = { marketing: false, statistics: false };
    window.dispatchEvent(new window.CustomEvent('CookiebotOnDecline'));
  };
  const sentEvents = () => batchCalls.map((call) => call.body.events[0]);
  return { window, document, click, hover, events, withdraw, refCalls, batchCalls, sentEvents };
}

const textOf = (el) => new URL(el.getAttribute('href')).searchParams.get('text');

const tests = {
  async 'a click with consent pushes one contact_whatsapp_start with the link and the business number'() {
    const link = anchor('https://wa.me/351912345678?text=Hi%20there#frag');
    const page = boot({ elements: [link] });
    page.click(link);
    const pushed = page.events('contact_whatsapp_start');
    assert.strictEqual(pushed.length, 1);
    assert.strictEqual(pushed[0].phone, '351912345678');
    assert.strictEqual(pushed[0].contact_type, 'whatsapp');
    assert.strictEqual(pushed[0].ref_code, undefined, 'no reference was registered yet');
    assert.strictEqual(pushed[0].link_url, 'https://wa.me/351912345678', 'text and fragment are not pushed');
  },

  async 'the v2 transport maps it to the canonical contact_whatsapp_start at the mid funnel stage'() {
    const link = anchor('https://api.whatsapp.com/send?phone=%2B351%20912%20345%20678&text=Hi');
    const page = boot({ elements: [link] });
    page.click(link);
    const sent = page.sentEvents();
    assert.strictEqual(sent.length, 1);
    assert.strictEqual(sent[0].event_name, 'contact_whatsapp_start');
    assert.strictEqual(sent[0].funnel_stage, 'mid');
    assert.strictEqual(sent[0].delivery_context.phone, '351912345678');
  },

  async 'whatsapp:// links and web.whatsapp.com count, other hosts and non-numeric paths do not carry a number'() {
    const app = anchor('whatsapp://send?phone=351912345678&text=Hi');
    const web = anchor('https://web.whatsapp.com/send?phone=351911111111');
    const business = anchor('https://wa.me/message/ABCDEF');
    const other = anchor('https://example.com/wa.me/351912345678');
    const page = boot({ elements: [app, web, business, other] });
    [app, web, business, other].forEach((link) => page.click(link));
    const pushed = page.events('contact_whatsapp_start');
    assert.deepStrictEqual(pushed.map((e) => e.phone), ['351912345678', '351911111111', '']);
    assert.strictEqual(pushed[0].link_url, 'whatsapp://send?phone=351912345678');
  },

  async 'denied or pending consent pushes nothing and leaves no dedupe marker'() {
    const link = anchor('https://wa.me/351912345678');
    const denied = boot({ granted: false, elements: [link] });
    denied.click(link);
    assert.strictEqual(denied.events('contact_whatsapp_start').length, 0);
    assert.strictEqual(denied.batchCalls.length, 0);
    assert.strictEqual(link.getAttribute('href'), 'https://wa.me/351912345678', 'the URL is untouched');
    assert.strictEqual(denied.refCalls.length, 0, 'no registration request without consent');

    const page = boot({ elements: [link] });
    page.withdraw();
    page.click(link);
    assert.strictEqual(page.events('contact_whatsapp_start').length, 0, 'withdrawal after boot stops the event');
    // Consent returns: the earlier denied click must not have consumed the dedupe window.
    page.window.Cookiebot.consent = { marketing: true, statistics: true };
    page.window.dispatchEvent(new page.window.CustomEvent('CookiebotOnAccept'));
    page.click(link);
    assert.strictEqual(page.events('contact_whatsapp_start').length, 1);
  },

  async 'a repeat click on the same link is deduped, another link or a later window is not'() {
    const first = anchor('https://wa.me/351912345678');
    const second = anchor('https://wa.me/351900000000');
    const clock = { now: 1700000000000 };
    const page = boot({ elements: [first, second], clock });
    page.click(first);
    page.click(first);
    assert.strictEqual(page.events('contact_whatsapp_start').length, 1);
    page.click(second);
    assert.strictEqual(page.events('contact_whatsapp_start').length, 2);
    clock.now += 10001;
    page.click(first);
    assert.strictEqual(page.events('contact_whatsapp_start').length, 3);
  },

  async 'with the setting on the text ends with Ref: CT-<8 base32>, registered first, and the base64 blob is gone'() {
    const link = anchor('https://wa.me/351912345678?text=Hi');
    const page = boot({ elements: [link] });
    await flush();
    assert.strictEqual(page.refCalls.length, 0, 'a page view alone registers nothing');
    page.hover(link);
    await flush();
    assert.strictEqual(page.refCalls.length, 1, 'one registration on the first hover');
    const call = page.refCalls[0];
    assert.strictEqual(call.headers['X-Clicutcl-Token'], 'tok.en');
    assert.match(call.body.code, /^[A-Z2-7]{8}$/);
    assert.strictEqual(call.body.data.gclid, 'G1');
    assert.strictEqual(call.body.data.lt_source, 'google');
    assert.ok(call.body.visitor_id, 'visitor id is sent');
    assert.strictEqual(call.body.selected_click_id_key, 'gclid');

    page.click(link);
    const text = textOf(link);
    assert.match(text, REF_TEXT);
    assert.ok(text.startsWith('Hi\n\nRef: CT-'));
    assert.ok(!text.includes('CT:'), 'no base64 blob');
    assert.strictEqual(text.split('Ref:').length, 2);
    assert.strictEqual(text.slice(-8), call.body.code);

    page.click(link);
    assert.strictEqual(textOf(link).split('Ref:').length, 2, 'a second click does not stack another reference');
    const pushed = page.events('contact_whatsapp_start');
    assert.strictEqual(pushed[0].ref_code, 'CT-' + call.body.code);
  },

  async 'a link without a text parameter gets a bare Ref line'() {
    const link = anchor('https://wa.me/351912345678');
    const page = boot({ elements: [link] });
    page.hover(link);
    await flush();
    page.click(link);
    assert.match(textOf(link), /^Ref: CT-[A-Z2-7]{8}$/);
  },

  async 'a collision (409) is retried once with a new code and only the confirmed code is used'() {
    const link = anchor('https://wa.me/351912345678');
    const page = boot({ elements: [link], refReplies: [409, 200] });
    page.hover(link);
    await flush();
    assert.strictEqual(page.refCalls.length, 2);
    assert.notStrictEqual(page.refCalls[0].body.code, page.refCalls[1].body.code);
    page.click(link);
    assert.ok(textOf(link).endsWith(page.refCalls[1].body.code));
  },

  async 'two collisions or a server refusal leave the link undecorated, and a failed snapshot is not retried on every hover'() {
    const link = anchor('https://wa.me/351912345678?text=Hi');
    const page = boot({ elements: [link], refReplies: [409, 409] });
    page.hover(link);
    await flush();
    assert.strictEqual(page.refCalls.length, 2, 'exactly one retry');
    page.click(link);
    assert.strictEqual(link.getAttribute('href'), 'https://wa.me/351912345678?text=Hi');
    assert.strictEqual(page.events('contact_whatsapp_start').length, 1, 'the click event still fires');
    assert.ok(!('ref_code' in page.events('contact_whatsapp_start')[0]));
    page.document.dispatchEvent({ type: 'mouseover', target: link });
    await flush();
    assert.strictEqual(page.refCalls.length, 2, 'no new request for the same failed snapshot');
  },

  async 'a page view, with or without a WhatsApp link, registers nothing'() {
    const link = anchor('https://wa.me/351912345678');
    const page = boot({ elements: [link] });
    await flush();
    assert.strictEqual(page.refCalls.length, 0);
    assert.strictEqual(page.window.sessionStorage.getItem('ct_wa_ref'), null);
  },

  async 'pointerdown or focus on a WhatsApp link registers once; other links and elements do not'() {
    const link = anchor('https://wa.me/351912345678');
    const other = anchor('https://example.com/contact');
    const page = boot({ elements: [link, other] });
    page.document.dispatchEvent({ type: 'pointerdown', target: other });
    page.hover(other);
    await flush();
    assert.strictEqual(page.refCalls.length, 0, 'a non-WhatsApp link is ignored');
    page.document.dispatchEvent({ type: 'focusin', target: link });
    page.document.dispatchEvent({ type: 'pointerdown', target: link });
    page.hover(link);
    await flush();
    assert.strictEqual(page.refCalls.length, 1, 'one registration however many warm-up events');
    page.click(link);
    assert.match(textOf(link), REF_TEXT);
  },

  async 'the first touch anywhere warms a page that has a WhatsApp link, and only such a page'() {
    const link = anchor('https://wa.me/351912345678');
    const withLink = boot({ elements: [link] });
    withLink.document.dispatchEvent({ type: 'touchstart', target: { closest: () => null } });
    await flush();
    assert.strictEqual(withLink.refCalls.length, 1);
    withLink.click(link);
    assert.match(textOf(link), REF_TEXT);

    const without = boot({ elements: [anchor('https://example.com/contact')] });
    without.document.dispatchEvent({ type: 'touchstart', target: { closest: () => null } });
    await flush();
    assert.strictEqual(without.refCalls.length, 0);
  },

  async 'a click before the code is confirmed is not decorated'() {
    const link = anchor('https://wa.me/351912345678');
    const page = boot({ elements: [link] });
    page.click(link); // the registration promise has not settled yet
    assert.strictEqual(link.getAttribute('href'), 'https://wa.me/351912345678');
    await flush();
    page.click(link);
    assert.match(textOf(link), REF_TEXT);
  },

  async 'the setting off sends no request and changes no link, the event still fires'() {
    const link = anchor('https://wa.me/351912345678?text=Hi');
    const page = boot({ elements: [link], config: { whatsappAppendAttribution: false } });
    await flush();
    page.click(link);
    assert.strictEqual(page.refCalls.length, 0);
    assert.strictEqual(link.getAttribute('href'), 'https://wa.me/351912345678?text=Hi');
    assert.strictEqual(page.events('contact_whatsapp_start').length, 1);
  },

  async 'no stored attribution means no reference is registered or appended'() {
    const link = anchor('https://wa.me/351912345678');
    const page = boot({ elements: [link], search: '', referrer: '' });
    await flush();
    page.click(link);
    assert.strictEqual(page.refCalls.length, 0);
    assert.strictEqual(link.getAttribute('href'), 'https://wa.me/351912345678');
  },

  async 'the confirmed code is reused for the tab session and forgotten on withdrawal'() {
    const firstLink = anchor('https://wa.me/351912345678');
    const first = boot({ elements: [firstLink] });
    first.hover(firstLink);
    await flush();
    assert.strictEqual(first.refCalls.length, 1);
    const seed = Object.assign({}, first.window.sessionStorage.values);
    assert.ok(seed.ct_wa_ref, 'the confirmed code is kept in sessionStorage');

    // A new page view in the same tab with the same attribution: no second registration.
    const link = anchor('https://wa.me/351912345678');
    const second = boot({ elements: [link], sessionSeed: seed });
    second.hover(link);
    await flush();
    assert.strictEqual(second.refCalls.length, 0);
    second.click(link);
    assert.ok(textOf(link).endsWith(JSON.parse(seed.ct_wa_ref).c));

    // Different attribution: the stored code does not apply, a new one is registered.
    const thirdLink = anchor('https://wa.me/351912345678');
    const third = boot({ elements: [thirdLink], sessionSeed: seed, search: '?gclid=OTHER' });
    third.hover(thirdLink);
    await flush();
    assert.strictEqual(third.refCalls.length, 1);

    first.withdraw();
    assert.strictEqual(first.window.sessionStorage.getItem('ct_wa_ref'), null, 'withdrawal forgets the code');
  },

  async 'withdrawal restores the original href and a later click adds nothing'() {
    const original = 'https://wa.me/351912345678?text=Hi';
    const link = anchor(original);
    const page = boot({ elements: [link] });
    page.hover(link);
    await flush();
    page.click(link);
    assert.match(textOf(link), REF_TEXT);
    assert.ok(link.hasAttribute('data-clicutcl-wa-ref'));

    page.withdraw();
    assert.strictEqual(link.getAttribute('href'), original, 'byte for byte');
    assert.ok(!link.hasAttribute('data-clicutcl-wa-ref'));
    page.click(link);
    assert.strictEqual(link.getAttribute('href'), original);
    assert.strictEqual(page.events('contact_whatsapp_start').length, 1, 'only the click made with consent');
  },

  async 'withdrawal restores a whatsapp:// link too'() {
    const original = 'whatsapp://send?phone=351912345678&text=Hi';
    const link = anchor(original);
    const page = boot({ elements: [link] });
    page.hover(link);
    await flush();
    page.click(link);
    assert.match(new URL(link.getAttribute('href')).searchParams.get('text'), REF_TEXT);
    assert.ok(link.getAttribute('href').startsWith('whatsapp://send?'));
    page.withdraw();
    assert.strictEqual(link.getAttribute('href'), original);
  },

  async 'a tampered stored original is never written back to href on click, hover or withdrawal'() {
    const authored = 'https://wa.me/351912345678?text=Hi%20Ref%3A%20CT-ABCDEFGH';
    const link = anchor(authored);
    link.setAttribute('data-clicutcl-href', 'javascript:alert(1)');
    link.setAttribute('data-clicutcl-decorated', authored);
    link.setAttribute('data-clicutcl-wa-ref', 'CT-ABCDEFGH');
    const page = boot({ elements: [link] });
    page.hover(link);
    page.click(link);
    await flush();
    page.click(link);
    assert.ok(!/^javascript:/i.test(link.getAttribute('href')), 'click never writes javascript:');
    assert.strictEqual(link.getAttribute('href'), authored);

    link.setAttribute('data-clicutcl-href', 'javascript:alert(1)');
    link.setAttribute('data-clicutcl-decorated', authored);
    page.withdraw();
    assert.ok(!/^javascript:/i.test(link.getAttribute('href')), 'withdrawal never writes javascript:');

    // The same for a link that is not WhatsApp at all, and for http: and data: originals.
    ['http://wa.me/351912345678', 'data:text/html,x', 'https://evil.example/wa.me'].forEach((bad) => {
      const other = anchor(bad);
      const p2 = boot({ elements: [other] });
      p2.hover(other);
      p2.click(other);
      assert.strictEqual(other.getAttribute('href'), bad, 'untouched: ' + bad);
    });
  },

  async 'the starter kit and the plugin agree on the event name'() {
    const kit = JSON.parse(fs.readFileSync(path.join(ROOT, 'assets/gtm-starter-kit.json'), 'utf8')).containerVersion;
    const trigger = kit.trigger.find((t) => t.name === 'LC - contact_whatsapp_start');
    assert.ok(trigger, 'starter kit trigger');
    assert.ok(JSON.stringify(trigger).includes('"contact_whatsapp_start"'));
    assert.ok(!JSON.stringify(kit).includes('whatsapp_click'), 'the producer-less name is gone');
    const eventV2 = fs.readFileSync(path.join(ROOT, 'includes/tracking/class-eventv2.php'), 'utf8');
    assert.ok(/'contact_whatsapp_start'\s*=>\s*true/.test(eventV2), 'accepted on the public browser route');
  },
};

(async () => {
  let failed = 0;
  for (const [name, fn] of Object.entries(tests)) {
    try {
      await fn();
      console.log('ok - ' + name);
    } catch (error) {
      failed += 1;
      console.error('not ok - ' + name + '\n' + (error && error.stack ? error.stack : error));
    }
  }
  if (failed) {
    console.error(failed + ' whatsapp-handoff test(s) failed');
    process.exit(1);
  }
  console.log('whatsapp-handoff: ' + Object.keys(tests).length + ' passed');
})();
