// Repeat-visit attribution matrix: replays the real attribution script and prints what downstream
// readers (stored payload, decorated link, Calendly stamp) see. Usage: node tools/qa/repeat-visit-scenarios.js [plugin-root]
// Documents the open cases in docs/guides/CODE-QUALITY.md §5; it prints, it does not assert.
const vm = require('vm'), fs = require('fs'), path = require('path');
const src = fs.readFileSync(path.join(process.argv[2] || path.join(__dirname, '..', '..'), 'assets/js/clicutcl-attribution.js'), 'utf8');
function browser() {
  const values = new Map(), listeners = {};
  const storage = { getItem: (k) => values.has(k) ? values.get(k) : null, setItem: (k, v) => values.set(k, String(v)), removeItem: (k) => values.delete(k) };
  let cookies = '';
  const document = { forms: [], referrer: '', readyState: 'complete', addEventListener: (t, f) => { listeners[t] = f; }, dispatchEvent() {}, querySelector: () => null, querySelectorAll: () => [] };
  Object.defineProperty(document, 'cookie', { get: () => cookies, set(v) { const p = String(v).split(';', 1)[0]; const n = p.slice(0, p.indexOf('=')); const keep = cookies.split('; ').filter((c) => c && !c.startsWith(n + '=')); if (!/Max-Age=0|expires=Thu, 01 Jan 1970/i.test(v)) keep.push(p); cookies = keep.join('; '); } });
  let uuid = 0;
  const window = { addEventListener() {}, clicutcl_config: { cookieName: 'ct_attribution', cookieDays: 90, requireConsent: false, injectEnabled: false, linkDecorateEnabled: true, linkAllowedDomains: ['calendly.com'], linkAppendToken: false }, crypto: { randomUUID: () => 'u' + (++uuid) }, dataLayer: [] };
  const ctx = { window, document, navigator: { userAgent: 'x', webdriver: false }, localStorage: storage, sessionStorage: storage, URL, Event, CustomEvent: class { constructor(t, o) { this.type = t; this.detail = o && o.detail; } }, console };
  return {
    visit(search, referrer = '') { document.referrer = referrer; window.location = { href: 'https://example.test/' + search, origin: 'https://example.test', protocol: 'https:', hostname: 'example.test', pathname: '/', search }; vm.runInNewContext(src, ctx); },
    link(href) { const at = { href }; const a = { getAttribute: (n) => (n in at ? at[n] : null), setAttribute: (n, v) => { at[n] = String(v); }, hasAttribute: (n) => n in at, removeAttribute: (n) => { delete at[n]; }, href }; listeners.mousedown({ target: { closest: () => a } }); return new URL(at.href).searchParams; },
    data: () => window.ClickTrail.getData(),
  };
}
const scenarios = {
  'Google A -> Google B': [['?gclid=A&utm_source=google&utm_medium=cpc&utm_campaign=g1'], ['?gclid=B&utm_source=google&utm_medium=cpc&utm_campaign=g2']],
  'Google A -> Meta B': [['?gclid=A&utm_source=google&utm_medium=cpc&utm_campaign=g1'], ['?fbclid=B&utm_source=facebook&utm_medium=paid&utm_campaign=m1']],
  'Google A -> UTM-only': [['?gclid=A&utm_source=google&utm_medium=cpc&utm_campaign=g1&utm_term=kw'], ['?utm_source=newsletter&utm_medium=email&utm_campaign=n1']],
  'Google A -> direct': [['?gclid=A&utm_source=google&utm_medium=cpc&utm_campaign=g1'], ['']],
};
for (const [name, visits] of Object.entries(scenarios)) {
  const b = browser();
  visits.forEach(([s, r]) => b.visit(s, r));
  const d = b.data(), l = b.link('https://apply.example.test/f'), c = b.link('https://calendly.com/x/y');
  console.log(`${name}\n  lt: campaign=${d.lt_campaign} term=${d.lt_term || ''} lt_gclid=${d.lt_gclid || ''} lt_fbclid=${d.lt_fbclid || ''}\n  top: gclid=${d.gclid || ''} fbclid=${d.fbclid || ''}\n  link: utm_campaign=${l.get('utm_campaign')} gclid=${l.get('gclid')} fbclid=${l.get('fbclid')}\n  calendly: ${c.get('salesforce_uuid')}`);
}
