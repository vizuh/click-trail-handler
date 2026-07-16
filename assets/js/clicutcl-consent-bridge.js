/**
 * ClickTrail Consent Bridge
 *
 * Abstracts consent state from multiple sources:
 *   - ClickTrail plugin consent cookie/banner
 *   - Cookiebot
 *   - OneTrust
 *   - Complianz
 *   - Google Consent Mode (dataLayer)
 *   - Custom implementations via window.ClickTrailConsent.grant/deny
 *
 * Dispatches:
 *   - document event: ct:consentResolved
 */
(function (window, document) {
    'use strict';

    var CONFIG = window.ctConsentBridgeConfig || {
        enabled: true,
        cookieName: 'ct_consent',
        serverCookieName: 'ct_consent_state',
        cmpSource: 'auto',
        gtmConsentKey: 'analytics_storage',
        timeout: 3000,
        mode: 'strict',
        fallbackGranted: false,
        debug: false
    };

    window.ctDebug = !!window.ctDebug || !!CONFIG.debug;

    var resolved = false;
    var consentState = { marketing: false, analytics: false };
    var resolvedBy = 'unknown';

    function normalizeConsent(value) {
        if (typeof value === 'boolean') {
            return { marketing: value, analytics: value };
        }
        return {
            marketing: !!(value && value.marketing),
            analytics: !!(value && value.analytics)
        };
    }

    function dispatchLegacyEvents() {
        var detail = {
            marketing: consentState.marketing,
            analytics: consentState.analytics,
            source: resolvedBy
        };

        window.dispatchEvent(new CustomEvent('ct_consent_updated', {
            detail: detail
        }));

        if (consentState.marketing) {
            window.dispatchEvent(new CustomEvent('consent_granted', {
                detail: detail
            }));
        }
    }

    function debugLog() {
        if (!window.ctDebug) return;
        try {
            var args = Array.prototype.slice.call(arguments);
            args.unshift('[ClickTrail]');
            window.console.log.apply(window.console, args);
        } catch (e) {
            // no-op
        }
    }

    function dispatchResolved() {
        document.dispatchEvent(new CustomEvent('ct:consentResolved', {
            detail: {
                granted: consentState.marketing,
                marketing: consentState.marketing,
                analytics: consentState.analytics,
                resolvedBy: resolvedBy
            },
            bubbles: false
        }));
        dispatchLegacyEvents();
        debugLog('Consent resolved:', consentState, 'via', resolvedBy);
    }

    function writeServerCookie() {
        var name = String(CONFIG.serverCookieName || 'ct_consent_state');
        var value = encodeURIComponent(JSON.stringify(consentState));
        var expires = new Date(Date.now() + (365 * 24 * 60 * 60 * 1000)).toUTCString();
        var secure = window.location && window.location.protocol === 'https:' ? '; Secure' : '';
        document.cookie = name + '=' + value + '; expires=' + expires + '; path=/; SameSite=Lax' + secure;
    }

    function resolve(nextConsent, source, force) {
        if (resolved && !force) {
            return;
        }

        var normalized = normalizeConsent(nextConsent);
        var nextSource = String(source || 'unknown');

        if (
            resolved &&
            consentState.marketing === normalized.marketing &&
            consentState.analytics === normalized.analytics &&
            resolvedBy === nextSource
        ) {
            return;
        }

        resolved = true;
        consentState = normalized;
        resolvedBy = nextSource;
        if (CONFIG.enabled !== false && nextSource !== 'plugin-cookie') {
            writeServerCookie();
        }
        dispatchResolved();
    }

    function parseConsentToken(rawValue) {
        var value = String(rawValue || '').trim();
        if (!value) return null;

        var lowered = value.toLowerCase();
        if (lowered === 'granted' || lowered === '1' || lowered === 'true' || lowered === 'yes') {
            return normalizeConsent(true);
        }
        if (lowered === 'denied' || lowered === '0' || lowered === 'false' || lowered === 'no') {
            return normalizeConsent(false);
        }

        // Backward compatibility: ct_consent stored as JSON object.
        try {
            var parsed = JSON.parse(value);
            if (typeof parsed === 'boolean') return normalizeConsent(parsed);
            if (parsed && typeof parsed === 'object') {
                return normalizeConsent(parsed);
            }
        } catch (e) {
            // no-op
        }

        return null;
    }

    function readCookie(name) {
        var nameEq = String(name) + '=';
        var parts = String(document.cookie || '').split(';');
        for (var i = 0; i < parts.length; i++) {
            var part = parts[i].trim();
            if (part.indexOf(nameEq) === 0) {
                var raw = part.substring(nameEq.length);
                try {
                    return decodeURIComponent(raw);
                } catch (e) {
                    return raw;
                }
            }
        }
        return '';
    }

    function readPluginCookie() {
        var raw = readCookie(CONFIG.cookieName || 'ct_consent');
        if (!raw) return null;
        return parseConsentToken(raw);
    }

    function tryCookiebot() {
        if (typeof window.Cookiebot === 'undefined') {
            return false;
        }

        function readState() {
            var cb = window.Cookiebot;
            if (cb && cb.consent) {
                resolve({
                    analytics: !!cb.consent.statistics,
                    marketing: !!cb.consent.marketing
                }, 'cookiebot', true);
            } else {
                resolve(false, 'cookiebot');
            }
        }

        if (window.Cookiebot && window.Cookiebot.hasResponse) {
            readState();
        } else {
            window.addEventListener('CookiebotOnConsentReady', readState, { once: true });
        }

        return true;
    }

    function tryOneTrust() {
        if (typeof window.OneTrust === 'undefined' && typeof window.OptanonWrapper === 'undefined') {
            return false;
        }

        function readState() {
            var groups = String(window.OnetrustActiveGroups || '');
            resolve({
                analytics: groups.indexOf('C0002') !== -1,
                marketing: groups.indexOf('C0004') !== -1
            }, 'onetrust', true);
        }

        var originalWrapper = window.OptanonWrapper;
        window.OptanonWrapper = function () {
            if (typeof originalWrapper === 'function') {
                originalWrapper();
            }
            readState();
        };

        if (window.OnetrustActiveGroups) {
            readState();
        }

        return true;
    }

    function tryComplianz() {
        if (typeof window.complianz === 'undefined') {
            return false;
        }

        document.addEventListener('cmplz_fire_categories', function (e) {
            var cats = (e && e.detail) || {};
            resolve({ analytics: !!cats.statistics, marketing: !!cats.marketing }, 'complianz', true);
        });

        if (window.complianz && window.complianz.consent_data) {
            resolve({
                analytics: !!window.complianz.consent_data.statistics,
                marketing: !!window.complianz.consent_data.marketing
            }, 'complianz-sync', true);
        }

        return true;
    }

    function extractGcmState(entry, key) {
        if (!entry) return null;

        // gtag push style: ['consent', 'update', {...}]
        if (Array.isArray(entry) && entry[0] === 'consent' && entry[2] && typeof entry[2][key] !== 'undefined') {
            return entry[2][key] === 'granted';
        }

        // object style fallback
        if (typeof entry === 'object' && entry !== null) {
            if (entry[0] === 'consent' && entry[2] && typeof entry[2][key] !== 'undefined') {
                return entry[2][key] === 'granted';
            }
            if (entry.event === 'consent' && entry[2] && typeof entry[2][key] !== 'undefined') {
                return entry[2][key] === 'granted';
            }
            if (entry.event === 'consent_update' && typeof entry[key] !== 'undefined') {
                return entry[key] === 'granted';
            }
        }

        return null;
    }

    function tryGoogleConsentMode() {
        window.dataLayer = window.dataLayer || [];
        var dl = window.dataLayer;
        var targetKey = String(CONFIG.gtmConsentKey || 'analytics_storage');

        function readState(entry) {
            var analytics = extractGcmState(entry, targetKey);
            var marketing = extractGcmState(entry, 'ad_storage');
            if (analytics === null && marketing === null) return null;
            return { analytics: analytics === true, marketing: marketing === true };
        }

        for (var i = 0; i < dl.length; i++) {
            var state = readState(dl[i]);
            if (state !== null) {
                resolve(state, 'gcm-datalayer');
                return true;
            }
        }

        // Optional bridge from external code (for custom integrations).
        document.addEventListener('ct:gtmConsentUpdate', function (e) {
            var detail = e && e.detail ? e.detail : {};
            if (typeof detail[targetKey] !== 'undefined' || typeof detail.ad_storage !== 'undefined') {
                resolve({
                    analytics: detail[targetKey] === 'granted',
                    marketing: detail.ad_storage === 'granted'
                }, 'gcm-event', true);
            }
        });

        // Official GTM hook pattern: dataLayer function callbacks.
        dl.push(function () {
            var self = this;
            if (!self) return;

            var state = readState(self);
            if (state !== null) {
                resolve(state, 'gcm-datalayer-push', true);
            }
        });

        return false;
    }

    function startTimeoutFallback() {
        var timeout = Number(CONFIG.timeout);
        if (!Number.isFinite(timeout) || timeout <= 0) {
            timeout = 3000;
        }
        var fallbackGranted = !!CONFIG.fallbackGranted;

        window.setTimeout(function () {
            if (!resolved) {
                resolve(fallbackGranted, fallbackGranted ? 'timeout-fallback-granted' : 'timeout-fallback-denied');
            }
        }, timeout);
    }

    function autoDetect() {
        if (CONFIG.enabled === false) {
            resolve(true, 'mode-disabled');
            return;
        }

        var source = String(CONFIG.cmpSource || 'auto').toLowerCase();

        var cookieState = readPluginCookie();
        if (cookieState !== null) {
            resolve(cookieState, 'plugin-cookie');
            return;
        }

        if (source === 'plugin') {
            if (CONFIG.fallbackGranted) {
                resolve(true, 'mode-default-granted');
                return;
            }
            startTimeoutFallback();
            return;
        }
        if (source === 'custom') {
            if (CONFIG.fallbackGranted) {
                resolve(true, 'mode-default-granted');
                return;
            }
            startTimeoutFallback();
            return;
        }
        if (source === 'cookiebot') {
            tryCookiebot();
            startTimeoutFallback();
            return;
        }
        if (source === 'onetrust') {
            tryOneTrust();
            startTimeoutFallback();
            return;
        }
        if (source === 'complianz') {
            tryComplianz();
            startTimeoutFallback();
            return;
        }
        if (source === 'gtm') {
            tryGoogleConsentMode();
            startTimeoutFallback();
            return;
        }

        // Auto detect mode.
        if (tryCookiebot()) {
            startTimeoutFallback();
            return;
        }
        if (tryOneTrust()) {
            startTimeoutFallback();
            return;
        }
        if (tryComplianz()) {
            startTimeoutFallback();
            return;
        }
        tryGoogleConsentMode();
        startTimeoutFallback();
    }

    window.ClickTrailConsent = {
        isGranted: function () {
            return !!(resolved && consentState.marketing);
        },
        isResolved: function () {
            return !!resolved;
        },
        getState: function () {
            return {
                resolved: !!resolved,
                granted: !!consentState.marketing,
                marketing: !!consentState.marketing,
                analytics: !!consentState.analytics,
                source: resolvedBy
            };
        },
        update: function (preferences, source) {
            resolve(preferences, source || 'manual', true);
        },
        grant: function (source) {
            resolve(true, source || 'manual', true);
        },
        deny: function (source) {
            resolve(false, source || 'manual', true);
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', autoDetect, { once: true });
    } else {
        autoDetect();
    }

})(window, document);
