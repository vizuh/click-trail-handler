(function () {
    'use strict';

    const CONFIG = window.clickTrailConfig || {
        cookieName: 'attribution',
        cookieDays: 90,
        requireConsent: true
    };

    // Support both the current cookie/localStorage key and a legacy/fallback key.
    const COOKIE_KEYS = Array.from(
        new Set([
            CONFIG.cookieName || 'ct_attribution',
            'attribution'
        ])
    );

    const CONSENT_COOKIE = 'ct_consent';

    class ClickTrailAttribution {
        constructor() {
            this.paramsToCapture = [
                'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
                'campaign_id', 'campaignid', 'adgroup_id', 'adgroupid', 'ad_id', 'creative', 'keyword', 'matchtype', 'network', 'device', 'placement', 'targetid',
                'gclid', 'fbclid', 'wbraid', 'gbraid', 'msclkid', 'ttclid', 'twclid', 'sc_click_id', 'epik'
            ];
            this.paidMediums = ['cpc', 'ppc', 'paidsearch', 'paid-search', 'paid', 'paid_social', 'paid social', 'display'];
            this.init();
        }

        init() {
            this.migrateLegacyData();

            const requiresConsent = CONFIG.requireConsent === true || CONFIG.requireConsent === '1' || CONFIG.requireConsent === 1;

            if (requiresConsent) {
                const consent = this.getConsent();
                if (consent && consent.marketing) {
                    this.runAttribution();
                    return;
                }

                const maybeRun = (event) => {
                    const preferences = event.detail || {};
                    if (preferences.marketing) {
                        console.log('ClickTrail: Consent granted, running...');
                        this.runAttribution();
                        window.removeEventListener('ct_consent_updated', maybeRun);
                        window.removeEventListener('consent_granted', maybeRun);
                    }
                };

                console.log('ClickTrail: Waiting for consent...');
                window.addEventListener('ct_consent_updated', maybeRun);
                window.addEventListener('consent_granted', maybeRun);
                return;
            }

            this.runAttribution();
        }

        runAttribution() {
            const currentParams = this.getURLParams();
            const referrer = document.referrer;
            const isExternalReferrer = referrer && !this.isInternalReferrer(referrer);

            const hasGclid = !!currentParams['gclid'];
            const hasUtm = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'].some(key => !!currentParams[key]);

            let storedData = this.getStoredData() || {};

            const attributionFields = this.mapParamsToAttributionFields(currentParams, referrer);
            const hasAttributionSignal = this.hasAttributionSignal(attributionFields);

            // Timestamp
            const now = new Date().toISOString();

            if (hasAttributionSignal) {
                if (!this.hasFirstTouch(storedData)) {
                    this.applyTouch('ft', storedData, attributionFields, now);
                }

                this.applyTouch('lt', storedData, attributionFields, now);
                storedData.session_count = (storedData.session_count || 0) + 1;

                this.saveData(storedData);

                // Debug: Saved
                console.log('ClickTrail saved', {
                    storedDataAfter: storedData
                });
            }

            // Expose to window
            window.clickTrail = storedData;

            // GTM Bridge: Page View
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push({
                event: 'ct_page_view',
                ct_attribution: storedData
            });

            // GTM Bridge: Form Listeners
            this.initFormListeners(storedData);
        }

        hasAttributionSignal(attributionFields) {
            return Object.keys(attributionFields).some((key) => {
                const value = attributionFields[key];
                return value !== undefined && value !== null && value !== '';
            });
        }

        hasFirstTouch(storedData) {
            return !!(storedData && (storedData.ft_source || storedData.ft_medium || storedData.ft_campaign || storedData.ft_gclid));
        }

        applyTouch(prefix, storedData, fields, timestamp) {
            const mapping = ['source', 'medium', 'campaign', 'campaign_id', 'adgroup_id', 'ad_id', 'term', 'matchtype', 'network', 'device', 'gclid', 'wbraid', 'gbraid', 'msclkid', 'fbclid', 'ttclid', 'twclid', 'sc_click_id', 'epik', 'content', 'referrer'];

            mapping.forEach((key) => {
                const value = fields[key];
                const prop = `${prefix}_${key}`;
                if (value !== undefined && value !== null && value !== '') {
                    storedData[prop] = value;
                }
            });

            storedData[`${prefix === 'ft' ? 'first' : 'last'}_touch_timestamp`] = timestamp;
            storedData[`${prefix}_landing_page`] = window.location.href;
        }

        mapParamsToAttributionFields(params, referrer) {
            const fields = {
                source: params.utm_source || '',
                medium: params.utm_medium || '',
                campaign: params.utm_campaign || '',
                campaign_id: params.campaignid || params.utm_campaign_id || '',
                adgroup_id: params.adgroupid || '',
                ad_id: params.creative || '',
                term: params.utm_term || params.keyword || '',
                matchtype: params.matchtype || '',
                network: params.network || '',
                device: params.device || '',
                gclid: params.gclid || '',
                wbraid: params.wbraid || '',
                gbraid: params.gbraid || '',
                msclkid: params.msclkid || '',
                fbclid: params.fbclid || '',
                ttclid: params.ttclid || '',
                twclid: params.twclid || '',
                sc_click_id: params.sc_click_id || '',
                epik: params.epik || '',
                content: params.utm_content || ''
            };

            if (referrer && !this.isInternalReferrer(referrer)) {
                fields.referrer = referrer;
            }

            return fields;
        }

        migrateLegacyData() {
            // Don't migrate if new key is already present
            const existing = this.getStoredData();
            if (existing) {
                return;
            }

            const legacyRaw = this.readLegacyData();
            if (!legacyRaw) {
                return;
            }

            const mapped = this.mapLegacyToFlat(legacyRaw);
            if (mapped) {
                this.saveData(mapped);
            }
        }

        readLegacyData() {
            const cookie = this.getCookie(LEGACY_KEY);
            if (cookie) {
                try {
                    return JSON.parse(cookie);
                } catch (e) {
                    return null;
                }
            }

            try {
                const ls = localStorage.getItem(LEGACY_KEY);
                if (ls) {
                    return JSON.parse(ls);
                }
            } catch (e) {
                // Ignore
            }

            return null;
        }

        mapLegacyToFlat(legacyObj) {
            if (!legacyObj) return null;

            const out = {};
            const mapTouch = (touch, prefix) => {
                if (!touch || typeof touch !== 'object') return;

                out[`${prefix}_source`] = touch.utm_source || touch.source || '';
                out[`${prefix}_medium`] = touch.utm_medium || touch.medium || '';
                out[`${prefix}_campaign`] = touch.utm_campaign || touch.campaign || '';
                out[`${prefix}_campaign_id`] = touch.campaign_id || '';
                out[`${prefix}_adgroup_id`] = touch.adgroup_id || '';
                out[`${prefix}_ad_id`] = touch.ad_id || '';
                out[`${prefix}_term`] = touch.utm_term || touch.term || touch.keyword || '';
                out[`${prefix}_matchtype`] = touch.matchtype || '';
                out[`${prefix}_network`] = touch.network || '';
                out[`${prefix}_device`] = touch.device || '';
                out[`${prefix}_gclid`] = touch.gclid || '';
                out[`${prefix}_wbraid`] = touch.wbraid || '';
                out[`${prefix}_gbraid`] = touch.gbraid || '';
                out[`${prefix}_msclkid`] = touch.msclkid || '';
                out[`${prefix}_fbclid`] = touch.fbclid || '';
                out[`${prefix}_ttclid`] = touch.ttclid || '';
                out[`${prefix}_twclid`] = touch.twclid || '';
                out[`${prefix}_sc_click_id`] = touch.sc_click_id || '';
                out[`${prefix}_epik`] = touch.epik || '';
                out[`${prefix}_content`] = touch.utm_content || touch.content || '';

                if (touch.referrer) {
                    out[`${prefix}_referrer`] = touch.referrer;
                }

                if (touch.landing_page) {
                    out[`${prefix}_landing_page`] = touch.landing_page;
                }
            };

            mapTouch(legacyObj.first_touch, 'ft');
            mapTouch(legacyObj.last_touch, 'lt');

            if (legacyObj.first_touch_timestamp) {
                out.first_touch_timestamp = legacyObj.first_touch_timestamp;
            }

            if (legacyObj.last_touch_timestamp) {
                out.last_touch_timestamp = legacyObj.last_touch_timestamp;
            }

            if (legacyObj.session_count) {
                out.session_count = legacyObj.session_count;
            }

            return out;
        }

        initFormListeners(data) {
            // Contact Form 7
            document.addEventListener('wpcf7mailsent', (e) => {
                this.pushLeadEvents('cf7', e.detail.contactFormId, data);
            });

            // Fluent Forms (jQuery)
            if (window.jQuery) {
                window.jQuery(document.body).on('fluentform_submission_success', function () {
                    window.clickTrailAttribution.pushLeadEvents('fluentform', '', data);
                });
            }

            // Gravity Forms (jQuery) - gform_confirmation_loaded
            if (window.jQuery) {
                window.jQuery(document).on('gform_confirmation_loaded', function (e, formId) {
                    window.clickTrailAttribution.pushLeadEvents('gravityforms', formId, data);
                });
            }

            // PII Scanner
            this.scanDataLayerForPII();
        }

        pushLeadEvents(provider, formId, data) {
            const payload = this.buildLeadPayload(provider, formId, data);

            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push(payload);

            // Maintain legacy event shape for existing GTM setups.
            window.dataLayer.push({
                event: 'ct_lead',
                form_provider: provider,
                form_id: formId,
                ct_attribution: data
            });

            if (typeof window.gtag === 'function') {
                const { event, ...params } = payload;
                window.gtag('event', event, params);
            }
        }

        buildLeadPayload(provider, formId, data) {
            const flat = this.flattenAttribution(data);

            return {
                event: 'lead_submit',
                form_provider: provider || '',
                form_id: formId || '',
                ...flat
            };
        }

        flattenAttribution(data) {
            const flat = {
                ft_source: '',
                ft_medium: '',
                ft_campaign: '',
                ft_term: '',
                ft_content: '',
                ft_gclid: '',
                ft_fbclid: '',
                ft_wbraid: '',
                ft_gbraid: '',
                ft_msclkid: '',
                ft_ttclid: '',
                ft_twclid: '',
                ft_sc_click_id: '',
                ft_epik: '',
                lt_source: '',
                lt_medium: '',
                lt_campaign: '',
                lt_term: '',
                lt_content: '',
                lt_gclid: '',
                lt_fbclid: '',
                lt_wbraid: '',
                lt_gbraid: '',
                lt_msclkid: '',
                lt_ttclid: '',
                lt_twclid: '',
                lt_sc_click_id: '',
                lt_epik: ''
            };

            if (!data) {
                return flat;
            }

            const mapKey = (key) => {
                const map = {
                    utm_source: 'source',
                    utm_medium: 'medium',
                    utm_campaign: 'campaign',
                    utm_term: 'term',
                    utm_content: 'content'
                };
                return map[key] || key;
            };

            const assignTouch = (touchKey, prefix) => {
                if (data[touchKey] && typeof data[touchKey] === 'object') {
                    Object.keys(data[touchKey]).forEach((key) => {
                        const mappedKey = mapKey(key);
                        flat[`${prefix}${mappedKey}`] = data[touchKey][key];
                    });
                }
            };

            assignTouch('first_touch', 'ft_');
            assignTouch('last_touch', 'lt_');

            return flat;
        }

        scanDataLayerForPII() {
            // Simple check for common PII keys in dataLayer
            if (!window.dataLayer) return;

            const piiKeys = ['email', 'phone', 'firstname', 'lastname', 'first_name', 'last_name', 'customerEmail', 'customer_email'];
            let piiFound = false;

            const hasPermissionForPiiLogging = () => {
                const consent = this.getConsent();

                if (CONFIG.requireConsent == '1') {
                    return !!(consent && consent.marketing);
                }

                // Even when consent is not required globally, only log PII when an explicit consent decision allows marketing.
                return !!(consent && consent.marketing);
            };

            // Helper to recursively search object
            const searchObj = (obj) => {
                for (let key in obj) {
                    if (typeof obj[key] === 'object' && obj[key] !== null) {
                        searchObj(obj[key]);
                    } else if (piiKeys.includes(key.toLowerCase())) {
                        // Check if value looks like PII (basic check)
                        if (obj[key] && obj[key].toString().length > 2) {
                            piiFound = true;
                        }
                    }
                }
            };

            window.dataLayer.forEach(item => {
                searchObj(item);
            });

            if (piiFound) {
                if (!hasPermissionForPiiLogging()) {
                    console.warn('ClickTrail: PII detected but logging blocked until user grants marketing consent.');
                    return;
                }

                console.warn('ClickTrail: PII detected in Data Layer. Sending alert.');
                // Send AJAX to log risk
                // We use fetch for simplicity, assuming modern browser or polyfill
                if (CONFIG.ajaxUrl) {
                    const formData = new FormData();
                    formData.append('action', 'ct_log_pii_risk');
                    formData.append('pii_found', 'true');
                    if (CONFIG.nonce) {
                        formData.append('nonce', CONFIG.nonce);
                    }

                    fetch(CONFIG.ajaxUrl, {
                        method: 'POST',
                        body: formData
                    }).catch(e => console.error('ClickTrail: Error logging PII risk', e));
                }
            }
        }

        getURLParams() {
            const params = {};
            const queryString = window.location.search.substring(1);
            const regex = /([^&=]+)=([^&]*)/g;
            let m;
            while (m = regex.exec(queryString)) {
                params[decodeURIComponent(m[1])] = decodeURIComponent(m[2]);
            }
            return params;
        }

        isAdClick(params) {
            const paidClickIds = ['gclid', 'fbclid', 'wbraid', 'gbraid', 'msclkid', 'ttclid', 'twclid', 'sc_click_id', 'epik'];
            const hasPaidId = paidClickIds.some(id => params[id]);

            const medium = (params['utm_medium'] || '').toLowerCase();
            const mediumIsPaid = this.paidMediums.includes(medium);

            return hasPaidId || mediumIsPaid;
        }

        isInternalReferrer(referrer) {
            if (!referrer) return false;
            return referrer.indexOf(window.location.hostname) !== -1;
        }

        getConsent() {
            const cookie = this.getCookie(CONSENT_COOKIE);
            if (cookie) {
                try {
                    return JSON.parse(cookie);
                } catch (e) {
                    return null;
                }
            }
            return null;
        }

        getStoredData() {
            // Try cookies first
            for (const key of COOKIE_KEYS) {
                const cookie = this.getCookie(key);
                if (cookie) {
                    try {
                        return JSON.parse(cookie);
                    } catch (e) {
                        console.error('ClickTrail Attribution: Error parsing cookie', e);
                    }
                }
            }

            // Fallback to LocalStorage
            for (const key of COOKIE_KEYS) {
                const ls = localStorage.getItem(key);
                if (ls) {
                    try {
                        return JSON.parse(ls);
                    } catch (e) {
                        console.error('ClickTrail Attribution: Error parsing localStorage', e);
                    }
                }
            } catch (e) {
                console.error('ClickTrail Attribution: Error parsing localStorage', e);
            }

            return null;
        }

        saveData(data) {
            const dataStr = JSON.stringify(data);
            // Save Cookie
            this.setCookie(CONFIG.cookieName, dataStr, CONFIG.cookieDays);
            // Save LocalStorage
            localStorage.setItem(CONFIG.cookieName, dataStr);
        }

        setCookie(name, value, days) {
            let expires = "";
            if (days) {
                const date = new Date();
                date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
                expires = "; expires=" + date.toUTCString();
            }

            // Rely on default browser behavior for domain; only set Secure on HTTPS to allow HTTP support
            const secureFlag = window.location.protocol === 'https:' ? '; Secure' : '';
            document.cookie = name + "=" + (value || "") + expires + "; path=/; SameSite=Lax" + secureFlag;
        }

        getCookie(name) {
            const nameEQ = name + "=";
            const ca = document.cookie.split(';');
            for (let i = 0; i < ca.length; i++) {
                let c = ca[i];
                while (c.charAt(0) === ' ') c = c.substring(1, c.length);
                if (c.indexOf(nameEQ) === 0) return c.substring(nameEQ.length, c.length);
            }
            return null;
        }
    }

    const bootstrapAttribution = () => {
        const instance = new ClickTrailAttribution();
        window.clickTrailAttribution = instance;
        return instance;
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootstrapAttribution);
    } else {
        bootstrapAttribution();
    }

})();
