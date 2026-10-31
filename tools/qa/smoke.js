const fs = require('fs');
const path = require('path');
const vm = require('vm');
const formRuntimeEvidence = require('./form-runtime-evidence.js');

const root = path.resolve(__dirname, '..', '..');

function loadJson(relativePath) {
  const absolutePath = path.join(root, relativePath);
  return JSON.parse(fs.readFileSync(absolutePath, 'utf8'));
}

function readFile(relativePath) {
  return fs.readFileSync(path.join(root, relativePath), 'utf8');
}

function assert(condition, message, failures) {
  if (!condition) {
    failures.push(message);
  }
}

function collectRegistrySmokeIds(registry) {
  const ids = new Set();
  ['delivery_adapters', 'destinations', 'features'].forEach((sectionKey) => {
    const section = registry[sectionKey] || {};
    Object.values(section).forEach((entry) => {
      const smokeIds = Array.isArray(entry.smoke_test_ids) ? entry.smoke_test_ids : [];
      smokeIds.forEach((id) => ids.add(String(id)));
    });
  });
  return ids;
}

function validateDocsTargets(registry, failures) {
  ['delivery_adapters', 'destinations', 'features'].forEach((sectionKey) => {
    const section = registry[sectionKey] || {};
    Object.entries(section).forEach(([key, entry]) => {
      const docsTarget = entry && entry.docs_target ? String(entry.docs_target) : '';
      assert(docsTarget !== '', `Missing docs_target for ${sectionKey}.${key}`, failures);
      if (docsTarget) {
        assert(fs.existsSync(path.join(root, docsTarget)), `Missing docs_target file ${docsTarget} for ${sectionKey}.${key}`, failures);
      }
    });
  });
}

function validateMatrixCoverage(registryIds, matrix, failures) {
  const tests = matrix.tests || {};
  registryIds.forEach((id) => {
    assert(Boolean(tests[id]), `Missing test matrix entry for smoke ID ${id}`, failures);
  });

  Object.keys(tests).forEach((id) => {
    assert(registryIds.has(id), `Test matrix entry ${id} is not referenced by the feature registry`, failures);
  });
}

function validateMatrixEvidence(matrix, failures) {
  const tests = matrix.tests || {};
  Object.entries(tests).forEach(([id, test]) => {
    assert(typeof test.label === 'string' && test.label.length > 0, `Test ${id} is missing a label`, failures);
    assert(typeof test.expected_behavior === 'string' && test.expected_behavior.length > 0, `Test ${id} is missing expected_behavior`, failures);
    assert(Array.isArray(test.prerequisites) && test.prerequisites.length > 0, `Test ${id} is missing prerequisites`, failures);
    assert(Array.isArray(test.manual_verification) && test.manual_verification.length > 0, `Test ${id} is missing manual_verification steps`, failures);
    assert(Array.isArray(test.evidence) && test.evidence.length > 0, `Test ${id} is missing evidence entries`, failures);

    (test.evidence || []).forEach((entry, index) => {
      const relativeFile = entry && entry.file ? String(entry.file) : '';
      const patterns = Array.isArray(entry && entry.patterns) ? entry.patterns : [];

      assert(relativeFile !== '', `Test ${id} evidence #${index + 1} is missing file`, failures);
      if (!relativeFile) {
        return;
      }

      const absoluteFile = path.join(root, relativeFile);
      assert(fs.existsSync(absoluteFile), `Test ${id} references missing file ${relativeFile}`, failures);
      if (!fs.existsSync(absoluteFile)) {
        return;
      }

      const content = readFile(relativeFile);
      assert(patterns.length > 0, `Test ${id} evidence for ${relativeFile} is missing patterns`, failures);
      patterns.forEach((pattern) => {
        assert(content.includes(String(pattern)), `Test ${id} is missing pattern "${pattern}" in ${relativeFile}`, failures);
      });
    });
  });
}

function resolveConsent(config, cookie = '', windowOverrides = {}) {
  const document = {
    cookie,
    readyState: 'complete',
    addEventListener() {},
    dispatchEvent() {}
  };
  const window = {
    ctConsentBridgeConfig: config,
    location: { protocol: 'https:' },
    console,
    dataLayer: [],
    dispatchEvent() {},
    addEventListener() {},
    setTimeout(callback) { callback(); },
    ...windowOverrides
  };
  const context = {
    window,
    document,
    CustomEvent: class CustomEvent {
      constructor(type, options) {
        this.type = type;
        this.detail = options && options.detail;
      }
    }
  };

  vm.runInNewContext(readFile('assets/js/clicutcl-consent-bridge.js'), context);
  return window.ClickTrailConsent.getState();
}

function validateConsentRuntime(failures) {
  const disabled = resolveConsent({ enabled: false, fallbackGranted: true });
  assert(disabled.marketing && disabled.analytics, 'Disabled consent mode must bypass CMP gating', failures);

  const encoded = encodeURIComponent(JSON.stringify({ marketing: false, analytics: true }));
  const custom = resolveConsent({ enabled: true, cookieName: 'custom_consent' }, `custom_consent=${encoded}`);
  assert(!custom.marketing && custom.analytics, 'Custom consent cookie must preserve categories', failures);

  const cookiebot = resolveConsent(
    { enabled: true, cmpSource: 'cookiebot', cookieName: 'missing' },
    '',
    { Cookiebot: { hasResponse: true, consent: { statistics: true, marketing: false } } }
  );
  assert(!cookiebot.marketing && cookiebot.analytics, 'CMP analytics consent must not imply marketing consent', failures);
}

function validatePendingCaptureConsentGate(failures) {
  const source = readFile('assets/js/clicutcl-attribution.js');
  const initStart = source.indexOf('        init() {');
  const initEnd = source.indexOf('        bindConsentListener() {', initStart);
  const init = source.slice(initStart, initEnd);

  assert(initStart >= 0 && initEnd > initStart, 'Attribution init consent gate is missing', failures);
  assert(
    /if \(\s*!requiresConsent \|\| \(consent && consent\.resolved && consent\.marketing\)\s*\) \{\s*PendingCapture\.save\(\);\s*\}/s.test(init),
    'Pending capture must not write while required consent is unresolved',
    failures
  );
}

function validateCachedFormActionBoundary(failures) {
  const values = new Map();
  const memoryStorage = {
    getItem(key) { return values.has(key) ? values.get(key) : null; },
    setItem(key, value) { values.set(key, String(value)); },
    removeItem(key) { values.delete(key); }
  };
  const form = { action: '/?gclid=visitor-a' };
  const document = {
    forms: [form],
    referrer: '',
    readyState: 'complete',
    addEventListener() {},
    dispatchEvent() {},
    querySelector() { return null; },
    querySelectorAll() { return []; }
  };
  let cookies = '';
  Object.defineProperty(document, 'cookie', {
    get() { return cookies; },
    set(value) {
      const pair = String(value).split(';', 1)[0];
      const name = pair.slice(0, pair.indexOf('='));
      const keep = cookies.split('; ').filter((cookie) => cookie && !cookie.startsWith(`${name}=`));
      if (!/Max-Age=0|expires=Thu, 01 Jan 1970/i.test(value)) keep.push(pair);
      cookies = keep.join('; ');
    }
  });

  let uuid = 0;
  const window = {
    clicutcl_config: {
      cookieName: 'ct_attribution',
      cookieDays: 90,
      requireConsent: false,
      injectEnabled: false,
      linkDecorateEnabled: false,
      linkAppendToken: false,
      enableWhatsapp: false
    },
    location: {
      href: 'https://example.test/',
      origin: 'https://example.test',
      protocol: 'https:',
      hostname: 'example.test',
      pathname: '/',
      search: ''
    },
    crypto: { randomUUID: () => `test-${++uuid}` },
    dataLayer: []
  };
  const context = {
    window,
    document,
    navigator: { userAgent: 'ClickTrail smoke', webdriver: false },
    localStorage: memoryStorage,
    sessionStorage: memoryStorage,
    URL,
    Event,
    CustomEvent: class CustomEvent {
      constructor(type, options) { this.type = type; this.detail = options && options.detail; }
    },
    console
  };

  vm.runInNewContext(readFile('assets/js/clicutcl-attribution.js'), context);

  assert(window.ClickTrail.getField('gclid') === '', 'Cached form action must not become current visitor attribution', failures);
  assert(form.action === '/?gclid=visitor-a', 'ClickTrail must not treat or rewrite a cached form action as attribution input', failures);
}

// vm browser around the real attribution script; cookie jar and storage persist across visits.
function makeBrowser(cookieName) {
  const values = new Map();
  const memoryStorage = {
    getItem(key) { return values.has(key) ? values.get(key) : null; },
    setItem(key, value) { values.set(key, String(value)); },
    removeItem(key) { values.delete(key); }
  };
  const listeners = {};
  const document = {
    forms: [],
    referrer: '',
    readyState: 'complete',
    addEventListener(type, fn) { listeners[type] = fn; },
    dispatchEvent() {},
    querySelector() { return null; },
    querySelectorAll() { return []; }
  };
  let cookies = '';
  Object.defineProperty(document, 'cookie', {
    get() { return cookies; },
    set(value) {
      const pair = String(value).split(';', 1)[0];
      const name = pair.slice(0, pair.indexOf('='));
      const keep = cookies.split('; ').filter((cookie) => cookie && !cookie.startsWith(`${name}=`));
      if (!/Max-Age=0|expires=Thu, 01 Jan 1970/i.test(value)) keep.push(pair);
      cookies = keep.join('; ');
    }
  });

  let uuid = 0;
  const window = {
    clicutcl_config: {
      cookieName,
      cookieDays: 90,
      requireConsent: false,
      injectEnabled: false,
      linkDecorateEnabled: true,
      linkAllowedDomains: ['calendly.com'],
      linkAppendToken: false,
      enableWhatsapp: false
    },
    addEventListener() {},
    crypto: { randomUUID: () => `test-${++uuid}` },
    dataLayer: []
  };
  const context = {
    window,
    document,
    navigator: { userAgent: 'ClickTrail smoke', webdriver: false },
    localStorage: memoryStorage,
    sessionStorage: memoryStorage,
    URL,
    Event,
    CustomEvent: class CustomEvent {
      constructor(type, options) { this.type = type; this.detail = options && options.detail; }
    },
    console
  };
  const source = readFile('assets/js/clicutcl-attribution.js');
  const visit = (search) => {
    window.location = {
      href: `https://example.test/${search}`,
      origin: 'https://example.test',
      protocol: 'https:',
      hostname: 'example.test',
      pathname: '/',
      search
    };
    vm.runInNewContext(source, context);
  };
  const decorate = (href) => {
    const attrs = { href };
    const anchor = {
      getAttribute: (name) => (name in attrs ? attrs[name] : null),
      setAttribute: (name, value) => { attrs[name] = String(value); },
      hasAttribute: (name) => name in attrs,
      removeAttribute: (name) => { delete attrs[name]; }
    };
    // Like the DOM, the href property and attribute are one value.
    Object.defineProperty(anchor, 'href', { get: () => attrs.href, set: (v) => { attrs.href = String(v); } });
    listeners.mousedown({ target: { closest: () => anchor } });
    return new URL(attrs.href).searchParams;
  };

  return {
    window,
    values,
    visit,
    decorate,
    cookie(name) {
      const raw = cookies.split('; ').find((cookie) => cookie.startsWith(`${name}=`));
      return raw ? JSON.parse(decodeURIComponent(raw.slice(name.length + 1))) : null;
    },
    setCookie(name, data) {
      cookies = [cookies, `${name}=${encodeURIComponent(JSON.stringify(data))}`].filter(Boolean).join('; ');
    },
    resetCookies() { cookies = ''; }
  };
}

// Returning visitor with a new ad click: link decoration and the persisted cookie must carry the
// last-touch click ID, not the first visit's copy.
function validateReturningVisitorClickId(failures) {
  const browser = makeBrowser('ct_attribution');
  const { window, values, visit, decorate } = browser;

  visit('?gclid=TEST_GCLID_123&utm_source=google&utm_campaign=first');
  visit('?gclid=TEST_PERSIST_456&utm_source=google&utm_campaign=repeat');

  const applyLink = decorate('https://apply.example.test/form');
  assert(applyLink.get('gclid') === 'TEST_PERSIST_456', `Decorated link must carry last-touch gclid, got ${applyLink.get('gclid')}`, failures);
  assert(applyLink.get('utm_campaign') === 'repeat', 'Decorated link must carry last-touch UTMs', failures);

  const stamp = decorate('https://calendly.com/acme/intro').get('salesforce_uuid') || '';
  assert(stamp.includes('gclid=TEST_PERSIST_456'), `Calendly stamp must carry last-touch gclid, got ${stamp}`, failures);

  const stored = browser.cookie('ct_attribution') || {};
  assert(stored.gclid === 'TEST_PERSIST_456', `Persisted top-level gclid must mirror lt_gclid, got ${stored.gclid}`, failures);
  assert(stored.ft_gclid === 'TEST_GCLID_123', 'First-touch gclid must keep the first visit value', failures);

  const pageView = window.dataLayer.filter((entry) => entry.event === 'ct_page_view').pop();
  const pageGclid = pageView && pageView.marketing_trail.click_ids.gclid;
  assert(pageGclid === 'TEST_PERSIST_456', `ct_page_view must carry last-touch gclid, got ${pageGclid}`, failures);

  // Cookie written before the fix, visitor returns with no URL signal: still decorates with last touch.
  values.clear();
  browser.resetCookies();
  browser.setCookie('ct_attribution', { gclid: 'TEST_GCLID_123', ft_gclid: 'TEST_GCLID_123', lt_gclid: 'TEST_PERSIST_456' });
  visit('');
  const legacyGclid = decorate('https://apply.example.test/form').get('gclid');
  assert(legacyGclid === 'TEST_PERSIST_456', `Legacy cookie must decorate with last-touch gclid, got ${legacyGclid}`, failures);
}

// Embed handoff: window.ClickTrail.decorateEmbedUrl() decorates only exact allowlisted https hosts,
// carries last-touch UTMs plus the one selected click ID, and returns the input otherwise.
function validateEmbedUrlDecoration(failures) {
  const browser = makeBrowser('attribution');
  browser.window.clicutcl_config.embedAllowedDomains = ['widget.example.test'];
  browser.window.clicutcl_config.linkSkipSigned = true;
  browser.visit('?gclid=GA&utm_source=google&utm_campaign=first');
  browser.visit('?fbclid=FB&utm_source=facebook&utm_medium=paid&utm_campaign=meta');
  const api = browser.window.ClickTrail;

  const out = new URL(api.decorateEmbedUrl('https://widget.example.test/booking/new?location=2'));
  const p = out.searchParams;
  assert(p.get('fbclid') === 'FB' && !p.has('gclid'), `Embed URL must carry only the selected click, got ${out.search}`, failures);
  assert(p.get('utm_source') === 'facebook' && p.get('utm_campaign') === 'meta', 'Embed URL must carry last-touch UTMs', failures);
  assert(p.get('location') === '2', 'Embed URL keeps the site params', failures);

  const kept = new URL(api.decorateEmbedUrl('https://widget.example.test/b?utm_source=site')).searchParams;
  assert(kept.get('utm_source') === 'site' && !kept.has('utm_campaign'), 'Site utm_* is kept and never mixed with stored UTMs', failures);
  assert(kept.get('fbclid') === 'FB', 'Click ID is still added next to site UTMs', failures);

  ['https://other.widget.example.test/b', 'https://example.test/b', 'http://widget.example.test/b',
    'https://www.widget.example.test/b',
    'https://widget.example.test/b?signature=x', 'javascript:alert(1)'].forEach((url) => {
    assert(api.decorateEmbedUrl(url) === url, `Embed URL must be returned unchanged for ${url}`, failures);
  });
  assert(api.decorateEmbedUrl(undefined) === undefined, 'Non-string input is returned as is', failures);

  const wbraid = makeBrowser('attribution');
  wbraid.window.clicutcl_config.embedAllowedDomains = ['widget.example.test'];
  wbraid.visit('?wbraid=WB1&utm_source=google');
  const wb = new URL(wbraid.window.ClickTrail.decorateEmbedUrl('https://widget.example.test/b')).searchParams;
  assert(wb.get('wbraid') === 'WB1' && !wb.has('gclid'), 'wbraid travels under its own key', failures);

  const snap = makeBrowser('attribution');
  snap.window.clicutcl_config.embedAllowedDomains = ['widget.example.test'];
  snap.visit('?ScCid=S1&utm_source=snapchat');
  const sc = new URL(snap.window.ClickTrail.decorateEmbedUrl('https://widget.example.test/b')).searchParams;
  assert(sc.get('ScCid') === 'S1' && !sc.has('sccid'), 'Snap click uses the ScCid param name, like link decoration', failures);

  const denied = makeBrowser('attribution');
  denied.window.clicutcl_config.embedAllowedDomains = ['widget.example.test'];
  denied.visit('?gclid=GA&utm_source=google');
  denied.window.clicutcl_config.requireConsent = true;
  denied.setCookie('ct_consent', { marketing: false, analytics: true });
  const deniedUrl = 'https://widget.example.test/b';
  assert(denied.window.ClickTrail.decorateEmbedUrl(deniedUrl) === deniedUrl, 'No decoration without marketing consent', failures);

  const none = makeBrowser('attribution');
  none.visit('?gclid=GA&utm_source=google');
  const noneUrl = 'https://widget.example.test/b';
  assert(none.window.ClickTrail.decorateEmbedUrl(noneUrl) === noneUrl, 'Empty embed allowlist decorates nothing', failures);
}

// D3 (clicktrail-js ruling): Google A then Meta B selects B; history stays browser-side.
// Cookie migration: canonical `attribution` wins, legacy `ct_attribution` is read but never refreshed.
function validateClickIdSelectionAndCookieMigration(failures) {
  const meta = makeBrowser('attribution');
  meta.visit('?gclid=GA&utm_source=google&utm_medium=cpc&utm_campaign=g1');
  meta.visit('?fbclid=FB&utm_source=facebook&utm_medium=paid&utm_campaign=m1');
  const data = meta.window.ClickTrail.getData();
  assert(data.attribution_selected_click_id === 'FB', `Selected click must be the newest (FB), got ${data.attribution_selected_click_id}`, failures);
  assert(data.attribution_selected_click_id_reason === 'newest_valid_superseded_previous', `Unexpected selection reason ${data.attribution_selected_click_id_reason}`, failures);
  const envelope = JSON.parse(meta.values.get('attribution') || '{}');
  const history = JSON.parse((envelope.data || {}).click_id_history || '[]').map((entry) => `${entry.k}=${entry.v}`).join(',');
  assert(history === 'gclid=GA,fbclid=FB', `click_id_history must record both clicks in order, got ${history}`, failures);
  assert(!('click_id_history' in data), 'click_id_history must not leave browser storage via ClickTrail.getData()', failures);
  meta.visit(''); // a page view without a new touch is not saved, but still pushes ct_page_view
  const leaked = meta.window.dataLayer.some((entry) => entry.ct_attribution && 'click_id_history' in entry.ct_attribution);
  assert(!leaked, 'click_id_history must not reach the dataLayer', failures);
  assert(data.gclid === 'GA' && data.fbclid === 'FB', 'Per-platform top-level click IDs are both kept', failures);
  const cookie = meta.cookie('attribution') || {};
  assert(!('click_id_history' in cookie), 'click_id_history must stay out of the server cookie', failures);
  assert(cookie.attribution_selected_click_id === 'FB', 'Selected click must reach the server cookie', failures);
  const stamp = meta.decorate('https://calendly.com/acme/intro').get('salesforce_uuid') || '';
  assert(stamp.includes('fbclid=FB') && !stamp.includes('gclid'), `Calendly stamp must carry the selected click, got ${stamp}`, failures);

  const snap = makeBrowser('attribution');
  snap.visit('?gclid=GA&utm_source=google');
  snap.visit('?ScCid=SNAP&utm_source=snapchat');
  assert(snap.window.ClickTrail.getData().attribution_selected_click_id === 'SNAP', 'Snap click is selected', failures);
  const snapStamp = snap.decorate('https://calendly.com/acme/intro').get('salesforce_uuid') || '';
  assert(snapStamp === 'ct1;sccid=SNAP', `Stamp must carry the selected Snap click, never the older Google one, got ${snapStamp}`, failures);

  const legacy = makeBrowser('attribution');
  legacy.setCookie('ct_attribution', { lt_source: 'old', lt_gclid: 'OLD', gclid: 'OLD' });
  legacy.visit('');
  assert(legacy.window.ClickTrail.getData().gclid === 'OLD', 'Legacy-only cookie is still read', failures);
  assert(!legacy.values.has('attribution'), 'A legacy-only cookie is not mirrored into localStorage with a fresh expiry', failures);
  assert(legacy.cookie('attribution') === null, 'Reading the legacy cookie must not rewrite it (no retention refresh)', failures);
  legacy.setCookie('attribution', { lt_source: 'new', lt_gclid: 'NEW', gclid: 'NEW' });
  assert(legacy.window.ClickTrail.getData().gclid === 'NEW', 'Canonical cookie wins over the legacy one', failures);
  legacy.visit('?gclid=NEWER&utm_source=google');
  assert(legacy.cookie('ct_attribution') === null, 'Saving drops the legacy cookie', failures);
  assert((legacy.cookie('attribution') || {}).gclid === 'NEWER', 'Canonical cookie holds the new touch', failures);
}

function validateWooRuntimeRemediations(failures) {
  const integration = readFile('includes/integrations/class-woocommerce.php');
  const admin = readFile('includes/admin/class-clicutcl-woocommerce-admin.php');

  assert(
    /store_trace_snapshot\([\s\S]+?array_merge\([\s\S]+?\);\s*\$order->save\(\);\s*return \$result;/m.test(integration),
    'Woo dispatch result trace must be saved before returning',
    failures
  );
  assert(
    admin.includes('manage_woocommerce_page_wc-orders_columns') &&
      admin.includes('manage_woocommerce_page_wc-orders_custom_column') &&
      admin.includes("wc_get_page_screen_id( 'shop-order' )"),
    'Woo admin must register HPOS list and order-screen surfaces',
    failures
  );
}

function validateWooConsentSnapshotV1(failures) {
  const integration = readFile('includes/integrations/class-woocommerce.php');
  const diagnostics = readFile('includes/admin/traits/trait-admin-diagnostics-ajax.php');

  assert(
    integration.includes("wp_json_encode( Consent::snapshot() )") &&
      integration.includes('Snapshot_V1::normalize'),
    'Woo checkout and dispatch must share the versioned consent snapshot contract',
    failures
  );
  assert(
    diagnostics.includes('Snapshot_V1::normalize'),
    'Woo Diagnostics must read legacy and v1 consent snapshots through one normalizer',
    failures
  );
}

function main() {
  const failures = [];
  const registry = loadJson('config/feature-registry.json');
  const matrix = loadJson('config/feature-test-matrix.json');
  const registryIds = collectRegistrySmokeIds(registry);

  validateDocsTargets(registry, failures);
  validateMatrixCoverage(registryIds, matrix, failures);
  validateMatrixEvidence(matrix, failures);
  validateConsentRuntime(failures);
  validatePendingCaptureConsentGate(failures);
  validateCachedFormActionBoundary(failures);
  validateReturningVisitorClickId(failures);
  validateClickIdSelectionAndCookieMigration(failures);
  validateEmbedUrlDecoration(failures);
  validateWooRuntimeRemediations(failures);
  validateWooConsentSnapshotV1(failures);
  failures.push(...formRuntimeEvidence.validate(root));

  if (failures.length > 0) {
    console.error('Smoke coverage check failed:');
    failures.forEach((failure) => console.error(` - ${failure}`));
    process.exit(1);
  }

  console.log(`Smoke coverage check passed for ${registryIds.size} registry-backed smoke IDs.`);
}

main();
