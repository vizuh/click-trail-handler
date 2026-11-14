// Sensitive query redaction checks (issue #232).
//
// Runs the real attribution script in a vm and checks that landing page and
// referrer URLs are stored without email, token, phone and similar parameters,
// while UTMs and click IDs stay. Every cookie, storage and dataLayer write is
// recorded, so a secret that leaks anywhere fails the test.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.join(__dirname, '..', '..');
const SOURCE = fs.readFileSync(path.join(ROOT, 'assets/js/clicutcl-attribution.js'), 'utf8');
const EVENTS_SOURCE = fs.readFileSync(path.join(ROOT, 'assets/js/clicutcl-events.js'), 'utf8');
const BASE = 'https://shop.example.test';
const ISSUE_URL = BASE + '/pricing/?utm_source=mail&utm_medium=email&email=jane%40example.com&token=SECRET123&phone=5551234';
const ISSUE_URL_REDACTED = BASE + '/pricing/?utm_source=mail&utm_medium=email';
const SECRETS = ['jane', 'SECRET123', '5551234'];
const EVENTS_CONFIG = { enabled: true, transportEnabled: true, eventsBatchUrl: BASE + '/wp-json/clicutcl/v2/events/batch', eventsToken: 'tok', iframeOrigins: [] };

function phpSensitiveKeys() {
  const php = fs.readFileSync(path.join(ROOT, 'includes/Core/class-attribution-provider.php'), 'utf8');
  const match = php.match(/const SENSITIVE_QUERY_KEYS = array\(([\s\S]*?)\);/);
  assert.ok(match, 'Attribution_Provider::SENSITIVE_QUERY_KEYS must exist');
  return [...match[1].matchAll(/'([^']+)'/g)].map((m) => m[1]);
}

// `events` (a clicutclEventsConfig object) also loads the real events script into the same page.
// `attribution: false` loads the events script alone, as on a site with attribution switched off.
function boot({ url, referrer = '', stored = null, config = {}, verifyData = null, events = null, attribution = true }) {
  const jar = Object.create(null);
  const writes = [];
  const sent = [];
  const listeners = { document: Object.create(null), window: Object.create(null) };
  const bus = (name) => ({
    addEventListener(type, fn) { if (typeof fn === 'function') (listeners[name][type] = listeners[name][type] || []).push(fn); },
    removeEventListener() {},
    dispatchEvent(event) { (listeners[name][event.type] || []).slice().forEach((fn) => fn(event)); return true; },
  });
  if (stored) jar.attribution = encodeURIComponent(JSON.stringify(stored));

  const document = {
    readyState: 'complete',
    referrer,
    title: 'Page',
    visibilityState: 'visible',
    body: { appendChild() {} },
    head: { appendChild() {} },
    documentElement: {},
    ...bus('document'),
    querySelectorAll: () => [],
    querySelector: () => null,
    getElementsByTagName: () => [],
    createElement: () => ({ setAttribute() {}, appendChild() {}, style: {} }),
  };
  Object.defineProperty(document, 'cookie', {
    get: () => Object.keys(jar).map((name) => name + '=' + jar[name]).join('; '),
    set: (raw) => {
      writes.push(String(raw));
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
      setItem: (key, value) => { values[key] = String(value); writes.push(key + '=' + String(value)); },
      removeItem: (key) => { delete values[key]; },
      key: (index) => Object.keys(values)[index] || null,
      get length() { return Object.keys(values).length; },
    };
  };

  const location = new URL(url);
  const dataLayer = [];
  const window = {
    document,
    localStorage: storage(),
    sessionStorage: storage(),
    location: {
      href: location.href,
      search: location.search,
      hash: location.hash,
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
    dataLayer,
    console: { log() {}, warn() {}, error() {} },
    setTimeout: () => 0,
    clearTimeout() {},
    setInterval: () => 0,
    clearInterval() {},
    ...bus('window'),
    fetch: (target, init) => {
      sent.push(String(target) + ' ' + String((init && init.body) || ''));
      return Promise.resolve({ ok: true, json: () => (verifyData ? { data: verifyData } : {}) });
    },
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
    clicutcl_config: Object.assign({
      cookieName: 'attribution',
      cookieDays: 90,
      requireConsent: false,
      ignoredReferrerHosts: [],
      sensitiveQueryKeys: phpSensitiveKeys(),
    }, config),
  };
  window.window = window;
  window.self = window;

  const context = vm.createContext(window);
  if (events) {
    window.clicutclEventsConfig = events;
    window.ClickTrailConsent = { isResolved: () => true, isGranted: () => true, getState: () => ({ marketing: true, analytics: true }) };
  }
  if (attribution) vm.runInContext(SOURCE, context, { filename: 'clicutcl-attribution.js' });
  if (events) vm.runInContext(EVENTS_SOURCE, context, { filename: 'clicutcl-events.js' });
  const data = window.ClickTrail ? window.ClickTrail.getData() : {};
  return { data, writes, dataLayer, window, sent, document };
}

function assertNoSecrets(result, secrets = SECRETS) {
  const haystack = [
    JSON.stringify(result.data),
    result.writes.map((w) => { try { return decodeURIComponent(w); } catch (e) { return w; } }).join('\n'),
    JSON.stringify(result.dataLayer),
  ].join('\n');
  secrets.forEach((secret) => assert.ok(!haystack.includes(secret), secret + ' was written'));
}

const tests = {
  'issue scenario D stores the landing page without email, token and phone'() {
    const result = boot({ url: ISSUE_URL });
    assert.strictEqual(result.data.ft_landing_page, ISSUE_URL_REDACTED);
    assert.strictEqual(result.data.lt_landing_page, ISSUE_URL_REDACTED);
    assert.strictEqual(result.data.lt_source, 'mail');
    assert.strictEqual(result.data.lt_medium, 'email');
    assertNoSecrets(result);
  },

  'suffix match removes user_email and keeps similar names'() {
    const result = boot({
      url: BASE + '/p/?utm_campaign=c1&user_email=a%40b.co&monkey=1&keyword=x&tokens=2&my_key=K&gclid=G1',
    });
    assert.strictEqual(result.data.lt_landing_page, BASE + '/p/?utm_campaign=c1&monkey=1&keyword=x&tokens=2&gclid=G1');
    assertNoSecrets(result, ['a%40b.co', 'a@b.co', 'my_key']);
  },

  'key is removed only as an exact name or an _key suffix'() {
    assert.strictEqual(boot({ url: BASE + '/p/?utm_source=a&key=wc_order_1' }).data.lt_landing_page, BASE + '/p/?utm_source=a');
    assert.strictEqual(boot({ url: BASE + '/p/?utm_source=a&monkey=1' }).data.lt_landing_page, BASE + '/p/?utm_source=a&monkey=1');
    assert.strictEqual(boot({ url: BASE + '/p/?utm_source=a&api_key=K' }).data.lt_landing_page, BASE + '/p/?utm_source=a');
  },

  'matching is case-insensitive and sees encoded keys'() {
    const result = boot({ url: BASE + '/p/?utm_source=a&EMAIL=x&User_Token=y&%74oken=z' });
    assert.strictEqual(result.data.lt_landing_page, BASE + '/p/?utm_source=a');
  },

  'UTMs and click IDs are kept in original order and encoding'() {
    const url = BASE + '/p/?gclid=G%201&utm_term=a%20b&email=x&fbclid=F1&utm_content=c%2Bd&gad_source=1&gad_campaignid=999&oppref=o1&mc_cid=m1';
    const result = boot({ url });
    assert.strictEqual(
      result.data.lt_landing_page,
      BASE + '/p/?gclid=G%201&utm_term=a%20b&fbclid=F1&utm_content=c%2Bd&gad_source=1&gad_campaignid=999&oppref=o1&mc_cid=m1'
    );
  },

  'protected keys survive even when the list names them'() {
    const keys = phpSensitiveKeys().concat(['utm_source', 'gclid', 'mc_cid']);
    const result = boot({ url: BASE + '/p/?utm_source=a&gclid=G1&mc_cid=m&email=x', config: { sensitiveQueryKeys: keys } });
    assert.strictEqual(result.data.lt_landing_page, BASE + '/p/?utm_source=a&gclid=G1&mc_cid=m');
  },

  'the question mark is dropped when no parameter remains'() {
    const result = boot({ url: BASE + '/p/?gclid=G1&email=x', stored: null });
    assert.strictEqual(result.data.lt_landing_page, BASE + '/p/?gclid=G1');
    // A referrer becomes the touch only when the landing URL carries no UTM or click ID.
    const only = boot({ url: BASE + '/p/', referrer: 'https://partner.example.org/r?email=a%40b.co&token=T' });
    assert.strictEqual(only.data.lt_referrer, 'https://partner.example.org/r');
  },

  'fragment never reaches storage'() {
    const result = boot({ url: BASE + '/p/?utm_source=a#access_token=SECRETFRAG' });
    assert.strictEqual(result.data.lt_landing_page, BASE + '/p/?utm_source=a');
    assertNoSecrets(result, ['SECRETFRAG']);
  },

  'referrer query parameters are redacted before storage'() {
    const result = boot({
      url: BASE + '/p/?page=2',
      referrer: 'https://partner.example.org/review?id=7&email=jane%40example.com&session=S3',
    });
    assert.strictEqual(result.data.lt_referrer, 'https://partner.example.org/review?id=7');
    assertNoSecrets(result, ['jane', 'S3']);
  },

  'a custom list from the PHP filter replaces the default'() {
    const result = boot({ url: BASE + '/p/?utm_source=a&email=x&ref_no=9', config: { sensitiveQueryKeys: ['ref_no'] } });
    assert.strictEqual(result.data.lt_landing_page, BASE + '/p/?utm_source=a&email=x');
  },

  'the script default list (no localized config) redacts every PHP default key and its _suffix form'() {
    phpSensitiveKeys().forEach((key) => {
      const result = boot({
        url: BASE + '/p/?utm_source=a&' + key + '=v1&x_' + key + '=v2&' + key + 'x=kept',
        config: { sensitiveQueryKeys: undefined },
      });
      assert.strictEqual(result.data.lt_landing_page, BASE + '/p/?utm_source=a&' + key + 'x=kept', key);
    });
  },

  'the pending capture saved for consent promotion holds no secrets'() {
    const result = boot({ url: ISSUE_URL, referrer: 'https://partner.example.org/r?token=SECRET123' });
    const pendingWrites = result.writes.filter((w) => w.startsWith('ct_pending_v1='));
    assert.ok(pendingWrites.length > 0, 'pending capture was not written');
    pendingWrites.forEach((w) => {
      const pending = JSON.parse(w.slice('ct_pending_v1='.length));
      assert.strictEqual(pending.landingPage, ISSUE_URL_REDACTED);
      assert.strictEqual(pending.referrer, 'https://partner.example.org/r');
      ['SECRET123', 'jane', '5551234'].forEach((s) => assert.ok(!JSON.stringify(pending).includes(s), s));
    });
  },

  'an old cookie with a raw landing page is cleaned on read'() {
    const result = boot({
      url: BASE + '/checkout/',
      stored: {
        ft_source: 'mail',
        ft_landing_page: ISSUE_URL,
        ft_referrer: 'https://partner.example.org/r?token=SECRET123',
        lt_source: 'mail',
        lt_landing_page: ISSUE_URL,
        gclid: 'G1',
      },
    });
    assert.strictEqual(result.data.ft_landing_page, ISSUE_URL_REDACTED);
    assert.strictEqual(result.data.lt_landing_page, ISSUE_URL_REDACTED);
    assert.strictEqual(result.data.ft_referrer, 'https://partner.example.org/r');
    assert.strictEqual(result.data.gclid, 'G1');
    assertNoSecrets(result);
  },

  async 'the cross-domain token merge stores a redacted landing page'() {
    const result = boot({
      url: BASE + '/landing/?ct_token=abc.def&email=jane%40example.com',
      verifyData: { ft_source: 'partner', ft_landing_page: 'https://other.example.test/x?token=SECRET123&utm_source=partner' },
      config: {
        linkAppendToken: true,
        tokenParam: 'ct_token',
        tokenVerifyUrl: BASE + '/wp-json/clicutcl/v2/attribution-token/verify',
        eventsToken: 'e',
      },
    });
    await new Promise((resolve) => setTimeout(resolve, 20));
    const data = result.window.ClickTrail.getData();
    assert.strictEqual(data.lt_landing_page, BASE + '/landing/');
    assert.strictEqual(data.ft_landing_page, 'https://other.example.test/x?utm_source=partner');
    assertNoSecrets({ data, writes: result.writes, dataLayer: result.dataLayer });
  },

  // Second page of the issue scenario: same-origin, so no referrer is stored and the events
  // script falls back to the raw document.referrer, which is the full landing URL.
  'events: a lead after a same-origin hop carries the landing URL without email, token and phone'() {
    const result = boot({ url: BASE + '/quote/', referrer: ISSUE_URL, events: EVENTS_CONFIG });
    result.document.dispatchEvent({ type: 'wpcf7mailsent', detail: { contactFormId: 12, unitTag: 'wpcf7-f12-p3-o1' } });
    const lead = result.dataLayer.find((entry) => entry.event === 'lead');
    assert.ok(lead, 'a lead was pushed');
    assert.strictEqual(lead.marketing_trail.referrer, ISSUE_URL_REDACTED);
    assert.strictEqual(result.sent.length, 1, 'the lead was sent to the collector');
    assert.strictEqual(JSON.parse(result.sent[0].slice(result.sent[0].indexOf('{'))).events[0].page_context.referrer, ISSUE_URL_REDACTED);
    assertNoSecrets({ data: lead, writes: result.sent, dataLayer: result.dataLayer });
  },

  'events: with attribution switched off the referrer query is dropped, never sent raw'() {
    const result = boot({ url: BASE + '/quote/', referrer: ISSUE_URL, events: EVENTS_CONFIG, attribution: false });
    result.document.dispatchEvent({ type: 'wpcf7mailsent', detail: { contactFormId: 12, unitTag: 'wpcf7-f12-p3-o1' } });
    const lead = result.dataLayer.find((entry) => entry.event === 'lead');
    assert.ok(lead, 'a lead was pushed');
    assert.strictEqual(lead.marketing_trail.referrer, BASE + '/pricing/');
    assertNoSecrets({ data: lead, writes: result.sent, dataLayer: result.dataLayer });
  },
};

let failed = 0;
(async () => {
  for (const [name, run] of Object.entries(tests)) {
    try {
      await run();
      console.log('ok - ' + name);
    } catch (error) {
      failed += 1;
      console.log('not ok - ' + name + '\n  ' + String(error && error.message).split('\n').join('\n  '));
    }
  }
  if (failed) {
    console.error(failed + ' sensitive query check(s) failed.');
    process.exit(1);
  }
  console.log('Sensitive query checks passed.');
})();
