"""Shared helpers for the form-adapter E2E scenarios (adapters.py): wp-cli bridge, storage deltas, form drivers."""
import json, pathlib, subprocess, time

HERE = pathlib.Path(__file__).resolve().parent
BASE = 'http://127.0.0.1:8088'
WEBDRIVER_MASK = "Object.defineProperty(navigator, 'webdriver', { get: () => false })"
MARK = '@@JSON@@'

# slug, ClickTrail platform label, what the form is
PROVIDERS = {
    'cf7': {'path': '/form-cf7/', 'platform': 'contact-form-7'},
    'fluent': {'path': '/form-fluent/', 'platform': 'fluentform'},
    'wpforms_hidden': {'path': '/form-wpforms/', 'platform': 'wpforms'},
    'wpforms_text': {'path': '/form-wpforms-text/', 'platform': 'wpforms'},
}
CT_FIELDS = ('gclid', 'fbclid', 'ft_source', 'lt_source', 'lt_medium', 'attribution_selected_click_id')


def wp(*args, check=True):
    """Run wp-cli inside the pod; returns stdout."""
    proc = subprocess.run([str(HERE / 'wp.sh'), *args], capture_output=True, text=True)
    if check and proc.returncode != 0:
        raise RuntimeError('wp %s failed: %s' % (' '.join(args), proc.stderr[-800:]))
    return proc.stdout


def wp_json(php):
    """Evaluate PHP that echoes JSON after the marker; returns the decoded value."""
    out = wp('eval', "echo '%s' . wp_json_encode( (function() { %s })() );" % (MARK, php))
    return json.loads(out.split(MARK, 1)[1])


def pod_lines(name):
    proc = subprocess.run(['podman', 'exec', 'ct-e2e-wp', 'cat', '/var/www/html/wp-content/' + name], capture_output=True, text=True)
    return [json.loads(line) for line in proc.stdout.splitlines() if line.strip()] if proc.returncode == 0 else []


def snapshot():
    ids = wp_json(
        "global $wpdb; $p = $wpdb->prefix; "
        "$f = $wpdb->get_var( \"SELECT IFNULL(MAX(id),0) FROM {$p}fluentform_submissions\" ); "
        "return array( 'events' => (int) $wpdb->get_var( \"SELECT IFNULL(MAX(id),0) FROM {$p}clicutcl_events\" ), 'fluent' => (int) $f );"
    )
    ids['provider'] = len(pod_lines('ct-provider.jsonl'))
    ids['capture'] = len(pod_lines('ct-capture.jsonl'))
    return ids


def delta(snap):
    """Everything stored since `snap`: ClickTrail leads, provider-side records, dispatched events."""
    stored = wp_json(
        "global $wpdb; $p = $wpdb->prefix; "
        "$ev = array(); foreach ( $wpdb->get_results( $wpdb->prepare( \"SELECT id, event_data FROM {$p}clicutcl_events WHERE event_type='form_submission' AND id > %%d ORDER BY id\", %d ) ) as $r ) { $d = json_decode( $r->event_data, true ); $d['row_id'] = (int) $r->id; $ev[] = $d; } "
        "$fl = array(); foreach ( $wpdb->get_results( $wpdb->prepare( \"SELECT id, form_id, response FROM {$p}fluentform_submissions WHERE id > %%d ORDER BY id\", %d ) ) as $r ) { "
        "$meta = array(); foreach ( $wpdb->get_results( $wpdb->prepare( \"SELECT meta_key, value FROM {$p}fluentform_submission_meta WHERE response_id = %%d\", $r->id ) ) as $m ) { $meta[ $m->meta_key ] = $m->value; } "
        "$fl[] = array( 'id' => (int) $r->id, 'form_id' => (int) $r->form_id, 'response' => json_decode( $r->response, true ), 'meta' => $meta ); } "
        "return array( 'events' => $ev, 'fluent' => $fl );" % (snap['events'], snap['fluent'])
    )
    stored['provider'] = pod_lines('ct-provider.jsonl')[snap['provider']:]
    stored['capture'] = [e for e in pod_lines('ct-capture.jsonl')[snap['capture']:]]
    return stored


def new_context(browser):
    ctx = browser.new_context()
    # ClickTrail skips automated browsers by design (navigator.webdriver); present as a normal browser.
    ctx.add_init_script(WEBDRIVER_MASK)
    return ctx


def ct_inputs(page):
    """Every ClickTrail-marked input in the first form: by name (`ct_*`) or by a `ct_*` class on the field wrapper."""
    return page.evaluate(
        """() => {
            const out = {};
            for (const el of document.querySelectorAll('form input:not([type=submit]):not([type=button]), form textarea')) {
                let label = (el.name || '').startsWith('ct_') ? el.name : null;
                if (!label) {
                    const wrap = el.closest('[class*="ct_"]');
                    const tok = wrap && [...wrap.classList].find((c) => c.startsWith('ct_'));
                    label = tok || null;
                }
                if (label) out[label] = el.value;
            }
            return out;
        }"""
    )


def fill(page, provider, tag):
    email = '%s@example.test' % tag
    if provider == 'cf7':
        page.fill('input[name="your-name"]', 'E2E ' + tag)
        page.fill('input[name="your-email"]', email)
        if page.locator('input[name="your-subject"]').count():
            page.fill('input[name="your-subject"]', tag)
        page.fill('textarea[name="your-message"]', 'adapter evidence ' + tag)
    elif provider == 'fluent':
        page.fill('input[name="your_name"]', 'E2E ' + tag)
        page.fill('input[name="email"]', email)
    else:
        page.fill('input[name="wpforms[fields][1]"]', 'E2E ' + tag)
        # WPForms' mail-check widget blocks unknown TLDs such as .test with a "Did you mean" prompt.
        page.fill('input[name="wpforms[fields][2]"]', '%s@example.com' % tag)
        page.locator('input[name="wpforms[fields][2]"]').blur()


def submit_button(provider):
    return {
        'cf7': 'input[type="submit"].wpcf7-submit',
        'fluent': 'button.ff-btn-submit',
    }.get(provider, 'button.wpforms-submit')


def wait_done(page, provider, timeout=25000):
    """Wait for the provider's own outcome marker; returns 'sent' or the failing state."""
    if provider == 'cf7':
        page.wait_for_selector('form.sent, form.failed, form.invalid, form.spam, form.aborted', timeout=timeout)
        return 'sent' if page.locator('form.sent').count() else page.get_attribute('form.wpcf7-form', 'class')
    if provider == 'fluent':
        page.wait_for_selector('.ff-message-success, .ff-errors-in-stack, .ff-el-is-error', timeout=timeout)
        return 'sent' if page.locator('.ff-message-success').count() else 'failed: ' + page.inner_text('form, .ff-errors-in-stack')[:200]
    page.wait_for_selector('.wpforms-confirmation-container-full, .wpforms-confirmation-container, .wpforms-error-container, .wpforms-has-error', timeout=timeout)
    return 'sent' if page.locator('.wpforms-confirmation-container-full, .wpforms-confirmation-container').count() else 'failed'


def submit(page, provider, tag):
    fill(page, provider, tag)
    page.click(submit_button(provider))
    return wait_done(page, provider)


def settle(seconds=1.5):
    """Server-side dispatch and wp-cron-less work finish inside the request; this only absorbs REST/ajax tails."""
    time.sleep(seconds)


def set_consent_mode(enabled):
    mode = {
        'enabled': bool(enabled), 'mode': 'strict', 'regions': ['EEA', 'UK'], 'cmp_source': 'plugin',
        'cmp_timeout_ms': 3000, 'cookie_name': 'ct_consent', 'gcm_analytics_key': 'analytics_storage',
    }
    wp('option', 'update', 'clicutcl_consent_mode', json.dumps(mode), '--format=json')
