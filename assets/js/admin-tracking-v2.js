(function (wp, config) {
    'use strict';

    if (!wp || !wp.element || !wp.components) {
        return;
    }

    var el = wp.element.createElement;
    var useState = wp.element.useState;
    var __ = wp.i18n && wp.i18n.__ ? wp.i18n.__ : function (s) { return s; };

    var Button   = wp.components.Button;
    var Notice   = wp.components.Notice;
    var Spinner  = wp.components.Spinner;
    var TextControl    = wp.components.TextControl;
    var TextareaControl = wp.components.TextareaControl;
    var ToggleControl  = wp.components.ToggleControl;

    function deepClone(value) {
        try { return JSON.parse(JSON.stringify(value || {})); } catch (e) { return {}; }
    }

    function getIn(obj, path, fallback) {
        var parts = String(path || '').split('.');
        var cursor = obj;
        for (var i = 0; i < parts.length; i++) {
            var key = parts[i];
            if (!cursor || typeof cursor !== 'object' || !(key in cursor)) { return fallback; }
            cursor = cursor[key];
        }
        return cursor;
    }

    function setIn(obj, path, value) {
        var parts = String(path || '').split('.');
        var cursor = obj;
        for (var i = 0; i < parts.length - 1; i++) {
            var key = parts[i];
            if (!cursor[key] || typeof cursor[key] !== 'object') { cursor[key] = {}; }
            cursor = cursor[key];
        }
        cursor[parts[parts.length - 1]] = value;
    }

    function splitList(input) {
        if (Array.isArray(input)) { return input.map(function (v) { return String(v).trim(); }).filter(Boolean); }
        return String(input || '').split(/[\r\n,]+/).map(function (s) { return s.trim(); }).filter(Boolean);
    }

    function joinList(input) { return splitList(input).join('\n'); }

    function postAjax(action, payload) {
        var body = new URLSearchParams();
        body.set('action', action);
        body.set('nonce', String(config.nonce || ''));
        Object.keys(payload || {}).forEach(function (k) { body.set(k, payload[k]); });
        return fetch(String(config.ajaxUrl || ''), {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).then(function (res) { return res.json(); });
    }

    // Card shell using our .clicktrail-card CSS identity
    function Card(props) {
        var iconCls = props.icon || 'dashicons-admin-generic';
        var title   = props.title || '';
        var desc    = props.description || '';
        return el('section', { key: props.cardKey, className: 'clicktrail-card' }, [
            el('div', { key: 'hd', className: 'clicktrail-card__header clicktrail-card__header--static' },
                el('span', { className: 'clicktrail-card__header-main' }, [
                    el('span', { key: 'icon', className: 'clicktrail-card__icon dashicons ' + iconCls, 'aria-hidden': 'true' }),
                    el('span', { key: 'hdg', className: 'clicktrail-card__heading' }, [
                        el('span', { key: 'title', className: 'clicktrail-card__title' }, title),
                        desc ? el('span', { key: 'desc', className: 'clicktrail-card__description' }, desc) : null
                    ])
                ])
            ),
            el('div', { key: 'bd', className: 'clicktrail-card__body clicktrail-card__body--react' },
                props.children
            )
        ]);
    }

    function App(props) {
        var mode = (props && props.mode) || 'full';

        var stateArr      = useState(deepClone(config.settings || {}));
        var stateSettings = stateArr[0], setStateSettings = stateArr[1];

        var loadingArr = useState(false);
        var loading = loadingArr[0], setLoading = loadingArr[1];

        var savingArr = useState(false);
        var saving = savingArr[0], setSaving = savingArr[1];

        var noticeArr = useState(null);
        var notice = noticeArr[0], setNotice = noticeArr[1];

        function update(path, value) {
            setStateSettings(function (prev) {
                var next = deepClone(prev);
                setIn(next, path, value);
                return next;
            });
        }

        function reload() {
            setLoading(true);
            setNotice(null);
            postAjax('clicutcl_get_tracking_v2_settings', {})
                .then(function (json) {
                    if (!json || !json.success) { throw new Error((json && json.data && json.data.message) || 'load_failed'); }
                    setStateSettings(deepClone(json.data.settings || {}));
                    setNotice({ status: 'success', message: __('Settings loaded.', 'click-trail-handler') });
                })
                .catch(function () { setNotice({ status: 'error', message: __('Failed to load settings.', 'click-trail-handler') }); })
                .finally(function () { setLoading(false); });
        }

        function save() {
            setSaving(true);
            setNotice(null);
            postAjax('clicutcl_save_tracking_v2_settings', { settings: JSON.stringify(stateSettings || {}) })
                .then(function (json) {
                    if (!json || !json.success) { throw new Error((json && json.data && json.data.message) || 'save_failed'); }
                    setStateSettings(deepClone(json.data.settings || {}));
                    setNotice({ status: 'success', message: (json.data && json.data.message) || __('Settings saved.', 'click-trail-handler') });
                })
                .catch(function () { setNotice({ status: 'error', message: __('Failed to save settings.', 'click-trail-handler') }); })
                .finally(function () { setSaving(false); });
        }

        var featureFlags  = getIn(stateSettings, 'feature_flags', {});
        var security      = getIn(stateSettings, 'security', {});
        var diagnostics   = getIn(stateSettings, 'diagnostics', {});
        var dedup         = getIn(stateSettings, 'dedup', {});
        var lifecycle     = getIn(stateSettings, 'lifecycle.crm_ingestion', {});
        var providers     = getIn(stateSettings, 'external_forms.providers', {});
        var destinations  = getIn(stateSettings, 'destinations', {});

        function renderFlag(flagKey, label, description) {
            return el(ToggleControl, {
                key: flagKey,
                label: label,
                help: description || null,
                checked: !!featureFlags[flagKey],
                onChange: function (v) { update('feature_flags.' + flagKey, !!v); }
            });
        }

        function renderDestination(key, label) {
            return el(ToggleControl, {
                key: key,
                label: label,
                checked: !!getIn(destinations, key + '.enabled', false),
                onChange: function (v) { update('destinations.' + key + '.enabled', !!v); }
            });
        }

        function renderProvider(key, label) {
            return el('div', { key: key, className: 'ct-v2-provider' }, [
                el(ToggleControl, {
                    key: key + '_enabled',
                    label: label,
                    checked: !!getIn(providers, key + '.enabled', false),
                    onChange: function (v) { update('external_forms.providers.' + key + '.enabled', !!v); }
                }),
                el(TextControl, {
                    key: key + '_secret',
                    label: __('Webhook Secret', 'click-trail-handler'),
                    value: String(getIn(providers, key + '.secret', '')),
                    onChange: function (v) { update('external_forms.providers.' + key + '.secret', v); }
                })
            ]);
        }

        // Destinations card
        var destinationsCard = el(Card, {
            cardKey: 'destinations',
            icon: 'dashicons-share',
            title: __('Advertising platforms', 'click-trail-handler'),
            description: __('Each enabled platform receives its own payload — formatted independently, with consent signals already applied.', 'click-trail-handler')
        }, [
            renderDestination('meta', 'Meta (Facebook Ads)'),
            renderDestination('google', 'Google Ads / GA4'),
            renderDestination('linkedin', 'LinkedIn Ads'),
            renderDestination('reddit', 'Reddit Ads'),
            renderDestination('pinterest', 'Pinterest Ads')
        ]);

        // Full-mode cards (all except destinations)
        var fullCards = [
            el(Card, {
                cardKey: 'flags',
                icon: 'dashicons-flag',
                title: __('Feature flags', 'click-trail-handler'),
                description: __('Enable or disable delivery pipeline capabilities for this installation.', 'click-trail-handler')
            }, [
                renderFlag('event_v2',           __('Event v2 intake', 'click-trail-handler'),           __('Normalize all incoming events through the v2 schema before delivery.', 'click-trail-handler')),
                renderFlag('external_webhooks',   __('External webhooks', 'click-trail-handler'),          __('Accept inbound payloads from external form providers.', 'click-trail-handler')),
                renderFlag('connector_native',    __('Native connectors', 'click-trail-handler'),          __('Use built-in platform connectors instead of generic HTTP dispatch.', 'click-trail-handler')),
                renderFlag('diagnostics_v2',      __('Diagnostics v2', 'click-trail-handler'),             __('Store structured debug entries in the v2 intake buffer.', 'click-trail-handler')),
                renderFlag('lifecycle_ingestion', __('Lifecycle ingestion', 'click-trail-handler'),        __('Accept CRM lifecycle events via the REST endpoint.', 'click-trail-handler'))
            ]),
            el(Card, {
                cardKey: 'providers',
                icon: 'dashicons-feedback',
                title: __('External form providers', 'click-trail-handler'),
                description: __('Receive and attribute form submissions from third-party platforms via signed webhooks.', 'click-trail-handler')
            }, [
                renderProvider('calendly', 'Calendly'),
                renderProvider('hubspot',  'HubSpot'),
                renderProvider('typeform', 'Typeform')
            ]),
            el(Card, {
                cardKey: 'lifecycle',
                icon: 'dashicons-update',
                title: __('Lifecycle ingestion', 'click-trail-handler'),
                description: __('Accept CRM lifecycle events (deals won, trial conversions) and route them through the delivery pipeline.', 'click-trail-handler')
            }, [
                el(ToggleControl, {
                    key: 'crm_enabled',
                    label: __('Enable CRM lifecycle endpoint', 'click-trail-handler'),
                    checked: !!lifecycle.enabled,
                    onChange: function (v) { update('lifecycle.crm_ingestion.enabled', !!v); }
                }),
                el(TextControl, {
                    key: 'crm_token',
                    label: __('CRM token', 'click-trail-handler'),
                    value: String(lifecycle.token || ''),
                    onChange: function (v) { update('lifecycle.crm_ingestion.token', v); }
                })
            ]),
            el(Card, {
                cardKey: 'security',
                icon: 'dashicons-shield',
                title: __('Security', 'click-trail-handler'),
                description: __('Token expiry, replay protection, rate limiting, and trusted proxy configuration.', 'click-trail-handler')
            }, [
                el(TextControl, { key: 'token_ttl',       label: __('Token TTL (seconds)', 'click-trail-handler'),           type: 'number', value: String(security.token_ttl_seconds || ''),   onChange: function (v) { update('security.token_ttl_seconds', v); } }),
                el(TextControl, { key: 'nonce_limit',     label: __('Token nonce limit (0 = off)', 'click-trail-handler'),    type: 'number', value: String(security.token_nonce_limit || 0),    onChange: function (v) { update('security.token_nonce_limit', v); } }),
                el(TextControl, { key: 'replay_window',   label: __('Webhook replay window (seconds)', 'click-trail-handler'), type: 'number', value: String(security.webhook_replay_window || ''), onChange: function (v) { update('security.webhook_replay_window', v); } }),
                el(TextControl, { key: 'rate_window',     label: __('API rate window (seconds)', 'click-trail-handler'),       type: 'number', value: String(security.rate_limit_window || ''),   onChange: function (v) { update('security.rate_limit_window', v); } }),
                el(TextControl, { key: 'rate_limit',      label: __('API rate limit (req/window)', 'click-trail-handler'),     type: 'number', value: String(security.rate_limit_limit || ''),    onChange: function (v) { update('security.rate_limit_limit', v); } }),
                el(TextareaControl, { key: 'proxies',     label: __('Trusted proxies (one per line)', 'click-trail-handler'),  value: joinList(security.trusted_proxies || []),                   onChange: function (v) { update('security.trusted_proxies', splitList(v)); } }),
                el(TextareaControl, { key: 'token_hosts', label: __('Allowed token hosts (one per line)', 'click-trail-handler'), value: joinList(security.allowed_token_hosts || []),            onChange: function (v) { update('security.allowed_token_hosts', splitList(v)); } })
            ]),
            el(Card, {
                cardKey: 'ops',
                icon: 'dashicons-chart-area',
                title: __('Diagnostics & deduplication', 'click-trail-handler'),
                description: __('Buffering, retention, and dedup settings for the delivery pipeline.', 'click-trail-handler')
            }, [
                el(TextControl, { key: 'buf',      label: __('Dispatch buffer size', 'click-trail-handler'),       type: 'number', value: String(diagnostics.dispatch_buffer_size || ''),    onChange: function (v) { update('diagnostics.dispatch_buffer_size', v); } }),
                el(TextControl, { key: 'flush',    label: __('Failure flush interval (seconds)', 'click-trail-handler'), type: 'number', value: String(diagnostics.failure_flush_interval || ''), onChange: function (v) { update('diagnostics.failure_flush_interval', v); } }),
                el(TextControl, { key: 'retain',   label: __('Failure bucket retention (hours)', 'click-trail-handler'), type: 'number', value: String(diagnostics.failure_bucket_retention || ''), onChange: function (v) { update('diagnostics.failure_bucket_retention', v); } }),
                el(TextControl, { key: 'dedup_ttl',label: __('Dedup TTL (seconds)', 'click-trail-handler'),           type: 'number', value: String(dedup.ttl_seconds || ''),                onChange: function (v) { update('dedup.ttl_seconds', v); } })
            ])
        ];

        var visibleCards = mode === 'destinations'
            ? [destinationsCard]
            : fullCards;

        return el('div', { className: 'clicutcl-tracking-v2' }, [
            notice ? el(Notice, {
                key: 'notice',
                status: notice.status || 'info',
                isDismissible: true,
                onRemove: function () { setNotice(null); }
            }, notice.message || '') : null,
            loading ? el('div', { key: 'loading', style: { marginBottom: '12px' } }, el(Spinner)) : null
        ].concat(visibleCards).concat([
            el('div', { key: 'actions', className: 'clicktrail-save-bar' }, [
                el(Button, { key: 'save', variant: 'primary', isBusy: saving, disabled: saving || loading, onClick: save }, __('Save Settings', 'click-trail-handler')),
                el(Button, { key: 'reload', variant: 'secondary', disabled: saving || loading, onClick: reload }, __('Reload', 'click-trail-handler'))
            ])
        ]));
    }

    function mount(id, mode) {
        var root = document.getElementById(id);
        if (!root) { return; }
        var app = el(App, { mode: mode });
        if (typeof wp.element.createRoot === 'function') {
            wp.element.createRoot(root).render(app);
        } else if (typeof wp.element.render === 'function') {
            wp.element.render(app, root);
        }
    }

    function boot() {
        mount('clicutcl-tracking-v2-root', 'full');
        mount('clicutcl-destinations-v2-root', 'destinations');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})(window.wp, window.clicutclTrackingV2Config || {});
