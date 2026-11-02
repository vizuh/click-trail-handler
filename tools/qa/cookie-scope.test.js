// Cookie scope checks (specs/007-cookie-scope, #154).
//
// Runs the four real front-end scripts in a vm with a cookie jar that records every
// write and expiry together with its `domain` attribute, and checks that the localized
// `cookieDomain` is applied identically to writes and removals, that a non-covering
// domain falls back to host-only, and that the attribution script migrates existing
// cookies once per origin in both directions.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.join(__dirname, '..', '..');
const read = (file) => fs.readFileSync(path.join(ROOT, 'assets/js', file), 'utf8');
const SOURCES = {
  bridge: read('clicutcl-consent-bridge.js'),
  attribution: read('clicutcl-attribution.js'),
  events: read('clicutcl-events.js'),
  consent: read('clicutcl-consent.js'),
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
function makeJar(seed) {
  const entries = new Map();
  const log = [];
  Object.entries(seed || {}).forEach(([key, value]) => {
    const [name, domain = ''] = key.split('@');
    entries.set(name + '@' + domain, value);
  });
  return {
    entries,
    log,
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
      log.push({ name, domain, expired, raw: String(raw) });
      const key = name + '@' + domain;
      if (expired) entries.delete(key); else entries.set(key, value);
    },
  };
}

function boot({ scripts, hostname = 'www.example.com', cookieDomain = '', jar = makeJar(), local = {}, search = '', configs = {} }) {
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
  window.window = window;
  window.self = window;
  const context = vm.createContext(window);
  scripts.forEach((name) => vm.runInContext(SOURCES[name], context, { filename: name + '.js' }));
  return { window, document, jar };
}

const domains = (jar, names) => jar.log.filter((w) => !names || names.includes(w.name));
const allDomains = (entries) => [...new Set(entries.map((w) => w.domain))];

const tests = {
  'attribution writes carry the domain attribute under registrable'() {
    const { window, jar } = boot({ scripts: ['attribution'], cookieDomain: 'example.com', search: '?utm_source=g&gclid=G1' });
    const writes = domains(jar, ['attribution', 'ct_visitor_id', 'ct_session_id', 'ct_session']).filter((w) => !w.expired);
    assert.ok(writes.length >= 3, 'attribution, visitor and session cookies are written: ' + writes.map((w) => w.name));
    assert.deepStrictEqual(allDomains(writes), ['example.com']);
    assert.ok(writes.every((w) => /; domain=example\.com; path=\/; SameSite=Lax/.test(w.raw)), 'domain sits before path');
    assert.strictEqual(jar.log.filter((w) => !w.expired && w.domain === '').length, 0, 'no host-only write at all');
    assert.strictEqual(window.localStorage.getItem('ct_cookie_scope'), 'example.com');
  },

  'every attribution removal carries the same domain as the writes'() {
    const { window, jar } = boot({ scripts: ['attribution'], cookieDomain: 'example.com', search: '?gclid=G1' });
    jar.log.length = 0;
    window.ClickTrail.clearData();
    const removals = jar.log.filter((w) => w.expired);
    assert.ok(removals.some((w) => w.name === 'attribution'));
    assert.ok(removals.some((w) => w.name === 'ct_attribution'), 'legacy-name expiry is emitted');
    assert.deepStrictEqual(allDomains(removals), ['example.com']);
    assert.ok(!jar.names().some((n) => n.startsWith('attribution@')), 'the domain cookie is actually gone');
  },

  'scope host emits no domain attribute byte-for-byte'() {
    const { window, jar } = boot({ scripts: ['attribution'], cookieDomain: '', search: '?gclid=G1' });
    assert.ok(jar.log.length > 0);
    assert.ok(jar.log.every((w) => !/domain=/i.test(w.raw)), 'no domain attribute');
    window.ClickTrail.clearData();
    assert.ok(jar.log.every((w) => !/domain=/i.test(w.raw)));
    assert.ok(jar.log.some((w) => /^attribution=.*; expires=.*; path=\/; SameSite=Lax; Secure$/.test(w.raw)), 'today\'s attribute order is unchanged');
  },

  'a cookieDomain that does not cover the hostname falls back to host-only'() {
    const { window, jar } = boot({ scripts: ['attribution'], hostname: 'preview.cdn.test', cookieDomain: 'example.com', search: '?gclid=G1' });
    assert.ok(jar.log.length > 0);
    assert.ok(jar.log.every((w) => w.domain === ''), 'host-only writes');
    assert.strictEqual(window.localStorage.getItem('ct_cookie_scope'), null, 'host-only writes record no domain marker');
  },

  'a lookalike suffix does not count as covering'() {
    const { jar } = boot({ scripts: ['attribution'], hostname: 'badexample.com', cookieDomain: 'example.com', search: '?gclid=G1' });
    assert.ok(jar.log.every((w) => w.domain === ''));
  },

  'events writes and removals carry the domain'() {
    const { window, jar } = boot({ scripts: ['bridge', 'events'], cookieDomain: 'example.com', search: '?s=term' });
    window.ClickTrailConsent.grant('manual');
    const writes = jar.log.filter((w) => !w.expired && ['ct_session_id', 'ct_visitor_id'].includes(w.name));
    assert.deepStrictEqual(writes.map((w) => w.name).sort(), ['ct_session_id', 'ct_visitor_id']);
    assert.deepStrictEqual(allDomains(writes), ['example.com']);
    jar.log.length = 0;
    window.ClickTrailConsent.deny('manual');
    const removals = jar.log.filter((w) => w.expired && ['ct_session_id', 'ct_visitor_id'].includes(w.name));
    assert.deepStrictEqual(removals.map((w) => w.name).sort(), ['ct_session_id', 'ct_visitor_id']);
    assert.deepStrictEqual(allDomains(removals), ['example.com']);
  },

  'events under scope host emits no domain'() {
    const { window, jar } = boot({ scripts: ['bridge', 'events'], cookieDomain: '', search: '?s=term' });
    window.ClickTrailConsent.grant('manual');
    window.ClickTrailConsent.deny('manual');
    assert.ok(jar.log.filter((w) => ['ct_session_id', 'ct_visitor_id'].includes(w.name)).length >= 4);
    assert.ok(jar.log.every((w) => !/domain=/i.test(w.raw)));
  },

  'consent banner writes ct_consent with the domain'() {
    const created = [];
    const jar = makeJar();
    const page = boot({
      scripts: ['bridge'], cookieDomain: 'example.com', jar,
    });
    page.document.createElement = () => {
      const el = { setAttribute() {}, appendChild() {}, style: {}, remove() {}, handlers: {}, addEventListener(type, fn) { el.handlers[type] = fn; } };
      created.push(el);
      return el;
    };
    vm.runInContext(SOURCES.consent, vm.createContext(page.window), { filename: 'consent.js' });
    const accept = created.find((el) => el.id === 'ct-accept-all');
    assert.ok(accept, 'banner rendered');
    jar.log.length = 0;
    accept.handlers.click();
    const consent = jar.log.filter((w) => w.name === 'ct_consent' && !w.expired);
    assert.strictEqual(consent.length, 1);
    assert.strictEqual(consent[0].domain, 'example.com');
    assert.ok(/; domain=example\.com; path=\/; SameSite=Lax/.test(consent[0].raw));
  },

  'bridge writes ct_consent_state with the domain and clears ct_consent with it on denial'() {
    const jar = makeJar({ 'ct_consent@example.com': encodeURIComponent('{"marketing":true}') });
    const { window } = boot({ scripts: ['bridge'], cookieDomain: 'example.com', jar });
    window.ClickTrailConsent.grant('manual');
    const state = jar.log.filter((w) => w.name === 'ct_consent_state' && !w.expired);
    assert.ok(state.length >= 1);
    assert.deepStrictEqual(allDomains(state), ['example.com']);
    window.ClickTrailConsent.deny('manual');
    const cleared = jar.log.filter((w) => w.name === 'ct_consent' && w.expired);
    assert.ok(cleared.length >= 1);
    assert.deepStrictEqual(allDomains(cleared), ['example.com']);
    assert.ok(!jar.names().includes('ct_consent@example.com'), 'domain-scoped ct_consent really expired');
  },

  'bridge under a non-covering domain stays host-only'() {
    const { window, jar } = boot({ scripts: ['bridge'], hostname: 'other.test', cookieDomain: 'example.com' });
    window.ClickTrailConsent.grant('manual');
    assert.ok(jar.log.length > 0);
    assert.ok(jar.log.every((w) => w.domain === ''));
  },

  'migration moves a host-only cookie to the domain once and stores the marker'() {
    const value = encodeURIComponent(JSON.stringify({ gclid: 'OLD', lt_source: 'google' }));
    const jar = makeJar({ attribution: value, ct_visitor_id: 'vis_1', ct_consent: encodeURIComponent('{"marketing":true}') });
    const { window } = boot({ scripts: ['attribution'], cookieDomain: 'example.com', jar });
    assert.deepStrictEqual(jar.names().filter((n) => /^(attribution|ct_visitor_id|ct_consent)@/.test(n)), ['attribution@example.com', 'ct_consent@example.com', 'ct_visitor_id@example.com']);
    assert.strictEqual(jar.entries.get('attribution@example.com'), value, 'value preserved');
    assert.ok(jar.log.some((w) => w.name === 'attribution' && w.expired && w.domain === ''), 'host-only copy expired without a domain');
    assert.strictEqual(window.localStorage.getItem('ct_cookie_scope'), 'example.com');
  },

  'a second boot with the marker performs no migration writes'() {
    const jar = makeJar({ 'attribution@example.com': 'v' });
    boot({ scripts: ['attribution'], cookieDomain: 'example.com', jar, local: { ct_cookie_scope: 'example.com' }, configs: { attribution: { requireConsent: true } } });
    assert.strictEqual(jar.log.length, 0);
  },

  'switching back to host expires the domain cookies and rewrites them host-only'() {
    const jar = makeJar({ 'attribution@example.com': 'AV', 'ct_session_id@example.com': 'SV', 'ct_consent_state@example.com': 'CV' });
    const { window } = boot({ scripts: ['attribution'], cookieDomain: '', jar, local: { ct_cookie_scope: 'example.com' }, configs: { attribution: { requireConsent: true } } });
    assert.deepStrictEqual(jar.names(), ['attribution@', 'ct_consent_state@', 'ct_session_id@']);
    assert.strictEqual(jar.entries.get('attribution@'), 'AV');
    assert.ok(jar.log.filter((w) => w.expired).every((w) => w.domain === 'example.com'), 'expiry used the previous domain');
    assert.strictEqual(window.localStorage.getItem('ct_cookie_scope'), '');
  },

  'switching between two domains expires the previous one'() {
    const jar = makeJar({ 'attribution@example.com': 'AV' });
    boot({ scripts: ['attribution'], hostname: 'www.shop.example.com', cookieDomain: 'shop.example.com', jar, local: { ct_cookie_scope: 'example.com' }, configs: { attribution: { requireConsent: true } } });
    assert.deepStrictEqual(jar.names(), ['attribution@shop.example.com']);
  },

  'migration keeps each cookie on its own retention and skips absent cookies'() {
    const jar = makeJar({ ct_session_id: 'S' });
    boot({ scripts: ['attribution'], cookieDomain: 'example.com', jar, configs: { attribution: { requireConsent: true } } });
    assert.deepStrictEqual(jar.names(), ['ct_session_id@example.com']);
    const written = jar.log.find((w) => w.name === 'ct_session_id' && !w.expired);
    const days = (Date.parse(/expires=([^;]+)/.exec(written.raw)[1]) - Date.now()) / 86400000;
    assert.ok(days > 0.9 && days < 1.1, 'one-day retention kept, got ' + days);
  },

  'host scope on a fresh origin writes nothing, not even the marker'() {
    const jar = makeJar({ attribution: 'V' });
    const { window } = boot({ scripts: ['attribution'], cookieDomain: '', jar, configs: { attribution: { requireConsent: true } } });
    assert.strictEqual(jar.log.length, 0, 'existing host-only cookies are left alone');
    assert.strictEqual(window.localStorage.getItem('ct_cookie_scope'), null);
  },

  'a domain-scoped write before any migration records the marker so a later switch can clean up'() {
    const jar = makeJar();
    const { window } = boot({ scripts: ['bridge'], cookieDomain: 'example.com', jar });
    window.ClickTrailConsent.grant('manual');
    assert.strictEqual(window.localStorage.getItem('ct_cookie_scope'), 'example.com');
  },

  'registrable with nothing stored and no consent writes no marker and no cookie'() {
    const jar = makeJar();
    const { window } = boot({ scripts: ['attribution'], cookieDomain: 'example.com', jar, configs: { attribution: { requireConsent: true } } });
    assert.strictEqual(jar.log.length, 0);
    assert.strictEqual(window.localStorage.getItem('ct_cookie_scope'), null);
  },
};

let failed = 0;
Object.entries(tests).forEach(([name, fn]) => {
  try {
    fn();
    console.log('ok   ' + name);
  } catch (error) {
    failed += 1;
    console.error('FAIL ' + name + '\n     ' + String(error && error.stack || error).split('\n').slice(0, 4).join('\n     '));
  }
});
if (failed) {
  console.error(failed + ' cookie-scope check(s) failed');
  process.exit(1);
}
console.log('cookie-scope: ' + Object.keys(tests).length + ' checks passed');
