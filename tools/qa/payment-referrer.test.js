// Payment-return referrer checks (issue #129).
//
// Runs the real attribution script in a vm on a WooCommerce thank-you URL and
// checks that a buyer returning from a payment host keeps the stored touch.
// The default host list is read from the PHP source that localizes it.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.join(__dirname, '..', '..');
const SOURCE = fs.readFileSync(path.join(ROOT, 'assets/js/clicutcl-attribution.js'), 'utf8');
const THANK_YOU_URL = 'https://shop.example.test/checkout/order-received/123/?key=wc_order_abc';

function defaultIgnoredHosts() {
  const php = fs.readFileSync(path.join(ROOT, 'includes/Core/class-attribution-provider.php'), 'utf8');
  const match = php.match(/const IGNORED_REFERRER_HOSTS = array\(([\s\S]*?)\);/);
  assert.ok(match, 'Attribution_Provider::IGNORED_REFERRER_HOSTS must exist');
  return [...match[1].matchAll(/'([^']+)'/g)].map((m) => m[1]);
}

const DEFAULT_HOSTS = defaultIgnoredHosts();

// Stored attribution from a Google Ads click before checkout.
const PAID_TOUCH = {
  ft_source: 'google',
  ft_medium: 'cpc',
  ft_campaign: 'spring',
  ft_channel: 'Google Ads',
  ft_landing_page: 'https://shop.example.test/landing/?gclid=G1',
  ft_touch_timestamp: '2026-10-20T10:00:00.000Z',
  lt_source: 'google',
  lt_medium: 'cpc',
  lt_campaign: 'spring',
  lt_channel: 'Google Ads',
  lt_referrer: 'https://www.google.com/',
  lt_landing_page: 'https://shop.example.test/landing/?gclid=G1',
  lt_touch_timestamp: '2026-10-20T10:00:00.000Z',
  ft_gclid: 'G1',
  lt_gclid: 'G1',
  gclid: 'G1',
};
const TOUCH_KEYS = ['source', 'medium', 'campaign', 'channel', 'referrer', 'landing_page', 'touch_timestamp'];

function boot({ referrer, url = THANK_YOU_URL, stored = null, hosts = DEFAULT_HOSTS }) {
  const jar = Object.create(null);
  if (stored) jar.attribution = encodeURIComponent(JSON.stringify(stored));

  const document = {
    readyState: 'complete',
    referrer,
    title: 'Order received',
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
      ignoredReferrerHosts: hosts,
    },
  };
  window.window = window;
  window.self = window;

  vm.runInContext(SOURCE, vm.createContext(window), { filename: 'clicutcl-attribution.js' });
  return window.ClickTrail ? window.ClickTrail.getData() : {};
}

function assertTouchUnchanged(data, prefix) {
  TOUCH_KEYS.forEach((key) => {
    const field = prefix + '_' + key;
    if (field in PAID_TOUCH) assert.strictEqual(data[field], PAID_TOUCH[field], field + ' changed');
  });
}

const tests = {
  'default host list names the hosted payment pages'() {
    ['paypal.com', 'stripe.com', 'mollie.com', 'klarna.com', 'squareup.com', 'adyen.com', 'checkout.com'].forEach((host) => {
      assert.ok(DEFAULT_HOSTS.includes(host), host + ' missing from the default list');
    });
  },

  'PayPal return keeps the stored Google Ads last-touch'() {
    const data = boot({ referrer: 'https://www.paypal.com/checkoutnow?token=EC-1', stored: PAID_TOUCH });
    assertTouchUnchanged(data, 'lt');
    assertTouchUnchanged(data, 'ft');
    assert.strictEqual(data.gclid, 'G1');
    assert.strictEqual(data.lt_gclid, 'G1');
  },

  'subdomains of default payment hosts are ignored'() {
    [
      'https://checkout.stripe.com/c/pay/cs_live_1',
      'https://www.mollie.com/checkout/select-method/abc',
      'https://pay.klarna.com/eu/hpp/payments/1',
      'https://checkoutshopper-live.adyen.com/checkoutshopper/threeDS2.shtml',
    ].forEach((referrer) => {
      const data = boot({ referrer, stored: PAID_TOUCH });
      assertTouchUnchanged(data, 'lt');
    });
  },

  'a host added through the filter is ignored the same way'() {
    const referrer = 'https://acs.issuer-bank.example/3ds/challenge';
    const control = boot({ referrer, stored: PAID_TOUCH });
    assert.strictEqual(control.lt_source, 'acs.issuer-bank.example', 'without the filter the host is a referral');

    const data = boot({ referrer, stored: PAID_TOUCH, hosts: DEFAULT_HOSTS.concat(['issuer-bank.example']) });
    assertTouchUnchanged(data, 'lt');
    assertTouchUnchanged(data, 'ft');
  },

  'a real partner referrer still updates last-touch only'() {
    const data = boot({ referrer: 'https://partner.example.org/review', stored: PAID_TOUCH });
    assert.strictEqual(data.lt_source, 'partner.example.org');
    assert.strictEqual(data.lt_medium, 'referral');
    assert.strictEqual(data.lt_referrer, 'https://partner.example.org/review');
    assert.strictEqual(data.lt_landing_page, THANK_YOU_URL);
    assertTouchUnchanged(data, 'ft');
  },

  'a payment referrer never creates a first-touch'() {
    const data = boot({ referrer: 'https://www.paypal.com/checkoutnow?token=EC-1' });
    assert.deepStrictEqual(
      Object.keys(data).filter((key) => /^(ft|lt)_/.test(key)),
      [],
      'no touch fields from a payment referrer'
    );
  },

  'URL parameters on the return still form a touch'() {
    const data = boot({
      referrer: 'https://www.paypal.com/checkoutnow?token=EC-1',
      url: THANK_YOU_URL + '&utm_source=newsletter&utm_medium=email',
      stored: PAID_TOUCH,
    });
    assert.strictEqual(data.lt_source, 'newsletter');
    assert.strictEqual(data.lt_medium, 'email');
    assertTouchUnchanged(data, 'ft');
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
  console.error(failed + ' payment referrer check(s) failed.');
  process.exit(1);
}
console.log('Payment referrer checks passed.');
