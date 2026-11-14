// Last-touch replacement checks (issue #230).
//
// Runs the real attribution script in a vm against a stored Google Ads touch and
// checks that a new touch replaces the whole last touch (no field or click ID left
// from the earlier touch) while the first touch and top-level click IDs are kept.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.join(__dirname, '..', '..');
const SOURCE = fs.readFileSync(path.join(ROOT, 'assets/js/clicutcl-attribution.js'), 'utf8');
const SITE = 'https://shop.example.test/landing/';

// Stored attribution from a Google Ads click: every lt_ field the script can write.
const PAID_TOUCH = {
  ft_source: 'google',
  ft_medium: 'cpc',
  ft_campaign: 'spring',
  ft_channel: 'Google Ads',
  ft_landing_page: SITE + '?gclid=G1',
  ft_touch_timestamp: '2026-10-20T10:00:00.000Z',
  ft_gclid: 'G1',
  lt_source: 'google',
  lt_medium: 'cpc',
  lt_campaign: 'spring',
  lt_term: 'shoes',
  lt_content: 'ad-a',
  lt_utm_id: 'cmp-1',
  lt_utm_source_platform: 'Search Ads 360',
  lt_utm_creative_format: 'text',
  lt_utm_marketing_tactic: 'prospecting',
  lt_channel: 'Google Ads',
  lt_referrer: 'https://www.google.com/',
  lt_landing_page: SITE + '?gclid=G1',
  lt_touch_timestamp: '2026-10-20T10:00:00.000Z',
  lt_gclid: 'G1',
  lt_wbraid: 'W1',
  gclid: 'G1',
  wbraid: 'W1',
};

const newStorage = () => {
  const values = Object.create(null);
  return {
    getItem: (key) => (key in values ? values[key] : null),
    setItem: (key, value) => { values[key] = String(value); },
    removeItem: (key) => { delete values[key]; },
    key: (index) => Object.keys(values)[index] || null,
    get length() { return Object.keys(values).length; },
  };
};

// `jar` is shared between boots of different hosts; `localStorage` is per origin.
function boot({
  referrer = '',
  search = '',
  stored = PAID_TOUCH,
  requireConsent = false,
  site = SITE,
  cookieDomain = '',
  jar = new Map(),
  localStorage = newStorage(),
  extraCookies = {},
}) {
  const host = new URL(site).hostname;
  // Cookie jar entries are keyed `name|domain`; a cookie without `domain=` is host-only (`host:<host>`).
  if (stored && !jar.size) jar.set('attribution|' + (cookieDomain || 'host:' + host), encodeURIComponent(JSON.stringify(stored)));
  Object.entries(extraCookies).forEach(([name, value]) => jar.set(name + '|' + (cookieDomain || 'host:' + host), value));
  const visible = () => [...jar.entries()].filter(([key]) => {
    const scope = key.split('|')[1];
    return scope.startsWith('host:') ? scope === 'host:' + host : host === scope || host.endsWith('.' + scope);
  });

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
    get: () => visible().map(([key, value]) => key.split('|')[0] + '=' + value).join('; '),
    set: (raw) => {
      const [pair, ...attrs] = String(raw).split(';');
      const split = pair.indexOf('=');
      if (split === -1) return;
      const name = pair.slice(0, split).trim();
      const value = pair.slice(split + 1);
      const domain = attrs.join(';').match(/domain=([^;]+)/i);
      const key = name + '|' + (domain ? domain[1].trim().toLowerCase() : 'host:' + host);
      if (value === '' || /max-age=0|expires=thu, 01 jan 1970/i.test(attrs.join(';'))) jar.delete(key);
      else jar.set(key, value);
    },
  });

  const location = new URL(site + search);
  const window = {
    document,
    localStorage,
    sessionStorage: newStorage(),
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
      requireConsent,
      ignoredReferrerHosts: ['paypal.com'],
      cookieDomain,
    },
  };
  window.window = window;
  window.self = window;

  vm.runInContext(SOURCE, vm.createContext(window), { filename: 'clicutcl-attribution.js' });
  return window.ClickTrail ? window.ClickTrail.getData() : {};
}

const ltKeys = (data) => Object.keys(data).filter((key) => key.startsWith('lt_')).sort();
const pick = (data, prefix) => Object.fromEntries(Object.entries(data).filter(([key]) => key.startsWith(prefix)));

function assertFirstTouchAndTopLevelKept(data) {
  assert.deepStrictEqual(pick(data, 'ft_'), pick(PAID_TOUCH, 'ft_'), 'first touch changed');
  assert.strictEqual(data.gclid, 'G1', 'top-level gclid lost');
  assert.strictEqual(data.wbraid, 'W1', 'top-level wbraid lost');
}

const tests = {
  'Google organic visit replaces the whole last touch'() {
    const data = boot({ referrer: 'https://www.google.com/' });
    assert.strictEqual(data.lt_source, 'google');
    assert.strictEqual(data.lt_medium, 'organic');
    assert.strictEqual(data.lt_referrer, 'https://www.google.com/');
    assert.deepStrictEqual(
      ltKeys(data),
      ['lt_channel', 'lt_landing_page', 'lt_medium', 'lt_referrer', 'lt_source', 'lt_touch_timestamp'],
      'lt_ fields left over from the paid touch'
    );
    assertFirstTouchAndTopLevelKept(data);
  },

  'UTM-only visit replaces the whole last touch'() {
    const data = boot({ search: '?utm_source=newsletter&utm_medium=email' });
    assert.strictEqual(data.lt_source, 'newsletter');
    assert.strictEqual(data.lt_medium, 'email');
    assert.deepStrictEqual(
      ltKeys(data),
      ['lt_channel', 'lt_landing_page', 'lt_medium', 'lt_source', 'lt_touch_timestamp'],
      'lt_ fields or click IDs left over from the paid touch'
    );
    assertFirstTouchAndTopLevelKept(data);
  },

  'Meta click ID visit replaces the whole last touch and keeps the top-level Google ID'() {
    const data = boot({ search: '?fbclid=F1' });
    assert.strictEqual(data.lt_fbclid, 'F1');
    assert.deepStrictEqual(
      ltKeys(data),
      ['lt_channel', 'lt_fbclid', 'lt_landing_page', 'lt_touch_timestamp'],
      'lt_ fields or click IDs left over from the paid touch'
    );
    assert.strictEqual(data.fbclid, 'F1', 'top-level fbclid not updated');
    assertFirstTouchAndTopLevelKept(data);
  },

  'a touch carrying every field overwrites each one'() {
    const data = boot({
      search: '?utm_source=a&utm_medium=b&utm_campaign=c&utm_term=d&utm_content=e&utm_id=f&utm_source_platform=g&utm_creative_format=h&utm_marketing_tactic=i&gclid=G2',
    });
    assert.strictEqual(data.lt_source, 'a');
    assert.strictEqual(data.lt_campaign, 'c');
    assert.strictEqual(data.lt_term, 'd');
    assert.strictEqual(data.lt_utm_marketing_tactic, 'i');
    assert.strictEqual(data.lt_gclid, 'G2');
    assert.ok(!('lt_wbraid' in data), 'lt_wbraid left over');
    assert.ok(!('lt_referrer' in data), 'lt_referrer left over');
    assert.strictEqual(data.gclid, 'G2', 'top-level gclid is newest');
    assert.strictEqual(data.wbraid, 'W1', 'top-level wbraid kept');
  },

  'first touch is written once on a fresh visitor and not rewritten later'() {
    const first = boot({ stored: null, search: '?utm_source=newsletter&utm_medium=email&utm_campaign=n1' });
    assert.strictEqual(first.ft_campaign, 'n1');
    assert.strictEqual(first.lt_campaign, 'n1');
    const second = boot({ stored: first, search: '?utm_source=partner&utm_medium=referral' });
    assert.strictEqual(second.ft_campaign, 'n1');
    assert.strictEqual(second.ft_source, 'newsletter');
    assert.strictEqual(second.lt_source, 'partner');
    assert.ok(!('lt_campaign' in second), 'lt_campaign left over');
  },

  'a direct visit changes nothing'() {
    const data = boot({});
    assert.deepStrictEqual(pick(data, 'lt_'), pick(PAID_TOUCH, 'lt_'));
    assertFirstTouchAndTopLevelKept(data);
  },

  'a payment referrer changes nothing'() {
    const data = boot({ referrer: 'https://www.paypal.com/checkoutnow?token=EC-1' });
    assert.deepStrictEqual(pick(data, 'lt_'), pick(PAID_TOUCH, 'lt_'));
    assertFirstTouchAndTopLevelKept(data);
  },

  'a same-site referrer changes nothing'() {
    const data = boot({ referrer: SITE + 'pricing/' });
    assert.deepStrictEqual(pick(data, 'lt_'), pick(PAID_TOUCH, 'lt_'));
  },

  'a sibling subdomain does not bring the old last touch back from its own localStorage (cookieDomain)'() {
    const jar = new Map();
    jar.set('attribution|example.test', encodeURIComponent(JSON.stringify(PAID_TOUCH)));
    const www = 'https://www.example.test/';
    const shop = 'https://shop.example.test/';
    const shared = () => JSON.parse(decodeURIComponent(jar.get('attribution|example.test')));
    const wwwStorage = newStorage();
    const shared_opts = { cookieDomain: 'example.test', jar, stored: null };
    // www. reads the paid touch, which mirrors it into www.'s localStorage.
    boot({ ...shared_opts, site: www, localStorage: wwwStorage });
    // shop. gets a new touch and rewrites the shared cookie.
    boot({ ...shared_opts, site: shop, search: '?utm_source=newsletter&utm_medium=email' });
    assert.deepStrictEqual(
      ltKeys(shared()),
      ['lt_channel', 'lt_landing_page', 'lt_medium', 'lt_source', 'lt_touch_timestamp'],
      'shared cookie kept the old last touch'
    );
    // www. direct visit: must read the new touch, not its stale mirror.
    const direct = boot({ ...shared_opts, site: www, localStorage: wwwStorage });
    assert.deepStrictEqual(ltKeys(direct), ltKeys(shared()), 'www. read the stale mirror');
    assert.ok(!('lt_campaign' in direct) && !('lt_gclid' in direct), 'stale lt_campaign or lt_gclid came back');
    // www. save (a new _fbp forces one) must not write the stale keys back into the shared cookie.
    boot({ ...shared_opts, site: www, localStorage: wwwStorage, extraCookies: { _fbp: 'fb.1.1700000000000.123' } });
    assert.ok(!('lt_campaign' in shared()) && !('lt_gclid' in shared()), 'stale keys written back to the shared cookie');
    assert.strictEqual(shared().lt_source, 'newsletter');
  },

  'denied consent still clears stored attribution'() {
    const data = boot({ requireConsent: true, search: '?fbclid=F1' });
    assert.deepStrictEqual(data, {}, 'attribution stored without consent');
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
  console.error(failed + ' last-touch replacement check(s) failed.');
  process.exit(1);
}
console.log('Last-touch replacement checks passed.');
