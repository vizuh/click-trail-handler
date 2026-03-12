(function () {
    'use strict';

    /**
     * ClickTrail Events Tracking
     * Handles: Search, Downloads, Scroll, Time on Page
     */
    class ClickTrailEvents {
        constructor() {
            this.collectionEnabled = !!(window.clicutclEventsConfig && window.clicutclEventsConfig.enabled);
            this.debugEnabled = !!(window.clicutclEventsConfig && window.clicutclEventsConfig.debug);
            this.transport = {
                enabled: !!(window.clicutclEventsConfig && window.clicutclEventsConfig.transportEnabled),
                url: window.clicutclEventsConfig && window.clicutclEventsConfig.eventsBatchUrl ? String(window.clicutclEventsConfig.eventsBatchUrl) : '',
                token: window.clicutclEventsConfig && window.clicutclEventsConfig.eventsToken ? String(window.clicutclEventsConfig.eventsToken) : ''
            };
            this.thankYouMatchers = Array.isArray(window.clicutclEventsConfig && window.clicutclEventsConfig.thankYouMatchers)
                ? window.clicutclEventsConfig.thankYouMatchers
                : [];
            this.iframeOrigins = Array.isArray(window.clicutclEventsConfig && window.clicutclEventsConfig.iframeOrigins)
                ? window.clicutclEventsConfig.iframeOrigins
                : [];
            this.formStarts = new WeakSet();
            this.externalMarkers = new Set();
            this.sessionId = this.getOrCreateSessionId();
            this.visitorId = this.getOrCreateVisitorId();
            this.init();
        }

        init() {
            if (!this.collectionEnabled) {
                this.debugLog('Browser event collection disabled.');
                return;
            }

            this.trackSearch();
            this.trackDownloads();
            this.trackScroll();
            this.trackTimeOnPage();
            this.trackLeadGenEvents();
            this.trackThankYouLead();
            this.trackExternalFormMessages();
        }

        pushEvent(eventName, params = {}) {
            if (!this.collectionEnabled) {
                return;
            }

            const consentBridge = window.ClickTrailConsent;
            if (typeof consentBridge === 'undefined' || !consentBridge.isGranted()) {
                this.debugLog('Event blocked (no consent):', eventName);
                return;
            }

            window.dataLayer = window.dataLayer || [];
            const eventId = this.generateEventId(eventName);
            const eventData = {
                event: eventName,
                event_id: eventId,
                session_id: this.sessionId,
                visitor_id: this.visitorId,
                ...params
            };

            this.debugLog('ClickTrail Event:', eventName, eventData);

            window.dataLayer.push(eventData);
            this.sendServerEvent(eventName, eventData, eventId);
        }

        debugLog(...args) {
            if (!this.debugEnabled) return;
            console.log('[ClickTrail]', ...args);
        }

        sendServerEvent(eventName, eventData, eventId) {
            if (!this.transport.enabled || !this.transport.url || !this.transport.token) return;

            const canonical = this.buildCanonicalEvent(eventName, eventData, eventId);
            if (!canonical) return;

            const body = JSON.stringify({
                token: this.transport.token,
                events: [canonical]
            });

            fetch(this.transport.url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Clicutcl-Token': this.transport.token
                },
                body,
                keepalive: true
            }).catch(() => {
                this.debugLog('Server event send failed:', eventName);
            });
        }

        buildCanonicalEvent(eventName, eventData, eventId) {
            const map = {
                view_search_results: { event_name: 'search', funnel_stage: 'top' },
                file_download: { event_name: 'view_content', funnel_stage: 'mid' },
                scroll: { event_name: 'scroll_depth', funnel_stage: 'top' },
                user_engagement: { event_name: 'key_page_view', funnel_stage: 'top' },
                cta_click: { event_name: 'cta_click', funnel_stage: 'mid' },
                form_start: { event_name: 'form_start', funnel_stage: 'mid' },
                form_submit_attempt: { event_name: 'form_submit_attempt', funnel_stage: 'mid' },
                lead: { event_name: 'lead', funnel_stage: 'bottom' },
                contact_call_click: { event_name: 'contact_call_click', funnel_stage: 'mid' },
                contact_chat_start: { event_name: 'contact_chat_start', funnel_stage: 'mid' },
                book_appointment: { event_name: 'book_appointment', funnel_stage: 'bottom' },
                qualified_lead: { event_name: 'qualified_lead', funnel_stage: 'bottom' },
                client_won: { event_name: 'client_won', funnel_stage: 'bottom' }
            };

            const mapped = map[eventName] || { event_name: String(eventName || ''), funnel_stage: 'unknown' };
            const attribution = this.getAttributionPayload();
            const consent = this.getConsentState();
            const leadContext = this.extractLeadContext(eventData, eventName);

            return {
                event_name: mapped.event_name,
                event_id: eventId,
                event_time: Math.floor(Date.now() / 1000),
                funnel_stage: mapped.funnel_stage,
                session_id: this.sessionId,
                source_channel: 'web',
                page_context: {
                    path: window.location.pathname || '/',
                    title: document.title || '',
                    referrer: document.referrer || '',
                    viewport_w: window.innerWidth || 0,
                    viewport_h: window.innerHeight || 0
                },
                attribution,
                consent,
                lead_context: leadContext,
                meta: {
                    schema_version: 2,
                    source_event: eventName,
                    device_type: (function () {
                        const w = window.innerWidth || 0;
                        const touch = navigator.maxTouchPoints > 0;
                        if (touch && w < 768) return 'mobile';
                        if (touch && w < 1200) return 'tablet';
                        return 'desktop';
                    }())
                }
            };
        }

        extractLeadContext(eventData, eventName) {
            const fallbackStatus = eventName === 'lead' || eventName === 'book_appointment' ? 'success' : 'captured';
            const fromEvent = eventData && typeof eventData.lead_context === 'object' ? eventData.lead_context : {};
            const out = {
                submit_status: this.safeText(fromEvent.submit_status || fallbackStatus),
                form_id: this.safeText(fromEvent.form_id || eventData.form_id || ''),
                form_name: this.safeText(fromEvent.form_name || eventData.form_name || ''),
                provider: this.safeText(fromEvent.provider || eventData.form_provider || ''),
                service_line: this.safeText(fromEvent.service_line || eventData.service_line || ''),
                validation_error_count: Number.isFinite(Number(fromEvent.validation_error_count))
                    ? Number(fromEvent.validation_error_count)
                    : 0
            };
            // Scroll depth: pass through exact value when present
            if (Number.isFinite(eventData.scroll_pct)) {
                out.scroll_pct = Math.round(eventData.scroll_pct);
            }
            if (Number.isFinite(eventData.scroll_threshold)) {
                out.scroll_threshold = parseInt(eventData.scroll_threshold);
            }
            // Form timing: pass through elapsed time when present
            if (Number.isFinite(eventData.time_to_submit_ms)) {
                out.time_to_submit_ms = Math.round(eventData.time_to_submit_ms);
            }
            return out;
        }

        getAttributionPayload() {
            if (window.ClickTrail && typeof window.ClickTrail.getData === 'function') {
                const data = window.ClickTrail.getData();
                if (data && typeof data === 'object') return this.sanitizeAttribution(data);
            }
            return {};
        }

        sanitizeAttribution(data) {
            const allow = [
                'ft_source', 'ft_medium', 'ft_campaign', 'ft_term', 'ft_content',
                'ft_utm_id', 'ft_utm_source_platform', 'ft_utm_creative_format', 'ft_utm_marketing_tactic',
                'lt_source', 'lt_medium', 'lt_campaign', 'lt_term', 'lt_content',
                'lt_utm_id', 'lt_utm_source_platform', 'lt_utm_creative_format', 'lt_utm_marketing_tactic',
                'ft_gclid', 'ft_fbclid', 'ft_msclkid', 'ft_ttclid', 'ft_wbraid', 'ft_gbraid',
                'lt_gclid', 'lt_fbclid', 'lt_msclkid', 'lt_ttclid', 'lt_wbraid', 'lt_gbraid',
                'ft_twclid', 'ft_li_fat_id', 'ft_sccid', 'ft_sc_click_id', 'ft_epik',
                'lt_twclid', 'lt_li_fat_id', 'lt_sccid', 'lt_sc_click_id', 'lt_epik',
                'gclid', 'fbclid', 'msclkid', 'ttclid', 'wbraid', 'gbraid',
                'twclid', 'li_fat_id', 'sccid', 'sc_click_id', 'epik',
                'fbc', 'fbp', 'ttp', 'li_gc', 'ga_client_id', 'ga_session_id', 'ga_session_number'
            ];

            const out = {};
            allow.forEach((key) => {
                if (!Object.prototype.hasOwnProperty.call(data, key)) return;
                const v = this.safeText(data[key], 128);
                if (v) out[key] = v;
            });
            return out;
        }

        getConsentState() {
            const consentBridge = window.ClickTrailConsent;
            if (
                typeof consentBridge !== 'undefined' &&
                typeof consentBridge.isResolved === 'function' &&
                typeof consentBridge.isGranted === 'function' &&
                consentBridge.isResolved()
            ) {
                const bridgeGranted = !!consentBridge.isGranted();
                return {
                    marketing: bridgeGranted,
                    analytics: bridgeGranted
                };
            }

            const cookieName = (
                window.ctConsentBridgeConfig && window.ctConsentBridgeConfig.cookieName
                    ? String(window.ctConsentBridgeConfig.cookieName)
                    : 'ct_consent'
            );
            const raw = this.getCookie(cookieName);
            if (!raw) return {};

            try {
                const parsed = JSON.parse(raw);
                return {
                    marketing: !!(parsed && parsed.marketing),
                    analytics: !!(parsed && parsed.analytics)
                };
            } catch (e) {
                const lowered = String(raw || '').trim().toLowerCase();
                if (lowered === 'granted' || lowered === '1' || lowered === 'true') {
                    return { marketing: true, analytics: true };
                }
                if (lowered === 'denied' || lowered === '0' || lowered === 'false') {
                    return { marketing: false, analytics: false };
                }
                return {};
            }
        }

        getCookie(name) {
            const match = document.cookie.match(new RegExp("(^| )" + name + "=([^;]+)"));
            return match ? decodeURIComponent(match[2]) : '';
        }

        setCookie(name, value, days) {
            let expires = '';
            if (days) {
                const date = new Date();
                date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
                expires = '; expires=' + date.toUTCString();
            }
            const secureFlag = window.location.protocol === 'https:' ? '; Secure' : '';
            document.cookie = name + "=" + encodeURIComponent(value) + expires + "; path=/; SameSite=Lax" + secureFlag;
        }

        generateEventId(prefix = 'evt') {
            if (window.crypto && typeof window.crypto.randomUUID === 'function') {
                return window.crypto.randomUUID();
            }
            return prefix + '_' + Math.random().toString(36).slice(2) + Date.now().toString(36);
        }

        getOrCreateSessionId() {
            try {
                const existing = sessionStorage.getItem('ct_session_id');
                if (existing) return existing;
                const created = this.generateEventId('sess');
                sessionStorage.setItem('ct_session_id', created);
                this.setCookie('ct_session_id', created, 1);
                return created;
            } catch (e) {
                const cookie = this.getCookie('ct_session_id');
                if (cookie) return cookie;
                const created = this.generateEventId('sess');
                this.setCookie('ct_session_id', created, 1);
                return created;
            }
        }

        getOrCreateVisitorId() {
            try {
                const existing = localStorage.getItem('ct_visitor_id');
                if (existing) return existing;
                const created = this.generateEventId('vis');
                localStorage.setItem('ct_visitor_id', created);
                this.setCookie('ct_visitor_id', created, 365);
                return created;
            } catch (e) {
                const cookie = this.getCookie('ct_visitor_id');
                if (cookie) return cookie;
                const created = this.generateEventId('vis');
                this.setCookie('ct_visitor_id', created, 365);
                return created;
            }
        }

        /**
         * Track Site Search
         * Detects ?s= or ?q= or ?search= parameters
         */
        trackSearch() {
            const params = new URLSearchParams(window.location.search);
            const searchTerms = params.get('s') || params.get('q') || params.get('search');

            if (searchTerms) {
                this.pushEvent('view_search_results', {
                    search_term: searchTerms
                });
            }
        }

        /**
         * Track File Downloads
         */
        trackDownloads() {
            const fileExtensions = ['pdf', 'zip', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'mp3', 'mp4', 'txt', 'csv'];

            document.addEventListener('click', (e) => {
                const link = e.target.closest('a');
                if (!link || !link.href) return;

                const url = link.href;
                const extension = url.split('.').pop().toLowerCase();

                if (fileExtensions.includes(extension)) {
                    this.pushEvent('file_download', {
                        file_name: url.split('/').pop(),
                        file_extension: extension,
                        link_url: url
                    });
                }
            });
        }

        /**
         * Track Scroll Depth
         * Tracks 25, 50, 75, 90%
         */
        trackScroll() {
            let marks = { 25: false, 50: false, 75: false, 90: false };

            const calculateScroll = () => {
                const scrollTop = window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop || 0;
                const scrollHeight = document.documentElement.scrollHeight || document.body.scrollHeight;
                const clientHeight = document.documentElement.clientHeight || window.innerHeight;

                // Calculate scroll percentage
                const percent = (scrollTop / (scrollHeight - clientHeight)) * 100;

                Object.keys(marks).forEach(mark => {
                    if (!marks[mark] && percent >= mark) {
                        marks[mark] = true;
                        this.pushEvent('scroll', {
                            // GTM standard built-in variables
                            'gtm.scrollThreshold': parseInt(mark),
                            'gtm.scrollUnits': 'percent',
                            'gtm.scrollDirection': 'vertical',
                            // GA4 Enhanced Measurement compatibility
                            'percent_scrolled': parseInt(mark),
                            // Exact depth at the moment of threshold crossing
                            'scroll_pct': Math.round(percent),
                            'scroll_threshold': parseInt(mark)
                        });
                    }
                });
            };

            window.addEventListener('scroll', () => {
                // Simple throttle
                if (this.scrollTimeout) return;
                this.scrollTimeout = setTimeout(() => {
                    calculateScroll();
                    this.scrollTimeout = null;
                }, 100);
            });
        }

        /**
         * Track Time on Page
         * Tracks 10s, 30s, 60s, 120s, 300s
         */
        trackTimeOnPage() {
            const timeThresholds = [
                { seconds: 10, label: '10_seconds', engagement: 'quick_view' },
                { seconds: 30, label: '30_seconds', engagement: 'browsing' },
                { seconds: 60, label: '1_minute', engagement: 'engaged' },
                { seconds: 120, label: '2_minutes', engagement: 'interested' },
                { seconds: 300, label: '5_minutes', engagement: 'highly_engaged' }
            ];

            timeThresholds.forEach(threshold => {
                setTimeout(() => {
                    // Only track if tab is visible
                    if (!document.hidden) {
                        this.pushEvent('user_engagement', {
                            // GTM friendly parameters
                            'engagement_time_msec': threshold.seconds * 1000,
                            'time_threshold': threshold.seconds,
                            'time_label': threshold.label,
                            'engagement_level': threshold.engagement,
                            // GA4 compatibility
                            'value': threshold.seconds
                        });
                    }
                }, threshold.seconds * 1000);
            });
        }

        trackLeadGenEvents() {
            document.addEventListener('focusin', (e) => {
                const form = e.target && e.target.closest ? e.target.closest('form') : null;
                if (!form || this.formStarts.has(form)) return;

                this.formStarts.add(form);
                const ctx = this.getFormContext(form);
                // Record start time keyed by form_id for completion timing (T4)
                if (!this._formStartTimes) this._formStartTimes = {};
                this._formStartTimes[ctx.form_id || '_default'] = Date.now();
                this.pushEvent('form_start', {
                    form_id: ctx.form_id,
                    form_name: ctx.form_name,
                    lead_context: {
                        ...ctx,
                        submit_status: 'started'
                    }
                });
            }, true);

            document.addEventListener('submit', (e) => {
                const form = e.target && e.target.closest ? e.target.closest('form') : null;
                if (!form) return;

                const ctx = this.getFormContext(form);
                const startKey = ctx.form_id || '_default';
                const timeToSubmit = (this._formStartTimes && this._formStartTimes[startKey])
                    ? Date.now() - this._formStartTimes[startKey]
                    : 0;
                this.pushEvent('form_submit_attempt', {
                    form_id: ctx.form_id,
                    form_name: ctx.form_name,
                    time_to_submit_ms: timeToSubmit,
                    lead_context: {
                        ...ctx,
                        submit_status: 'attempt'
                    }
                });
            }, true);

            document.addEventListener('wpcf7mailsent', (e) => {
                const formId = e && e.detail && e.detail.contactFormId ? String(e.detail.contactFormId) : '';
                this.pushEvent('lead', {
                    form_provider: 'cf7',
                    form_id: formId,
                    lead_context: {
                        provider: 'cf7',
                        form_id: formId,
                        submit_status: 'success'
                    }
                });
            });

            document.addEventListener('click', (e) => {
                const el = e.target && e.target.closest ? e.target.closest('a,button,[data-ct-cta],[data-clicktrail-cta],[data-chat-trigger],[data-booking-trigger]') : null;
                if (!el) return;

                const href = el.tagName === 'A' ? (el.getAttribute('href') || '') : '';

                if (href && href.toLowerCase().startsWith('tel:')) {
                    this.pushEvent('contact_call_click', {
                        cta_label: this.safeText(el.textContent || ''),
                        contact_type: 'phone'
                    });
                    return;
                }

                if (el.hasAttribute('data-chat-trigger')) {
                    this.pushEvent('contact_chat_start', {
                        cta_label: this.safeText(el.textContent || ''),
                        contact_type: 'chat'
                    });
                    return;
                }

                const lowerHref = href.toLowerCase();
                if (el.hasAttribute('data-booking-trigger') || lowerHref.includes('calendly.com') || lowerHref.includes('acuityscheduling.com')) {
                    this.pushEvent('book_appointment', {
                        cta_label: this.safeText(el.textContent || ''),
                        lead_context: {
                            provider: lowerHref.includes('calendly') ? 'calendly' : (lowerHref.includes('acuity') ? 'acuity' : ''),
                            submit_status: 'success'
                        }
                    });
                    return;
                }

                if (el.hasAttribute('data-ct-cta') || el.hasAttribute('data-clicktrail-cta') || el.hasAttribute('data-cta')) {
                    this.pushEvent('cta_click', {
                        cta_label: this.safeText(el.textContent || '')
                    });
                }
            }, true);
        }

        trackThankYouLead() {
            if (!Array.isArray(this.thankYouMatchers) || !this.thankYouMatchers.length) return;

            const path = window.location.pathname || '/';
            const matched = this.thankYouMatchers.some((matcher) => this.pathMatches(path, String(matcher || '')));
            if (!matched) return;

            const marker = 'ct_thankyou_lead_' + path;
            try {
                if (sessionStorage.getItem(marker) === '1') return;
                sessionStorage.setItem(marker, '1');
            } catch (e) {}

            this.pushEvent('lead', {
                lead_context: {
                    provider: 'redirect_thank_you',
                    submit_status: 'success'
                }
            });
        }

        trackExternalFormMessages() {
            window.addEventListener('message', (event) => {
                if (!this.isAllowedOrigin(event.origin)) return;

                const data = event && event.data ? event.data : null;
                const normalized = this.normalizeExternalMessage(data, event.origin);
                if (!normalized) return;

                const marker = normalized.event_name + '|' + normalized.external_id;
                if (this.externalMarkers.has(marker)) return;
                this.externalMarkers.add(marker);

                this.pushEvent(normalized.event_name, {
                    lead_context: {
                        provider: normalized.provider,
                        submit_status: normalized.submit_status
                    }
                });
            });
        }

        normalizeExternalMessage(data, origin) {
            if (!data) return null;

            let type = '';
            let eventId = '';
            if (typeof data === 'string') {
                type = data.toLowerCase();
            } else if (typeof data === 'object') {
                type = String(data.type || data.event || '').toLowerCase();
                eventId = String(data.event_id || data.id || data.token || '');
            } else {
                return null;
            }

            const provider = this.providerFromOrigin(origin);
            const isLead = [
                'typeform-submit',
                'hubspot-form-submit',
                'clicktrail.lead',
                'clicutcl.lead',
                'lead'
            ].includes(type);
            const isBooked = [
                'calendly-booked',
                'book_appointment',
                'clicktrail.book_appointment',
                'clicutcl.book_appointment'
            ].includes(type);

            if (!isLead && !isBooked) return null;

            return {
                event_name: isBooked ? 'book_appointment' : 'lead',
                submit_status: 'success',
                external_id: eventId || this.generateEventId('ext'),
                provider
            };
        }

        providerFromOrigin(origin) {
            try {
                const host = new URL(origin).hostname.toLowerCase();
                if (host.includes('calendly')) return 'calendly';
                if (host.includes('typeform')) return 'typeform';
                if (host.includes('hubspot')) return 'hubspot';
                return host;
            } catch (e) {
                return '';
            }
        }

        isAllowedOrigin(origin) {
            if (!origin) return false;

            let host = '';
            try {
                host = new URL(origin).hostname.toLowerCase();
            } catch (e) {
                return false;
            }

            const sameHost = (window.location.hostname || '').toLowerCase();
            if (host === sameHost) return true;

            const allowlist = Array.isArray(this.iframeOrigins) ? this.iframeOrigins : [];
            return allowlist.some((entry) => {
                const pattern = String(entry || '').toLowerCase().trim();
                if (!pattern) return false;
                if (host === pattern) return true;
                return host.endsWith('.' + pattern);
            });
        }

        pathMatches(path, matcher) {
            if (!matcher) return false;
            const cleanPath = String(path || '/').toLowerCase();
            const rule = String(matcher || '').toLowerCase().trim();

            if (!rule) return false;
            if (rule === cleanPath) return true;
            if (rule.endsWith('*')) {
                return cleanPath.startsWith(rule.slice(0, -1));
            }
            return cleanPath.includes(rule);
        }

        getFormContext(form) {
            if (!form) {
                return { form_id: '', form_name: '', provider: '' };
            }

            const id = this.safeText(form.getAttribute('id') || form.dataset.formId || '');
            const name = this.safeText(form.getAttribute('name') || form.dataset.formName || '');
            const provider = this.safeText(
                form.dataset.formProvider ||
                form.getAttribute('data-provider') ||
                this.detectFormProvider(form)
            );

            return {
                form_id: id,
                form_name: name,
                provider
            };
        }

        detectFormProvider(form) {
            if (!form || !form.className) return '';
            const cls = String(form.className).toLowerCase();
            if (cls.includes('wpcf7')) return 'cf7';
            if (cls.includes('gform')) return 'gravity_forms';
            if (cls.includes('wpforms')) return 'wpforms';
            if (cls.includes('ninja-forms')) return 'ninja_forms';
            if (cls.includes('fluentform')) return 'fluent_forms';
            if (cls.includes('elementor-form')) return 'elementor_forms';
            if (cls.includes('formidable')) return 'formidable';
            return '';
        }

        safeText(value, maxLen = 120) {
            if (value === null || value === undefined) return '';
            const s = String(value).trim();
            if (!s) return '';
            return s.length > maxLen ? s.slice(0, maxLen) : s;
        }
    }

    let trackerInstance = null;

    function initTracking() {
        if (trackerInstance) return;

        if (!window.clicutclEventsConfig || !window.clicutclEventsConfig.enabled) {
            return;
        }

        if (
            typeof window.ClickTrailConsent !== 'undefined' &&
            !window.ClickTrailConsent.isGranted()
        ) {
            return;
        }

        trackerInstance = new ClickTrailEvents();
    }

    function boot() {
        if (!window.clicutclEventsConfig || !window.clicutclEventsConfig.enabled) {
            if (window.clicutclEventsConfig && window.clicutclEventsConfig.debug) {
                console.log('[ClickTrail] Browser event collection disabled.');
            }
            return;
        }

        const consent = window.ClickTrailConsent;

        // Bridge missing should fail safe.
        if (
            typeof consent === 'undefined' ||
            typeof consent.isResolved !== 'function' ||
            typeof consent.isGranted !== 'function'
        ) {
            if (window.clicutclEventsConfig && window.clicutclEventsConfig.debug) {
                console.warn('[ClickTrail] Consent bridge not found. Tracking disabled.');
            }
            return;
        }

        // Consent already resolved (cookie or fast CMP).
        if (consent.isResolved()) {
            if (consent.isGranted()) {
                initTracking();
                return;
            }
        }

        // Wait for async CMP/user interaction or later consent changes.
        const onConsentResolved = function (e) {
            if (e && e.detail && e.detail.granted) {
                initTracking();
                document.removeEventListener('ct:consentResolved', onConsentResolved);
            }
        };
        document.addEventListener('ct:consentResolved', onConsentResolved);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }

})();
