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
