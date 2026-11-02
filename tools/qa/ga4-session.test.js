// GA4 session cookie parsing, property pinning and reserved dataLayer fields (#135).
//
// Golden values mirror clicktrail-js packages/browser/tests/browser-browser-ids.test.ts and
// browser-datalayer-destination.test.ts (vizuh/clicktrail-js#52 GS2 parsing, property pin and
// no numeric fallback; #50 reserved Google tag fields). They run the real attribution and events
// scripts in a vm: a cookie in, the stored attribution and the dataLayer pushes out.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const ROOT = path.join(__dirname, '..', '..');
const ATTRIBUTION = fs.readFileSync(path.join(ROOT, 'assets/js/clicutcl-attribution.js'), 'utf8');
const EVENTS = fs.readFileSync(path.join(ROOT, 'assets/js/clicutcl-events.js'), 'utf8');
const KIT = JSON.parse(fs.readFileSync(path.join(ROOT, 'assets/gtm-starter-kit.json'), 'utf8'));

// Synthetic GA4 cookie values in the shapes gtag writes (no real property IDs).
const GS1_VALUE = 'GS1.1.1111111111.5.1.1111111200.0.0.0';
const GS2_OTHER = 'GS2.1.s1111111111$o4$g1$t1111111200$j60$l0$h0';
const GS2_PINNED = 'GS2.1.s2222222222$o7$g0$t2222222300$j60$l0$h0';

const RESERVED = [
  'session_id', 'client_id', 'user_id', 'session_number',
  'engagement_time_msec', 'timestamp_micros', 'page_location', 'page_referrer',
];

function storage() {
  const v = Object.create(null);
  return {
    getItem: (k) => (k in v ? v[k] : null), setItem: (k, x) => { v[k] = String(x); },
    removeItem: (k) => { delete v[k]; }, key: (i) => Object.keys(v)[i] || null,
    get length() { return Object.keys(v).length; },
  };
}

function bootAttribution({ cookies = {}, config = {} } = {}) {
  const jar = Object.assign({}, cookies);
  const listeners = {};
  const diagnostics = [];
  const document = {
    readyState: 'complete', referrer: '', title: 'Home', visibilityState: 'visible',
    body: { appendChild() {} }, head: { appendChild() {} }, documentElement: {},
    addEventListener(type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
    removeEventListener() {},
    dispatchEvent(event) {
      if (event.type === 'ct:diagnostic') diagnostics.push(event.detail);
      (listeners[event.type] || []).forEach((fn) => fn(event));
      return true;
    },
    querySelectorAll: () => [], querySelector: () => null, getElementsByTagName: () => [],
    createElement: () => ({ setAttribute() {}, appendChild() {}, style: {} }),
  };
  Object.defineProperty(document, 'cookie', {
    get: () => Object.keys(jar).map((n) => n + '=' + encodeURIComponent(jar[n])).join('; '),
    set: (raw) => {
      const [pair] = String(raw).split(';');
      const i = pair.indexOf('=');
      if (i === -1) return;
      const name = pair.slice(0, i).trim();
      const value = decodeURIComponent(pair.slice(i + 1));
      if (value === '' || /max-age=0|expires=thu, 01 jan 1970/i.test(String(raw))) delete jar[name];
      else jar[name] = value;
    },
  });
  const loc = new URL('https://shop.example.test/?utm_source=google&utm_medium=cpc');
  const window = {
    document, localStorage: storage(), sessionStorage: storage(),
    location: { href: loc.href, search: loc.search, protocol: loc.protocol, hostname: loc.hostname, origin: loc.origin, pathname: loc.pathname },
    navigator: { userAgent: 'Mozilla/5.0 Chrome/140', webdriver: false, language: 'en', sendBeacon: () => true },
    screen: { width: 1280, height: 800 }, innerWidth: 1280, innerHeight: 800,
    crypto: require('crypto').webcrypto, dataLayer: [], console: { log() {}, warn() {}, error() {} },
    setTimeout: () => 0, clearTimeout() {}, setInterval: () => 0, clearInterval() {},
    addEventListener() {}, removeEventListener() {}, dispatchEvent(event) { return document.dispatchEvent(event); },
    fetch: () => Promise.resolve({ ok: true, json: () => ({}) }),
    CSS: { escape: (v) => String(v) },
    CustomEvent: class CustomEvent { constructor(type, o) { this.type = type; this.detail = o && o.detail; } },
    URL, URLSearchParams, Event: class Event { constructor(type) { this.type = type; } }, TextEncoder, btoa, atob,
    clicutcl_config: Object.assign({ cookieName: 'attribution', cookieDays: 90, requireConsent: false }, config),
  };
  window.window = window;
  window.self = window;
  vm.runInContext(ATTRIBUTION, vm.createContext(window), { filename: 'clicutcl-attribution.js' });
  return { window, diagnostics, data: () => window.ClickTrail.getData() };
}

const session = (page) => ({ id: page.data().ga_session_id, number: page.data().ga_session_number });
const withCookies = (cookies, config) => bootAttribution({ cookies, config });

// Events are driven through the public server-event queue (clicutclServerEvents), which calls pushEvent.
function bootEvents(serverEvents, { transport = false } = {}) {
  const listeners = {};
  const sent = [];
  const document = {
    readyState: 'complete', referrer: '', title: 'Quote', cookie: '',
    addEventListener(type, fn) { (listeners[type] = listeners[type] || []).push(fn); },
    removeEventListener() {},
    dispatchEvent() { return true; },
    querySelectorAll: () => [], querySelector: () => null,
  };
  const window = {
    document,
    location: { href: 'https://example.test/quote', search: '', protocol: 'https:', hostname: 'example.test', origin: 'https://example.test', pathname: '/quote' },
    navigator: { userAgent: 'Mozilla/5.0', maxTouchPoints: 0 },
    innerWidth: 1280, innerHeight: 800, crypto: require('crypto').webcrypto,
    localStorage: storage(), sessionStorage: storage(), dataLayer: [],
    console: { log() {}, warn() {}, error() {} },
    setTimeout: () => 0, clearTimeout() {}, addEventListener() {}, removeEventListener() {},
    dispatchEvent() { return true; },
    fetch: (url, init) => { sent.push(JSON.parse(init.body)); return Promise.resolve({ ok: true }); },
    URL, URLSearchParams,
    clicutclEventsConfig: { enabled: true, transportEnabled: transport, eventsBatchUrl: 'https://example.test/wp-json/clicutcl/v2/events/batch', eventsToken: 'tok' },
    clicutclServerEvents: serverEvents,
    ClickTrailConsent: { isResolved: () => true, isGranted: () => true, getState: () => ({ marketing: true, analytics: true }) },
  };
  window.window = window;
  vm.runInContext(EVENTS, vm.createContext(window), { filename: 'clicutcl-events.js' });
  return { window, sent, pushed: (name) => window.dataLayer.filter((e) => e.event === name) };
}

function listedReservedFields(source, label) {
  const match = /RESERVED_GOOGLE_TAG_FIELDS\s*=\s*Object\.freeze\(\[([^\]]*)\]\)/.exec(source);
  assert.ok(match, label + ': RESERVED_GOOGLE_TAG_FIELDS missing');
  return match[1].split(',').map((s) => s.trim().replace(/^'|'$/g, '')).filter(Boolean);
}

const tests = {
  // --- GS1 / GS2 parsing -------------------------------------------------------------------
  'GS1 dot fields give the session id and number'() {
    assert.deepStrictEqual(session(withCookies({ _ga_ABC123: GS1_VALUE })), { id: '1111111111', number: '5' });
    assert.deepStrictEqual(session(withCookies({ _ga_ABC123: 'GS1.1.1111111111.5' })), { id: '1111111111', number: '5' });
  },

  'a GS2 cookie gives both the session id (leading s token) and the session number'() {
    assert.deepStrictEqual(session(withCookies({ _ga_ABC123: GS2_PINNED })), { id: '2222222222', number: '7' });
  },

  'a GS2 cookie with only the session token gives the id and no number'() {
    assert.deepStrictEqual(session(withCookies({ _ga_ABC123: 'GS2.1.s2222222222' })), { id: '2222222222', number: undefined });
  },

  'non-GS values give no session id (numeric-token fallback removed)'() {
    ['foo123bar456', 'other$s123456789$o3', 'GS3.1.s2222222222$o7', ''].forEach((value) => {
      assert.deepStrictEqual(session(withCookies({ _ga_X: value })), { id: undefined, number: undefined }, value);
    });
  },

  'malformed GS1 and GS2 fields are rejected instead of guessed'() {
    ['GS1.1', 'GS1.1.abc.5', 'GS2.1.s12$o3', 'GS2.1.$o3'].forEach((value) => {
      assert.deepStrictEqual(session(withCookies({ _ga_X: value })), { id: undefined, number: undefined }, value);
    });
  },

  'a cookie without a session id is skipped when choosing among several'() {
    const page = withCookies({ _ga_X: 'foo123bar456', _ga_Y: 'GS2.1.$o3', _ga_ABC123: GS1_VALUE });
    assert.deepStrictEqual(session(page), { id: '1111111111', number: '5' });
  },

  'the plain _ga cookie is the client id source and never session data'() {
    const page = withCookies({ _ga: 'GA1.1.1234567890.9876543210' });
    assert.strictEqual(page.data().ga_client_id, '1234567890.9876543210');
    assert.strictEqual(page.data().ga_session_id, undefined);
    assert.strictEqual(withCookies({ _ga: 'not-a-ga-id' }).data().ga_client_id, undefined);
  },

  // --- property pinning --------------------------------------------------------------------
  'unpinned: the first usable _ga_* cookie wins and the ambiguity is reported once'() {
    const page = withCookies({ _ga_OTHER: GS2_OTHER, _ga_ABC123: GS2_PINNED });
    assert.deepStrictEqual(session(page), { id: '1111111111', number: '4' });
    assert.strictEqual(page.diagnostics.length, 1);
    assert.strictEqual(page.diagnostics[0].code, 'ga_session_property_unpinned');
    assert.strictEqual(page.diagnostics[0].level, 'warn');
    assert.deepStrictEqual(JSON.parse(JSON.stringify(page.diagnostics[0].context)), { ga_session_cookie_count: 2 });
  },

  'unpinned with a single _ga_* cookie reports nothing'() {
    const page = withCookies({ _ga_ABC123: GS2_PINNED });
    assert.deepStrictEqual(session(page), { id: '2222222222', number: '7' });
    assert.deepStrictEqual(page.diagnostics, []);
  },

  'the diagnostic carries cookie counts, never cookie values'() {
    const page = withCookies({ _ga_OTHER: GS2_OTHER, _ga_ABC123: GS2_PINNED });
    assert.ok(!JSON.stringify(page.diagnostics).includes('1111111111'));
    assert.ok(!JSON.stringify(page.diagnostics).includes('2222222222'));
  },

  'pinned: only the configured property cookie is read and nothing is reported'() {
    const page = withCookies({ _ga_OTHER: GS2_OTHER, _ga_ABC123: GS2_PINNED }, { ga4MeasurementId: 'G-ABC123' });
    assert.deepStrictEqual(session(page), { id: '2222222222', number: '7' });
    assert.deepStrictEqual(page.diagnostics, []);
  },

  'pinned: the measurement id is matched case-insensitively'() {
    const page = withCookies({ _ga_OTHER: GS2_OTHER, _ga_ABC123: GS2_PINNED }, { ga4MeasurementId: 'g-abc123' });
    assert.deepStrictEqual(session(page), { id: '2222222222', number: '7' });
  },

  'pinned: a missing property cookie never falls back to another property'() {
    const page = withCookies({ _ga_OTHER: GS2_OTHER, _ga_ABC123: GS2_PINNED }, { ga4MeasurementId: 'G-MISSING1' });
    assert.deepStrictEqual(session(page), { id: undefined, number: undefined });
  },

  'pinned to several ids: configured order, first usable cookie wins, no fallback'() {
    const cookies = { _ga_OTHER: GS2_OTHER, _ga_ABC123: GS2_PINNED };
    assert.deepStrictEqual(session(withCookies(cookies, { ga4MeasurementId: ['G-MISSING1', 'g-abc123'] })), { id: '2222222222', number: '7' });
    assert.deepStrictEqual(session(withCookies(cookies, { ga4MeasurementId: ['G-ABC123', 'G-OTHER'] })), { id: '2222222222', number: '7' });
    assert.deepStrictEqual(session(withCookies(cookies, { ga4MeasurementId: ['G-MISSING1'] })), { id: undefined, number: undefined });
  },

  'a configured id that is not G-<alphanumeric> is ignored and the read stays unpinned'() {
    const cookies = { _ga_OTHER: GS2_OTHER, _ga_ABC123: GS2_PINNED };
    ['ABC123', 'G-', 'UA-1234-1', '', 42, null].forEach((bad) => {
      const page = withCookies(cookies, { ga4MeasurementId: bad });
      assert.deepStrictEqual(session(page), { id: '1111111111', number: '4' }, String(bad));
    });
  },

  // --- reserved Google tag fields in the dataLayer -----------------------------------------
  'the reserved field list matches the clicktrail-js GOOGLE_TAG_RESERVED_FIELDS in both scripts'() {
    assert.deepStrictEqual(listedReservedFields(ATTRIBUTION, 'attribution'), RESERVED);
    assert.deepStrictEqual(listedReservedFields(EVENTS, 'events'), RESERVED);
  },

  'ct_page_view carries ct_session_id and ct_session_number, never the bare names'() {
    const page = withCookies({});
    const push = page.window.dataLayer.find((e) => e.event === 'ct_page_view');
    assert.ok(push, 'ct_page_view pushed');
    RESERVED.forEach((field) => assert.ok(!(field in push), field + ' must not be pushed flat'));
    assert.ok(push.ct_session_id, 'ct_session_id present');
    assert.ok(push.ct_session_number, 'ct_session_number present');
    assert.strictEqual(push.datalayer_version, '2.0.0');
    assert.strictEqual(push.marketing_trail.event_name, 'page_view');
    assert.ok(!page.window.dataLayer.some((e) => e.event === 'page_view'), 'no page_view event');
  },

  'pushEvent prefixes every reserved key, keeps the rest flat, and the renamed value wins'() {
    const params = {};
    RESERVED.forEach((field) => { params[field] = 'param-' + field; });
    params.ct_session_id = 'caller-shadow';
    params.cta_text = 'Buy';
    const page = bootEvents([{ event: 'lead_submitted', event_id: 'evt-1', params }]);
    const push = page.pushed('lead_submitted')[0];
    assert.ok(push, 'pushed');
    RESERVED.forEach((field) => {
      assert.ok(!(field in push), field + ' must not be pushed flat');
      assert.ok(('ct_' + field) in push, 'ct_' + field + ' present');
    });
    RESERVED.filter((field) => field !== 'session_id').forEach((field) => assert.strictEqual(push['ct_' + field], 'param-' + field, field));
    // pushEvent always sets session_id to the plugin session, so that is the value renamed; it beats the shadow key.
    assert.ok(push.ct_session_id && push.ct_session_id !== 'caller-shadow' && push.ct_session_id !== 'param-session_id');
    assert.strictEqual(push.cta_text, 'Buy');
    assert.ok(push.visitor_id, 'visitor_id stays flat');
    assert.ok(!('ct_visitor_id' in push));
    assert.strictEqual(push.event_id, 'evt-1');
    assert.strictEqual(push.datalayer_version, '2.0.0');
  },

  'pushEvent puts the plugin session under ct_session_id'() {
    const page = bootEvents([{ event: 'cta_click', params: { cta_text: 'Buy' } }]);
    const push = page.pushed('cta_click')[0];
    assert.ok(!('session_id' in push));
    assert.ok(push.ct_session_id);
    assert.strictEqual(push.cta_text, 'Buy');
  },

  'pushEvent leaves the marketing_trail envelope and the server copy unchanged'() {
    const page = bootEvents([{ event: 'cta_click', params: { engagement_time_msec: 100 } }], { transport: true });
    const push = page.pushed('cta_click')[0];
    assert.ok(push.marketing_trail && push.marketing_trail.event_name);
    assert.strictEqual(page.sent.length, 1);
    const canonical = page.sent[0].events[0];
    assert.ok(canonical.session_id, 'the server event keeps its session_id');
    assert.strictEqual(canonical.delivery_context.engagement_time_msec, 100);
    assert.ok(!('session_id' in push) && !('engagement_time_msec' in push));
  },

  'the canonical page_view is pushed as ct_page_view and never as page_view'() {
    const page = bootEvents([{ event: 'page_view', params: {} }]);
    assert.strictEqual(page.pushed('page_view').length, 0);
    const push = page.pushed('ct_page_view')[0];
    assert.ok(push);
    assert.strictEqual(push.marketing_trail.event_name, 'page_view');
  },

  'no GA4 tag in the starter kit maps a reserved field name'() {
    const names = [];
    KIT.containerVersion.tag.forEach((tag) => {
      (tag.parameter || []).forEach((p) => {
        if (!['eventSettingsTable', 'configSettingsTable', 'userProperties'].includes(p.key)) return;
        (p.list || []).forEach((row) => row.map.forEach((m) => { if (m.key === 'parameter' || m.key === 'name') names.push([tag.name, m.value]); }));
      });
    });
    assert.ok(names.length > 0, 'the kit maps parameters');
    names.forEach(([tag, name]) => assert.ok(!RESERVED.includes(name), tag + ' maps reserved field ' + name));
  },
};

let failed = 0;
Object.keys(tests).forEach((name) => {
  try { tests[name](); console.log('ok   ' + name); } catch (error) { failed += 1; console.error('FAIL ' + name + '\n  ' + error.message); }
});
if (failed) process.exit(1);
console.log('ga4-session.test.js: ok');
