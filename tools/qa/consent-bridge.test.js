const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const bridgeSource = fs.readFileSync(
  path.join(__dirname, '..', '..', 'assets/js/clicutcl-consent-bridge.js'),
  'utf8'
);

class EventTarget {
  constructor() {
    this.listeners = Object.create(null);
  }

  addEventListener(type, listener, options) {
    if (typeof listener !== 'function') return;
    (this.listeners[type] || (this.listeners[type] = [])).push({ listener, once: !!(options && options.once) });
  }

  dispatchEvent(event) {
    const listeners = (this.listeners[event.type] || []).slice();
    listeners.forEach((entry) => {
      entry.listener.call(this, event);
      if (entry.once) {
        this.listeners[event.type] = (this.listeners[event.type] || []).filter((item) => item !== entry);
      }
    });
    return true;
  }
}

class CookieDocument extends EventTarget {
  constructor() {
    super();
    this.jar = Object.create(null);
    this.readyState = 'complete';
    this.referrer = '';
    this.body = { appendChild() {} };
  }

  get cookie() {
    return Object.keys(this.jar).map((name) => `${name}=${this.jar[name]}`).join('; ');
  }

  set cookie(value) {
    const parts = String(value).split(';');
    const separator = parts[0].indexOf('=');
    if (separator === -1) return;
    const name = parts[0].slice(0, separator).trim();
    const cookieValue = parts[0].slice(separator + 1);
    const expired = parts.some((part) => /max-age=0/i.test(part) || /expires=thu, 01 jan 1970/i.test(part));
    if (expired || cookieValue === '') delete this.jar[name];
    else this.jar[name] = cookieValue;
  }
}

class UnavailableStorage {
  getItem() { throw new Error('storage blocked'); }
  setItem() { throw new Error('storage blocked'); }
  removeItem() { throw new Error('storage blocked'); }
}

class Storage {
  constructor() {
    this.values = Object.create(null);
  }

  getItem(key) {
    return Object.prototype.hasOwnProperty.call(this.values, key) ? this.values[key] : null;
  }

  setItem(key, value) {
    this.values[key] = String(value);
  }

  removeItem(key) {
    delete this.values[key];
  }
}

function makeContext(storage, cookieJar, config = {}) {
  const document = new CookieDocument();
  document.jar = cookieJar;
  const window = new EventTarget();
  window.ctConsentBridgeConfig = Object.assign({
    enabled: true,
    cookieName: 'ct_consent',
    serverCookieName: 'ct_consent_state',
    cmpSource: 'custom',
    timeout: 3000,
    fallbackGranted: false
  }, config);
  window.localStorage = storage;
  window.location = { protocol: 'https:', hostname: 'example.test', href: 'https://example.test/' };
  window.console = { log() {} };
  window.dataLayer = [];
  window.setTimeout = () => 1;
  window.clearTimeout = () => {};
  const CustomEvent = class CustomEvent {
    constructor(type, options) {
      this.type = type;
      this.detail = options && options.detail;
      this.bubbles = options && options.bubbles;
    }
  };
  const context = { window, document, CustomEvent, console: window.console };
  vm.runInNewContext(bridgeSource, context, { filename: 'clicutcl-consent-bridge.js' });
  return { window, document };
}

function pluginConsent(value) {
  return encodeURIComponent(JSON.stringify(value));
}

function testGrantWithdrawalRetryAndReload() {
  const storage = new Storage();
  const cookies = Object.create(null);
  cookies.ct_consent = pluginConsent({ marketing: true, analytics: true });
  const tab = makeContext(storage, cookies);
  const resolved = [];
  tab.document.addEventListener('ct:consentResolved', (event) => resolved.push(event.detail));

  tab.window.ClickTrailConsent.grant('cmp');
  assert.strictEqual(tab.window.ClickTrailConsent.isGranted(), true);
  tab.window.ClickTrailConsent.deny('cmp-withdrawal');
  assert.strictEqual(tab.window.ClickTrailConsent.isGranted(), false);
  assert.strictEqual(resolved[resolved.length - 1].granted, false);

  // A retry must be initiated only while the authoritative decision grants marketing.
  let retries = 0;
  const initiateRetry = () => {
    if (!tab.window.ClickTrailConsent.isGranted()) return false;
    retries += 1;
    return true;
  };
  assert.strictEqual(initiateRetry(), false, 'withdrawal must block retry initiation');
  assert.strictEqual(retries, 0);

  // Simulate an old tab/plugin rewriting its stale grant cookie after withdrawal.
  cookies.ct_consent = pluginConsent({ marketing: true, analytics: true });
  const reloaded = makeContext(storage, cookies);
  assert.strictEqual(reloaded.window.ClickTrailConsent.isGranted(), false, 'stale plugin cookie must not resurrect consent');
  assert.strictEqual(reloaded.window.ClickTrailConsent.getState().source, 'cmp-withdrawal');
}

function testServerCookieFallback() {
  const cookies = Object.create(null);
  cookies.ct_consent = pluginConsent({ marketing: false, analytics: false });
  const first = makeContext(new UnavailableStorage(), cookies);
  first.window.ClickTrailConsent.grant('cmp');
  const grantCookie = JSON.parse(decodeURIComponent(cookies.ct_consent_state));
  assert.ok(grantCookie.updatedAt > 0, 'server mirror must retain the authoritative grant timestamp');

  first.window.ClickTrailConsent.deny('cmp-withdrawal');
  const withdrawalCookie = JSON.parse(decodeURIComponent(cookies.ct_consent_state));
  assert.ok(withdrawalCookie.updatedAt >= grantCookie.updatedAt, 'server mirror must retain the later withdrawal timestamp');

  // A stale plugin cookie and delayed plugin-banner callback must not revive the CMP withdrawal.
  cookies.ct_consent = pluginConsent({ marketing: true, analytics: true });
  const reloaded = makeContext(new UnavailableStorage(), cookies);
  assert.strictEqual(reloaded.window.ClickTrailConsent.isGranted(), false, 'server denial must beat a stale plugin cookie when storage is blocked');
  assert.strictEqual(cookies.ct_consent, undefined, 'server denial must clear the stale plugin cookie');
  reloaded.window.ClickTrailConsent.update({ marketing: true, analytics: true }, 'plugin-banner');
  assert.strictEqual(reloaded.window.ClickTrailConsent.isGranted(), false, 'stale plugin-banner state must not overwrite a CMP withdrawal');
}

function testCrossTabStorageEvent() {
  const storage = new Storage();
  const cookies = Object.create(null);
  const first = makeContext(storage, cookies);
  const second = makeContext(storage, cookies);
  const updates = [];
  second.document.addEventListener('ct:consentResolved', (event) => updates.push(event.detail));

  first.window.ClickTrailConsent.grant('cmp');
  let firstEnvelope = storage.getItem('ct_consent_v1');
  second.window.dispatchEvent({ key: 'ct_consent_v1', newValue: firstEnvelope, type: 'storage' });
  assert.strictEqual(second.window.ClickTrailConsent.isGranted(), true);

  first.window.ClickTrailConsent.deny('cmp-withdrawal');
  const withdrawalEnvelope = storage.getItem('ct_consent_v1');
  second.window.dispatchEvent({ key: 'ct_consent_v1', newValue: withdrawalEnvelope, type: 'storage' });
  assert.strictEqual(second.window.ClickTrailConsent.isGranted(), false, 'withdrawal must propagate through storage events');
  assert.strictEqual(updates[updates.length - 1].granted, false);
}

testGrantWithdrawalRetryAndReload();
testServerCookieFallback();
testCrossTabStorageEvent();
console.log('Consent bridge browser-boundary tests passed.');
