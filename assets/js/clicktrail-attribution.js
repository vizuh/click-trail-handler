(function () {
    'use strict';

    const CONFIG = window.hpAttributionConfig || {
        cookieName: 'hp_attribution',
        cookieDays: 90,
        requireConsent: false
    };

    const CONSENT_COOKIE = 'hp_consent';

    class HPAttribution {
        constructor() {
            this.paramsToCapture = [
                'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
                'gclid', 'fbclid', 'wbraid', 'gbraid', 'msclkid', 'ttclid', 'twclid', 'sc_click_id', 'epik'
            ];
            this.init();
        }

        init() {
            if (CONFIG.requireConsent == '1') { // PHP sends string '1' or '0' usually, but let's be safe
                const consent = this.getConsent();
                if (consent && consent.marketing) {
                    this.runAttribution();
                } else {
                    console.log('HP Attribution: Waiting for consent...');
                    window.addEventListener('hp_consent_updated', (e) => {
                        if (e.detail.marketing) {
                            console.log('HP Attribution: Consent granted, running...');
                            this.runAttribution();
                        }
                    });
                }
            } else {
                this.runAttribution();
            }
        }

        runAttribution() {
            const currentParams = this.getURLParams();
            const referrer = document.referrer;

            // Only proceed if we have params or if it's a new session (logic can be complex, for now check params)
            // Actually, we should always check if we need to update last_touch

            let storedData = this.getStoredData();

            // Debug: Init
            console.log('HP Attribution init', {
                url: window.location.href,
                params: currentParams,
                storedDataBefore: storedData
            });

            // Prepare new touch data
            let newTouch = {};
            let hasMarketingParams = false;

            this.paramsToCapture.forEach(param => {
                if (currentParams[param]) {
                    newTouch[param] = currentParams[param];
                    hasMarketingParams = true;
                }
            });

            if (referrer && !this.isInternalReferrer(referrer)) {
                newTouch['referrer'] = referrer;
                // If referrer is external, it might be a new touch even without UTMs (organic/referral)
                // But for MVP we focus on explicit params + referrer
            }

            // Timestamp
            const now = new Date().toISOString();

            if (hasMarketingParams || (newTouch['referrer'] && !storedData)) {
                // We have new data.
                newTouch['timestamp'] = now;
                newTouch['landing_page'] = window.location.href;

                if (!storedData) {
                    // First visit ever
                    storedData = {
                        first_touch: newTouch,
                        last_touch: newTouch,
                        session_count: 1
                    };
                } else {
                    // Returning visitor
                    // Update last_touch
                    storedData.last_touch = newTouch;
                    storedData.session_count = (storedData.session_count || 1) + 1;
                }

                this.saveData(storedData);

                // Debug: Saved
                console.log('HP Attribution saved', {
                    storedDataAfter: storedData
                });
            }

            // Expose to window
            window.hpAttribution = storedData;

            // GTM Bridge: Page View
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push({
                event: 'hp_page_view',
                hp_attribution: storedData
            });

            // GTM Bridge: Form Listeners
            this.initFormListeners(storedData);
        }

        initFormListeners(data) {
            // Contact Form 7
            document.addEventListener('wpcf7mailsent', (e) => {
                window.dataLayer.push({
                    event: 'hp_lead',
                    form_provider: 'cf7',
                    form_id: e.detail.contactFormId,
                    hp_attribution: data
                });
            });

            // Fluent Forms (jQuery)
            if (window.jQuery) {
                window.jQuery(document.body).on('fluentform_submission_success', function () {
                    window.dataLayer.push({
                        event: 'hp_lead',
                        form_provider: 'fluentform',
                        hp_attribution: data
                    });
                });
            }

            // Gravity Forms (jQuery) - gform_confirmation_loaded
            if (window.jQuery) {
                window.jQuery(document).on('gform_confirmation_loaded', function (e, formId) {
                    window.dataLayer.push({
                        event: 'hp_lead',
                        form_provider: 'gravityforms',
                        form_id: formId,
                        hp_attribution: data
                    });
                });
            }

            // PII Scanner
            this.scanDataLayerForPII();
        }

        scanDataLayerForPII() {
            // Simple check for common PII keys in dataLayer
            if (!window.dataLayer) return;

            const piiKeys = ['email', 'phone', 'firstname', 'lastname', 'first_name', 'last_name', 'customerEmail', 'customer_email'];
            let piiFound = false;

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
                console.warn('HP Attribution: PII detected in Data Layer. Sending alert.');
                // Send AJAX to log risk
                // We use fetch for simplicity, assuming modern browser or polyfill
                if (CONFIG.ajaxUrl) {
                    const formData = new FormData();
                    formData.append('action', 'hp_log_pii_risk');
                    formData.append('pii_found', 'true');

                    fetch(CONFIG.ajaxUrl, {
                        method: 'POST',
                        body: formData
                    }).catch(e => console.error('HP Attribution: Error logging PII risk', e));
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
            // Try Cookie first
            const cookie = this.getCookie(CONFIG.cookieName);
            if (cookie) {
                try {
                    return JSON.parse(cookie);
                } catch (e) {
                    console.error('HP Attribution: Error parsing cookie', e);
                }
            }
            // Fallback to LocalStorage
            const ls = localStorage.getItem(CONFIG.cookieName);
            if (ls) {
                try {
                    return JSON.parse(ls);
                } catch (e) {
                    console.error('HP Attribution: Error parsing localStorage', e);
                }
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

            // Rely on default browser behavior for domain
            document.cookie = name + "=" + (value || "") + expires + "; path=/; SameSite=Lax; Secure";
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

    // Initialize - Wait for consent signal (requires user to implement a CMP)
    window.addEventListener('consent_granted', () => {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', () => new HPAttribution());
        } else {
            new HPAttribution();
        }
    });

})();
