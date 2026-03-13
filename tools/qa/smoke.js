const fs = require('fs');
const path = require('path');

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

function main() {
  const failures = [];
  const registry = loadJson('config/feature-registry.json');
  const matrix = loadJson('config/feature-test-matrix.json');
  const registryIds = collectRegistrySmokeIds(registry);

  validateDocsTargets(registry, failures);
  validateMatrixCoverage(registryIds, matrix, failures);
  validateMatrixEvidence(matrix, failures);

  if (failures.length > 0) {
    console.error('Smoke coverage check failed:');
    failures.forEach((failure) => console.error(` - ${failure}`));
    process.exit(1);
  }

  console.log(`Smoke coverage check passed for ${registryIds.size} registry-backed smoke IDs.`);
}

main();
