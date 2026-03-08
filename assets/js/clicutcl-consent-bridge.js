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
        cookieName: 'ct_consent',
        cmpSource: 'auto',
        gtmConsentKey: 'analytics_storage',
        timeout: 3000,
        mode: 'strict',
        fallbackGranted: false,
        debug: false
    };

    window.ctDebug = !!window.ctDebug || !!CONFIG.debug;

    var resolved = false;
    var granted = false;
    var resolvedBy = 'unknown';

    function dispatchLegacyEvents() {
        var detail = {
            marketing: !!granted,
            analytics: !!granted,
            source: resolvedBy
        };

        window.dispatchEvent(new CustomEvent('ct_consent_updated', {
            detail: detail
        }));

        if (granted) {
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
                granted: granted,
                resolvedBy: resolvedBy
            },
            bubbles: false
        }));
        dispatchLegacyEvents();
        debugLog('Consent resolved:', granted, 'via', resolvedBy);
    }

    function resolve(isGranted, source, force) {
        if (resolved && !force) {
            return;
        }

        var nextGranted = !!isGranted;
        var nextSource = String(source || 'unknown');

        if (resolved && granted === nextGranted && resolvedBy === nextSource) {
            return;
        }

        resolved = true;
        granted = nextGranted;
        resolvedBy = nextSource;
        dispatchResolved();
    }

    function parseConsentToken(rawValue) {
        var value = String(rawValue || '').trim();
        if (!value) return null;

        var lowered = value.toLowerCase();
        if (lowered === 'granted' || lowered === '1' || lowered === 'true' || lowered === 'yes') {
            return true;
        }
        if (lowered === 'denied' || lowered === '0' || lowered === 'false' || lowered === 'no') {
            return false;
        }

        // Backward compatibility: ct_consent stored as JSON object.
        try {
            var parsed = JSON.parse(value);
            if (typeof parsed === 'boolean') return parsed;
            if (parsed && typeof parsed === 'object') {
                var marketing = !!parsed.marketing;
                var analytics = !!parsed.analytics;
                return marketing || analytics;
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
                resolve(!!cb.consent.statistics, 'cookiebot');
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
            resolve(groups.indexOf('C0002') !== -1, 'onetrust');
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
            resolve(!!cats.statistics, 'complianz');
        }, { once: true });

        if (window.complianz && window.complianz.consent_data) {
            resolve(!!window.complianz.consent_data.statistics, 'complianz-sync');
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

        for (var i = 0; i < dl.length; i++) {
            var state = extractGcmState(dl[i], targetKey);
            if (state !== null) {
                resolve(state, 'gcm-datalayer');
                return true;
            }
        }

        // Optional bridge from external code (for custom integrations).
        document.addEventListener('ct:gtmConsentUpdate', function (e) {
            var detail = e && e.detail ? e.detail : {};
            if (typeof detail[targetKey] !== 'undefined') {
                resolve(detail[targetKey] === 'granted', 'gcm-event');
            }
        });

        // Official GTM hook pattern: dataLayer function callbacks.
        dl.push(function () {
            var self = this;
            if (!self) return;

            var state = extractGcmState(self, targetKey);
            if (state !== null) {
                resolve(state, 'gcm-datalayer-push');
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
            if (!tryCookiebot()) startTimeoutFallback();
            return;
        }
        if (source === 'onetrust') {
            if (!tryOneTrust()) startTimeoutFallback();
            return;
        }
        if (source === 'complianz') {
            if (!tryComplianz()) startTimeoutFallback();
            return;
        }
        if (source === 'gtm') {
            tryGoogleConsentMode();
            startTimeoutFallback();
            return;
        }

        // Auto detect mode.
        if (tryCookiebot()) return;
        if (tryOneTrust()) return;
        if (tryComplianz()) return;
        tryGoogleConsentMode();
        startTimeoutFallback();
    }

    window.ClickTrailConsent = {
        isGranted: function () {
            return !!(resolved && granted);
        },
        isResolved: function () {
            return !!resolved;
        },
        getState: function () {
            return {
                resolved: !!resolved,
                granted: !!granted,
                source: resolvedBy
            };
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
