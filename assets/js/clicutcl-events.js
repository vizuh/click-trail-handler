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
            this.wooCommerce = this.getWooCommerceConfig();
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
            this.wooListSeen = new Set();
            this.wooListItemContext = {};
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
            this.trackWooCommerceEvents();
            this.trackThankYouLead();
            this.trackExternalFormMessages();
            this.consumeServerEvents();
        }

        pushEvent(eventName, params = {}, options = {}) {
            if (!this.collectionEnabled) {
                return;
            }

            const consentBridge = window.ClickTrailConsent;
            if (typeof consentBridge === 'undefined' || !consentBridge.isGranted()) {
                this.debugLog('Event blocked (no consent):', eventName);
                return;
            }

            window.dataLayer = window.dataLayer || [];
            const providedEventId = options && typeof options === 'object'
                ? this.safeText(options.eventId || options.event_id || '', 128)
                : '';
            const eventId = providedEventId || this.generateEventId(eventName);
            const eventData = {
                ...(params && typeof params === 'object' ? params : {}),
                event: eventName,
                event_id: eventId,
                session_id: this.sessionId,
                visitor_id: this.visitorId
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
                view_item: { event_name: 'view_item', funnel_stage: 'top' },
                view_item_list: { event_name: 'view_item_list', funnel_stage: 'top' },
                file_download: { event_name: 'view_content', funnel_stage: 'mid' },
                scroll: { event_name: 'scroll_depth', funnel_stage: 'top' },
                user_engagement: { event_name: 'key_page_view', funnel_stage: 'top' },
                add_to_cart: { event_name: 'add_to_cart', funnel_stage: 'mid' },
                remove_from_cart: { event_name: 'remove_from_cart', funnel_stage: 'mid' },
                begin_checkout: { event_name: 'begin_checkout', funnel_stage: 'bottom' },
                cta_click: { event_name: 'cta_click', funnel_stage: 'mid' },
                form_start: { event_name: 'form_start', funnel_stage: 'mid' },
                form_submit_attempt: { event_name: 'form_submit_attempt', funnel_stage: 'mid' },
                lead: { event_name: 'lead', funnel_stage: 'bottom' },
                login: { event_name: 'login', funnel_stage: 'bottom' },
                sign_up: { event_name: 'sign_up', funnel_stage: 'bottom' },
                comment_submit: { event_name: 'comment_submit', funnel_stage: 'mid' },
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
            const commerceContext = this.extractCommerceContext(eventData);
            const deliveryContext = this.extractDeliveryContext(eventData);

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
                commerce_context: commerceContext,
                delivery_context: deliveryContext,
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

        extractCommerceContext(eventData) {
            if (!eventData || typeof eventData !== 'object') {
                return {};
            }

            const raw = eventData.commerce_context && typeof eventData.commerce_context === 'object'
                ? eventData.commerce_context
                : (eventData.ecommerce && typeof eventData.ecommerce === 'object' ? eventData.ecommerce : null);

            if (!raw) {
                return {};
            }

            const out = {};

            ['transaction_id', 'currency', 'status', 'order_currency'].forEach((key) => {
                const value = this.safeText(raw[key] || eventData[key] || '', 64);
                if (value) {
                    out[key] = value;
                }
            });

            ['value', 'subtotal', 'tax_total', 'shipping_total', 'discount_total', 'item_quantity'].forEach((key) => {
                const value = Number(raw[key]);
                if (Number.isFinite(value)) {
                    out[key] = value;
                }
            });

            if (Array.isArray(raw.discount_codes)) {
                const codes = raw.discount_codes
                    .map((entry) => this.safeText(entry, 64))
                    .filter(Boolean);
                if (codes.length) {
                    out.discount_codes = codes;
                }
            }

            if (Array.isArray(raw.items)) {
                const items = raw.items
                    .map((item) => this.sanitizeCommerceItem(item))
                    .filter(Boolean);
                if (items.length) {
                    out.items = items;
                }
            }

            return out;
        }

        extractDeliveryContext(eventData) {
            if (!eventData || typeof eventData !== 'object') {
                return {};
            }

            const reserved = {
                event: true,
                event_id: true,
                session_id: true,
                visitor_id: true,
                attribution: true,
                consent: true,
                lead_context: true,
                commerce_context: true,
                ecommerce: true
            };
            const out = {};

            Object.keys(eventData).forEach((key) => {
                if (reserved[key]) {
                    return;
                }

                const value = eventData[key];
                if (value === null || value === undefined || value === '') {
                    return;
                }

                if (typeof value === 'string') {
                    const sanitized = this.safeText(value, 255);
                    if (sanitized) {
                        out[key] = sanitized;
                    }
                    return;
                }

                if (typeof value === 'number' && Number.isFinite(value)) {
                    out[key] = value;
                    return;
                }

                if (typeof value === 'boolean') {
                    out[key] = value;
                    return;
                }

                if (Array.isArray(value)) {
                    const list = value
                        .map((entry) => {
                            if (typeof entry === 'string') {
                                return this.safeText(entry, 255);
                            }
                            if (typeof entry === 'number' && Number.isFinite(entry)) {
                                return entry;
                            }
                            if (typeof entry === 'boolean') {
                                return entry;
                            }
                            return '';
                        })
                        .filter((entry) => entry !== '');
                    if (list.length) {
                        out[key] = list;
                    }
                }
            });

            return out;
        }

        sanitizeCommerceItem(item) {
            if (!item || typeof item !== 'object') {
                return null;
            }

            const out = {};
            const itemId = Number(item.item_id);
            const productId = Number(item.product_id);
            const price = Number(item.price);
            const quantity = Number(item.quantity);

            if (Number.isFinite(itemId) && itemId > 0) {
                out.item_id = itemId;
            }
            if (Number.isFinite(productId) && productId > 0) {
                out.product_id = productId;
            }
            if (Number.isFinite(price)) {
                out.price = price;
            }
            if (Number.isFinite(quantity) && quantity > 0) {
                out.quantity = Math.round(quantity);
            }

            ['item_name', 'sku', 'variant'].forEach((key) => {
                const value = this.safeText(item[key] || '', 160);
                if (value) {
                    out[key] = value;
                }
            });

            const listName = this.safeText(item.item_list_name || '', 160);
            if (listName) {
                out.item_list_name = listName;
            }

            const listIndex = Number(item.item_list_index);
            if (Number.isFinite(listIndex) && listIndex > 0) {
                out.item_list_index = Math.round(listIndex);
            }

            if (Array.isArray(item.categories)) {
                const categories = item.categories
                    .map((entry) => this.safeText(entry, 120))
                    .filter(Boolean);
                if (categories.length) {
                    out.categories = categories;
                }
            }

            return Object.keys(out).length ? out : null;
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

        trackWooCommerceEvents() {
            if (!this.wooCommerce.enabled) {
                return;
            }

            this.trackWooListViews();
            this.trackWooProductView();
            this.trackWooBeginCheckout();
            this.trackWooAddToCart();
            this.trackWooRemoveFromCart();
        }

        trackWooListViews() {
            const lists = this.getWooProductListContainers();
            if (!lists.length) {
                return;
            }

            const emitListView = (container, index) => {
                const signature = this.getWooListSignature(container, index);
                if (!signature || this.wooListSeen.has(signature)) {
                    return;
                }

                const ecommerce = this.extractWooListEcommerce(container);
                if (!ecommerce) {
                    return;
                }

                this.wooListSeen.add(signature);
                this.pushEvent('view_item_list', {
                    ecommerce
                });
            };

            if (typeof window.IntersectionObserver === 'function') {
                const observer = new window.IntersectionObserver((entries) => {
                    entries.forEach((entry) => {
                        if (!entry.isIntersecting) {
                            return;
                        }

                        const listIndex = lists.indexOf(entry.target);
                        emitListView(entry.target, listIndex);
                        observer.unobserve(entry.target);
                    });
                }, {
                    threshold: 0.2,
                    rootMargin: '0px 0px -10% 0px'
                });

                lists.forEach((container) => observer.observe(container));
                return;
            }

            lists.forEach((container, index) => emitListView(container, index));
        }

        trackWooProductView() {
            if (this.wooCommerce.pageType !== 'product') {
                return;
            }

            const ecommerce = this.buildWooCommerceEventPayload(this.wooCommerce.product);
            if (!ecommerce) {
                return;
            }

            this.pushEvent('view_item', {
                ecommerce
            });
        }

        trackWooBeginCheckout() {
            if (this.wooCommerce.pageType !== 'checkout') {
                return;
            }

            const ecommerce = this.buildWooCommerceEventPayload(this.wooCommerce.checkout);
            if (!ecommerce) {
                return;
            }

            this.pushEvent('begin_checkout', {
                ecommerce
            });
        }

        trackWooAddToCart() {
            if (!window.jQuery || !window.jQuery(document.body) || typeof window.jQuery(document.body).on !== 'function') {
                this.debugLog('WooCommerce add_to_cart listener unavailable.');
                return;
            }

            window.jQuery(document.body).on('added_to_cart', (event, fragments, cartHash, button) => {
                const item = this.extractWooAddToCartItem(button);
                if (!item) {
                    return;
                }

                const quantity = Number.isFinite(Number(item.quantity)) && Number(item.quantity) > 0
                    ? Math.round(Number(item.quantity))
                    : 1;
                const price = Number.isFinite(Number(item.price)) ? Number(item.price) : 0;

                this.pushEvent('add_to_cart', {
                    ecommerce: {
                        currency: this.safeText(this.wooCommerce.currency || '', 16),
                        value: price * quantity,
                        item_quantity: quantity,
                        items: [item]
                    }
                });
            });
        }

        trackWooRemoveFromCart() {
            if (window.jQuery && window.jQuery(document.body) && typeof window.jQuery(document.body).on === 'function') {
                window.jQuery(document.body).on('removed_from_cart', (event, fragments, cartHash, button) => {
                    const item = this.extractWooRemovedCartItem(button);
                    this.emitWooRemoveFromCart(item);
                });

                window.jQuery(document.body).on('click', '.woocommerce-cart-form .remove, .cart_item .remove, .mini_cart_item .remove, .woocommerce-mini-cart-item .remove', (event) => {
                    const item = this.extractWooRemovedCartItem(event.currentTarget);
                    this.emitWooRemoveFromCart(item);
                });
            }

            this.trackWooBlockCartRemoveFromCart();
        }

        extractWooAddToCartItem(button) {
            const buttonElement = button && button.jquery ? button[0] : button;
            const fallback = this.buildWooCommerceEventPayload(this.wooCommerce.product);
            const fallbackItem = fallback && Array.isArray(fallback.items) && fallback.items.length ? fallback.items[0] : null;

            if (!buttonElement || !buttonElement.closest) {
                return fallbackItem;
            }

            const form = buttonElement.closest('form');
            const quantityField = form ? form.querySelector('input.qty, input[name="quantity"]') : null;
            const quantity = quantityField && Number.isFinite(Number(quantityField.value))
                ? Math.max(1, Math.round(Number(quantityField.value)))
                : (fallbackItem && Number.isFinite(Number(fallbackItem.quantity)) ? Math.round(Number(fallbackItem.quantity)) : 1);
            const productId = Number(
                buttonElement.getAttribute('data-product_id') ||
                (buttonElement.dataset ? buttonElement.dataset.productId : '') ||
                (fallbackItem ? fallbackItem.product_id : 0)
            );
            const sku = this.safeText(
                buttonElement.getAttribute('data-product_sku') ||
                (buttonElement.dataset ? buttonElement.dataset.productSku : '') ||
                (fallbackItem ? fallbackItem.sku : ''),
                120
            );
            const name = this.pickWooProductName(buttonElement, fallbackItem);
            const price = this.extractWooProductPrice(buttonElement, fallbackItem);
            const listContext = this.resolveWooListContext(buttonElement, productId || (fallbackItem ? fallbackItem.product_id : 0));
            const item = this.sanitizeCommerceItem({
                item_id: productId || (fallbackItem ? fallbackItem.item_id : 0),
                item_name: name || (fallbackItem ? fallbackItem.item_name : ''),
                price: price,
                quantity: quantity,
                product_id: productId || (fallbackItem ? fallbackItem.product_id : 0),
                sku: sku || (fallbackItem ? fallbackItem.sku : ''),
                variant: fallbackItem ? fallbackItem.variant : '',
                categories: fallbackItem && Array.isArray(fallbackItem.categories) ? fallbackItem.categories : [],
                item_list_name: listContext.item_list_name || '',
                item_list_index: listContext.item_list_index || 0
            });

            return item || fallbackItem;
        }

        emitWooRemoveFromCart(item) {
            if (!item) {
                return;
            }

            const quantity = Number.isFinite(Number(item.quantity)) && Number(item.quantity) > 0
                ? Math.round(Number(item.quantity))
                : 1;
            const price = Number.isFinite(Number(item.price)) ? Number(item.price) : 0;
            const signature = [
                Number(item.product_id || item.item_id || 0),
                quantity,
                Math.round(price * 100)
            ].join(':');
            const now = Date.now();

            if (this.lastWooRemoveSignature === signature && (now - this.lastWooRemoveAt) < 1500) {
                return;
            }

            this.lastWooRemoveSignature = signature;
            this.lastWooRemoveAt = now;

            this.pushEvent('remove_from_cart', {
                ecommerce: {
                    currency: this.safeText(this.wooCommerce.currency || '', 16),
                    value: price * quantity,
                    item_quantity: quantity,
                    items: [item]
                }
            });
        }

        extractWooRemovedCartItem(button) {
            const buttonElement = button && button.jquery ? button[0] : button;
            const productId = Number(
                buttonElement && buttonElement.getAttribute
                    ? (
                        buttonElement.getAttribute('data-product_id') ||
                        (buttonElement.dataset ? buttonElement.dataset.productId : '') ||
                        this.getUrlParam(buttonElement.getAttribute('href') || '', 'remove_item')
                    )
                    : 0
            );
            const fallbackItem = this.findWooCommerceConfigItem(productId);

            if (!buttonElement || !buttonElement.closest) {
                return fallbackItem;
            }

            const container = buttonElement.closest('.woocommerce-cart-form__cart-item, .cart_item, .mini_cart_item, .woocommerce-mini-cart-item');
            const quantity = this.extractWooCartQuantity(container, fallbackItem);
            const name = this.pickWooProductName(buttonElement, fallbackItem);
            const price = this.extractWooProductPrice(buttonElement, fallbackItem);
            const item = this.sanitizeCommerceItem({
                item_id: productId || (fallbackItem ? fallbackItem.item_id : 0),
                item_name: name || (fallbackItem ? fallbackItem.item_name : ''),
                price: price,
                quantity: quantity,
                product_id: productId || (fallbackItem ? fallbackItem.product_id : 0),
                sku: fallbackItem ? fallbackItem.sku : '',
                variant: fallbackItem ? fallbackItem.variant : '',
                categories: fallbackItem && Array.isArray(fallbackItem.categories) ? fallbackItem.categories : []
            });

            return item || fallbackItem;
        }

        trackWooBlockCartRemoveFromCart() {
            if (
                typeof window.wp === 'undefined' ||
                !window.wp.data ||
                typeof window.wp.data.select !== 'function' ||
                typeof window.wp.data.subscribe !== 'function'
            ) {
                return;
            }

            const selectCartStore = () => {
                try {
                    return window.wp.data.select('wc/store/cart');
                } catch (e) {
                    return null;
                }
            };
            const getCartItems = () => {
                const store = selectCartStore();
                if (!store || typeof store.getCartData !== 'function') {
                    return [];
                }

                const cartData = store.getCartData();
                return cartData && Array.isArray(cartData.items) ? cartData.items : [];
            };

            let previousItems = getCartItems();

            window.wp.data.subscribe(() => {
                const currentItems = getCartItems();
                if (!Array.isArray(previousItems) || !Array.isArray(currentItems)) {
                    previousItems = currentItems;
                    return;
                }

                if (previousItems.length > currentItems.length) {
                    const removedItem = previousItems.find((previousItem) => {
                        const previousKey = String(previousItem && (previousItem.key || previousItem.id || previousItem.product_id || ''));
                        return !currentItems.some((currentItem) => {
                            const currentKey = String(currentItem && (currentItem.key || currentItem.id || currentItem.product_id || ''));
                            return currentKey === previousKey;
                        });
                    });

                    if (removedItem) {
                        this.emitWooRemoveFromCart(this.normalizeWooStoreCartItem(removedItem));
                    }
                }

                previousItems = currentItems;
            });
        }

        normalizeWooStoreCartItem(item) {
            const rawItem = item && typeof item === 'object' ? item : {};
            const productId = Number(rawItem.product_id || rawItem.id || 0);
            const fallbackItem = this.findWooCommerceConfigItem(productId);
            const quantity = Number.isFinite(Number(rawItem.quantity))
                ? Math.max(1, Math.round(Number(rawItem.quantity)))
                : (fallbackItem && Number.isFinite(Number(fallbackItem.quantity)) ? Math.round(Number(fallbackItem.quantity)) : 1);
            const price = this.parseWooStoreApiPrice(rawItem) || (fallbackItem && Number.isFinite(Number(fallbackItem.price)) ? Number(fallbackItem.price) : 0);
            const itemName = this.safeText(rawItem.name || '', 160) || (fallbackItem ? fallbackItem.item_name : '');

            return this.sanitizeCommerceItem({
                item_id: productId || (fallbackItem ? fallbackItem.item_id : 0),
                item_name: itemName,
                price: price,
                quantity: quantity,
                product_id: productId || (fallbackItem ? fallbackItem.product_id : 0),
                sku: fallbackItem ? fallbackItem.sku : '',
                variant: fallbackItem ? fallbackItem.variant : '',
                categories: fallbackItem && Array.isArray(fallbackItem.categories) ? fallbackItem.categories : []
            }) || fallbackItem;
        }

        parseWooStoreApiPrice(item) {
            if (!item || typeof item !== 'object' || !item.prices || typeof item.prices !== 'object') {
                return 0;
            }

            const raw = Number(item.prices.price);
            const minorUnit = Number(item.prices.currency_minor_unit);
            if (!Number.isFinite(raw)) {
                return 0;
            }

            if (Number.isFinite(minorUnit) && minorUnit >= 0) {
                return raw / Math.pow(10, minorUnit);
            }

            return raw;
        }

        extractWooCartQuantity(container, fallbackItem) {
            if (!container || !container.querySelector) {
                return fallbackItem && Number.isFinite(Number(fallbackItem.quantity))
                    ? Math.max(1, Math.round(Number(fallbackItem.quantity)))
                    : 1;
            }

            const quantityField = container.querySelector('input.qty, input[name="cart\\[qty\\]"], input[name="quantity"]');
            if (quantityField && Number.isFinite(Number(quantityField.value))) {
                return Math.max(1, Math.round(Number(quantityField.value)));
            }

            const quantityNode = container.querySelector('.quantity');
            if (quantityNode) {
                const match = String(quantityNode.textContent || '').match(/(\d+)\s*[×x]/);
                if (match && Number.isFinite(Number(match[1]))) {
                    return Math.max(1, Math.round(Number(match[1])));
                }
            }

            return fallbackItem && Number.isFinite(Number(fallbackItem.quantity))
                ? Math.max(1, Math.round(Number(fallbackItem.quantity)))
                : 1;
        }

        findWooCommerceConfigItem(productId) {
            const resolvedId = Number(productId);
            if (!Number.isFinite(resolvedId) || resolvedId <= 0) {
                return null;
            }

            const pools = [];
            ['product', 'cart', 'checkout'].forEach((key) => {
                const source = this.wooCommerce[key];
                if (source && Array.isArray(source.items)) {
                    pools.push(...source.items);
                }
            });

            return pools.find((item) => Number(item && (item.product_id || item.item_id || 0)) === resolvedId) || null;
        }

        getWooProductListContainers() {
            const selectors = [
                '.related.products',
                '.upsells.products',
                '.up-sells.products',
                '.cross-sells',
                '.woocommerce-cart .cross-sells',
                '.products',
                'ul.products',
                '.wc-block-grid',
                '.wc-block-product-template',
                '.widget .products',
                '.wc-block-product-categories-list'
            ];
            const seen = new Set();

            return Array.from(document.querySelectorAll(selectors.join(','))).filter((container) => {
                if (!container || seen.has(container)) {
                    return false;
                }

                const cards = this.getWooProductCards(container);
                if (!cards.length) {
                    return false;
                }

                seen.add(container);
                return true;
            });
        }

        getWooProductCards(container) {
            if (!container || !container.querySelectorAll) {
                return [];
            }

            return Array.from(container.querySelectorAll('.product, li.product, .wc-block-grid__product, .wc-block-product'))
                .filter((card) => card && card.querySelector);
        }

        getWooListSignature(container, fallbackIndex) {
            if (!container) {
                return '';
            }

            const listName = this.resolveWooListName(container);
            const firstCard = this.getWooProductCards(container)[0];
            const firstProductId = this.extractWooProductIdFromElement(firstCard);
            return [listName || 'list', firstProductId || 0, fallbackIndex || 0].join(':');
        }

        extractWooListEcommerce(container) {
            const listName = this.resolveWooListName(container);
            const items = this.getWooProductCards(container)
                .map((card, index) => this.extractWooListItem(card, index + 1, listName))
                .filter(Boolean);

            if (!items.length) {
                return null;
            }

            return {
                currency: this.safeText(this.wooCommerce.currency || '', 16),
                item_quantity: items.length,
                value: items.reduce((total, item) => {
                    const price = Number.isFinite(Number(item.price)) ? Number(item.price) : 0;
                    return total + price;
                }, 0),
                items: items
            };
        }

        extractWooListItem(card, listIndex, listName) {
            if (!card || !card.querySelector) {
                return null;
            }

            const productId = this.extractWooProductIdFromElement(card);
            const fallbackItem = this.findWooCommerceConfigItem(productId);
            const name = this.extractWooProductNameFromNode(card, fallbackItem);
            const price = this.extractWooPriceFromNode(card, fallbackItem);
            const item = this.sanitizeCommerceItem({
                item_id: productId || (fallbackItem ? fallbackItem.item_id : 0),
                item_name: name || (fallbackItem ? fallbackItem.item_name : ''),
                price: price,
                quantity: 1,
                product_id: productId || (fallbackItem ? fallbackItem.product_id : 0),
                sku: fallbackItem ? fallbackItem.sku : '',
                variant: fallbackItem ? fallbackItem.variant : '',
                categories: fallbackItem && Array.isArray(fallbackItem.categories) ? fallbackItem.categories : [],
                item_list_name: listName,
                item_list_index: listIndex
            });

            if (item && Number(item.product_id || item.item_id || 0) > 0) {
                const itemKey = String(item.product_id || item.item_id);
                this.wooListItemContext[itemKey] = {
                    item_list_name: item.item_list_name || '',
                    item_list_index: item.item_list_index || listIndex
                };
            }

            return item;
        }

        resolveWooListContext(node, productId) {
            const container = node && node.closest ? node.closest('.related.products, .upsells.products, .up-sells.products, .cross-sells, .products, ul.products, .wc-block-grid, .wc-block-product-template, .widget .products') : null;
            if (container) {
                const listName = this.resolveWooListName(container);
                if (listName) {
                    const cards = this.getWooProductCards(container);
                    const itemIndex = cards.findIndex((card) => Number(this.extractWooProductIdFromElement(card)) === Number(productId));
                    return {
                        item_list_name: listName,
                        item_list_index: itemIndex >= 0 ? itemIndex + 1 : 0
                    };
                }
            }

            const fallback = this.wooListItemContext[String(Number(productId || 0))];
            return fallback && typeof fallback === 'object' ? fallback : {};
        }

        resolveWooListName(container) {
            if (!container) {
                return this.safeText(this.wooCommerce.catalogContext && this.wooCommerce.catalogContext.listName || '', 160);
            }

            const explicit = this.readWooListDataAttribute(container);
            if (explicit) {
                return explicit;
            }

            if (container.closest('.related, .related.products')) {
                return 'Related Products';
            }
            if (container.closest('.upsells, .upsells.products, .up-sells, .up-sells.products')) {
                return 'Upsells';
            }
            if (container.closest('.cross-sells')) {
                return container.closest('.woocommerce-cart')
                    ? 'Cart Cross-sells'
                    : 'Cross-sells';
            }

            const widgetTitle = this.extractWooSectionHeading(container.closest('.widget, section, .wp-block-group, .woocommerce'));
            if (widgetTitle) {
                return widgetTitle;
            }

            return this.safeText(this.wooCommerce.catalogContext && this.wooCommerce.catalogContext.listName || '', 160);
        }

        readWooListDataAttribute(node) {
            if (!node || !node.getAttribute) {
                return '';
            }

            const attributes = ['data-clicutcl-list-name', 'data-clicktrail-list-name', 'data-list-name', 'data-list_name'];
            for (let i = 0; i < attributes.length; i += 1) {
                const value = this.safeText(node.getAttribute(attributes[i]) || '', 160);
                if (value) {
                    return value;
                }
            }

            return '';
        }

        extractWooSectionHeading(container) {
            if (!container || !container.querySelector) {
                return '';
            }

            const heading = container.querySelector('h1, h2, h3, h4, .widget-title, .wc-block-grid__products-title, .wp-block-heading');
            return heading ? this.safeText(heading.textContent || '', 160) : '';
        }

        extractWooProductIdFromElement(node) {
            if (!node || !node.getAttribute) {
                return 0;
            }

            const id = Number(
                node.getAttribute('data-product_id') ||
                node.getAttribute('data-product-id') ||
                (node.dataset ? (node.dataset.productId || node.dataset.product_id || node.dataset.postId || node.dataset.product) : '') ||
                (node.id || '').replace(/[^0-9]/g, '')
            );
            return Number.isFinite(id) ? id : 0;
        }

        extractWooProductNameFromNode(node, fallbackItem) {
            if (!node || !node.querySelector) {
                return fallbackItem ? this.safeText(fallbackItem.item_name || '', 160) : '';
            }

            const selectors = [
                '[data-product_name]',
                '.woocommerce-loop-product__title',
                '.wc-block-grid__product-title',
                '.wc-block-components-product-name',
                '.product-title',
                '.product-name',
                'h2',
                'h3'
            ];
            for (let i = 0; i < selectors.length; i += 1) {
                const target = node.querySelector(selectors[i]);
                if (!target) {
                    continue;
                }

                const value = this.safeText(target.textContent || target.getAttribute('data-product_name') || '', 160);
                if (value) {
                    return value;
                }
            }

            return fallbackItem ? this.safeText(fallbackItem.item_name || '', 160) : '';
        }

        extractWooPriceFromNode(node, fallbackItem) {
            if (!node || !node.querySelector) {
                return fallbackItem && Number.isFinite(Number(fallbackItem.price)) ? Number(fallbackItem.price) : 0;
            }

            const priceNode = node.querySelector('.price .amount, .woocommerce-Price-amount, .wc-block-components-product-price__value');
            const parsed = this.parsePrice(priceNode ? priceNode.textContent : '');
            if (parsed > 0) {
                return parsed;
            }

            return fallbackItem && Number.isFinite(Number(fallbackItem.price)) ? Number(fallbackItem.price) : 0;
        }

        getUrlParam(url, key) {
            if (!url) {
                return '';
            }

            try {
                const parsed = new URL(url, window.location.origin);
                return parsed.searchParams.get(key) || '';
            } catch (e) {
                return '';
            }
        }

        buildWooCommerceEventPayload(source) {
            const commerce = this.extractCommerceContext({
                ecommerce: source
            });

            if (!commerce || !Array.isArray(commerce.items) || !commerce.items.length) {
                return null;
            }

            if (!commerce.currency && this.wooCommerce.currency) {
                commerce.currency = this.safeText(this.wooCommerce.currency, 16);
            }

            if (!Number.isFinite(Number(commerce.value))) {
                commerce.value = commerce.items.reduce((total, item) => {
                    const price = Number.isFinite(Number(item.price)) ? Number(item.price) : 0;
                    const quantity = Number.isFinite(Number(item.quantity)) ? Number(item.quantity) : 1;
                    return total + (price * quantity);
                }, 0);
            }

            return commerce;
        }

        getWooCommerceConfig() {
            const source = window.clicutclEventsConfig && typeof window.clicutclEventsConfig.wooCommerce === 'object'
                ? window.clicutclEventsConfig.wooCommerce
                : {};

            return {
                enabled: !!source.enabled,
                pageType: this.safeText(source.pageType || 'other', 32) || 'other',
                currency: this.safeText(source.currency || '', 16),
                product: source.product && typeof source.product === 'object' ? source.product : {},
                cart: source.cart && typeof source.cart === 'object' ? source.cart : {},
                checkout: source.checkout && typeof source.checkout === 'object' ? source.checkout : {},
                catalogContext: source.catalogContext && typeof source.catalogContext === 'object'
                    ? {
                        pageType: this.safeText(source.catalogContext.page_type || source.catalogContext.pageType || '', 32),
                        listName: this.safeText(source.catalogContext.list_name || source.catalogContext.listName || '', 160)
                    }
                    : {}
            };
        }

        pickWooProductName(buttonElement, fallbackItem) {
            if (!buttonElement || !buttonElement.closest) {
                return fallbackItem ? this.safeText(fallbackItem.item_name || '', 160) : '';
            }

            const container = buttonElement.closest('.product, li.product, .single-product, form.cart, .wc-block-grid__product, .cart_item, .woocommerce-cart-form__cart-item, .mini_cart_item, .woocommerce-mini-cart-item, .wc-block-components-product-details');
            const selectors = [
                '[data-product_name]',
                '.product-name a',
                '.product-name',
                '.wc-block-components-product-name',
                'h1.product_title',
                '.woocommerce-loop-product__title',
                '.wc-block-grid__product-title',
                'h2',
                'h3'
            ];

            for (let i = 0; i < selectors.length; i += 1) {
                const node = container ? container.querySelector(selectors[i]) : null;
                if (!node) {
                    continue;
                }

                const text = this.safeText(node.textContent || node.getAttribute('data-product_name') || '', 160);
                if (text) {
                    return text;
                }
            }

            const ariaLabel = this.safeText(buttonElement.getAttribute('aria-label') || '', 160);
            if (ariaLabel) {
                return ariaLabel;
            }

            return fallbackItem ? this.safeText(fallbackItem.item_name || '', 160) : '';
        }

        extractWooProductPrice(buttonElement, fallbackItem) {
            if (!buttonElement || !buttonElement.closest) {
                return fallbackItem && Number.isFinite(Number(fallbackItem.price)) ? Number(fallbackItem.price) : 0;
            }

            const container = buttonElement.closest('.product, li.product, .single-product, form.cart, .wc-block-grid__product, .cart_item, .woocommerce-cart-form__cart-item, .mini_cart_item, .woocommerce-mini-cart-item, .wc-block-components-product-details');
            const priceNode = container ? container.querySelector('.product-price .amount, .mini_cart_item .amount, .price .amount, .woocommerce-Price-amount') : null;
            const parsed = this.parsePrice(priceNode ? priceNode.textContent : '');
            if (parsed > 0) {
                return parsed;
            }

            return fallbackItem && Number.isFinite(Number(fallbackItem.price)) ? Number(fallbackItem.price) : 0;
        }

        parsePrice(value) {
            if (typeof value === 'number' && Number.isFinite(value)) {
                return value;
            }

            const raw = this.safeText(value, 64);
            if (!raw) {
                return 0;
            }

            const normalized = raw.replace(/[^0-9,.-]/g, '');
            if (!normalized) {
                return 0;
            }

            const lastComma = normalized.lastIndexOf(',');
            const lastDot = normalized.lastIndexOf('.');
            let cleaned = normalized;

            if (lastComma > lastDot) {
                cleaned = cleaned.replace(/\./g, '').replace(',', '.');
            } else if (lastDot > lastComma) {
                cleaned = cleaned.replace(/,/g, '');
            } else {
                cleaned = cleaned.replace(/,/g, '');
            }

            const parsed = Number(cleaned);
            return Number.isFinite(parsed) ? parsed : 0;
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

        consumeServerEvents() {
            const queued = []
                .concat(Array.isArray(window.clicutclServerEvents) ? window.clicutclServerEvents : [])
                .concat(
                    window.clicutclEventsConfig && Array.isArray(window.clicutclEventsConfig.serverEvents)
                        ? window.clicutclEventsConfig.serverEvents
                        : []
                );

            if (!queued.length) {
                return;
            }

            queued.forEach((queuedEvent) => {
                if (!queuedEvent || typeof queuedEvent !== 'object') {
                    return;
                }

                const eventName = this.safeText(queuedEvent.event || queuedEvent.name || '', 64);
                if (!eventName) {
                    return;
                }

                const params = queuedEvent.params && typeof queuedEvent.params === 'object'
                    ? queuedEvent.params
                    : {};
                const eventId = this.safeText(queuedEvent.event_id || queuedEvent.eventId || '', 128);

                this.pushEvent(eventName, params, { eventId });
            });

            window.clicutclServerEvents = [];
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
