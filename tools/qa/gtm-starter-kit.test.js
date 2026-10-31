const assert = require('assert');
const fs = require('fs');
const path = require('path');

const kit = JSON.parse(fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'gtm-starter-kit.json'), 'utf8'));
const cv = kit.containerVersion;

assert.strictEqual(kit.exportFormatVersion, 2, 'exportFormatVersion');
assert.ok(kit.exportTime, 'exportTime');
['accountId', 'containerId', 'builtInVariable', 'customTemplate', 'folder', 'variable', 'trigger', 'tag'].forEach((key) => {
  assert.ok(cv[key] !== undefined, `containerVersion.${key} missing`);
});

const CT_PARAMS = {
  ct_ft_source: '{{DLV - ft_source}}', ct_ft_medium: '{{DLV - ft_medium}}',
  ct_ft_campaign: '{{DLV - ft_campaign}}', ct_ft_channel: '{{DLV - ft_channel}}',
  ct_lt_source: '{{DLV - lt_source}}', ct_lt_medium: '{{DLV - lt_medium}}',
  ct_lt_campaign: '{{DLV - lt_campaign}}', ct_lt_channel: '{{DLV - lt_channel}}',
  ct_click_id: '{{JS - selected click id}}',
};
const varNames = new Set(cv.variable.map((v) => v.name));

function eventSettings(tag) {
  const table = (tag.parameter || []).find((p) => p.key === 'eventSettingsTable');
  const out = {};
  ((table && table.list) || []).forEach((row) => {
    const map = {};
    row.map.forEach((m) => { map[m.key] = m.value; });
    out[map.parameter] = map.parameterValue;
  });
  return out;
}

const ga4 = cv.tag.filter((t) => t.type === 'gaawe');
assert.ok(ga4.length > 0, 'no gaawe tags');
ga4.forEach((tag) => {
  const settings = eventSettings(tag);
  Object.entries(CT_PARAMS).forEach(([name, value]) => {
    assert.strictEqual(settings[name], value, `${tag.name}: ${name}`);
  });
  assert.ok(!Object.keys(settings).some((k) => /^campaign($|_)/.test(k) || k === 'source' || k === 'medium'),
    `${tag.name}: must not override session campaign fields`);
});

// Every {{DLV/JS - ...}} reference in a ct_ param resolves to a defined variable.
Object.values(CT_PARAMS).forEach((ref) => {
  assert.ok(varNames.has(ref.slice(2, -2)), `undefined variable ${ref}`);
});

const meta = cv.tag.filter((t) => t.name.startsWith('Meta Pixel - '));
assert.strictEqual(meta.length, 6, 'Meta tag count');
meta.forEach((tag) => {
  assert.strictEqual(tag.consentSettings.consentStatus, 'NEEDED', `${tag.name}: consentStatus`);
  const types = tag.consentSettings.consentType.list.map((i) => i.value).sort();
  assert.deepStrictEqual(types, ['ad_storage', 'ad_user_data'], `${tag.name}: consentType`);
});

const purchase = meta.find((t) => t.name === 'Meta Pixel - Purchase');
const eventId = purchase.parameter.find((p) => p.key === 'eventId');
assert.strictEqual(eventId.value, '{{DLV - event_id}}', 'Meta Purchase eventId');
assert.ok(varNames.has('DLV - event_id'), 'DLV - event_id defined');

console.log('gtm-starter-kit.test.js: ok');
