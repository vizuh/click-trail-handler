(function (wp, config) {
    'use strict';

    if (!wp || !wp.element || !wp.components) {
        return;
    }

    const el = wp.element.createElement;
    const useState = wp.element.useState;
    const __ = wp.i18n && wp.i18n.__ ? wp.i18n.__ : function (s) { return s; };

    const {
        Button,
        Card,
        CardBody,
        CardHeader,
        Notice,
        Spinner,
        TextControl,
        TextareaControl,
        ToggleControl
    } = wp.components;

    function deepClone(value) {
        try {
            return JSON.parse(JSON.stringify(value || {}));
        } catch (e) {
            return {};
        }
    }

    function getIn(obj, path, fallback) {
        const parts = String(path || '').split('.');
        let cursor = obj;
        for (let i = 0; i < parts.length; i++) {
            const key = parts[i];
            if (!cursor || typeof cursor !== 'object' || !(key in cursor)) {
                return fallback;
            }
            cursor = cursor[key];
        }
        return cursor;
    }

    function setIn(obj, path, value) {
        const parts = String(path || '').split('.');
        let cursor = obj;
        for (let i = 0; i < parts.length - 1; i++) {
            const key = parts[i];
            if (!cursor[key] || typeof cursor[key] !== 'object') {
                cursor[key] = {};
            }
            cursor = cursor[key];
        }
        cursor[parts[parts.length - 1]] = value;
    }

    function splitList(input) {
        if (Array.isArray(input)) {
            return input.map((v) => String(v).trim()).filter(Boolean);
        }
        return String(input || '')
            .split(/[\r\n,]+/)
            .map((s) => s.trim())
            .filter(Boolean);
    }

    function joinList(input) {
        return splitList(input).join('\n');
    }

    function postAjax(action, payload) {
        const body = new URLSearchParams();
        body.set('action', action);
        body.set('nonce', String(config.nonce || ''));

        Object.keys(payload || {}).forEach((k) => {
            body.set(k, payload[k]);
        });

        return fetch(String(config.ajaxUrl || ''), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body.toString()
        }).then((res) => res.json());
    }

    function App() {
        const [settings, setSettings] = useState(deepClone(config.settings || {}));
        const [loading, setLoading] = useState(false);
        const [saving, setSaving] = useState(false);
        const [notice, setNotice] = useState(null);

        function update(path, value) {
            setSettings((prev) => {
                const next = deepClone(prev);
                setIn(next, path, value);
                return next;
            });
        }

        function reload() {
            setLoading(true);
            setNotice(null);
            postAjax('clicutcl_get_tracking_v2_settings', {})
                .then((json) => {
                    if (!json || !json.success) {
                        throw new Error((json && json.data && json.data.message) || 'load_failed');
                    }
                    setSettings(deepClone(json.data.settings || {}));
                    setNotice({ status: 'success', message: __('Settings loaded.', 'click-trail-handler') });
                })
                .catch(() => {
                    setNotice({ status: 'error', message: __('Failed to load settings.', 'click-trail-handler') });
                })
                .finally(() => setLoading(false));
        }

        function save() {
            setSaving(true);
            setNotice(null);
            postAjax('clicutcl_save_tracking_v2_settings', {
                settings: JSON.stringify(settings || {})
            })
                .then((json) => {
                    if (!json || !json.success) {
                        throw new Error((json && json.data && json.data.message) || 'save_failed');
                    }
                    setSettings(deepClone(json.data.settings || {}));
                    setNotice({
                        status: 'success',
                        message: (json.data && json.data.message) || __('Settings saved.', 'click-trail-handler')
                    });
                })
                .catch(() => {
                    setNotice({ status: 'error', message: __('Failed to save settings.', 'click-trail-handler') });
                })
                .finally(() => setSaving(false));
        }

        const featureFlags = getIn(settings, 'feature_flags', {});
        const security = getIn(settings, 'security', {});
        const diagnostics = getIn(settings, 'diagnostics', {});
        const dedup = getIn(settings, 'dedup', {});
        const lifecycle = getIn(settings, 'lifecycle.crm_ingestion', {});
        const providers = getIn(settings, 'external_forms.providers', {});
        const destinations = getIn(settings, 'destinations', {});

        function renderFlag(flagKey, label) {
            return el(ToggleControl, {
                key: flagKey,
                label: label,
                checked: !!featureFlags[flagKey],
                onChange: (v) => update('feature_flags.' + flagKey, !!v)
            });
        }

        function renderDestination(key, label) {
            return el(ToggleControl, {
                key: key,
                label: label,
                checked: !!getIn(destinations, key + '.enabled', false),
                onChange: (v) => update('destinations.' + key + '.enabled', !!v)
            });
        }

        function renderProvider(key, label) {
            return el('div', { key: key, style: { marginBottom: '12px' } }, [
                el(ToggleControl, {
                    key: key + '_enabled',
                    label: label,
                    checked: !!getIn(providers, key + '.enabled', false),
                    onChange: (v) => update('external_forms.providers.' + key + '.enabled', !!v)
                }),
                el(TextControl, {
                    key: key + '_secret',
                    label: __('Webhook Secret', 'click-trail-handler'),
                    value: String(getIn(providers, key + '.secret', '')),
                    onChange: (v) => update('external_forms.providers.' + key + '.secret', v)
                })
            ]);
        }

        const cards = [
            el(Card, { key: 'flags' }, [
                el(CardHeader, { key: 'h' }, __('Feature Flags', 'click-trail-handler')),
                el(CardBody, { key: 'b' }, [
                    renderFlag('event_v2', __('Enable Event v2 Intake', 'click-trail-handler')),
                    renderFlag('external_webhooks', __('Enable External Webhooks', 'click-trail-handler')),
                    renderFlag('connector_native', __('Enable Native Connectors', 'click-trail-handler')),
                    renderFlag('diagnostics_v2', __('Enable Diagnostics v2', 'click-trail-handler')),
                    renderFlag('lifecycle_ingestion', __('Enable Lifecycle Ingestion', 'click-trail-handler'))
                ])
            ]),
            el(Card, { key: 'destinations' }, [
                el(CardHeader, { key: 'h' }, __('Destinations', 'click-trail-handler')),
                el(CardBody, { key: 'b' }, [
                    renderDestination('meta', 'Meta'),
                    renderDestination('google', 'Google'),
                    renderDestination('linkedin', 'LinkedIn'),
                    renderDestination('reddit', 'Reddit'),
                    renderDestination('pinterest', 'Pinterest')
                ])
            ]),
            el(Card, { key: 'security' }, [
                el(CardHeader, { key: 'h' }, __('Security', 'click-trail-handler')),
                el(CardBody, { key: 'b' }, [
                    el(TextControl, {
                        key: 'token_ttl_seconds',
                        label: __('Token TTL (seconds)', 'click-trail-handler'),
                        type: 'number',
                        value: String(security.token_ttl_seconds || ''),
                        onChange: (v) => update('security.token_ttl_seconds', v)
                    }),
                    el(TextControl, {
                        key: 'token_nonce_limit',
                        label: __('Token Nonce Limit (0 = disabled)', 'click-trail-handler'),
                        type: 'number',
                        value: String(security.token_nonce_limit || 0),
                        onChange: (v) => update('security.token_nonce_limit', v)
                    }),
                    el(TextControl, {
                        key: 'webhook_replay_window',
                        label: __('Webhook Replay Window (seconds)', 'click-trail-handler'),
                        type: 'number',
                        value: String(security.webhook_replay_window || ''),
                        onChange: (v) => update('security.webhook_replay_window', v)
                    }),
                    el(TextareaControl, {
                        key: 'trusted_proxies',
                        label: __('Trusted Proxies (one per line)', 'click-trail-handler'),
                        value: joinList(security.trusted_proxies || []),
                        onChange: (v) => update('security.trusted_proxies', splitList(v))
                    }),
                    el(TextareaControl, {
                        key: 'allowed_token_hosts',
                        label: __('Allowed Token Hosts (one per line)', 'click-trail-handler'),
                        value: joinList(security.allowed_token_hosts || []),
                        onChange: (v) => update('security.allowed_token_hosts', splitList(v))
                    })
                ])
            ]),
            el(Card, { key: 'providers' }, [
                el(CardHeader, { key: 'h' }, __('External Form Providers', 'click-trail-handler')),
                el(CardBody, { key: 'b' }, [
                    renderProvider('calendly', 'Calendly'),
                    renderProvider('hubspot', 'HubSpot'),
                    renderProvider('typeform', 'Typeform')
                ])
            ]),
            el(Card, { key: 'lifecycle' }, [
                el(CardHeader, { key: 'h' }, __('Lifecycle Ingestion', 'click-trail-handler')),
                el(CardBody, { key: 'b' }, [
                    el(ToggleControl, {
                        key: 'crm_enabled',
                        label: __('Enable CRM Lifecycle Endpoint', 'click-trail-handler'),
                        checked: !!lifecycle.enabled,
                        onChange: (v) => update('lifecycle.crm_ingestion.enabled', !!v)
                    }),
                    el(TextControl, {
                        key: 'crm_token',
                        label: __('CRM Token', 'click-trail-handler'),
                        value: String(lifecycle.token || ''),
                        onChange: (v) => update('lifecycle.crm_ingestion.token', v)
                    })
                ])
            ]),
            el(Card, { key: 'ops' }, [
                el(CardHeader, { key: 'h' }, __('Diagnostics and Dedup', 'click-trail-handler')),
                el(CardBody, { key: 'b' }, [
                    el(TextControl, {
                        key: 'dispatch_buffer_size',
                        label: __('Dispatch Buffer Size', 'click-trail-handler'),
                        type: 'number',
                        value: String(diagnostics.dispatch_buffer_size || ''),
                        onChange: (v) => update('diagnostics.dispatch_buffer_size', v)
                    }),
                    el(TextControl, {
                        key: 'failure_flush_interval',
                        label: __('Failure Flush Interval (seconds)', 'click-trail-handler'),
                        type: 'number',
                        value: String(diagnostics.failure_flush_interval || ''),
                        onChange: (v) => update('diagnostics.failure_flush_interval', v)
                    }),
                    el(TextControl, {
                        key: 'failure_bucket_retention',
                        label: __('Failure Bucket Retention (hours)', 'click-trail-handler'),
                        type: 'number',
                        value: String(diagnostics.failure_bucket_retention || ''),
                        onChange: (v) => update('diagnostics.failure_bucket_retention', v)
                    }),
                    el(TextControl, {
                        key: 'dedup_ttl_seconds',
                        label: __('Dedup TTL (seconds)', 'click-trail-handler'),
                        type: 'number',
                        value: String(dedup.ttl_seconds || ''),
                        onChange: (v) => update('dedup.ttl_seconds', v)
                    })
                ])
            ])
        ];

        return el('div', { className: 'clicutcl-tracking-v2', style: { maxWidth: '980px', marginTop: '12px' } }, [
            notice ? el(Notice, {
                key: 'notice',
                status: notice.status || 'info',
                isDismissible: true,
                onRemove: function () { setNotice(null); }
            }, notice.message || '') : null,
            loading ? el('div', { key: 'loading', style: { marginBottom: '12px' } }, el(Spinner)) : null,
            ...cards,
            el('div', { key: 'actions', style: { marginTop: '16px', display: 'flex', gap: '8px' } }, [
                el(Button, {
                    key: 'save',
                    variant: 'primary',
                    isBusy: saving,
                    disabled: saving || loading,
                    onClick: save
                }, __('Save Tracking v2 Settings', 'click-trail-handler')),
                el(Button, {
                    key: 'reload',
                    variant: 'secondary',
                    disabled: saving || loading,
                    onClick: reload
                }, __('Reload', 'click-trail-handler'))
            ])
        ]);
    }

    function boot() {
        const root = document.getElementById('clicutcl-tracking-v2-root');
        if (!root) {
            return;
        }

        const app = el(App);
        if (typeof wp.element.createRoot === 'function') {
            wp.element.createRoot(root).render(app);
            return;
        }
        if (typeof wp.element.render === 'function') {
            wp.element.render(app, root);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})(window.wp, window.clicutclTrackingV2Config || {});

