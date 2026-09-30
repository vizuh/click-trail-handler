// Fails when assets/css/admin-refresh.css contains a rule that could reach wp-admin
// outside the ClickTrail dashboard wrappers. Allowed exceptions: @font-face and
// Tailwind's --tw-* variable defaults block.
const assert = require('assert');
const fs = require('fs');
const path = require('path');

const css = fs.readFileSync(path.join(__dirname, '..', '..', 'assets/css/admin-refresh.css'), 'utf8')
  .replace(/\/\*[\s\S]*?\*\//g, '');
const SCOPE = ':is(.clicktrail-settings-wrap, .clicktrail-diagnostics-wrap, .clicktrail-logs-wrap)';
const offenders = [];
const re = /([^{}]+)\{([^{}]*)\}/g;
let match;
while ((match = re.exec(css))) {
  const prelude = match[1].trim();
  const body = match[2];
  if (prelude.startsWith('@font-face')) continue;
  prelude.split(/,(?![^(]*\))/).map((s) => s.trim()).filter(Boolean).forEach((selector) => {
    const twDefaults = /^(\*|::before|::after|::backdrop)$/.test(selector) && /^\s*(--tw-[\w-]+:[^;]*;\s*)+$/.test(body);
    if (!selector.startsWith(SCOPE) && !twDefaults) offenders.push(selector);
  });
}
assert.deepStrictEqual(offenders, [], 'unscoped selectors in admin-refresh.css');
assert.ok(!/@import|fonts\.googleapis|https?:\/\//.test(css), 'no remote imports or URLs');
console.log('admin-refresh.css scope check passed.');
