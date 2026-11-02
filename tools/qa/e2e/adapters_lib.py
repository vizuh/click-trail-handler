"""Shared helpers for the form-adapter E2E scenarios (adapters.py): wp-cli bridge, storage deltas, form drivers."""
import base64, json, pathlib, re, subprocess, time

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
    'formidable': {'path': '/form-formidable/', 'platform': 'formidable'},
    # MetForm 4.3.4 block builder (PHP-rendered, FormData post) and Elementor builder (Preact state post).
    'metform': {'path': '/form-metform/', 'platform': 'metform'},
    'metform_el': {'path': '/form-metform-el/', 'platform': 'metform'},
    'sureforms': {'path': '/form-sureforms/', 'platform': 'sureforms'},
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
        "$fr = $wpdb->get_var( \"SELECT IFNULL(MAX(id),0) FROM {$p}frm_items\" ); "
        "$mf = $wpdb->get_var( \"SELECT IFNULL(MAX(ID),0) FROM {$p}posts WHERE post_type='metform-entry'\" ); "
        "return array( 'events' => (int) $wpdb->get_var( \"SELECT IFNULL(MAX(id),0) FROM {$p}clicutcl_events\" ), 'fluent' => (int) $f, 'formidable' => (int) $fr, 'metform' => (int) $mf );"
    )
    ids['provider'] = len(pod_lines('ct-provider.jsonl'))
    ids['capture'] = len(pod_lines('ct-capture.jsonl'))
    ids['sureforms'] = sureforms_max_id()
    return ids


def delta(snap):
    """Everything stored since `snap`: ClickTrail leads, provider-side records, dispatched events."""
    stored = wp_json(
        "global $wpdb; $p = $wpdb->prefix; "
        "$ev = array(); foreach ( $wpdb->get_results( $wpdb->prepare( \"SELECT id, event_data FROM {$p}clicutcl_events WHERE event_type='form_submission' AND id > %%d ORDER BY id\", %d ) ) as $r ) { $d = json_decode( $r->event_data, true ); $d['row_id'] = (int) $r->id; $ev[] = $d; } "
        "$fl = array(); foreach ( $wpdb->get_results( $wpdb->prepare( \"SELECT id, form_id, response FROM {$p}fluentform_submissions WHERE id > %%d ORDER BY id\", %d ) ) as $r ) { "
        "$meta = array(); foreach ( $wpdb->get_results( $wpdb->prepare( \"SELECT meta_key, value FROM {$p}fluentform_submission_meta WHERE response_id = %%d\", $r->id ) ) as $m ) { $meta[ $m->meta_key ] = $m->value; } "
        "$fl[] = array( 'id' => (int) $r->id, 'form_id' => (int) $r->form_id, 'response' => json_decode( $r->response, true ), 'meta' => $meta ); } "
        "$fm = array(); foreach ( $wpdb->get_results( $wpdb->prepare( \"SELECT id, form_id, is_draft FROM {$p}frm_items WHERE id > %%d ORDER BY id\", %d ) ) as $r ) { "
        "$fields = array(); $meta = array(); "
        "foreach ( $wpdb->get_results( $wpdb->prepare( \"SELECT m.field_id, m.meta_value, f.field_key FROM {$p}frm_item_metas m LEFT JOIN {$p}frm_fields f ON f.id = m.field_id WHERE m.item_id = %%d\", $r->id ) ) as $m ) { "
        "if ( (int) $m->field_id === 0 ) { $v = maybe_unserialize( $m->meta_value ); if ( is_array( $v ) ) { $meta = array_merge( $meta, $v ); } } else { $fields[ $m->field_key ] = wp_unslash( $m->meta_value ); } } "
        "$fm[] = array( 'id' => (int) $r->id, 'form_id' => (int) $r->form_id, 'is_draft' => (int) $r->is_draft, 'fields' => $fields, 'meta' => $meta ); } "
        "$mf = array(); foreach ( $wpdb->get_col( \"SELECT ID FROM {$p}posts WHERE post_type='metform-entry' AND ID > %d ORDER BY ID\" ) as $id ) { "
        "$m = array(); foreach ( get_post_meta( (int) $id ) as $k => $v ) { if ( 0 === strpos( $k, 'ct_' ) ) { $m[ $k ] = $v[0]; } } "
        "$mf[] = array( 'id' => (int) $id, 'form_id' => (int) get_post_meta( (int) $id, 'metform_entries__form_id', true ), 'data' => get_post_meta( (int) $id, 'metform_entries__form_data', true ), 'meta' => $m ); } "
        "return array( 'events' => $ev, 'fluent' => $fl, 'formidable' => $fm, 'metform' => $mf );" % (snap['events'], snap['fluent'], snap['formidable'], snap['metform'])
    )
    stored['provider'] = pod_lines('ct-provider.jsonl')[snap['provider']:]
    stored['capture'] = [e for e in pod_lines('ct-capture.jsonl')[snap['capture']:]]
    stored['sureforms'] = sureforms_entries(snap['sureforms'])
    return stored


def sureforms_max_id():
    """Highest SureForms entry ID (0 when the table does not exist)."""
    return wp_json(
        "global $wpdb; $t = $wpdb->prefix . 'srfm_entries'; "
        "return $wpdb->get_var( \"SHOW TABLES LIKE '$t'\" ) ? (int) $wpdb->get_var( \"SELECT IFNULL(MAX(ID),0) FROM $t\" ) : 0;"
    )


def sureforms_entries(after_id):
    """SureForms entries stored after `after_id`: form data, `extras` and submission info as SureForms keeps them."""
    return wp_json(
        "global $wpdb; $t = $wpdb->prefix . 'srfm_entries'; "
        "if ( ! $wpdb->get_var( \"SHOW TABLES LIKE '$t'\" ) ) { return array(); } "
        "$out = array(); foreach ( $wpdb->get_results( $wpdb->prepare( \"SELECT ID, form_id, form_data, extras FROM $t WHERE ID > %%d ORDER BY ID\", %d ) ) as $r ) { "
        "$out[] = array( 'id' => (int) $r->ID, 'form_id' => (int) $r->form_id, 'form_data' => json_decode( $r->form_data, true ), 'extras' => json_decode( $r->extras, true ), 'extras_raw' => $r->extras ); } "
        "return $out;" % int(after_id)
    )


def sureforms_label(name):
    """The `ct_*` label SureForms encodes in a field key (`srfm-<type>-<id>-lbl-<base64 label>-<slug>`), else ''."""
    m = re.search(r'-lbl-([A-Za-z0-9+/]*)-', name or '')
    if not m:
        return ''
    raw = m.group(1)
    try:
        label = base64.b64decode(raw + '=' * (-len(raw) % 4)).decode('utf-8', 'replace')
    except Exception:
        return ''
    return label if label.startswith('ct_') else ''


def new_context(browser):
    ctx = browser.new_context()
    # ClickTrail skips automated browsers by design (navigator.webdriver); present as a normal browser.
    ctx.add_init_script(WEBDRIVER_MASK)
    return ctx


def ct_inputs(page):
    """Every ClickTrail-marked input in the first form: by name (`ct_*`), by a `ct_*` class on the field wrapper, or by a SureForms key whose encoded label is `ct_*`."""
    return page.evaluate(
        """() => {
            const out = {};
            for (const el of document.querySelectorAll('form input:not([type=submit]):not([type=button]), form textarea')) {
                let label = (el.name || '').startsWith('ct_') ? el.name : null;
                // Formidable names inputs item_meta[<field id>]; the field key is in the input id (field_<key>).
                if (!label && (el.id || '').startsWith('field_ct_')) label = el.id.slice('field_'.length);
                const sf = !label && /-lbl-([A-Za-z0-9+/]*)-/.exec(el.name || '');
                if (sf) { try { const t = atob(sf[1] + '='.repeat((4 - sf[1].length % 4) % 4)); if (t.startsWith('ct_')) label = t; } catch (e) {} }
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
    elif provider == 'formidable':
        page.fill('#field_e2e_name', 'E2E ' + tag)
        page.fill('#field_e2e_email', email)
    elif provider.startswith('metform'):
        # The Elementor builder keeps field values in Preact state written on change/blur: type, then blur.
        for name, value in (('mf-name', 'E2E ' + tag), ('mf-email', email)):
            field = page.locator('input[name="%s"]' % name)
            field.click()
            field.press_sequentially(value, delay=10)
            field.blur()
    elif provider == 'sureforms':
        page.fill('input[name^="srfm-input-"][name$="-name"]', 'E2E ' + tag)
        page.fill('input[name^="srfm-email-"]', email)
    else:
        page.fill('input[name="wpforms[fields][1]"]', 'E2E ' + tag)
        # WPForms' mail-check widget blocks unknown TLDs such as .test with a "Did you mean" prompt.
        page.fill('input[name="wpforms[fields][2]"]', '%s@example.com' % tag)
        page.locator('input[name="wpforms[fields][2]"]').blur()


def submit_button(provider):
    return {
        'cf7': 'input[type="submit"].wpcf7-submit',
        'fluent': 'button.ff-btn-submit',
        'formidable': '.frm_button_submit',
        'metform': 'button.mfb-button[type="submit"]',
        'metform_el': 'button.metform-submit-btn',
        'sureforms': 'button.srfm-submit-button',
    }.get(provider, 'button.wpforms-submit')


def wait_done(page, provider, timeout=25000):
    """Wait for the provider's own outcome marker; returns 'sent' or the failing state."""
    if provider == 'cf7':
        page.wait_for_selector('form.sent, form.failed, form.invalid, form.spam, form.aborted', timeout=timeout)
        return 'sent' if page.locator('form.sent').count() else page.get_attribute('form.wpcf7-form', 'class')
    if provider == 'fluent':
        page.wait_for_selector('.ff-message-success, .ff-errors-in-stack, .ff-el-is-error', timeout=timeout)
        return 'sent' if page.locator('.ff-message-success').count() else 'failed: ' + page.inner_text('form, .ff-errors-in-stack')[:200]
    if provider == 'formidable':
        page.wait_for_selector('.frm_message, .frm_error_style, .frm_error', timeout=timeout)
        return 'sent' if page.locator('.frm_message').count() else 'failed: ' + page.inner_text('form, .frm_error_style')[:200]
    if provider.startswith('metform'):
        # Block builder: `.mfb-form__response` ends in data-state success or error; Elementor builder: `.mf-main-response-wrap[data-show="1"]`.
        done = {
            'metform': '.mfb-form__response[data-state="success"], .mfb-form__response[data-state="error"]',
            'metform_el': '.mf-main-response-wrap[data-show="1"]',
        }[provider]
        page.wait_for_selector(done, timeout=timeout)
        text = page.inner_text(done)
        return 'sent' if 'E2E thanks' in text else 'failed: ' + text[:200]
    if provider == 'sureforms':
        try:
            page.wait_for_selector('.srfm-success-box', timeout=timeout)
            return 'sent'
        except Exception:
            return 'failed: ' + page.inner_text('form, .srfm-form-container')[:200]
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
