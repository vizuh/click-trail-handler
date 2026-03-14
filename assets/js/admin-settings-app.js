(function (wp, config) {
    'use strict';

    if (!wp || !wp.element || !wp.components) {
        return;
    }

    var el = wp.element.createElement;
    var useState = wp.element.useState;
    var useEffect = wp.element.useEffect;
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

        return el('section', {
            className: classes.join(' '),
            id: props.sectionId || undefined,
            tabIndex: props.sectionId ? '-1' : undefined
        }, [
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

    function SetupChecklist(props) {
        var items = Array.isArray(props.items) ? props.items : [];
        if (!items.length) {
            return null;
        }

        function toneForStatus(status) {
            if (status === 'ready') {
                return 'ok';
            }
            if (status === 'attention') {
                return 'warn';
            }
            if (status === 'disabled') {
                return 'neutral';
            }
            return 'info';
        }

        function renderItem(item) {
            var isNavigable = !!(item && item.target_tab);
            var classes = 'clicktrail-diagnostic-stat clicktrail-diagnostic-stat--' + toneForStatus(item.status || 'info') + (isNavigable ? ' clicktrail-diagnostic-stat--action' : '');
            var children = [
                el('div', { key: 'label', className: 'clicktrail-diagnostic-stat__label' }, item.label || ''),
                el('div', { key: 'value', className: 'clicktrail-diagnostic-stat__value' }, item.status === 'ready' ? __('Ready', 'click-trail-handler') : (item.status === 'attention' ? __('Needs Review', 'click-trail-handler') : (item.status === 'disabled' ? __('Unavailable', 'click-trail-handler') : __('Optional', 'click-trail-handler')))),
                el('div', { key: 'detail', className: 'clicktrail-diagnostic-stat__sub' }, item.detail || '')
            ];

            if (!isNavigable) {
                return el('div', {
                    key: item.key || item.label,
                    className: classes
                }, children);
            }

            return el('button', {
                key: item.key || item.label,
                type: 'button',
                className: classes,
                onClick: function () {
                    if (typeof props.onNavigate === 'function') {
                        props.onNavigate(item);
                    }
                }
            }, children);
        }

        return el(AppCard, {
            key: 'setup-checklist',
            icon: 'dashicons-yes-alt',
            title: __('Setup Checklist', 'click-trail-handler'),
            description: __('Read-only readiness checks for rollout, delivery, and WooCommerce coverage.', 'click-trail-handler')
        }, el('div', { className: 'clicktrail-diagnostics-grid clicktrail-diagnostics-grid--compact' }, items.map(renderItem)));
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
        var pendingSectionState = useState('');
        var pendingSection = pendingSectionState[0];
        var setPendingSection = pendingSectionState[1];

        var sgtmPreviewState = useState(null);
        var sgtmPreview = sgtmPreviewState[0];
        var setSgtmPreview = sgtmPreviewState[1];

        var sgtmPreviewLoadingState = useState(false);
        var sgtmPreviewLoading = sgtmPreviewLoadingState[0];
        var setSgtmPreviewLoading = sgtmPreviewLoadingState[1];

        var tabs = config.tabs || {};
        var tabOrder = ['capture', 'forms', 'events', 'delivery'];
        var activeMeta = tabs[activeTab] || tabs.capture || {};
        var settingsBaseUrl = getIn(settings, 'urls.settings', '');
        var registry = config.registry || {};
        var setupChecklist = Array.isArray(getIn(settings, 'setup_checklist', [])) ? getIn(settings, 'setup_checklist', []) : [];
        var adapterRegistry = Array.isArray(registry.adapters) ? registry.adapters : [];
        var destinationRegistry = Array.isArray(registry.destinations) ? registry.destinations : [];
        var serverLocked = !!getIn(settings, 'delivery.server.has_network_defaults', false) && !!getIn(settings, 'delivery.server.use_network', false);
        var serverEnabled = !!getIn(settings, 'delivery.server.enabled', false);
        var consentEnabled = !!getIn(settings, 'delivery.privacy.enabled', false);
        var browserPipelineEnabled = !!getIn(settings, 'events.browser_pipeline', false);
        var lifecycleEnabled = !!getIn(settings, 'events.lifecycle.accept_updates', false);
        var lifecycleEndpointEnabled = lifecycleEnabled && !!getIn(settings, 'events.lifecycle.endpoint_enabled', false);
        var formFallbackEnabled = !!getIn(settings, 'forms.client_fallback', false);
        var linkDecorationEnabled = !!getIn(settings, 'capture.decorate_links', false);
        var whatsappEnabled = !!getIn(settings, 'forms.whatsapp.enabled', false);
        var webhookSourcesEnabled = !!getIn(settings, 'forms.webhook_sources_enabled', false);
        var gtmMode = String(getIn(settings, 'events.gtm_mode', 'standard') || 'standard');
        var sgtmModeEnabled = gtmMode === 'sgtm';
        var customLoaderEnabled = !!getIn(settings, 'events.gtm_custom_loader_enabled', false);
        var wooEnhancedDataLayerEnabled = !!getIn(settings, 'events.woo_enhanced_datalayer', false);

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

        function scrollToSection(sectionId) {
            if (!sectionId) {
                return false;
            }

            var node = document.getElementById(sectionId);
            if (!node) {
                return false;
            }

            if (typeof node.scrollIntoView === 'function') {
                node.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }

            if (typeof node.focus === 'function') {
                try {
                    node.focus({ preventScroll: true });
                } catch (e) {
                    node.focus();
                }
            }

            return true;
        }

        function navigateChecklistItem(item) {
            var targetTab = String(item && (item.target_tab || item.targetTab) || '');
            var targetSection = String(item && (item.target_section || item.targetSection) || '');

            if (targetSection) {
                setPendingSection(targetSection);
            }

            if (targetTab) {
                switchTab(null, targetTab);
            }
        }

        useEffect(function () {
            var attempts = 0;
            var timer = 0;

            if (!pendingSection) {
                return undefined;
            }

            function tryScroll() {
                if (scrollToSection(pendingSection)) {
                    setPendingSection('');
                    return;
                }

                attempts += 1;
                if (attempts >= 6) {
                    setPendingSection('');
                    return;
                }

                timer = window.setTimeout(tryScroll, 70);
            }

            tryScroll();

            return function () {
                if (timer) {
                    window.clearTimeout(timer);
                }
            };
        }, [activeTab, pendingSection]);

        function reload() {
            setLoading(true);
            setNotice(null);
            setSgtmPreview(null);
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

        function runSgtmPreviewChecks() {
            setSgtmPreviewLoading(true);
            setNotice(null);
            postAjax('clicutcl_sgtm_preview_check', {
                settings: JSON.stringify(settings || {})
            })
                .then(function (json) {
                    if (!json || !json.success) {
                        throw new Error((json && json.data && json.data.message) || 'preview_failed');
                    }

                    setSgtmPreview(json.data && json.data.report ? json.data.report : null);
                })
                .catch(function () {
                    setNotice({
                        status: 'error',
                        message: __('Failed to run sGTM preview checks.', 'click-trail-handler')
                    });
                })
                .finally(function () {
                    setSgtmPreviewLoading(false);
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
                    sectionId: 'capture-core',
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
                    sectionId: 'capture-cross-domain',
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
                    sectionId: 'forms-onsite',
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
                    sectionId: 'forms-whatsapp',
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
                    sectionId: 'forms-providers',
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
                    sectionId: 'forms-advanced',
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
            var previewChecks = Array.isArray(getIn(sgtmPreview, 'checks', [])) ? getIn(sgtmPreview, 'checks', []) : [];
            var templateHints = Array.isArray(getIn(sgtmPreview, 'template_hints', [])) ? getIn(sgtmPreview, 'template_hints', []) : [];

            function destinationHelp(entry) {
                if (entry && entry.support_level === 'relay_only') {
                    return __('Mark this destination when another relay or downstream collector owns final delivery.', 'click-trail-handler');
                }

                return __('Send eligible events to this destination when the matching delivery adapter or downstream collector is configured.', 'click-trail-handler');
            }

            function toneForCheck(status) {
                if (status === 'ready') {
                    return 'ok';
                }
                if (status === 'attention') {
                    return 'warn';
                }
                return 'info';
            }

            return [
                el(InlineNotice, {
                    key: 'events-note',
                    text: __('ClickTrail uses one unified event pipeline behind the scenes for browser events, webhooks, and server delivery. Configure the capabilities you use without worrying about the internal pipeline.', 'click-trail-handler')
                }),
                el(AppCard, {
                    key: 'events-core',
                    sectionId: 'events-core',
                    icon: 'dashicons-chart-bar',
                    title: __('Event collection', 'click-trail-handler'),
                    description: __('Control browser event collection and choose whether GTM should load in standard or sGTM compatibility mode.', 'click-trail-handler')
                }, [
                    renderToggle('events.browser_pipeline', __('Enable browser event collection', 'click-trail-handler'), __('Collect page, click, and form events through ClickTrail\'s unified event layer.', 'click-trail-handler')),
                    renderText('events.gtm_container_id', __('Google Tag Manager container ID', 'click-trail-handler'), __('Use only if your site does not already load Google Tag Manager.', 'click-trail-handler'), {
                        placeholder: 'GTM-XXXXXXX'
                    }),
                    renderSelect('events.gtm_mode', __('GTM loader mode', 'click-trail-handler'), __('Use standard mode for the default Google host, or sGTM mode when you want a tagging-server URL or custom loader path.', 'click-trail-handler'), {}, [
                        { label: __('Standard GTM', 'click-trail-handler'), value: 'standard' },
                        { label: __('sGTM compatibility mode', 'click-trail-handler'), value: 'sgtm' }
                    ]),
                    renderText('events.gtm_tagging_server_url', __('Tagging server URL', 'click-trail-handler'), __('Used for first-party GTM delivery and preview checks in sGTM mode.', 'click-trail-handler'), {
                        disabled: !sgtmModeEnabled,
                        placeholder: 'https://sgtm.example.com'
                    }),
                    renderToggle('events.gtm_first_party_script', __('Use first-party script delivery', 'click-trail-handler'), __('Load `gtm.js` and `ns.html` from the tagging server instead of the default Google host.', 'click-trail-handler'), {
                        disabled: !sgtmModeEnabled
                    }),
                    renderToggle('events.gtm_custom_loader_enabled', __('Use a custom loader path', 'click-trail-handler'), __('Prefer a same-site loader path when your sGTM setup rewrites GTM requests through a first-party URL.', 'click-trail-handler'), {
                        disabled: !sgtmModeEnabled
                    }),
                    renderText('events.gtm_custom_loader_url', __('Custom loader URL or path', 'click-trail-handler'), __('Examples: `/metrics/gtm.js` or `https://metrics.example.com/gtm.js`.', 'click-trail-handler'), {
                        disabled: !sgtmModeEnabled || !customLoaderEnabled,
                        placeholder: '/metrics/gtm.js'
                    }),
                    el(InlineNotice, {
                        key: 'events-gtm-mode-note',
                        text: sgtmModeEnabled
                            ? __('sGTM mode changes only the loader path and preview workflow. ClickTrail still uses the same canonical event pipeline underneath.', 'click-trail-handler')
                            : __('Standard mode keeps the default Google Tag Manager loader. Switch to sGTM mode when you need a tagging-server URL, first-party delivery, or a custom loader path.', 'click-trail-handler')
                    })
                ]),
                el(AppCard, {
                    key: 'events-sgtm-wizard',
                    sectionId: 'events-sgtm-wizard',
                    icon: 'dashicons-cloud',
                    title: __('sGTM setup wizard', 'click-trail-handler'),
                    description: __('Use this checklist when ClickTrail should cooperate with a server-side GTM stack without turning into a generic tag manager.', 'click-trail-handler')
                }, [
                    el(InlineNotice, {
                        key: 'events-sgtm-intro',
                        text: __('Recommended flow: set the web container, choose a tagging-server URL or custom loader, point Delivery to the sGTM adapter when ClickTrail sends server events, then verify ownership in GTM Preview.', 'click-trail-handler')
                    }),
                    el('ol', {
                        key: 'events-sgtm-steps',
                        className: 'clicktrail-checklist'
                    }, [
                        el('li', { key: 'step-container' }, __('Set the GTM web container ID for the site that owns the browser dataLayer.', 'click-trail-handler')),
                        el('li', { key: 'step-loader' }, __('Choose a tagging-server URL for first-party delivery or a custom loader path if your stack rewrites GTM requests.', 'click-trail-handler')),
                        el('li', { key: 'step-delivery' }, __('When ClickTrail should send server events into sGTM, switch Delivery to the sGTM adapter and point it at the collector URL.', 'click-trail-handler')),
                        el('li', { key: 'step-preview' }, __('Run preview checks here, then confirm the web and server containers receive the events you expect without duplicate ownership.', 'click-trail-handler'))
                    ]),
                    el('div', { key: 'events-sgtm-actions', className: 'clicktrail-ops-links' }, [
                        el(Button, {
                            key: 'events-sgtm-run',
                            variant: 'secondary',
                            isBusy: sgtmPreviewLoading,
                            disabled: sgtmPreviewLoading,
                            onClick: runSgtmPreviewChecks
                        }, __('Run Preview Checks', 'click-trail-handler'))
                    ]),
                    sgtmPreviewLoading
                        ? el('div', { key: 'events-sgtm-loading', style: { marginTop: '12px' } }, el(Spinner))
                        : null,
                    sgtmPreview && getIn(sgtmPreview, 'summary', '')
                        ? el(InlineNotice, {
                            key: 'events-sgtm-summary',
                            text: String(getIn(sgtmPreview, 'summary', ''))
                        })
                        : null,
                    previewChecks.length
                        ? el('div', { key: 'events-sgtm-checks', className: 'clicktrail-diagnostics-grid clicktrail-diagnostics-grid--compact' }, previewChecks.map(function (check) {
                            return el('div', {
                                key: check.key || check.label,
                                className: 'clicktrail-diagnostic-stat clicktrail-diagnostic-stat--' + toneForCheck(check.status || 'info')
                            }, [
                                el('div', { key: 'label', className: 'clicktrail-diagnostic-stat__label' }, check.label || ''),
                                el('div', { key: 'value', className: 'clicktrail-diagnostic-stat__value' }, check.status === 'ready' ? __('Ready', 'click-trail-handler') : (check.status === 'attention' ? __('Needs Review', 'click-trail-handler') : __('Optional', 'click-trail-handler'))),
                                el('div', { key: 'detail', className: 'clicktrail-diagnostic-stat__sub' }, check.detail || '')
                            ]);
                        }))
                        : null,
                    templateHints.length
                        ? el('div', { key: 'events-sgtm-hints', className: 'clicktrail-setting-block' }, [
                            el('strong', { key: 'events-sgtm-hints-title' }, __('Destination template hints', 'click-trail-handler')),
                            el('ul', { key: 'events-sgtm-hints-list' }, templateHints.map(function (hint) {
                                return el('li', { key: hint.key || hint.label }, (hint.label || '') + ': ' + (hint.detail || ''));
                            }))
                        ])
                        : null
                ]),
                el(AppCard, {
                    key: 'events-woocommerce',
                    sectionId: 'events-woocommerce',
                    icon: 'dashicons-cart',
                    title: __('WooCommerce', 'click-trail-handler'),
                    description: __('Keep campaign context visible on WooCommerce orders and extend the same event pipeline into the storefront when you need it.', 'click-trail-handler')
                }, [
                    el(InlineNotice, {
                        key: 'events-woo-orders',
                        text: __('Order attribution is stored on WooCommerce orders during checkout, so campaign context remains available after the visitor leaves the landing page.', 'click-trail-handler')
                    }),
                    el(InlineNotice, {
                        key: 'events-woo-purchase',
                        text: __('Purchase events are pushed automatically on the thank-you page and can also flow into ClickTrail\'s server-side delivery adapters when Delivery is enabled.', 'click-trail-handler')
                    }),
                    renderToggle('events.woocommerce_storefront_events', __('Enable WooCommerce storefront events', 'click-trail-handler'), __('Emit GA4-style `view_item`, `view_item_list`, `view_cart`, `add_to_cart`, `remove_from_cart`, and `begin_checkout` events through ClickTrail\'s browser event layer. Existing installs keep this off until you enable it.', 'click-trail-handler'), {
                        disabled: !browserPipelineEnabled
                    }),
                    renderToggle('events.woo_enhanced_datalayer', __('Use the richer Woo dataLayer contract', 'click-trail-handler'), __('Add `event_id` to Woo purchase pushes and make richer Woo browser events available for GTM-first setups.', 'click-trail-handler')),
                    renderToggle('events.woo_include_user_data', __('Include consent-aware Woo user_data', 'click-trail-handler'), __('Emit `user_data` objects with browser identifiers and purchase identity only when the richer contract is on and marketing consent is granted.', 'click-trail-handler'), {
                        disabled: !wooEnhancedDataLayerEnabled
                    }),
                    el(InlineNotice, {
                        key: 'events-woo-contract',
                        text: __('The richer contract is optional. ClickTrail still keeps Woo tracking on the same canonical pipeline and only widens the dataLayer shape for GTM-first workflows.', 'click-trail-handler')
                    }),
                    el(InlineNotice, {
                        key: 'events-woo-verify',
                        text: __('Verify WooCommerce order attribution in the order screen, storefront/browser events with GTM Preview or the dataLayer, and server-side traces from ClickTrail > Diagnostics.', 'click-trail-handler')
                    })
                ]),
                el(AppCard, {
                    key: 'events-destinations',
                    sectionId: 'events-destinations',
                    icon: 'dashicons-share',
                    title: __('Destinations', 'click-trail-handler'),
                    description: __('Choose which advertising platforms should receive compatible event payloads.', 'click-trail-handler')
                }, (destinationRegistry.length ? destinationRegistry : [
                    { key: 'meta', label: __('Meta', 'click-trail-handler') },
                    { key: 'google', label: __('Google', 'click-trail-handler') },
                    { key: 'linkedin', label: __('LinkedIn', 'click-trail-handler') },
                    { key: 'reddit', label: __('Reddit', 'click-trail-handler') },
                    { key: 'pinterest', label: __('Pinterest', 'click-trail-handler') },
                    { key: 'tiktok', label: __('TikTok', 'click-trail-handler') }
                ]).map(function (entry) {
                    return renderToggle('events.destinations.' + entry.key, entry.label, destinationHelp(entry));
                })),
                el(AppCard, {
                    key: 'events-lifecycle',
                    sectionId: 'events-lifecycle',
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
                sectionId: 'delivery-health',
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
                    sectionId: 'delivery-server',
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
                    }, adapterRegistry.length ? adapterRegistry : [
                        { label: __('Generic Collector', 'click-trail-handler'), value: 'generic' },
                        { label: __('sGTM (Server GTM)', 'click-trail-handler'), value: 'sgtm' },
                        { label: __('Meta CAPI', 'click-trail-handler'), value: 'meta_capi' },
                        { label: __('Google Ads / GA4', 'click-trail-handler'), value: 'google_ads' },
                        { label: __('LinkedIn CAPI', 'click-trail-handler'), value: 'linkedin_capi' },
                        { label: __('Pinterest Conversions API', 'click-trail-handler'), value: 'pinterest_capi' },
                        { label: __('TikTok Events API', 'click-trail-handler'), value: 'tiktok_events_api' }
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
                    sectionId: 'delivery-privacy',
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
                    sectionId: 'delivery-advanced',
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
            el(SetupChecklist, { key: 'checklist', items: setupChecklist, onNavigate: navigateChecklistItem }),
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
