// Fill-marker checks (specs/005-cache-safe-hidden-fields, #152).
//
// Runs the real attribution script in a vm against a tiny form DOM and checks that the
// Injector writes one hidden `ct_fill_source=js` into each form it filled, none into a
// form it did not, reuses it across runs, and removes it when consent is denied.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const SOURCE = fs.readFileSync(path.join(__dirname, '..', '..', 'assets/js/clicutcl-attribution.js'), 'utf8');
const URL_NOW = 'https://shop.example.test/contact/';
const STORED = { gclid: 'B1', lt_source: 'google', lt_medium: 'cpc' };

function input(name, value = '') {
  const attrs = {};
  const el = {
    tagName: 'INPUT', nodeType: 1, name, value, type: 'text', parentNode: null,
    getAttribute: (k) => (k in attrs ? attrs[k] : null),
    setAttribute: (k, v) => { attrs[k] = String(v); },
    hasAttribute: (k) => k in attrs,
    removeAttribute: (k) => { delete attrs[k]; },
    dispatchEvent() { return true; },
    closest(sel) { return sel === 'form' ? el.form || null : null; },
  };
  return el;
}

function form(inputs) {
  const f = { tagName: 'FORM', nodeType: 1, elements: [], appendChild(el) { el.form = f; el.parentNode = f; f.elements.push(el); return el; }, removeChild(el) { f.elements = f.elements.filter((e) => e !== el); el.parentNode = null; el.form = null; } };
  inputs.forEach((i) => f.appendChild(i));
  return f;
}

function boot(forms) {
  const jar = { attribution: encodeURIComponent(JSON.stringify(STORED)) };
  const listeners = {};
  const all = () => forms.reduce((acc, f) => acc.concat(f.elements), []);
  const document = {
    readyState: 'complete', referrer: '', title: 'Contact', visibilityState: 'visible',
    body: { appendChild() {} }, head: { appendChild() {} }, documentElement: {},
    addEventListener(type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
    removeEventListener() {},
    dispatchEvent(event) { (listeners[event.type] || []).forEach((fn) => fn(event)); return true; },
    querySelectorAll(selector) {
      const names = [...String(selector).matchAll(/input\[name="([^"]+)"\]/g)].map((m) => m[1]);
      return all().filter((el) => names.includes(el.name));
    },
    querySelector: () => null,
    getElementsByTagName: () => [],
    createElement: (tag) => (tag === 'input' ? input('') : { setAttribute() {}, appendChild() {}, style: {} }),
  };
  Object.defineProperty(document, 'cookie', {
    get: () => Object.keys(jar).map((n) => n + '=' + jar[n]).join('; '),
    set: (raw) => {
      const [pair] = String(raw).split(';');
      const i = pair.indexOf('=');
      if (i === -1) return;
      const name = pair.slice(0, i).trim();
      const value = pair.slice(i + 1);
      if (value === '' || /max-age=0|expires=thu, 01 jan 1970/i.test(String(raw))) delete jar[name];
      else jar[name] = value;
    },
  });
  const storage = () => {
    const v = Object.create(null);
    return { getItem: (k) => (k in v ? v[k] : null), setItem: (k, x) => { v[k] = String(x); }, removeItem: (k) => { delete v[k]; }, key: (i) => Object.keys(v)[i] || null, get length() { return Object.keys(v).length; } };
  };
  const loc = new URL(URL_NOW);
  const window = {
    document, localStorage: storage(), sessionStorage: storage(),
    location: { href: loc.href, search: loc.search, protocol: loc.protocol, hostname: loc.hostname, origin: loc.origin, pathname: loc.pathname },
    navigator: { userAgent: 'Mozilla/5.0 Chrome/140', webdriver: false, language: 'en', sendBeacon: () => true },
    screen: { width: 1280, height: 800 }, innerWidth: 1280, innerHeight: 800,
    crypto: require('crypto').webcrypto, dataLayer: [], console: { log() {}, warn() {}, error() {} },
    setTimeout: () => 0, clearTimeout() {}, setInterval: () => 0, clearInterval() {},
    addEventListener() {}, removeEventListener() {}, dispatchEvent() { return true; },
    fetch: () => Promise.resolve({ ok: true, json: () => ({}) }),
    CSS: { escape: (v) => String(v) },
    CustomEvent: class CustomEvent { constructor(type, o) { this.type = type; this.detail = o && o.detail; } },
    URL, URLSearchParams, Event: class Event { constructor(type) { this.type = type; } }, TextEncoder, btoa, atob,
    clicutcl_config: { cookieName: 'attribution', cookieDays: 90, requireConsent: false, injectEnabled: true, injectOverwrite: true },
  };
  window.window = window;
  window.self = window;
  vm.runInContext(SOURCE, vm.createContext(window), { filename: 'clicutcl-attribution.js' });
  return { window, document };
}

const markers = (f) => f.elements.filter((e) => e.name === 'ct_fill_source');

const tests = {
  'a filled form gets exactly one ct_fill_source=js; an unfilled form gets none'() {
    const filled = form([input('your-name'), input('ct_gclid'), input('ct_lt_source')]);
    const other = form([input('your-email')]);
    boot([filled, other]);

    assert.strictEqual(filled.elements.find((e) => e.name === 'ct_gclid').value, 'B1');
    const found = markers(filled);
    assert.strictEqual(found.length, 1, 'one marker in the filled form');
    assert.strictEqual(found[0].value, 'js');
    assert.strictEqual(found[0].type, 'hidden');
    assert.strictEqual(markers(other).length, 0, 'no marker in a form the Injector did not fill');
  },

  'a second run reuses the marker instead of adding another'() {
    const filled = form([input('ct_gclid')]);
    const { document } = boot([filled]);
    document.dispatchEvent({ type: 'wpcf7init' });
    document.dispatchEvent({ type: 'wpcf7init' });
    assert.strictEqual(markers(filled).length, 1);
  },

  'the marker is never itself filled from attribution data'() {
    const filled = form([input('ct_gclid')]);
    boot([filled]);
    assert.strictEqual(markers(filled)[0].value, 'js');
  },

  'consent denial removes the marker along with the filled values'() {
    const filled = form([input('ct_gclid')]);
    const { document, window } = boot([filled]);
    assert.strictEqual(markers(filled).length, 1);

    window.ClickTrailConsent = { isResolved: () => true, isGranted: () => false, getState: () => ({ marketing: false, analytics: false }) };
    document.dispatchEvent({ type: 'ct:consentResolved', detail: { granted: false } });

    assert.strictEqual(markers(filled).length, 0, 'marker removed');
    assert.strictEqual(filled.elements.find((e) => e.name === 'ct_gclid').value, '', 'value cleared');
  },
};

let failed = 0;
Object.keys(tests).forEach((name) => {
  try { tests[name](); console.log('ok   ' + name); } catch (error) { failed += 1; console.error('FAIL ' + name + '\n  ' + error.message); }
});
if (failed) process.exit(1);
