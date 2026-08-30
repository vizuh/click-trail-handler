#!/usr/bin/env node
/**
 * Validate the form-adapter runtime-evidence contract.
 *
 * This harness never treats source fixtures as runtime proof. A manifest can
 * only derive runtime_verified when every required case is verified and each
 * verified case names an evidence artifact and runtime observation.
 */
const fs = require('fs');
const path = require('path');

const REQUIRED_ADAPTERS = {
  cf7: 'source.form.cf7',
  fluent: 'source.form.fluent',
  gravity: 'source.form.gravity',
  wpforms: 'source.form.wpforms',
  ninja: 'source.form.ninja',
  elementor: 'source.form.elementor',
};
const REQUIRED_CASES = [
  'ajax_cache_path',
  'validation_failure',
  'success',
  'consent_granted',
  'consent_denied',
  'stored_record_inspection',
];
const CASE_STATUSES = new Set(['verified', 'unverified']);
const CERTIFICATION_STATUSES = new Set(['runtime_verified', 'runtime_unverified']);
const RUNTIME_UNAVAILABLE = 'wordpress_plugin_runtime_unavailable';

function loadJson(root, relativePath) {
  return JSON.parse(fs.readFileSync(path.join(root, relativePath), 'utf8'));
}

function addFailure(failures, message) {
  failures.push(message);
}

function validate(root) {
  const failures = [];
  const fixtureDir = path.join(root, 'tests', 'fixtures', 'form-runtime', 'v1');
  const seenAdapters = new Set();

  if (!fs.existsSync(fixtureDir)) {
    return ['Missing form runtime fixture directory tests/fixtures/form-runtime/v1'];
  }

  fs.readdirSync(fixtureDir)
    .filter((filename) => filename.endsWith('.json'))
    .forEach((filename) => {
      const adapter = filename.slice(0, -'.json'.length);
      if (!Object.prototype.hasOwnProperty.call(REQUIRED_ADAPTERS, adapter)) {
        addFailure(failures, `Unexpected runtime evidence fixture ${filename}`);
      }
    });

  Object.keys(REQUIRED_ADAPTERS).forEach((adapter) => {
    const relativePath = `tests/fixtures/form-runtime/v1/${adapter}.json`;
    const absolutePath = path.join(root, relativePath);
    if (!fs.existsSync(absolutePath)) {
      addFailure(failures, `Missing runtime evidence fixture ${relativePath}`);
      return;
    }

    let manifest;
    try {
      manifest = loadJson(root, relativePath);
    } catch (error) {
      addFailure(failures, `Invalid JSON in ${relativePath}`);
      return;
    }

    if (manifest.schema !== 'clicktrail/form-runtime-evidence/v1') {
      addFailure(failures, `${relativePath} has an invalid schema`);
    }
    if (manifest.adapter !== adapter) {
      addFailure(failures, `${relativePath} adapter does not match its filename`);
    }
    if (seenAdapters.has(manifest.adapter)) {
      addFailure(failures, `Duplicate runtime evidence adapter ${manifest.adapter}`);
    }
    seenAdapters.add(manifest.adapter);

    const sourceFixture = typeof manifest.source_fixture === 'string' ? manifest.source_fixture : '';
    if (!sourceFixture || !fs.existsSync(path.join(root, sourceFixture))) {
      addFailure(failures, `${relativePath} must reference an existing source readiness fixture`);
    } else {
      try {
        const source = loadJson(root, sourceFixture);
        if (source.adapter !== adapter) {
          addFailure(failures, `${relativePath} source fixture adapter does not match`);
        }
        if (source.schema !== 'clicktrail/form-readiness/request.v1') {
          addFailure(failures, `${relativePath} source fixture is not a form-readiness request`);
        }
        if (manifest.pattern !== source.pattern) {
          addFailure(failures, `${relativePath} pattern does not match its source readiness fixture`);
        }
      } catch (error) {
        addFailure(failures, `${relativePath} references invalid source fixture JSON`);
      }
    }

    if (!manifest.runtime_required) {
      addFailure(failures, `${relativePath} must declare runtime_required=true`);
    }
    if (typeof manifest.runtime_available !== 'boolean') {
      addFailure(failures, `${relativePath} must declare runtime_available as a boolean`);
    }

    const cases = Array.isArray(manifest.cases) ? manifest.cases : [];
    const caseIds = new Set();
    cases.forEach((testCase) => {
      const id = testCase && typeof testCase.id === 'string' ? testCase.id : '';
      if (!REQUIRED_CASES.includes(id)) {
        addFailure(failures, `${relativePath} contains an unknown case ID`);
        return;
      }
      if (caseIds.has(id)) {
        addFailure(failures, `${relativePath} contains duplicate case ${id}`);
      }
      caseIds.add(id);

      const status = testCase.status;
      if (!CASE_STATUSES.has(status)) {
        addFailure(failures, `${relativePath} case ${id} has an invalid status`);
        return;
      }
      if (status === 'unverified') {
        if (testCase.reason_code !== RUNTIME_UNAVAILABLE) {
          addFailure(failures, `${relativePath} case ${id} must name unavailable WordPress/plugin runtime`);
        }
        if (testCase.evidence !== undefined && testCase.evidence !== null &&
            (!Array.isArray(testCase.evidence) || testCase.evidence.length !== 0)) {
          addFailure(failures, `${relativePath} unverified case ${id} must not contain evidence`);
        }
      }
      if (status === 'verified') {
        const evidence = testCase.evidence;
        if (!evidence || typeof evidence !== 'object' || Array.isArray(evidence)) {
          addFailure(failures, `${relativePath} verified case ${id} must contain evidence metadata`);
        } else {
          ['artifact', 'runtime', 'observed_on'].forEach((key) => {
            if (typeof evidence[key] !== 'string' || evidence[key].length === 0) {
              addFailure(failures, `${relativePath} verified case ${id} is missing evidence.${key}`);
            }
          });
          if (!Array.isArray(evidence.assertions) || evidence.assertions.length === 0) {
            addFailure(failures, `${relativePath} verified case ${id} is missing evidence assertions`);
          }
        }
      }
    });

    REQUIRED_CASES.forEach((id) => {
      if (!caseIds.has(id)) {
        addFailure(failures, `${relativePath} is missing required case ${id}`);
      }
    });
    if (caseIds.size !== REQUIRED_CASES.length) {
      addFailure(failures, `${relativePath} must contain exactly the six required cases`);
    }

    const allVerified = cases.length === REQUIRED_CASES.length &&
      REQUIRED_CASES.every((id) => cases.some((testCase) => testCase.id === id && testCase.status === 'verified'));
    const expectedStatus = allVerified ? 'runtime_verified' : 'runtime_unverified';
    if (!CERTIFICATION_STATUSES.has(manifest.certification && manifest.certification.status)) {
      addFailure(failures, `${relativePath} has an invalid certification status`);
    } else if (manifest.certification.status !== expectedStatus) {
      addFailure(failures, `${relativePath} certification status does not match its case evidence`);
    }
    if (allVerified && manifest.runtime_available !== true) {
      addFailure(failures, `${relativePath} cannot certify runtime evidence while runtime_available=false`);
    }
    if (!allVerified && manifest.certification && manifest.certification.status === 'runtime_verified') {
      addFailure(failures, `${relativePath} cannot certify runtime evidence with an incomplete case set`);
    }
  });

  let ledger;
  try {
    ledger = loadJson(root, 'docs/reference/integration-capabilities.json');
  } catch (error) {
    addFailure(failures, 'Cannot load docs/reference/integration-capabilities.json');
    return failures;
  }
  const entries = Array.isArray(ledger.entries) ? ledger.entries : [];
  Object.entries(REQUIRED_ADAPTERS).forEach(([adapter, entryId]) => {
    const entry = entries.find((candidate) => candidate && candidate.id === entryId);
    if (!entry) {
      addFailure(failures, `Missing integration ledger entry ${entryId}`);
      return;
    }
    const fixturePath = `tests/fixtures/form-runtime/v1/${adapter}.json`;
    if (!Array.isArray(entry.evidence) || !entry.evidence.includes(fixturePath)) {
      addFailure(failures, `${entryId} must cite ${fixturePath}`);
    }
    if (entry.status === 'runtime_verified') {
      let manifest;
      try {
        manifest = loadJson(root, fixturePath);
      } catch (error) {
        manifest = null;
      }
      if (!manifest || manifest.certification.status !== 'runtime_verified' ||
          !Array.isArray(entry.runtime_tests) || entry.runtime_tests.length === 0) {
        addFailure(
          failures,
          `${entryId} cannot be runtime_verified without complete runtime evidence and a runtime test record`
        );
      }
    }
  });

  return failures;
}

function main() {
  const root = path.resolve(__dirname, '..', '..');
  const failures = validate(root);
  if (failures.length > 0) {
    console.error('Form runtime evidence check failed:');
    failures.forEach((failure) => console.error(` - ${failure}`));
    process.exit(1);
  }
  console.log(
    'Form runtime evidence check passed: six adapters, six required cases each; ' +
      'runtime proof remains explicitly unverified without WordPress/plugin runtime.'
  );
}

if (require.main === module) {
  main();
}

module.exports = { validate, REQUIRED_CASES, REQUIRED_ADAPTERS };
