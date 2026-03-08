(function (wp, config) {
    'use strict';

    if (!wp || !wp.element || !wp.components) {
        return;
    }

    var el = wp.element.createElement;
    var useState = wp.element.useState;
    var __ = wp.i18n && wp.i18n.__ ? wp.i18n.__ : function (s) { return s; };

    var Button = wp.components.Button;
    var Notice = wp.components.Notice;
    var Spinner = wp.components.Spinner;
    var TextControl = wp.components.TextControl;
    var TextareaControl = wp.components.TextareaControl;
    var ToggleControl = wp.components.ToggleControl;
    var SelectControl = wp.components.SelectControl;

    function deepClone(value) {
        try {
            return JSON.parse(JSON.stringify(value || {}));
        } catch (e) {
            return {};
        }
    }

    function getIn(obj, path, fallback) {
        var parts = String(path || '').split('.');
        var cursor = obj;
        var i;

        for (i = 0; i < parts.length; i++) {
            if (!cursor || typeof cursor !== 'object' || !(parts[i] in cursor)) {
                return fallback;
            }
            cursor = cursor[parts[i]];
        }

        return cursor;
    }

    function setIn(obj, path, value) {
        var parts = String(path || '').split('.');
        var cursor = obj;
        var i;

        for (i = 0; i < parts.length - 1; i++) {
            if (!cursor[parts[i]] || typeof cursor[parts[i]] !== 'object') {
                cursor[parts[i]] = {};
            }
            cursor = cursor[parts[i]];
        }

        cursor[parts[parts.length - 1]] = value;
    }

    function postAjax(action, payload) {
        var body = new URLSearchParams();
        body.set('action', action);
        body.set('nonce', String(config.nonce || ''));
        Object.keys(payload || {}).forEach(function (key) {
            body.set(key, payload[key]);
        });

        return fetch(String(config.ajaxUrl || ''), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: body.toString()
        }).then(function (res) {
            return res.json();
        });
    }

    function settingLabel(label, badge) {
        if (!badge) {
            return label;
        }

        return el('span', { className: 'clicktrail-setting-label' }, [
            el('span', { key: 'text' }, label),
            el('span', {
                key: 'badge',
                className: 'clicktrail-card__tag clicktrail-card__tag--recommended'
            }, badge)
        ]);
    }

    function wrapControl(key, control, disabled) {
        return el('div', {
            key: key,
            className: 'clicktrail-setting-block' + (disabled ? ' is-disabled' : '')
        }, control);
    }

    function AppCard(props) {
        var collapseState = useState(!!props.collapsed);
        var collapsed = collapseState[0];
        var setCollapsed = collapseState[1];
        var collapsible = !!props.collapsible;
        var classes = ['clicktrail-card'];

        if (collapsible) {
            classes.push('clicktrail-card--collapsible');
        }
        if (collapsed) {
            classes.push('is-collapsed');
        }

        function toggle() {
            if (collapsible) {
                setCollapsed(!collapsed);
            }
        }

        return el('section', { className: classes.join(' ') }, [
            collapsible
                ? el('button', {
                    key: 'header',
                    type: 'button',
                    className: 'clicktrail-card__header',
                    onClick: toggle,
                    'aria-expanded': collapsed ? 'false' : 'true'
                }, [
                    el('span', { key: 'main', className: 'clicktrail-card__header-main' }, [
                        el('span', {
                            key: 'icon',
                            className: 'clicktrail-card__icon dashicons ' + (props.icon || 'dashicons-admin-generic'),
                            'aria-hidden': 'true'
                        }),
                        el('span', { key: 'heading', className: 'clicktrail-card__heading' }, [
                            el('span', { key: 'title', className: 'clicktrail-card__title' }, props.title || ''),
                            props.description
                                ? el('span', { key: 'desc', className: 'clicktrail-card__description' }, props.description)
                                : null
                        ])
                    ]),
                    el('span', { key: 'meta', className: 'clicktrail-card__meta' }, [
                        props.tag
                            ? el('span', {
                                key: 'tag',
                                className: 'clicktrail-card__tag ' + (props.tagClass || 'clicktrail-card__tag--muted')
                            }, props.tag)
                            : null,
                        el('span', {
                            key: 'chevron',
                            className: 'clicktrail-card__chevron dashicons dashicons-arrow-down-alt2',
                            'aria-hidden': 'true'
                        })
                    ])
                ])
                : el('div', {
                    key: 'header',
                    className: 'clicktrail-card__header clicktrail-card__header--static'
                }, [
                    el('span', { key: 'main', className: 'clicktrail-card__header-main' }, [
                        el('span', {
                            key: 'icon',
                            className: 'clicktrail-card__icon dashicons ' + (props.icon || 'dashicons-admin-generic'),
                            'aria-hidden': 'true'
                        }),
                        el('span', { key: 'heading', className: 'clicktrail-card__heading' }, [
                            el('span', { key: 'title', className: 'clicktrail-card__title' }, props.title || ''),
                            props.description
                                ? el('span', { key: 'desc', className: 'clicktrail-card__description' }, props.description)
                                : null
                        ])
                    ]),
                    el('span', { key: 'meta', className: 'clicktrail-card__meta' }, [
                        props.tag
                            ? el('span', {
                                key: 'tag',
                                className: 'clicktrail-card__tag ' + (props.tagClass || 'clicktrail-card__tag--muted')
                            }, props.tag)
                            : null
                    ])
                ]),
            el('div', { key: 'body', className: 'clicktrail-card__body clicktrail-card__body--react' }, props.children)
        ]);
    }

    function SummaryBar(props) {
        return el('div', { className: 'clicktrail-summary-bar' }, (props.items || []).map(function (item) {
            return el('div', {
                key: item.key,
                className: 'clicktrail-status-pill clicktrail-status-pill--' + item.tone
            }, [
                el('span', { key: 'dot', className: 'clicktrail-status-pill__dot', 'aria-hidden': 'true' }),
                el('span', { key: 'text', className: 'clicktrail-status-pill__text' }, [
                    el('strong', { key: 'label' }, item.label + ':'),
                    ' ',
                    item.value
                ])
            ]);
        }));
    }

    function InlineNotice(props) {
        return el('div', {
            className: 'clicktrail-inline-notice' + (props.warning ? ' clicktrail-inline-notice--warning' : '')
        }, [
            el('span', {
                key: 'icon',
                className: 'dashicons ' + (props.icon || 'dashicons-info-outline'),
                'aria-hidden': 'true'
            }),
            el('span', { key: 'text' }, props.text || '')
        ]);
    }

    function App() {
        var settingsState = useState(deepClone(config.settings || {}));
        var settings = settingsState[0];
        var setSettings = settingsState[1];

        var loadingState = useState(false);
        var loading = loadingState[0];
        var setLoading = loadingState[1];

        var savingState = useState(false);
        var saving = savingState[0];
        var setSaving = savingState[1];

        var noticeState = useState(null);
        var notice = noticeState[0];
        var setNotice = noticeState[1];

        var tabState = useState(String(config.activeTab || 'capture'));
        var activeTab = tabState[0];
        var setActiveTab = tabState[1];

        var tabs = config.tabs || {};
        var tabOrder = ['capture', 'forms', 'events', 'delivery'];
        var activeMeta = tabs[activeTab] || tabs.capture || {};
        var settingsBaseUrl = getIn(settings, 'urls.settings', '');
        var serverLocked = !!getIn(settings, 'delivery.server.has_network_defaults', false) && !!getIn(settings, 'delivery.server.use_network', false);
        var serverEnabled = !!getIn(settings, 'delivery.server.enabled', false);
        var consentEnabled = !!getIn(settings, 'delivery.privacy.enabled', false);
        var lifecycleEnabled = !!getIn(settings, 'events.lifecycle.accept_updates', false);
        var lifecycleEndpointEnabled = lifecycleEnabled && !!getIn(settings, 'events.lifecycle.endpoint_enabled', false);
        var formFallbackEnabled = !!getIn(settings, 'forms.client_fallback', false);
        var linkDecorationEnabled = !!getIn(settings, 'capture.decorate_links', false);
        var whatsappEnabled = !!getIn(settings, 'forms.whatsapp.enabled', false);
        var webhookSourcesEnabled = !!getIn(settings, 'forms.webhook_sources_enabled', false);

        function update(path, value) {
            setSettings(function (prev) {
                var next = deepClone(prev);
                setIn(next, path, value);
                return next;
            });
        }

        function buildTabUrl(slug) {
            if (!settingsBaseUrl) {
                return '#';
            }

            return settingsBaseUrl + '&tab=' + encodeURIComponent(slug);
        }

        function switchTab(event, slug) {
            if (event && typeof event.preventDefault === 'function') {
                event.preventDefault();
            }

            setActiveTab(slug);

            if (window.history && typeof window.history.replaceState === 'function') {
                window.history.replaceState({}, document.title, buildTabUrl(slug));
            }
        }

        function reload() {
            setLoading(true);
            setNotice(null);
            postAjax('clicutcl_get_admin_settings', {})
                .then(function (json) {
                    if (!json || !json.success) {
                        throw new Error((json && json.data && json.data.message) || 'load_failed');
                    }
                    setSettings(deepClone(json.data.settings || {}));
                    setNotice({
                        status: 'success',
                        message: __('Settings reloaded.', 'click-trail-handler')
                    });
                })
                .catch(function () {
                    setNotice({
                        status: 'error',
                        message: __('Failed to reload settings.', 'click-trail-handler')
                    });
                })
                .finally(function () {
                    setLoading(false);
                });
        }

        function save() {
            setSaving(true);
            setNotice(null);
            postAjax('clicutcl_save_admin_settings', {
                settings: JSON.stringify(settings || {})
            })
                .then(function (json) {
                    if (!json || !json.success) {
                        throw new Error((json && json.data && json.data.message) || 'save_failed');
                    }
                    setSettings(deepClone(json.data.settings || {}));
                    setNotice({
                        status: 'success',
                        message: (json.data && json.data.message) || __('Settings saved.', 'click-trail-handler')
                    });
                })
                .catch(function () {
                    setNotice({
                        status: 'error',
                        message: __('Failed to save settings.', 'click-trail-handler')
                    });
                })
                .finally(function () {
                    setSaving(false);
                });
        }

        function computeStatusItems() {
            var providers = ['calendly', 'hubspot', 'typeform'];
            var providersOn = providers.some(function (provider) {
                return !!getIn(settings, 'forms.providers.' + provider + '.enabled', false);
            });
            var formsOn = !!getIn(settings, 'forms.client_fallback', false)
                || !!getIn(settings, 'forms.whatsapp.enabled', false)
                || providersOn;
            var crossDomainOn = !!getIn(settings, 'capture.decorate_links', false)
                || !!getIn(settings, 'capture.pass_token', false);

            return [
                {
                    key: 'capture',
                    label: __('Capture', 'click-trail-handler'),
                    value: !!getIn(settings, 'capture.enabled', false) ? __('On', 'click-trail-handler') : __('Off', 'click-trail-handler'),
                    tone: !!getIn(settings, 'capture.enabled', false) ? 'success' : 'neutral'
                },
                {
                    key: 'forms',
                    label: __('Forms', 'click-trail-handler'),
                    value: formsOn ? __('On', 'click-trail-handler') : __('Off', 'click-trail-handler'),
                    tone: formsOn ? 'success' : 'neutral'
                },
                {
                    key: 'events',
                    label: __('Events', 'click-trail-handler'),
                    value: !!getIn(settings, 'events.browser_pipeline', false) ? __('On', 'click-trail-handler') : __('Off', 'click-trail-handler'),
                    tone: !!getIn(settings, 'events.browser_pipeline', false) ? 'success' : 'neutral'
                },
                {
                    key: 'cross_domain',
                    label: __('Cross-domain', 'click-trail-handler'),
                    value: crossDomainOn ? __('On', 'click-trail-handler') : __('Off', 'click-trail-handler'),
                    tone: crossDomainOn ? 'info' : 'neutral'
                },
                {
                    key: 'delivery',
                    label: __('Delivery', 'click-trail-handler'),
                    value: !!getIn(settings, 'delivery.server.enabled', false) ? __('On', 'click-trail-handler') : __('Off', 'click-trail-handler'),
                    tone: !!getIn(settings, 'delivery.server.enabled', false) ? 'success' : 'neutral'
                },
                {
                    key: 'consent',
                    label: __('Consent', 'click-trail-handler'),
                    value: !!getIn(settings, 'delivery.privacy.enabled', false) ? __('On', 'click-trail-handler') : __('Off', 'click-trail-handler'),
                    tone: !!getIn(settings, 'delivery.privacy.enabled', false) ? 'success' : 'neutral'
                }
            ];
        }

        function renderToggle(path, label, help, options) {
            var opts = options || {};
            return wrapControl(
                opts.key || path,
                el(ToggleControl, {
                    label: settingLabel(label, opts.badge || ''),
                    help: help || null,
                    checked: !!getIn(settings, path, false),
                    disabled: !!opts.disabled,
                    onChange: function (value) {
                        update(path, !!value);
                    }
                }),
                !!opts.disabled
            );
        }

        function renderText(path, label, help, options) {
            var opts = options || {};
            return wrapControl(
                opts.key || path,
                el(TextControl, {
                    label: settingLabel(label, opts.badge || ''),
                    help: help || null,
                    value: String(getIn(settings, path, '')),
                    type: opts.type || 'text',
                    disabled: !!opts.disabled,
                    placeholder: opts.placeholder || '',
                    onChange: function (value) {
                        update(path, value);
                    }
                }),
                !!opts.disabled
            );
        }

        function renderTextarea(path, label, help, options) {
            var opts = options || {};
            return wrapControl(
                opts.key || path,
                el(TextareaControl, {
                    label: settingLabel(label, opts.badge || ''),
                    help: help || null,
                    value: String(getIn(settings, path, '')),
                    disabled: !!opts.disabled,
                    rows: opts.rows || 3,
                    placeholder: opts.placeholder || '',
                    onChange: function (value) {
                        update(path, value);
                    }
                }),
                !!opts.disabled
            );
        }

        function renderSelect(path, label, help, options, items) {
            var opts = options || {};
            return wrapControl(
                opts.key || path,
                el(SelectControl, {
                    label: settingLabel(label, opts.badge || ''),
                    help: help || null,
                    value: String(getIn(settings, path, '')),
                    disabled: !!opts.disabled,
                    options: items || [],
                    onChange: function (value) {
                        update(path, value);
                    }
                }),
                !!opts.disabled
            );
        }

        function renderCaptureTab() {
            return [
                el(AppCard, {
                    key: 'capture-core',
                    icon: 'dashicons-chart-area',
                    title: __('Core tracking', 'click-trail-handler'),
                    description: __('Enable attribution tracking and choose how long visit source data should be stored.', 'click-trail-handler')
                }, [
                    renderToggle('capture.enabled', __('Enable attribution tracking', 'click-trail-handler'), __('Capture campaign and referral data for each visit.', 'click-trail-handler')),
                    renderText('capture.retention_days', __('Attribution retention (days)', 'click-trail-handler'), __('How long attribution data should be stored.', 'click-trail-handler'), {
                        type: 'number'
                    })
                ]),
                el(AppCard, {
                    key: 'capture-cross-domain',
                    icon: 'dashicons-admin-links',
                    title: __('Cross-domain attribution', 'click-trail-handler'),
                    description: __('Preserve attribution when visitors move between your domains or subdomains.', 'click-trail-handler')
                }, [
                    renderToggle('capture.decorate_links', __('Decorate outgoing links', 'click-trail-handler'), __('Append attribution parameters to approved links.', 'click-trail-handler')),
                    renderTextarea('capture.allowed_domains', __('Allowed cross-domain destinations', 'click-trail-handler'), __('Domains where attribution parameters may be added.', 'click-trail-handler'), {
                        disabled: !linkDecorationEnabled,
                        placeholder: 'app.example.com\ncheckout.example.com'
                    }),
                    renderToggle('capture.skip_signed_urls', __('Do not modify signed URLs', 'click-trail-handler'), __('Recommended when links contain temporary signatures or protected access tokens.', 'click-trail-handler'), {
                        disabled: !linkDecorationEnabled,
                        badge: __('Recommended', 'click-trail-handler')
                    }),
                    renderToggle('capture.pass_token', __('Pass cross-domain attribution token', 'click-trail-handler'), __('Adds a temporary token to preserve attribution across approved domains. No personal data is included.', 'click-trail-handler'), {
                        disabled: !linkDecorationEnabled
                    })
                ])
            ];
        }

        function renderProvider(providerKey, label) {
            var enabledPath = 'forms.providers.' + providerKey + '.enabled';
            var secretPath = 'forms.providers.' + providerKey + '.secret';

            return el('div', { key: providerKey, className: 'clicktrail-provider-block' + (webhookSourcesEnabled ? '' : ' is-disabled') }, [
                el(ToggleControl, {
                    key: providerKey + '_toggle',
                    label: label,
                    help: __('Enable this source and accept signed webhook submissions.', 'click-trail-handler'),
                    checked: !!getIn(settings, enabledPath, false),
                    disabled: !webhookSourcesEnabled,
                    onChange: function (value) {
                        update(enabledPath, !!value);
                    }
                }),
                el(TextControl, {
                    key: providerKey + '_secret',
                    label: __('Signing secret', 'click-trail-handler'),
                    help: __('Leave unchanged to keep the current secret.', 'click-trail-handler'),
                    value: String(getIn(settings, secretPath, '')),
                    disabled: !webhookSourcesEnabled || !getIn(settings, enabledPath, false),
                    onChange: function (value) {
                        update(secretPath, value);
                    }
                })
            ]);
        }

        function renderFormsTab() {
            return [
                el(AppCard, {
                    key: 'forms-onsite',
                    icon: 'dashicons-feedback',
                    title: __('On-site form capture', 'click-trail-handler'),
                    description: __('Recommended settings to keep attribution attached to forms on cached pages and dynamic sites.', 'click-trail-handler'),
                    tag: __('Recommended', 'click-trail-handler'),
                    tagClass: 'clicktrail-card__tag--recommended'
                }, [
                    renderToggle('forms.client_fallback', __('Client-side capture fallback', 'click-trail-handler'), __('Recommended for cached or highly optimized pages.', 'click-trail-handler'), {
                        badge: __('Recommended', 'click-trail-handler')
                    }),
                    renderToggle('forms.watch_dynamic_content', __('Watch dynamic content', 'click-trail-handler'), __('Detect forms and links added after page load.', 'click-trail-handler'), {
                        disabled: !formFallbackEnabled,
                        badge: __('Recommended', 'click-trail-handler')
                    }),
                    renderToggle('forms.replace_existing_values', __('Replace existing attribution values', 'click-trail-handler'), __('Use newly detected values even if attribution was already stored.', 'click-trail-handler'), {
                        disabled: !formFallbackEnabled
                    })
                ]),
                el(AppCard, {
                    key: 'forms-whatsapp',
                    icon: 'dashicons-format-chat',
                    title: __('WhatsApp', 'click-trail-handler'),
                    description: __('Carry attribution into outbound WhatsApp clicks and pre-filled messages.', 'click-trail-handler'),
                    collapsible: true,
                    collapsed: true
                }, [
                    renderToggle('forms.whatsapp.enabled', __('Enable WhatsApp tracking', 'click-trail-handler'), __('Track clicks on WhatsApp links and buttons.', 'click-trail-handler')),
                    renderToggle('forms.whatsapp.append_attribution', __('Append attribution to message', 'click-trail-handler'), __('Add attribution details to the pre-filled WhatsApp message.', 'click-trail-handler'), {
                        disabled: !whatsappEnabled
                    })
                ]),
                el(AppCard, {
                    key: 'forms-providers',
                    icon: 'dashicons-randomize',
                    title: __('External form sources', 'click-trail-handler'),
                    description: __('Accept attributed submissions from supported providers without exposing raw engineering controls.', 'click-trail-handler')
                }, [
                    renderToggle('forms.webhook_sources_enabled', __('Accept external form source webhooks', 'click-trail-handler'), __('Enable signed inbound submissions from supported providers.', 'click-trail-handler')),
                    renderProvider('calendly', 'Calendly'),
                    renderProvider('hubspot', 'HubSpot'),
                    renderProvider('typeform', 'Typeform')
                ]),
                el(AppCard, {
                    key: 'forms-advanced',
                    icon: 'dashicons-admin-tools',
                    title: __('Advanced technical options', 'click-trail-handler'),
                    description: __('Only change these if you need more control over how the browser watches the page.', 'click-trail-handler'),
                    collapsible: true,
                    collapsed: true,
                    tag: __('Advanced', 'click-trail-handler'),
                    tagClass: 'clicktrail-card__tag--muted'
                }, [
                    renderText('forms.observer_target', __('Dynamic content root selector', 'click-trail-handler'), __('Defaults to body. Narrow this only if you need to watch a specific part of the page.', 'click-trail-handler'), {
                        disabled: !formFallbackEnabled,
                        placeholder: 'body'
                    })
                ])
            ];
        }

        function renderEventsTab() {
            return [
                el(InlineNotice, {
                    key: 'events-note',
                    text: __('ClickTrail uses one unified event pipeline behind the scenes for browser events, webhooks, and server delivery. Configure the capabilities you use without worrying about the internal pipeline.', 'click-trail-handler')
                }),
                el(AppCard, {
                    key: 'events-core',
                    icon: 'dashicons-chart-bar',
                    title: __('Event collection', 'click-trail-handler'),
                    description: __('Control browser event collection and optional GTM loading for this site.', 'click-trail-handler')
                }, [
                    renderToggle('events.browser_pipeline', __('Enable browser event collection', 'click-trail-handler'), __('Collect page, click, and form events through ClickTrail\'s unified event layer.', 'click-trail-handler')),
                    renderText('events.gtm_container_id', __('Google Tag Manager container ID', 'click-trail-handler'), __('Use only if your site does not already load Google Tag Manager.', 'click-trail-handler'), {
                        placeholder: 'GTM-XXXXXXX'
                    })
                ]),
                el(AppCard, {
                    key: 'events-destinations',
                    icon: 'dashicons-share',
                    title: __('Destinations', 'click-trail-handler'),
                    description: __('Choose which advertising platforms should receive compatible event payloads.', 'click-trail-handler')
                }, [
                    renderToggle('events.destinations.meta', __('Meta', 'click-trail-handler'), __('Send eligible events to Meta-compatible delivery adapters when configured.', 'click-trail-handler')),
                    renderToggle('events.destinations.google', __('Google', 'click-trail-handler'), __('Send eligible events to Google-compatible delivery adapters when configured.', 'click-trail-handler')),
                    renderToggle('events.destinations.linkedin', __('LinkedIn', 'click-trail-handler'), __('Send eligible events to LinkedIn-compatible delivery adapters when configured.', 'click-trail-handler')),
                    renderToggle('events.destinations.reddit', __('Reddit', 'click-trail-handler'), __('Send eligible events to Reddit-compatible delivery adapters when configured.', 'click-trail-handler')),
                    renderToggle('events.destinations.pinterest', __('Pinterest', 'click-trail-handler'), __('Send eligible events to Pinterest-compatible delivery adapters when configured.', 'click-trail-handler'))
                ]),
                el(AppCard, {
                    key: 'events-lifecycle',
                    icon: 'dashicons-update',
                    title: __('Lifecycle updates', 'click-trail-handler'),
                    description: __('Accept lifecycle updates from your CRM or backend and route them through the same event pipeline.', 'click-trail-handler')
                }, [
                    renderToggle('events.lifecycle.accept_updates', __('Accept lifecycle updates', 'click-trail-handler'), __('Allow lifecycle events to enter the unified event pipeline.', 'click-trail-handler')),
                    renderToggle('events.lifecycle.endpoint_enabled', __('Enable lifecycle endpoint', 'click-trail-handler'), __('Turn on the REST endpoint used by your CRM or backend.', 'click-trail-handler'), {
                        disabled: !lifecycleEnabled
                    }),
                    renderText('events.lifecycle.token', __('Lifecycle endpoint token', 'click-trail-handler'), __('Leave unchanged to keep the current token.', 'click-trail-handler'), {
                        disabled: !lifecycleEndpointEnabled
                    })
                ])
            ];
        }

        function renderDeliveryHealth() {
            var ops = getIn(settings, 'delivery.operations', {});
            var lastError = ops.last_error_code ? ops.last_error_code : __('None', 'click-trail-handler');

            return el(AppCard, {
                key: 'delivery-health',
                icon: 'dashicons-chart-area',
                title: __('Delivery health', 'click-trail-handler'),
                description: __('Quick operational summary for queue health, recent delivery attempts, and debug state.', 'click-trail-handler')
            }, [
                !serverEnabled
                    ? el(InlineNotice, {
                        key: 'delivery-disabled',
                        warning: true,
                        icon: 'dashicons-warning',
                        text: __('Server-side delivery is currently off. Historical diagnostics can still appear until their retention window expires.', 'click-trail-handler')
                    })
                    : null,
                el('div', { key: 'stats', className: 'clicktrail-diagnostics-grid clicktrail-diagnostics-grid--compact' }, [
                    el('div', { key: 'queue', className: 'clicktrail-diagnostic-stat ' + ((ops.queue_pending || 0) > 0 ? 'clicktrail-diagnostic-stat--warn' : 'clicktrail-diagnostic-stat--ok') }, [
                        el('div', { key: 'label', className: 'clicktrail-diagnostic-stat__label' }, __('Queue Backlog', 'click-trail-handler')),
                        el('div', { key: 'value', className: 'clicktrail-diagnostic-stat__value' }, String(ops.queue_pending || 0)),
                        el('div', { key: 'sub', className: 'clicktrail-diagnostic-stat__sub' }, __('Due now: ', 'click-trail-handler') + String(ops.queue_due_now || 0))
                    ]),
                    el('div', { key: 'dispatch', className: 'clicktrail-diagnostic-stat clicktrail-diagnostic-stat--info' }, [
                        el('div', { key: 'label', className: 'clicktrail-diagnostic-stat__label' }, __('Last Dispatch', 'click-trail-handler')),
                        el('div', { key: 'value', className: 'clicktrail-diagnostic-stat__value' }, String(ops.latest_dispatch || __('No attempts yet', 'click-trail-handler'))),
                        el('div', { key: 'sub', className: 'clicktrail-diagnostic-stat__sub' }, String(ops.latest_dispatch_time || ''))
                    ]),
                    el('div', { key: 'error', className: 'clicktrail-diagnostic-stat ' + (ops.last_error_code ? 'clicktrail-diagnostic-stat--err' : 'clicktrail-diagnostic-stat--ok') }, [
                        el('div', { key: 'label', className: 'clicktrail-diagnostic-stat__label' }, __('Last Error', 'click-trail-handler')),
                        el('div', { key: 'value', className: 'clicktrail-diagnostic-stat__value' }, lastError),
                        el('div', { key: 'sub', className: 'clicktrail-diagnostic-stat__sub' }, String(ops.last_error_time || __('No errors recorded.', 'click-trail-handler')))
                    ]),
                    el('div', { key: 'debug', className: 'clicktrail-diagnostic-stat clicktrail-diagnostic-stat--neutral' }, [
                        el('div', { key: 'label', className: 'clicktrail-diagnostic-stat__label' }, __('Debug Logging', 'click-trail-handler')),
                        el('div', { key: 'value', className: 'clicktrail-diagnostic-stat__value' }, ops.debug_active ? __('Enabled', 'click-trail-handler') : __('Disabled', 'click-trail-handler')),
                        el('div', { key: 'sub', className: 'clicktrail-diagnostic-stat__sub' }, ops.debug_active ? String(ops.debug_until || '') : __('Enable it from Diagnostics when you need a short trace window.', 'click-trail-handler'))
                    ])
                ]),
                el('div', { key: 'links', className: 'clicktrail-ops-links' }, [
                    el(Button, {
                        key: 'diagnostics',
                        variant: 'secondary',
                        href: getIn(settings, 'urls.diagnostics', '#')
                    }, __('Open Diagnostics', 'click-trail-handler')),
                    el(Button, {
                        key: 'logs',
                        variant: 'secondary',
                        href: getIn(settings, 'urls.logs', '#')
                    }, __('Open Logs', 'click-trail-handler'))
                ])
            ]);
        }

        function renderDeliveryTab() {
            return [
                el(InlineNotice, {
                    key: 'delivery-note',
                    text: __('Delivery controls cover transport, consent, queue health, and the safeguards that keep outbound tracking reliable. Most sites only need the server-side transport card and privacy controls.', 'click-trail-handler')
                }),
                serverLocked
                    ? el(InlineNotice, {
                        key: 'network-note',
                        warning: true,
                        icon: 'dashicons-admin-site-alt3',
                        text: __('This site is currently using network defaults for server-side delivery. Disable the network toggle below to customize this site independently.', 'click-trail-handler')
                    })
                    : null,
                el(AppCard, {
                    key: 'delivery-server',
                    icon: 'dashicons-cloud',
                    title: __('Server-side transport', 'click-trail-handler'),
                    description: __('Route events through your own collector endpoint when you need a more durable delivery path.', 'click-trail-handler')
                }, [
                    !!getIn(settings, 'delivery.server.has_network_defaults', false)
                        ? renderToggle('delivery.server.use_network', __('Use network defaults', 'click-trail-handler'), __('Use the multisite network configuration for this site.', 'click-trail-handler'))
                        : null,
                    renderToggle('delivery.server.enabled', __('Enable server-side delivery', 'click-trail-handler'), __('Send events through your own collector endpoint.', 'click-trail-handler'), {
                        disabled: serverLocked
                    }),
                    renderText('delivery.server.endpoint_url', __('Collector URL', 'click-trail-handler'), __('Endpoint that receives server-side events.', 'click-trail-handler'), {
                        disabled: serverLocked || !serverEnabled,
                        placeholder: 'https://collect.example.com'
                    }),
                    renderSelect('delivery.server.adapter', __('Delivery adapter', 'click-trail-handler'), __('Choose the format best suited to your receiving endpoint.', 'click-trail-handler'), {
                        disabled: serverLocked || !serverEnabled
                    }, [
                        { label: __('Generic Collector', 'click-trail-handler'), value: 'generic' },
                        { label: __('sGTM (Server GTM)', 'click-trail-handler'), value: 'sgtm' },
                        { label: __('Meta CAPI', 'click-trail-handler'), value: 'meta_capi' },
                        { label: __('Google Ads / GA4', 'click-trail-handler'), value: 'google_ads' },
                        { label: __('LinkedIn CAPI', 'click-trail-handler'), value: 'linkedin_capi' }
                    ]),
                    renderText('delivery.server.timeout', __('Request timeout (seconds)', 'click-trail-handler'), __('How long ClickTrail should wait before treating a delivery attempt as failed.', 'click-trail-handler'), {
                        disabled: serverLocked || !serverEnabled,
                        type: 'number'
                    }),
                    renderToggle('delivery.server.remote_failure_telemetry', __('Share anonymous failure counts', 'click-trail-handler'), __('Only aggregated failure counts are shared. No payloads or personal data are included.', 'click-trail-handler'), {
                        disabled: serverLocked || !serverEnabled
                    })
                ]),
                el(AppCard, {
                    key: 'delivery-privacy',
                    icon: 'dashicons-privacy',
                    title: __('Privacy & consent', 'click-trail-handler'),
                    description: __('Control when tracking is allowed to start and which consent signals ClickTrail should use.', 'click-trail-handler')
                }, [
                    renderToggle('delivery.privacy.enabled', __('Enable consent mode', 'click-trail-handler'), __('Gate attribution and event collection until consent requirements are satisfied.', 'click-trail-handler')),
                    renderSelect('delivery.privacy.mode', __('Consent behavior', 'click-trail-handler'), __('Choose whether consent is always required, never required, or region-based.', 'click-trail-handler'), {
                        disabled: !consentEnabled
                    }, [
                        { label: __('Strict', 'click-trail-handler'), value: 'strict' },
                        { label: __('Relaxed', 'click-trail-handler'), value: 'relaxed' },
                        { label: __('Region-based', 'click-trail-handler'), value: 'geo' }
                    ]),
                    renderTextarea('delivery.privacy.regions', __('Regions requiring consent', 'click-trail-handler'), __('One region per line. Examples: EEA, UK, CA, US-CA.', 'click-trail-handler'), {
                        disabled: !consentEnabled,
                        rows: 3,
                        placeholder: 'EEA\nUK'
                    }),
                    renderSelect('delivery.privacy.cmp_source', __('Consent source', 'click-trail-handler'), __('Which consent platform ClickTrail should listen to.', 'click-trail-handler'), {
                        disabled: !consentEnabled
                    }, [
                        { label: __('Auto-detect', 'click-trail-handler'), value: 'auto' },
                        { label: __('ClickTrail plugin', 'click-trail-handler'), value: 'plugin' },
                        { label: __('Cookiebot', 'click-trail-handler'), value: 'cookiebot' },
                        { label: __('OneTrust', 'click-trail-handler'), value: 'onetrust' },
                        { label: __('Complianz', 'click-trail-handler'), value: 'complianz' },
                        { label: __('Google Tag Manager', 'click-trail-handler'), value: 'gtm' },
                        { label: __('Custom', 'click-trail-handler'), value: 'custom' }
                    ]),
                    renderText('delivery.privacy.cmp_timeout_ms', __('Consent wait time (ms)', 'click-trail-handler'), __('How long ClickTrail should wait for a consent signal before continuing.', 'click-trail-handler'), {
                        disabled: !consentEnabled,
                        type: 'number'
                    })
                ]),
                renderDeliveryHealth(),
                el(AppCard, {
                    key: 'delivery-advanced',
                    icon: 'dashicons-admin-tools',
                    title: __('Advanced delivery controls', 'click-trail-handler'),
                    description: __('Security, buffering, deduplication, and low-level transport controls for technical users.', 'click-trail-handler'),
                    collapsible: true,
                    collapsed: true,
                    tag: __('Advanced', 'click-trail-handler'),
                    tagClass: 'clicktrail-card__tag--muted'
                }, [
                    renderToggle('delivery.advanced.use_native_adapters', __('Use native platform adapters', 'click-trail-handler'), __('Prefer ClickTrail\'s built-in platform adapters when available.', 'click-trail-handler')),
                    renderToggle('delivery.advanced.store_event_diagnostics', __('Store structured event diagnostics', 'click-trail-handler'), __('Keep a structured intake buffer for troubleshooting and validation.', 'click-trail-handler')),
                    renderToggle('delivery.advanced.encrypt_saved_secrets', __('Encrypt saved secrets', 'click-trail-handler'), __('Encrypt stored secrets at rest when supported by the host environment.', 'click-trail-handler')),
                    renderText('delivery.advanced.token_ttl_seconds', __('Attribution token lifetime (seconds)', 'click-trail-handler'), __('Controls how long attribution tokens remain valid.', 'click-trail-handler'), { type: 'number' }),
                    renderText('delivery.advanced.token_nonce_limit', __('Maximum token replays (0 to disable)', 'click-trail-handler'), __('Cap how many times the same token nonce can be used.', 'click-trail-handler'), { type: 'number' }),
                    renderText('delivery.advanced.webhook_replay_window', __('Webhook replay protection window (seconds)', 'click-trail-handler'), __('Reject webhook signatures that fall outside this replay window.', 'click-trail-handler'), { type: 'number' }),
                    renderText('delivery.advanced.rate_limit_window', __('API rate limit window (seconds)', 'click-trail-handler'), __('The time window used by intake rate limiting.', 'click-trail-handler'), { type: 'number' }),
                    renderText('delivery.advanced.rate_limit_limit', __('API requests allowed per window', 'click-trail-handler'), __('Maximum requests allowed within the configured rate limit window.', 'click-trail-handler'), { type: 'number' }),
                    renderTextarea('delivery.advanced.trusted_proxies', __('Trusted proxy IPs or CIDR ranges', 'click-trail-handler'), __('One proxy per line. Only add proxies you control.', 'click-trail-handler'), { rows: 3 }),
                    renderTextarea('delivery.advanced.allowed_token_hosts', __('Allowed token hosts', 'click-trail-handler'), __('Hosts allowed to mint or receive attribution tokens. One host per line.', 'click-trail-handler'), { rows: 3 }),
                    renderText('delivery.advanced.dispatch_buffer_size', __('Recent dispatch records kept', 'click-trail-handler'), __('How many recent dispatch attempts should be kept for diagnostics.', 'click-trail-handler'), { type: 'number' }),
                    renderText('delivery.advanced.failure_flush_interval', __('Failure summary flush interval (seconds)', 'click-trail-handler'), __('How often failure counters are flushed into hourly telemetry buckets.', 'click-trail-handler'), { type: 'number' }),
                    renderText('delivery.advanced.failure_bucket_retention', __('Failure summary retention (hours)', 'click-trail-handler'), __('How long failure telemetry buckets should be retained.', 'click-trail-handler'), { type: 'number' }),
                    renderText('delivery.advanced.dedup_ttl_seconds', __('Event deduplication window (seconds)', 'click-trail-handler'), __('How long dispatch deduplication markers should be kept.', 'click-trail-handler'), { type: 'number' })
                ])
            ];
        }

        function renderActiveTab() {
            if (activeTab === 'forms') {
                return renderFormsTab();
            }
            if (activeTab === 'events') {
                return renderEventsTab();
            }
            if (activeTab === 'delivery') {
                return renderDeliveryTab();
            }
            return renderCaptureTab();
        }

        return el('div', { className: 'clicktrail-settings-app' }, [
            el('div', { key: 'header', className: 'clicktrail-page-header' }, [
                el('div', { key: 'title', className: 'clicktrail-page-title' }, [
                    el('span', { key: 'eyebrow', className: 'clicktrail-page-eyebrow' }, config.pageTitle || 'ClickTrail'),
                    el('h1', { key: 'heading' }, activeMeta.title || __('ClickTrail', 'click-trail-handler')),
                    activeMeta.description
                        ? el('p', { key: 'desc', className: 'clicktrail-page-description' }, activeMeta.description)
                        : null
                ])
            ]),
            config.migrationNotice
                ? el(Notice, {
                    key: 'migration',
                    status: 'info',
                    isDismissible: false
                }, config.migrationNotice)
                : null,
            notice
                ? el(Notice, {
                    key: 'notice',
                    status: notice.status || 'info',
                    isDismissible: true,
                    onRemove: function () {
                        setNotice(null);
                    }
                }, notice.message || '')
                : null,
            loading ? el('div', { key: 'loading', style: { marginBottom: '12px' } }, el(Spinner)) : null,
            el(SummaryBar, { key: 'summary', items: computeStatusItems() }),
            el('h2', { key: 'tabs', className: 'nav-tab-wrapper clicktrail-app-tabs' }, tabOrder.map(function (slug) {
                var tab = tabs[slug] || {};
                return el('a', {
                    key: slug,
                    href: buildTabUrl(slug),
                    className: 'nav-tab ' + (slug === activeTab ? 'nav-tab-active' : ''),
                    onClick: function (event) {
                        switchTab(event, slug);
                    }
                }, [
                    el('span', {
                        key: 'icon',
                        className: 'dashicons ' + (tab.icon || 'dashicons-admin-generic'),
                        'aria-hidden': 'true'
                    }),
                    ' ',
                    tab.label || slug
                ]);
            })),
            el('div', { key: 'panel', className: 'clicktrail-settings-panel' }, renderActiveTab()),
            el('div', { key: 'actions', className: 'clicktrail-save-bar' }, [
                el(Button, {
                    key: 'save',
                    variant: 'primary',
                    isBusy: saving,
                    disabled: saving || loading,
                    onClick: save
                }, __('Save Changes', 'click-trail-handler')),
                el(Button, {
                    key: 'reload',
                    variant: 'secondary',
                    disabled: saving || loading,
                    onClick: reload
                }, __('Reload Saved Settings', 'click-trail-handler'))
            ])
        ]);
    }

    function mount() {
        var root = document.getElementById('clicutcl-admin-settings-root');
        if (!root) {
            return;
        }

        if (typeof wp.element.createRoot === 'function') {
            wp.element.createRoot(root).render(el(App));
        } else if (typeof wp.element.render === 'function') {
            wp.element.render(el(App), root);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mount);
    } else {
        mount();
    }
})(window.wp, window.clicutclAdminSettingsConfig || {});
