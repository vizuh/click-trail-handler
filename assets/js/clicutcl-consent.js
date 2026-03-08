(function () {
    'use strict';

    const CONSENT_COOKIE = (
        window.clicutclConsentL10n && window.clicutclConsentL10n.cookieName
            ? String(window.clicutclConsentL10n.cookieName)
            : (
                window.ctConsentBridgeConfig && window.ctConsentBridgeConfig.cookieName
                    ? String(window.ctConsentBridgeConfig.cookieName)
                    : 'ct_consent'
            )
    );
    const CONSENT_DAYS = 365;

    class ClickTrailConsent {
        constructor() {
            this.init();
        }

        init() {
            if (!this.hasConsentDecision()) {
                this.showBanner();
            } else {
                const consent = this.getConsent();
                this.pushConsentToDataLayer(consent);
                this.syncBridgeFromConsent(consent, 'plugin-cookie');
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
            const l10n = window.clicutclConsentL10n || {
                bannerText: 'We use cookies to improve your experience and analyze traffic.',
                readMore: 'Read more',
                acceptAll: 'Accept All',
                rejectEssential: 'Reject Non-Essential',
                privacyUrl: '/privacy-policy'
            };

            const banner = document.createElement('div');
            banner.id = 'ct-consent-banner';
            banner.setAttribute('role', 'dialog');
            banner.setAttribute('aria-live', 'polite');

            const content = document.createElement('div');
            content.className = 'ct-consent-content';

            const paragraph = document.createElement('p');
            paragraph.textContent = String(l10n.bannerText || '') + ' ';

            const link = document.createElement('a');
            link.textContent = String(l10n.readMore || '');
            const rawUrl = String(l10n.privacyUrl || '#');
            link.href = /^https?:\/\//i.test(rawUrl) ? rawUrl : '#';
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            paragraph.appendChild(link);

            const actions = document.createElement('div');
            actions.className = 'ct-consent-actions';

            const acceptBtn = document.createElement('button');
            acceptBtn.id = 'ct-accept-all';
            acceptBtn.className = 'ct-btn-primary';
            acceptBtn.textContent = String(l10n.acceptAll || '');
            acceptBtn.addEventListener('click', () => this.handleAccept());

            const rejectBtn = document.createElement('button');
            rejectBtn.id = 'ct-reject-all';
            rejectBtn.className = 'ct-btn-secondary';
            rejectBtn.textContent = String(l10n.rejectEssential || '');
            rejectBtn.addEventListener('click', () => this.handleReject());

            actions.appendChild(acceptBtn);
            actions.appendChild(rejectBtn);
            content.appendChild(paragraph);
            content.appendChild(actions);
            banner.appendChild(content);
            document.body.appendChild(banner);
        }

        hideBanner() {
            const banner = document.getElementById('ct-consent-banner');
            if (banner) banner.remove();
        }

        setConsent(preferences, source = 'plugin-banner') {
            const value = JSON.stringify(preferences);
            this.setCookie(CONSENT_COOKIE, value, CONSENT_DAYS);
            this.pushConsentToDataLayer(preferences);
            this.syncBridgeFromConsent(preferences, source);
        }

        handleAccept() {
            const prefs = { analytics: true, marketing: true };
            this.setConsent(prefs, 'plugin-banner');
            this.hideBanner();
        }

        handleReject() {
            const prefs = { analytics: false, marketing: false };
            this.setConsent(prefs, 'plugin-banner');
            this.hideBanner();
        }

        syncBridgeFromConsent(preferences, source) {
            if (typeof window.ClickTrailConsent === 'undefined') {
                return;
            }

            const granted = !!(preferences && (preferences.analytics || preferences.marketing));
            if (granted) {
                window.ClickTrailConsent.grant(source || 'plugin-cookie');
            } else {
                window.ClickTrailConsent.deny(source || 'plugin-cookie');
            }
        }

        pushConsentToDataLayer(preferences) {
            window.dataLayer = window.dataLayer || [];

            // Push event
            // Push event
            // window.dataLayer.push({
            //     event: 'ct_consent_update',
            //     ct_consent: preferences
            // });

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
            document.dispatchEvent(new CustomEvent('ct:gtmConsentUpdate', { detail: consentMode }));
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
        document.addEventListener('DOMContentLoaded', () => new ClickTrailConsent());
    } else {
        new ClickTrailConsent();
    }

})();
