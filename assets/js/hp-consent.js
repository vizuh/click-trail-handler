(function () {
    'use strict';

    const CONSENT_COOKIE = 'hp_consent';
    const CONSENT_DAYS = 365;

    class HPConsent {
        constructor() {
            this.init();
        }

        init() {
            if (!this.hasConsentDecision()) {
                this.showBanner();
            } else {
                this.pushConsentToDataLayer(this.getConsent());
            }
        }

        hasConsentDecision() {
            return !!this.getCookie(CONSENT_COOKIE);
        }

        getConsent() {
            const cookie = this.getCookie(CONSENT_COOKIE);
            if (cookie) {
                try {
                    return JSON.parse(cookie);
                } catch (e) {
                    return { analytics: false, marketing: false };
                }
            }
            return { analytics: false, marketing: false };
        }

        showBanner() {
            // Create Banner HTML
            const banner = document.createElement('div');
            banner.id = 'hp-consent-banner';
            banner.innerHTML = `
                <div class="hp-consent-content">
                    <p>We use cookies to improve your experience and analyze traffic. 
                       <a href="/privacy-policy">Read more</a>.
                    </p>
                    <div class="hp-consent-actions">
                        <button id="hp-accept-all" class="hp-btn-primary">Accept All</button>
                        <button id="hp-reject-all" class="hp-btn-secondary">Reject Non-Essential</button>
                    </div>
                </div>
            `;
            document.body.appendChild(banner);

            // Bind Events
            document.getElementById('hp-accept-all').addEventListener('click', () => {
                this.setConsent({ analytics: true, marketing: true });
                this.hideBanner();
            });

            document.getElementById('hp-reject-all').addEventListener('click', () => {
                this.setConsent({ analytics: false, marketing: false });
                this.hideBanner();
            });
        }

        hideBanner() {
            const banner = document.getElementById('hp-consent-banner');
            if (banner) banner.remove();
        }

        setConsent(preferences) {
            const value = JSON.stringify(preferences);
            this.setCookie(CONSENT_COOKIE, value, CONSENT_DAYS);
            this.pushConsentToDataLayer(preferences);

            // Dispatch event for other scripts
            window.dispatchEvent(new CustomEvent('hp_consent_updated', { detail: preferences }));
        }

        pushConsentToDataLayer(preferences) {
            window.dataLayer = window.dataLayer || [];

            // Push event
            window.dataLayer.push({
                event: 'hp_consent_update',
                hp_consent: preferences
            });

            // Google Consent Mode v2 (Basic)
            // If GTM is used, this helps. 
            // Note: Ideally this should run BEFORE GTM loads, but as a plugin we might load later.
            // Users should use the GTM template or we hook high in head.
            function gtag() { window.dataLayer.push(arguments); }

            const consentMode = {
                'analytics_storage': preferences.analytics ? 'granted' : 'denied',
                'ad_storage': preferences.marketing ? 'granted' : 'denied',
                'ad_user_data': preferences.marketing ? 'granted' : 'denied',
                'ad_personalization': preferences.marketing ? 'granted' : 'denied'
            };

            gtag('consent', 'update', consentMode);
        }

        setCookie(name, value, days) {
            let expires = "";
            if (days) {
                const date = new Date();
                date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
                expires = "; expires=" + date.toUTCString();
            }
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

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => new HPConsent());
    } else {
        new HPConsent();
    }

})();
