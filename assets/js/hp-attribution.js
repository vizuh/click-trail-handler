(function () {
    'use strict';

    const CONFIG = window.hpAttributionConfig || {
        cookieName: 'hp_attribution',
        cookieDays: 90
    };

    class HPAttribution {
        constructor() {
            this.paramsToCapture = [
                'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
                'gclid', 'fbclid', 'wbraid', 'gbraid', 'msclkid', 'ttclid', 'twclid', 'sc_click_id', 'epik'
            ];
            this.init();
        }

        init() {
            const currentParams = this.getURLParams();
            const referrer = document.referrer;

            // Only proceed if we have params or if it's a new session (logic can be complex, for now check params)
            // Actually, we should always check if we need to update last_touch

            let storedData = this.getStoredData();

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

            // Get Apex Domain (simplified for MVP, can be improved)
            const hostname = window.location.hostname;
            const parts = hostname.split('.');
            let domain = hostname;
            if (parts.length > 2) {
                // e.g. www.example.com -> .example.com
                // This is naive for co.uk etc, but works for standard domains. 
                // For MVP we can try to set on the root.
                // A better way is to try setting on the widest possible domain.
                domain = '.' + parts.slice(-2).join('.');
            } else {
                domain = '.' + hostname;
            }

            document.cookie = name + "=" + (value || "") + expires + "; path=/; domain=" + domain + "; SameSite=Lax; Secure";
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

    // Initialize
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => new HPAttribution());
    } else {
        new HPAttribution();
    }

})();
