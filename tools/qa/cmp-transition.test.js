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

function boot({ search = '?gclid=G1&utm_source=google', cookiebot, seed = {} } = {}) {
  const writes = new Set();
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
    querySelectorAll: () => [],
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
    navigator: { userAgent: 'Mozilla/5.0 Chrome/140', webdriver: false, language: 'en' },
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
    CSS: { escape: (value) => String(value) },
    CustomEvent: class CustomEvent {
      constructor(type, options) { this.type = type; this.detail = options && options.detail; }
    },
    URL,
    URLSearchParams,
    TextEncoder,
    btoa,
    atob,
    clicutcl_config: { cookieName: 'attribution', cookieDays: 90, requireConsent: true },
    // Thank-you matcher on the landing path exercises the events script's session marker.
    clicutclEventsConfig: { enabled: true, thankYouMatchers: ['/landing'] },
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
  return { window, writes, present, decide, data };
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
