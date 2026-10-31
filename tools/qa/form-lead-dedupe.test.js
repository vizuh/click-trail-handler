// Form-success lead dedupe (#140).
//
// Runs the real events script in a vm with a stubbed consent bridge and a controllable
// clock, dispatches Contact Form 7's `wpcf7mailsent` and generic external lead messages,
// and asserts that one submission yields one `lead` in window.dataLayer.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '..', '..', 'assets/js/clicutcl-events.js'), 'utf8');
const WINDOW_MS = 10000;

class Bus {
  constructor() { this.listeners = Object.create(null); }
  addEventListener(type, fn) {
    if (typeof fn !== 'function') return;
    (this.listeners[type] || (this.listeners[type] = [])).push(fn);
  }
  removeEventListener(type, fn) {
    this.listeners[type] = (this.listeners[type] || []).filter((entry) => entry !== fn);
  }
  dispatchEvent(event) {
    (this.listeners[event.type] || []).slice().forEach((fn) => fn.call(this, event));
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

function boot({ granted = true, readyState = 'complete' } = {}) {
  const consent = { granted };
  const clock = { now: 1700000000000 };
  class FakeDate extends Date {
    static now() { return clock.now; }
  }
  const document = new Bus();
  Object.assign(document, {
    readyState,
    referrer: '',
    title: 'Quote',
    cookie: '',
    querySelectorAll: () => [],
    querySelector: () => null,
  });
  const window = new Bus();
  Object.assign(window, {
    document,
    location: { href: 'https://example.test/quote', search: '', protocol: 'https:', hostname: 'example.test', origin: 'https://example.test', pathname: '/quote' },
    navigator: { userAgent: 'Mozilla/5.0', maxTouchPoints: 0 },
    innerWidth: 1280,
    innerHeight: 800,
    crypto: require('crypto').webcrypto,
    localStorage: new FakeStorage(),
    sessionStorage: new FakeStorage(),
    dataLayer: [],
    console: { log() {}, warn() {}, error() {} },
    setTimeout: () => 0,
    clearTimeout() {},
    Date: FakeDate,
    URL,
    URLSearchParams,
    clicutclEventsConfig: { enabled: true, iframeOrigins: ['calendly.com', 'typeform.com', 'hubspot.com', 'hsforms.net'] },
    ClickTrailConsent: {
      isResolved: () => true,
      isGranted: () => consent.granted,
      getState: () => ({ marketing: consent.granted, analytics: consent.granted }),
    },
  });
  window.window = window;
  vm.runInContext(source, vm.createContext(window), { filename: 'clicutcl-events.js' });

  const cf7 = (contactFormId, unitTag) =>
    document.dispatchEvent({ type: 'wpcf7mailsent', detail: { contactFormId, unitTag } });
  const message = (data, origin = 'https://typeform.com') =>
    window.dispatchEvent({ type: 'message', origin, data });
  const pushed = (name) => window.dataLayer.filter((entry) => !name || entry.event === name);
  return { window, document, consent, clock, cf7, message, pushed };
}

const tests = {
  'the same CF7 form instance succeeding twice within the window pushes one lead'() {
    const page = boot();
    page.cf7(12, 'wpcf7-f12-p3-o1');
    page.clock.now += 2000;
    page.cf7(12, 'wpcf7-f12-p3-o1');
    const leads = page.pushed('lead');
    assert.strictEqual(leads.length, 1);
    assert.strictEqual(leads[0].form_id, '12');
    assert.strictEqual(leads[0].form_provider, 'cf7');
    assert.strictEqual(page.pushed('form_submit_failed').length, 0, 'the dropped repeat is silent');
  },

  'a different CF7 instance or form within the window still pushes'() {
    const page = boot();
    page.cf7(12, 'wpcf7-f12-p3-o1');
    page.cf7(12, 'wpcf7-f12-p3-o2');
    page.cf7(13, 'wpcf7-f13-p3-o1');
    assert.deepStrictEqual(page.pushed('lead').map((e) => e.form_id), ['12', '12', '13']);
  },

  'the same CF7 instance pushes again once the window has passed'() {
    const page = boot();
    page.cf7(12, 'wpcf7-f12-p3-o1');
    page.clock.now += WINDOW_MS - 1;
    page.cf7(12, 'wpcf7-f12-p3-o1');
    assert.strictEqual(page.pushed('lead').length, 1);
    page.clock.now += 2;
    page.cf7(12, 'wpcf7-f12-p3-o1');
    assert.strictEqual(page.pushed('lead').length, 2);
  },

  'a CF7 event without a unit tag dedupes on the form id'() {
    const page = boot();
    page.cf7(12, undefined);
    page.cf7(12, undefined);
    assert.strictEqual(page.pushed('lead').length, 1);
  },

  'a dropped repeat does not extend the window'() {
    const page = boot();
    page.cf7(12, 'u');
    page.clock.now += 6000;
    page.cf7(12, 'u');
    page.clock.now += 6000;
    page.cf7(12, 'u');
    assert.strictEqual(page.pushed('lead').length, 2);
  },

  'a generic external lead message without an id dedupes per provider within the window'() {
    const page = boot();
    page.message('typeform-submit');
    page.message('typeform-submit');
    assert.strictEqual(page.pushed('lead').length, 1);
    page.clock.now += WINDOW_MS + 1;
    page.message('typeform-submit');
    assert.strictEqual(page.pushed('lead').length, 2);
  },

  'external lead messages carrying different ids both push, the same id once'() {
    const page = boot();
    page.message({ type: 'clicutcl.lead', event_id: 'a' }, 'https://example.test');
    page.message({ type: 'clicutcl.lead', event_id: 'b' }, 'https://example.test');
    page.message({ type: 'clicutcl.lead', event_id: 'b' }, 'https://example.test');
    assert.strictEqual(page.pushed('lead').length, 2);
  },

  'consent denied at boot pushes nothing'() {
    const page = boot({ granted: false });
    page.cf7(12, 'u');
    page.message('typeform-submit');
    assert.strictEqual(page.pushed().length, 0);
  },

  'consent denied pushes nothing and leaves no marker behind'() {
    const page = boot();
    page.consent.granted = false;
    page.cf7(12, 'u');
    page.message('typeform-submit');
    assert.strictEqual(page.pushed().length, 0);
    page.consent.granted = true;
    page.cf7(12, 'u');
    assert.strictEqual(page.pushed('lead').length, 1, 'a denied attempt must not suppress the first granted lead');
  },
};

let failed = 0;
Object.entries(tests).forEach(([name, run]) => {
  try {
    run();
    console.log('ok   ' + name);
  } catch (error) {
    failed += 1;
    console.error('FAIL ' + name + '\n' + (error && error.stack ? error.stack : error));
  }
});
if (failed) {
  console.error(failed + ' form lead dedupe check(s) failed.');
  process.exit(1);
}
console.log('Form lead dedupe checks passed.');
