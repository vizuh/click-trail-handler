// HubSpot embedded form capture (specs/003-hubspot-form-capture).
//
// Runs the real events script in a vm with a stubbed consent bridge and HubSpotFormsV4,
// dispatches HubSpot's documented v4 window events and legacy hsFormCallback messages,
// and asserts what reaches window.dataLayer.
const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const source = fs.readFileSync(path.join(__dirname, '..', '..', 'assets/js/clicutcl-events.js'), 'utf8');
const SUCCESS = 'hs-form-event:on-submission:success';
const FAILED = 'hs-form-event:on-submission:failed';
const FORM_ID = 'df93f0c1-2919-44ef-9446-6a29f9a7f0a1';

class Bus {
  constructor() { this.listeners = Object.create(null); }
  addEventListener(type, fn) {
    if (typeof fn !== 'function') return;
    (this.listeners[type] || (this.listeners[type] = [])).push(fn);
  }
  removeEventListener(type, fn) {
    this.listeners[type] = (this.listeners[type] || []).filter((entry) => entry !== fn);
  }
  dispatchEvent(event) {
    (this.listeners[event.type] || []).slice().forEach((fn) => fn.call(this, event));
    return true;
  }
}

class FakeStorage {
  constructor() { this.values = Object.create(null); }
  getItem(key) { return key in this.values ? this.values[key] : null; }
  setItem(key, value) { this.values[key] = String(value); }
  removeItem(key) { delete this.values[key]; }
  key(index) { return Object.keys(this.values)[index] || null; }
  get length() { return Object.keys(this.values).length; }
}

// conversions: map of instanceId -> value returned by getConversionId(); omit for no HubSpotFormsV4.
function boot({ granted = true, conversions, readyState = 'complete' } = {}) {
  const consent = { granted };
  const document = new Bus();
  Object.assign(document, {
    readyState,
    referrer: '',
    title: 'Quote',
    cookie: '',
    querySelectorAll: () => [],
    querySelector: () => null,
  });
  const window = new Bus();
  Object.assign(window, {
    document,
    location: { href: 'https://example.test/quote', search: '', protocol: 'https:', hostname: 'example.test', origin: 'https://example.test', pathname: '/quote' },
    navigator: { userAgent: 'Mozilla/5.0', maxTouchPoints: 0 },
    innerWidth: 1280,
    innerHeight: 800,
    crypto: require('crypto').webcrypto,
    localStorage: new FakeStorage(),
    sessionStorage: new FakeStorage(),
    dataLayer: [],
    console: { log() {}, warn() {}, error() {} },
    setTimeout: () => 0,
    clearTimeout() {},
    URL,
    URLSearchParams,
    clicutclEventsConfig: { enabled: true, iframeOrigins: ['calendly.com', 'typeform.com', 'hubspot.com', 'hsforms.net'] },
    ClickTrailConsent: {
      isResolved: () => true,
      isGranted: () => consent.granted,
      getState: () => ({ marketing: consent.granted, analytics: consent.granted }),
    },
  });
  window.window = window;
  if (conversions) {
    window.HubSpotFormsV4 = {
      getFormFromEvent: (event) => ({ getConversionId: () => conversions[event.detail.instanceId] }),
    };
  }
  vm.runInContext(source, vm.createContext(window), { filename: 'clicutcl-events.js' });

  const v4 = (type, formId = FORM_ID, instanceId = 'inst-1') =>
    window.dispatchEvent({ type, detail: { formId, instanceId } });
  const legacy = (data, origin = 'https://example.test') =>
    window.dispatchEvent({ type: 'message', origin, data });
  const pushed = (name) => window.dataLayer.filter((entry) => !name || entry.event === name);
  return { window, document, consent, v4, legacy, pushed };
}

const submitted = (id = FORM_ID) => ({
  type: 'hsFormCallback',
  eventName: 'onFormSubmitted',
  id,
  data: { redirectUrl: '', submissionValues: { email: 'jane@example.test', firstname: 'Jane' } },
});

const tests = {
  'listeners are registered at script load, before the tracker boots'() {
    const page = boot({ readyState: 'loading' });
    assert.ok((page.window.listeners[SUCCESS] || []).length, 'success listener exists at script load');
    assert.ok((page.window.listeners[FAILED] || []).length, 'failed listener exists at script load');
  },

  'v4 success pushes one lead with the conversion ID as event_id'() {
    const page = boot({ conversions: { 'inst-1': 'conv-123' } });
    page.v4(SUCCESS);
    const leads = page.pushed('lead');
    assert.strictEqual(leads.length, 1);
    assert.strictEqual(leads[0].event_id, 'hsform_conv-123');
    assert.strictEqual(leads[0].form_id, FORM_ID);
    assert.strictEqual(leads[0].form_provider, 'hubspot');
    assert.deepStrictEqual(
      { ...leads[0].lead_context },
      { provider: 'hubspot', form_id: FORM_ID, submit_status: 'success' }
    );
    assert.strictEqual(leads[0].marketing_trail.form.provider, 'hubspot');
    assert.strictEqual(page.pushed('qualified_lead').length, 0, 'the browser never pushes qualified_lead');
  },

  'without a conversion ID event_id falls back to form and instance IDs'() {
    const page = boot({ conversions: { 'inst-1': '' } });
    page.v4(SUCCESS);
    const leads = page.pushed('lead');
    assert.strictEqual(leads.length, 1);
    assert.match(leads[0].event_id, new RegExp('^hsform_' + FORM_ID + '\\|inst-1\\|[0-9a-f]{12}$'));
  },

  'without HubSpotFormsV4 the event still yields one lead'() {
    const page = boot();
    page.v4(SUCCESS);
    assert.strictEqual(page.pushed('lead').length, 1);
    assert.ok(page.pushed('lead')[0].event_id.startsWith('hsform_' + FORM_ID + '|inst-1|'));
  },

  'a duplicate success dispatch pushes one lead'() {
    const page = boot({ conversions: { 'inst-1': 'conv-123' } });
    page.v4(SUCCESS);
    page.v4(SUCCESS);
    assert.strictEqual(page.pushed('lead').length, 1);

    const fallback = boot();
    fallback.v4(SUCCESS);
    fallback.v4(SUCCESS);
    assert.strictEqual(fallback.pushed('lead').length, 1, 'fallback IDs are stable within the page');
  },

  'a legacy message for the same submission does not push a second lead'() {
    const page = boot({ conversions: { 'inst-1': 'conv-123' } });
    page.v4(SUCCESS);
    page.legacy(submitted());
    assert.strictEqual(page.pushed('lead').length, 1);

    const reverse = boot({ conversions: { 'inst-1': 'conv-123' } });
    reverse.legacy(submitted());
    reverse.v4(SUCCESS);
    assert.strictEqual(reverse.pushed('lead').length, 1);
  },

  'a different form still counts'() {
    const page = boot({ conversions: { 'inst-1': 'conv-1', 'inst-2': 'conv-2' } });
    page.v4(SUCCESS);
    page.v4(SUCCESS, 'other-form', 'inst-2');
    assert.deepStrictEqual(page.pushed('lead').map((e) => e.form_id), [FORM_ID, 'other-form']);
  },

  'failed submission pushes form_submit_failed and never a lead'() {
    const page = boot({ conversions: { 'inst-1': 'conv-123' } });
    page.v4(FAILED);
    assert.strictEqual(page.pushed('lead').length, 0);
    const failed = page.pushed('form_submit_failed');
    assert.strictEqual(failed.length, 1);
    assert.strictEqual(failed[0].lead_context.submit_status, 'failed');
    assert.strictEqual(failed[0].lead_context.provider, 'hubspot');
    assert.strictEqual(failed[0].form_id, FORM_ID);
  },

  'events without a formId are ignored'() {
    const page = boot();
    page.window.dispatchEvent({ type: SUCCESS, detail: {} });
    page.window.dispatchEvent({ type: SUCCESS });
    assert.strictEqual(page.pushed().length, 0);
  },

  'legacy onFormSubmitted pushes a lead and reads no field values'() {
    const page = boot();
    page.legacy(submitted());
    const leads = page.pushed('lead');
    assert.strictEqual(leads.length, 1);
    assert.strictEqual(leads[0].form_id, FORM_ID);
    assert.strictEqual(leads[0].lead_context.provider, 'hubspot');
    assert.ok(leads[0].event_id.startsWith('hsform_' + FORM_ID + '|legacy|'));
    assert.ok(!JSON.stringify(page.window.dataLayer).includes('jane@example.test'), 'no submitted values are copied');
  },

  'other hsFormCallback event names are ignored'() {
    const page = boot();
    ['onBeforeFormInit', 'onFormReady', 'onBeforeFormSubmit', 'onFormSubmit'].forEach((eventName) => {
      page.legacy({ type: 'hsFormCallback', eventName, id: FORM_ID, data: [] });
    });
    page.legacy({ type: 'hsFormCallback', eventName: 'onFormSubmitted', data: {} });
    assert.strictEqual(page.pushed().length, 0);
  },

  'legacy messages follow the external-message origin rule'() {
    const page = boot();
    page.legacy(submitted(), 'https://evil.example');
    assert.strictEqual(page.pushed().length, 0, 'untrusted origin is dropped');
    page.legacy(submitted(), 'https://js-na1.hsforms.net');
    assert.strictEqual(page.pushed('lead').length, 1, 'allowlisted HubSpot form host is accepted');
  },

  'consent denied at boot pushes nothing'() {
    const page = boot({ granted: false, conversions: { 'inst-1': 'conv-123' } });
    page.v4(SUCCESS);
    page.v4(FAILED);
    page.legacy(submitted());
    assert.strictEqual(page.pushed().length, 0);
  },

  'consent withdrawn after boot pushes nothing'() {
    const page = boot({ conversions: { 'inst-1': 'conv-123' } });
    page.consent.granted = false;
    page.v4(SUCCESS);
    page.v4(FAILED);
    page.legacy(submitted());
    assert.strictEqual(page.pushed().length, 0);
  },
};

let failed = 0;
Object.entries(tests).forEach(([name, run]) => {
  try {
    run();
    console.log('ok   ' + name);
  } catch (error) {
    failed += 1;
    console.error('FAIL ' + name + '\n' + (error && error.stack ? error.stack : error));
  }
});
if (failed) {
  console.error(failed + ' HubSpot form capture check(s) failed.');
  process.exit(1);
}
console.log('HubSpot form capture checks passed.');
