// Paid-marker checks (issue #231): gad_source, gad_campaignid and oppref.
//
// Runs the real attribution script in a vm (same harness as payment-referrer.test.js) and checks
// that the three markers are captured as touch fields, label the channel, and never become the
// selected click ID.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.join(__dirname, '..', '..');
const SOURCE = fs.readFileSync(path.join(ROOT, 'assets/js/clicutcl-attribution.js'), 'utf8');
const LANDING = 'https://shop.example.test/landing/';

function boot({ referrer, url, stored = null }) {
  const jar = Object.create(null);
  if (stored) jar.attribution = encodeURIComponent(JSON.stringify(stored));

  const document = {
    readyState: 'complete',
    referrer,
    title: 'Landing',
    visibilityState: 'visible',
    body: { appendChild() {} },
    head: { appendChild() {} },
    documentElement: {},
    addEventListener() {},
    removeEventListener() {},
    dispatchEvent() { return true; },
    querySelectorAll: () => [],
    querySelector: () => null,
    getElementsByTagName: () => [],
    createElement: () => ({ setAttribute() {}, appendChild() {}, style: {} }),
  };
  Object.defineProperty(document, 'cookie', {
    get: () => Object.keys(jar).map((name) => name + '=' + jar[name]).join('; '),
    set: (raw) => {
      const [pair, ...attrs] = String(raw).split(';');
      const split = pair.indexOf('=');
      if (split === -1) return;
      const name = pair.slice(0, split).trim();
      const value = pair.slice(split + 1);
      if (value === '' || /max-age=0|expires=thu, 01 jan 1970/i.test(attrs.join(';'))) delete jar[name];
      else jar[name] = value;
    },
  });

  const storage = () => {
    const values = Object.create(null);
    return {
      getItem: (key) => (key in values ? values[key] : null),
      setItem: (key, value) => { values[key] = String(value); },
      removeItem: (key) => { delete values[key]; },
      key: (index) => Object.keys(values)[index] || null,
      get length() { return Object.keys(values).length; },
    };
  };

  const location = new URL(url);
  const window = {
    document,
    localStorage: storage(),
    sessionStorage: storage(),
    location: {
      href: location.href,
      search: location.search,
      protocol: location.protocol,
      hostname: location.hostname,
      origin: location.origin,
      pathname: location.pathname,
    },
    navigator: { userAgent: 'Mozilla/5.0 Chrome/140', webdriver: false, language: 'en', sendBeacon: () => true },
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
    addEventListener() {},
    removeEventListener() {},
    dispatchEvent() { return true; },
    fetch: () => Promise.resolve({ ok: true, json: () => ({}) }),
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
    clicutcl_config: {
      cookieName: 'attribution',
      cookieDays: 90,
      requireConsent: false,
      ignoredReferrerHosts: [],
    },
  };
  window.window = window;
  window.self = window;

  vm.runInContext(SOURCE, vm.createContext(window), { filename: 'clicutcl-attribution.js' });
  return window.ClickTrail ? window.ClickTrail.getData() : {};
}

const tests = {
  'gad_source from google.com is stored and labelled Google Ads'() {
    const data = boot({ referrer: 'https://www.google.com/', url: LANDING + '?gad_source=1&gad_campaignid=999' });
    assert.strictEqual(data.lt_channel, 'Google Ads');
    assert.strictEqual(data.ft_channel, 'Google Ads');
    assert.strictEqual(data.lt_medium, 'cpc');
    ['gad_source', 'lt_gad_source', 'ft_gad_source'].forEach((key) => assert.strictEqual(data[key], '1', key));
    ['gad_campaignid', 'lt_gad_campaignid', 'ft_gad_campaignid'].forEach((key) => assert.strictEqual(data[key], '999', key));
  },

  'oppref from chatgpt.com is labelled ChatGPT Ads'() {
    const data = boot({ referrer: 'https://chatgpt.com/', url: LANDING + '?oppref=abc123' });
    assert.strictEqual(data.lt_channel, 'ChatGPT Ads');
    assert.strictEqual(data.lt_medium, 'cpc');
    assert.strictEqual(data.lt_source, 'chatgpt');
    ['oppref', 'lt_oppref', 'ft_oppref'].forEach((key) => assert.strictEqual(data[key], 'abc123', key));
  },

  'an unpaid chatgpt.com referral keeps its label'() {
    const data = boot({ referrer: 'https://chatgpt.com/', url: LANDING });
    assert.strictEqual(data.lt_channel, 'ChatGPT');
    assert.strictEqual(data.lt_source, 'chatgpt.com');
    assert.strictEqual(data.lt_medium, 'referral');
    assert.strictEqual(data.oppref, undefined);
  },

  'explicit utm_medium and utm_source are not overwritten'() {
    const data = boot({
      referrer: 'https://chatgpt.com/',
      url: LANDING + '?oppref=abc123&utm_source=newsletter&utm_medium=email&gad_source=1',
    });
    assert.strictEqual(data.lt_source, 'newsletter');
    assert.strictEqual(data.lt_medium, 'email');
  },

  'gad_source and oppref are never the selected click ID'() {
    const data = boot({ referrer: 'https://www.google.com/', url: LANDING + '?gad_source=1&gad_campaignid=999&oppref=abc123' });
    assert.strictEqual(data.attribution_selected_click_id, undefined);
  },

  'gclid still wins the selection next to gad_source'() {
    const data = boot({ referrer: 'https://www.google.com/', url: LANDING + '?gclid=G1&gad_source=1&gad_campaignid=999' });
    assert.strictEqual(data.attribution_selected_click_id, 'G1');
    assert.strictEqual(data.lt_channel, 'Google Ads');
  },

  'a later gad_source landing does not replace a selected gclid'() {
    const first = boot({ referrer: 'https://www.google.com/', url: LANDING + '?gclid=G1' });
    const data = boot({ referrer: 'https://www.google.com/', url: LANDING + '?gad_source=1', stored: first });
    assert.strictEqual(data.attribution_selected_click_id, 'G1');
    assert.strictEqual(data.lt_gad_source, '1');
  },

  'values are capped at 128 characters'() {
    const data = boot({ referrer: 'https://www.google.com/', url: LANDING + '?gad_campaignid=' + 'a'.repeat(300) });
    assert.strictEqual(data.lt_gad_campaignid.length, 128);
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
  console.error(failed + ' paid marker check(s) failed.');
  process.exit(1);
}
console.log('Paid marker checks passed.');
