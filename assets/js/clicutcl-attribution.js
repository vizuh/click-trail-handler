(function () {
    'use strict';

    const CONFIG = window.clicutcl_config || {
        cookieName: 'attribution',
        cookieDays: 90,
        consentCookieName: 'ct_consent',
        requireConsent: true
    };
    const DEBUG_ENABLED = !!CONFIG.debug;
    const STORAGE_ENVELOPE_VERSION = 1;
    const TOUCH_QUERY_FIELD_MAP = Object.freeze({
        utm_source: 'source',
        utm_medium: 'medium',
        utm_campaign: 'campaign',
        utm_term: 'term',
        utm_content: 'content',
        utm_id: 'utm_id',
        utm_source_platform: 'utm_source_platform',
        utm_creative_format: 'utm_creative_format',
        utm_marketing_tactic: 'utm_marketing_tactic'
    });
    const TOUCH_QUERY_KEYS = Object.keys(TOUCH_QUERY_FIELD_MAP);
    const TOUCH_FIELD_KEYS = Array.from(new Set(Object.values(TOUCH_QUERY_FIELD_MAP)));
    const CLICK_ID_KEYS = [
        'gclid', 'fbclid', 'msclkid', 'ttclid', 'wbraid', 'gbraid',
        'twclid', 'li_fat_id', 'sccid', 'epik'
    ];
    const CLICK_ID_SIGNAL_KEYS = CLICK_ID_KEYS.concat(['sc_click_id']);
    const BROWSER_IDENTIFIER_KEYS = [
        'fbc', 'fbp', 'ttp', 'li_gc', 'ga_client_id', 'ga_session_id', 'ga_session_number'
    ];
    const CLICK_ID_ALIASES = {
        gclid: ['gclid'],
        fbclid: ['fbclid'],
        msclkid: ['msclkid'],
        ttclid: ['ttclid'],
        wbraid: ['wbraid'],
        gbraid: ['gbraid'],
        twclid: ['twclid'],
        li_fat_id: ['li_fat_id'],
        sccid: ['sccid', 'sc_click_id'],
        epik: ['epik']
    };
    const ATTRIBUTION_KEY_ALIASES = {
        _fbc: 'fbc',
        _fbp: 'fbp',
        _ttp: 'ttp',
        sc_click_id: 'sccid',
        ft_sc_click_id: 'ft_sccid',
        lt_sc_click_id: 'lt_sccid',
        first_touch_timestamp: 'ft_touch_timestamp',
        last_touch_timestamp: 'lt_touch_timestamp',
        first_landing_page: 'ft_landing_page',
        last_landing_page: 'lt_landing_page'
    };
    const SEARCH_REFERRER_RULES = Object.freeze([
        { source: 'google', labels: ['google'] },
        { source: 'bing', domains: ['bing.com'] },
        { source: 'yahoo', labels: ['yahoo'] },
        { source: 'duckduckgo', domains: ['duckduckgo.com'] },
        { source: 'ecosia', domains: ['ecosia.org'] },
        { source: 'yandex', labels: ['yandex'] },
        { source: 'baidu', domains: ['baidu.com'] }
    ]);
    const SOCIAL_REFERRER_RULES = Object.freeze([
        { source: 'facebook', domains: ['facebook.com'] },
        { source: 'instagram', domains: ['instagram.com'] },
        { source: 'linkedin', domains: ['linkedin.com', 'lnkd.in'] },
        { source: 'twitter', domains: ['twitter.com', 't.co', 'x.com'] },
        { source: 'reddit', domains: ['reddit.com'] },
        { source: 'pinterest', domains: ['pinterest.com'] },
        { source: 'youtube', domains: ['youtube.com', 'youtu.be'] },
        { source: 'tiktok', domains: ['tiktok.com'] }
    ]);

    function sanitizeKey(key) {
        if (key === null || key === undefined) return '';
        const cleaned = String(key).toLowerCase().replace(/[^a-z0-9_]/g, '');
        return cleaned.slice(0, 64);
    }

    function canonicalizeAttributionKey(key) {
        const cleaned = sanitizeKey(key);
        if (!cleaned) return '';
        return ATTRIBUTION_KEY_ALIASES[cleaned] || cleaned;
    }

    function sanitizeValue(value, maxLen = 256) {
        if (value === null || value === undefined) return '';
        let s = String(value);
        s = s.replace(/[\u0000-\u001F\u007F]/g, ' ').trim();
        if (!s) return '';
        if (s.length > maxLen) s = s.slice(0, maxLen);
        return s;
    }

    function escapeRegExp(value) {
        return String(value || '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    function sanitizeAttributionData(input) {
        if (!input || typeof input !== 'object') return {};
        const out = {};
        Object.keys(input).forEach((rawKey) => {
            const rawSanitizedKey = sanitizeKey(rawKey);
            const key = canonicalizeAttributionKey(rawKey);
            if (!key) return;

            if (key !== rawSanitizedKey && Object.prototype.hasOwnProperty.call(out, key)) {
                return;
            }

            const rawValue = input[rawKey];
            if (typeof rawValue === 'boolean') {
                out[key] = rawValue;
                return;
            }
            if (typeof rawValue === 'number' && Number.isFinite(rawValue)) {
                out[key] = rawValue;
                return;
            }
            if (typeof rawValue === 'string' || typeof rawValue === 'number') {
                const v = sanitizeValue(rawValue, 256);
                if (v !== '') out[key] = v;
            }
        });
        return out;
    }

    function getConsentCookieName() {
        const configured = String(
            CONFIG.consentCookieName ||
            (window.ctConsentBridgeConfig && window.ctConsentBridgeConfig.cookieName) ||
            'ct_consent'
        ).toLowerCase().replace(/[^a-z0-9_-]/g, '');
        return configured || 'ct_consent';
    }

    function getRetentionMs() {
        const days = Number(CONFIG.cookieDays);
        if (!Number.isFinite(days) || days <= 0) return 0;
        return days * 24 * 60 * 60 * 1000;
    }

    function pickClickId(raw, key) {
        if (!raw || typeof raw !== 'object') return '';
        const aliases = CLICK_ID_ALIASES[key] || [key];

        for (let i = 0; i < aliases.length; i++) {
            const alias = aliases[i];
            if (raw[alias]) return raw[alias];
            if (raw['lt_' + alias]) return raw['lt_' + alias];
            if (raw['ft_' + alias]) return raw['ft_' + alias];
        }

        return '';
    }

    function withCanonicalClickIds(raw) {
        const out = Object.assign({}, raw || {});
        CLICK_ID_KEYS.forEach((key) => {
            const value = pickClickId(raw, key);
            if (value && !out[key]) out[key] = String(value);
        });
        return out;
    }

    function normalizeStoredAttribution(raw) {
        return sanitizeAttributionData(withCanonicalClickIds(raw || {}));
    }

    function getTouchStorageKey(queryKey, prefix) {
        const fieldKey = TOUCH_QUERY_FIELD_MAP[queryKey];
        if (!fieldKey) return '';
        return prefix + '_' + fieldKey;
    }

    function normalizeHostname(host) {
        return String(host || '')
            .trim()
            .toLowerCase()
            .replace(/\.+$/, '')
            .replace(/^www\./, '');
    }

    function parseUrlSafely(rawUrl, baseUrl) {
        try {
            return new URL(rawUrl, baseUrl || window.location.href);
        } catch (e) {
            return null;
        }
    }

    function areRelatedHosts(firstHost, secondHost) {
        const first = normalizeHostname(firstHost);
        const second = normalizeHostname(secondHost);

        if (!first || !second) return false;

        return first === second || first.endsWith('.' + second) || second.endsWith('.' + first);
    }

    function hostMatchesDomain(host, domain) {
        const normalizedHost = normalizeHostname(host);
        const normalizedDomain = normalizeHostname(domain);

        if (!normalizedHost || !normalizedDomain) return false;

        return normalizedHost === normalizedDomain || normalizedHost.endsWith('.' + normalizedDomain);
    }

    function hostMatchesLabel(host, label) {
        const normalizedHost = normalizeHostname(host);
        const normalizedLabel = sanitizeValue(label, 64).toLowerCase();

        if (!normalizedHost || !normalizedLabel) return false;

        return new RegExp('(^|\\.)' + escapeRegExp(normalizedLabel) + '\\.').test(normalizedHost);
    }

    function matchReferrerRule(host, rules) {
        const normalizedHost = normalizeHostname(host);

        if (!normalizedHost || !Array.isArray(rules)) {
            return null;
        }

        for (let i = 0; i < rules.length; i++) {
            const rule = rules[i];
            if (Array.isArray(rule.domains) && rule.domains.some((domain) => hostMatchesDomain(normalizedHost, domain))) {
                return rule;
            }
            if (Array.isArray(rule.labels) && rule.labels.some((label) => hostMatchesLabel(normalizedHost, label))) {
                return rule;
            }
        }

        return null;
    }

    function classifyReferrerHost(host) {
        const normalizedHost = normalizeHostname(host);
        if (!normalizedHost) return null;

        const searchRule = matchReferrerRule(normalizedHost, SEARCH_REFERRER_RULES);
        if (searchRule) {
            return {
                source: searchRule.source,
                medium: 'organic'
            };
        }

        const socialRule = matchReferrerRule(normalizedHost, SOCIAL_REFERRER_RULES);
        if (socialRule) {
            return {
                source: socialRule.source,
                medium: 'social'
            };
        }

        return {
            source: normalizedHost,
            medium: 'referral'
        };
    }

    function getExternalReferrerDetails(rawReferrer) {
        const referrerUrl = parseUrlSafely(rawReferrer, window.location.href);
        if (!referrerUrl || !/^https?:$/i.test(referrerUrl.protocol)) {
            return null;
        }

        const referrerHost = normalizeHostname(referrerUrl.hostname);
        const currentHost = normalizeHostname(window.location.hostname);

        if (!referrerHost || !currentHost || areRelatedHosts(referrerHost, currentHost)) {
            return null;
        }

        return {
            host: referrerHost,
            referrer: sanitizeValue(referrerUrl.toString(), 256)
        };
    }

    // --- 1. STORE & UTILS ---
    const Store = {
        base64UrlEncode: function (str) {
            return btoa(unescape(encodeURIComponent(str)))
                .replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/g, "");
        },

        base64UrlDecode: function (str) {
            try {
                let s = String(str || '').replace(/-/g, '+').replace(/_/g, '/');
                while (s.length % 4) s += '=';
                return decodeURIComponent(escape(atob(s)));
            } catch (e) {
                return '';
            }
        },

        safeJsonParse: function (str) {
            try { return JSON.parse(str); } catch (e) { return null; }
        },

        getQueryParams: function () {
            const params = {};
            const queryString = window.location.search.substring(1);
            const regex = /([^&=]+)=([^&]*)/g;
            let m;
            while (m = regex.exec(queryString)) {
                let rawKey = '';
                let rawValue = '';
                try {
                    rawKey = decodeURIComponent(m[1] || '');
                    rawValue = decodeURIComponent(m[2] || '');
                } catch (e) {
                    continue;
                }
                const key = sanitizeKey(rawKey);
                const value = sanitizeValue(rawValue, 256);
                if (key && value !== '') {
                    params[key] = value;
                }
            }
            return params;
        },

        getCookie: function (name) {
            const match = document.cookie.match(new RegExp("(^| )" + name + "=([^;]+)"));
            return match ? decodeURIComponent(match[2]) : null;
        },

        setCookie: function (name, value, days) {
            let expires = "";
            if (days) {
                const date = new Date();
                date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
                expires = "; expires=" + date.toUTCString();
            }
            const secureFlag = window.location.protocol === 'https:' ? '; Secure' : '';
            document.cookie = name + "=" + (value || "") + expires + "; path=/; SameSite=Lax" + secureFlag;
        },

        removeCookie: function (name) {
            const secureFlag = window.location.protocol === 'https:' ? '; Secure' : '';
            document.cookie = name + "=; expires=Thu, 01 Jan 1970 00:00:00 GMT; Max-Age=0; path=/; SameSite=Lax" + secureFlag;
        },

        getLocalData: function () {
            try {
                const raw = localStorage.getItem(CONFIG.cookieName);
                if (!raw) return null;

                const parsed = this.safeJsonParse(raw);
                if (!parsed || typeof parsed !== 'object') {
                    localStorage.removeItem(CONFIG.cookieName);
                    return null;
                }

                if (
                    parsed.v === STORAGE_ENVELOPE_VERSION &&
                    parsed.data &&
                    typeof parsed.data === 'object'
                ) {
                    const expiresAt = Number(parsed.expiresAt || 0);
                    if (expiresAt > 0 && expiresAt <= Date.now()) {
                        localStorage.removeItem(CONFIG.cookieName);
                        return null;
                    }
                    return normalizeStoredAttribution(parsed.data);
                }

                // Legacy localStorage copies had no retention metadata. Drop them
                // so they cannot outlive the configured cookie retention window.
                localStorage.removeItem(CONFIG.cookieName);
            } catch (e) { }

            return null;
        },

        setLocalData: function (data) {
            const retentionMs = getRetentionMs();
            if (retentionMs <= 0) {
                try { localStorage.removeItem(CONFIG.cookieName); } catch (e) { }
                return;
            }

            try {
                localStorage.setItem(CONFIG.cookieName, JSON.stringify({
                    v: STORAGE_ENVELOPE_VERSION,
                    savedAt: Date.now(),
                    expiresAt: Date.now() + retentionMs,
                    data: sanitizeAttributionData(data)
                }));
            } catch (e) { }
        },

        clearData: function () {
            this.removeCookie(CONFIG.cookieName);
            if (CONFIG.cookieName !== 'ct_attribution') {
                this.removeCookie('ct_attribution');
            }
            try { localStorage.removeItem(CONFIG.cookieName); } catch (e) { }
            this.signedTokenCache = '';
            this.signedTokenPayloadHash = '';
        },

        getData: function () {
            // 1. Try Cookie
            const cookieRaw = this.getCookie(CONFIG.cookieName) || (
                CONFIG.cookieName !== 'ct_attribution' ? this.getCookie('ct_attribution') : null
            );
            const cookieObj = cookieRaw ? this.safeJsonParse(cookieRaw) : null;

            // 2. Try LocalStorage
            const lsObj = this.getLocalData();

            if (cookieObj && typeof cookieObj === 'object' && !lsObj) {
                this.setLocalData(cookieObj);
            }

            // 3. Merge (Cookie takes precedence over LS, but current logic usually keeps them in sync)
            return normalizeStoredAttribution(Object.assign({}, lsObj || {}, cookieObj || {}));
        },

        saveData: function (data) {
            const sanitized = sanitizeAttributionData(data);
            if (!Object.keys(sanitized).length) {
                this.clearData();
                return;
            }
            const dataStr = JSON.stringify(sanitized);
            this.setCookie(CONFIG.cookieName, dataStr, CONFIG.cookieDays);
            this.setLocalData(sanitized);
        },

        signedTokenCache: '',
        signedTokenPayloadHash: '',
        signedTokenInFlight: false,

        buildToken: function (data) {
            const normalized = withCanonicalClickIds(data || {});
            const allow = [
                'ft_source', 'ft_medium', 'ft_campaign', 'ft_term', 'ft_content',
                'ft_utm_id', 'ft_utm_source_platform', 'ft_utm_creative_format', 'ft_utm_marketing_tactic',
                'lt_source', 'lt_medium', 'lt_campaign', 'lt_term', 'lt_content',
                'lt_utm_id', 'lt_utm_source_platform', 'lt_utm_creative_format', 'lt_utm_marketing_tactic',
                'gclid', 'fbclid', 'msclkid', 'ttclid', 'wbraid', 'gbraid',
                'twclid', 'li_fat_id', 'sccid', 'epik'
            ];
            const out = {};
            allow.forEach((k) => {
                if (normalized && normalized[k]) {
                    const v = String(normalized[k]);
                    out[k] = v.length > 128 ? v.slice(0, 128) : v;
                }
            });
            if (!Object.keys(out).length) return null;
            return { v: 1, ts: Math.floor(Date.now() / 1000), data: out };
        },

        isSignedTokenFormat: function (token) {
            const raw = String(token || '').trim();
            return /^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/.test(raw);
        },

        getSignedToken: function () {
            return this.signedTokenCache || '';
        },

        prepareSignedToken: function (data) {
            if (!CONFIG.linkAppendToken) return;
            if (!window.fetch) return;

            const signUrl = String(CONFIG.tokenSignUrl || '');
            const eventsToken = String(CONFIG.eventsToken || '');
            if (!signUrl || !eventsToken) return;

            const payload = this.buildToken(data);
            if (!payload || !payload.data) {
                this.signedTokenCache = '';
                this.signedTokenPayloadHash = '';
                return;
            }

            const payloadHash = JSON.stringify(payload.data);
            if (this.signedTokenPayloadHash === payloadHash && this.signedTokenCache) {
                return;
            }
            if (this.signedTokenInFlight) {
                // If payload changed while a request is in flight, queue one re-sign for after it completes.
                if (this.signedTokenPayloadHash !== payloadHash) {
                    this.pendingResign = data;
                }
                return;
            }

            this.signedTokenInFlight = true;
            fetch(signUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Clicutcl-Token': eventsToken
                },
                body: JSON.stringify({
                    token: eventsToken,
                    data: payload.data
                }),
                keepalive: true
            })
                .then((response) => {
                    if (!response.ok) return null;
                    return response.json();
                })
                .then((json) => {
                    const signed = json && json.token ? String(json.token) : '';
                    if (!this.isSignedTokenFormat(signed)) return;
                    this.signedTokenCache = signed;
                    this.signedTokenPayloadHash = payloadHash;
                })
                .catch(() => { })
                .finally(() => {
                    this.signedTokenInFlight = false;
                    if (this.pendingResign) {
                        const pendingData = this.pendingResign;
                        this.pendingResign = null;
                        this.prepareSignedToken(pendingData);
                    }
                });
        },

        verifySignedToken: function (token) {
            const rawToken = sanitizeValue(token || '', 2048);
            if (!this.isSignedTokenFormat(rawToken)) {
                return Promise.resolve(null);
            }

            const verifyUrl = String(CONFIG.tokenVerifyUrl || '');
            if (!verifyUrl || !window.fetch) {
                return Promise.resolve(null);
            }

            return fetch(verifyUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ token: rawToken }),
                keepalive: true
            })
                .then((response) => {
                    if (!response.ok) return null;
                    return response.json();
                })
                .then((json) => {
                    if (!json || !json.data || typeof json.data !== 'object') {
                        return null;
                    }
                    return sanitizeAttributionData(withCanonicalClickIds(json.data));
                })
                .catch(() => null);
        }
    };

    const BrowserIdentifiers = {
        getCookieMap: function () {
            return String(document.cookie || '')
                .split(';')
                .map((part) => part.trim())
                .filter(Boolean)
                .reduce((acc, part) => {
                    const separator = part.indexOf('=');
                    if (separator === -1) return acc;
                    const name = sanitizeKey(part.slice(0, separator));
                    if (!name) return acc;
                    let value = part.slice(separator + 1);
                    try {
                        value = decodeURIComponent(value);
                    } catch (e) { }
                    acc[name] = value;
                    return acc;
                }, {});
        },

        parseGaClientId: function (rawValue) {
            const value = sanitizeValue(rawValue || '', 128);
            if (!value) return '';

            const parts = value.split('.');
            if (parts.length >= 4) {
                const left = parts[parts.length - 2];
                const right = parts[parts.length - 1];
                if (/^\d+$/.test(left) && /^\d+$/.test(right)) {
                    return left + '.' + right;
                }
            }

            return '';
        },

        parseGaSessionData: function (rawValue) {
            const value = sanitizeValue(rawValue || '', 256);
            if (!value) return {};

            const out = {};
            const gs2SessionId = value.match(/(?:^|\$)s(\d{6,})(?:\$|$)/);
            const gs2SessionNumber = value.match(/(?:^|\$)o(\d+)(?:\$|$)/);
            if (gs2SessionId) {
                out.ga_session_id = gs2SessionId[1];
            }
            if (gs2SessionNumber) {
                out.ga_session_number = gs2SessionNumber[1];
            }
            if (Object.keys(out).length) {
                return out;
            }

            if (value.indexOf('GS1.') === 0) {
                const parts = value.split('.');
                if (parts[2]) {
                    out.ga_session_id = sanitizeValue(parts[2], 32);
                }
                if (parts[3]) {
                    out.ga_session_number = sanitizeValue(parts[3], 16);
                }
                if (Object.keys(out).length) {
                    return out;
                }
            }

            const numericTokens = value.match(/\d+/g) || [];
            if (numericTokens[0]) {
                out.ga_session_id = numericTokens[0];
            }
            if (numericTokens[1]) {
                out.ga_session_number = numericTokens[1];
            }

            return out;
        },

        collect: function (params) {
            const cookies = this.getCookieMap();
            const out = {};

            const fbp = sanitizeValue(cookies._fbp || cookies.fbp || params.fbp || params._fbp || '', 128);
            if (fbp) {
                out.fbp = fbp;
            }

            let fbc = sanitizeValue(cookies._fbc || cookies.fbc || params.fbc || params._fbc || '', 128);
            if (!fbc) {
                const fbclid = sanitizeValue(params.fbclid || '', 128);
                if (fbclid) {
                    fbc = 'fb.1.' + Date.now() + '.' + fbclid;
                }
            }
            if (fbc) {
                out.fbc = fbc;
            }

            const ttp = sanitizeValue(cookies._ttp || cookies.ttp || params._ttp || params.ttp || '', 128);
            if (ttp) {
                out.ttp = ttp;
            }

            const linkedInCookie = sanitizeValue(cookies.li_gc || params.li_gc || '', 128);
            if (linkedInCookie) {
                out.li_gc = linkedInCookie;
            }

            const gaClientId = this.parseGaClientId(cookies._ga || params.ga_client_id || '');
            if (gaClientId) {
                out.ga_client_id = gaClientId;
            }

            Object.keys(cookies).some((name) => {
                if (name === '_ga' || name.indexOf('_ga_') !== 0) {
                    return false;
                }

                const gaSession = this.parseGaSessionData(cookies[name]);
                if (!gaSession.ga_session_id && !gaSession.ga_session_number) {
                    return false;
                }

                Object.assign(out, gaSession);
                return true;
            });

            if (!out.ga_session_id && params.ga_session_id) {
                out.ga_session_id = sanitizeValue(params.ga_session_id, 32);
            }
            if (!out.ga_session_number && params.ga_session_number) {
                out.ga_session_number = sanitizeValue(params.ga_session_number, 16);
            }

            return sanitizeAttributionData(out);
        }
    };

    // --- 2. PUBLIC API ---
    const API = {
        install: function (options = {}) {
            const withIdentity = options.withIdentity !== false;
            window.ClickTrail = {
                getData: () => Store.getData(),
                getField: (key) => {
                    const d = Store.getData();
                    return (d && d[key] != null) ? String(d[key]) : "";
                },
                getEncoded: () => {
                    const d = Store.getData();
                    return Store.base64UrlEncode(JSON.stringify(d || {}));
                },
                clearData: () => {
                    Store.clearData();
                    SessionManager.clear();
                    API.install({ withIdentity: false });
                },
                getSession: () => SessionManager.getPayload()
            };
            window.ClickTrailIdentity = withIdentity ? Identity.get() : null;
            window.ClickTrailSession = withIdentity ? SessionManager.getPayload() : null;

            // Site Health Timestamp
            try { localStorage.setItem('clicutcl_js_last_seen', String(Date.now())); } catch (e) { }

            // Fire ready event
            document.dispatchEvent(new CustomEvent("ct_ready", { detail: { data: Store.getData() } }));
        }
    };

    // --- 3. FORM INJECTOR ---
    const Injector = {
        findInputs: function (names) {
            const selectors = names.map(function (n) { var e = CSS.escape(n); return 'input[name="' + e + '"], textarea[name="' + e + '"], select[name="' + e + '"]'; });
            return Array.from(document.querySelectorAll(selectors.join(",")));
        },

        map: function () {
            const mappings = [];

            TOUCH_QUERY_KEYS.forEach((queryKey) => {
                const fieldKey = TOUCH_QUERY_FIELD_MAP[queryKey];
                mappings.push(['ft_' + fieldKey, ['ct_ft_' + fieldKey, queryKey]]);
                mappings.push(['lt_' + fieldKey, ['ct_lt_' + fieldKey]]);
            });

            CLICK_ID_KEYS.forEach((key) => {
                const topLevelNames = ['ct_' + key, key];
                const firstTouchNames = ['ct_ft_' + key];
                const lastTouchNames = ['ct_lt_' + key];

                if (key === 'sccid') {
                    topLevelNames.push('ct_sc_click_id', 'ScCid', 'sccid', 'sc_click_id');
                    firstTouchNames.push('ct_ft_sc_click_id');
                    lastTouchNames.push('ct_lt_sc_click_id');
                }

                mappings.push([key, topLevelNames]);
                mappings.push(['ft_' + key, firstTouchNames]);
                mappings.push(['lt_' + key, lastTouchNames]);
            });

            BROWSER_IDENTIFIER_KEYS.forEach((key) => {
                const names = ['ct_' + key, key];
                if (key === 'ttp') {
                    names.push('_ttp');
                }
                mappings.push([key, names]);
            });

            mappings.push(
                ['ft_referrer', ['ct_ft_referrer']],
                ['lt_referrer', ['ct_lt_referrer', 'ct_referrer']],
                ['ft_landing_page', ['ct_ft_landing_page', 'ct_first_landing_page', 'ct_landing']],
                ['lt_landing_page', ['ct_lt_landing_page', 'ct_last_landing_page']],
                ['ft_touch_timestamp', ['ct_ft_touch_timestamp', 'ct_first_touch_timestamp']],
                ['lt_touch_timestamp', ['ct_lt_touch_timestamp', 'ct_last_touch_timestamp']],
                ['session_count', ['ct_session_count']]
            );

            return mappings;
        },

        setIfEmpty: function (input, value) {
            if (!input) return;
            // Config: Overwrite?
            const overwrite = CONFIG.injectOverwrite === true || CONFIG.injectOverwrite === '1';

            if (!overwrite && input.value) return; // Skip if has value and no overwrite

            input.value = value;
            // Trigger events for frameworks (React, Vue, jQuery listeners)
            input.dispatchEvent(new Event("input", { bubbles: true }));
            input.dispatchEvent(new Event("change", { bubbles: true }));
        },

        run: function () {
            if (!CONFIG.injectEnabled) return;

            const data = Store.getData();
            if (!data || Object.keys(data).length === 0) return;

            this.map().forEach(([key, fieldNames]) => {
                const val = data[key];
                if (val == null || val === "") return;
                const inputs = this.findInputs(fieldNames);
                inputs.forEach(inp => this.setIfEmpty(inp, String(val)));
            });
        },

        clear: function () {
            this.map().forEach(([, fieldNames]) => {
                const inputs = this.findInputs(fieldNames);
                inputs.forEach((input) => {
                    if (!input || input.value === '') return;
                    input.value = '';
                    input.dispatchEvent(new Event("input", { bubbles: true }));
                    input.dispatchEvent(new Event("change", { bubbles: true }));
                });
            });
        },

        install: function () {
            if (!CONFIG.injectEnabled) return;

            const run = () => this.run();

            // 1. Initial
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', run);
            } else {
                run();
            }

            // 2. CF7 / Dynamic
            document.addEventListener("wpcf7init", run);

            // 3. MutationObserver (Elementor popups etc)
            if (CONFIG.injectMutationObserver) {
                const targetSelector = CONFIG.injectObserverTarget || 'body';
                const targetNode = document.querySelector(targetSelector);

                if (targetNode) {
                    let timeout;
                    const obs = new MutationObserver((mutations) => {
                        // De-bounce execution to avoid performance hits on rapid DOM changes
                        clearTimeout(timeout);
                        timeout = setTimeout(run, 200);
                    });
                    obs.observe(targetNode, { childList: true, subtree: true });
                }
            }

            // 4. Fallback
            setTimeout(run, 1500);
        }
    };

    // --- 3.5 BOT DETECTOR ---
    const BotDetector = {
        isBot: function () {
            const ua = navigator.userAgent || "";
            // Common bots list
            const bots = [
                "googlebot", "bingbot", "yandexbot", "duckduckbot", "baiduspider",
                "twitterbot", "facebookexternalhit", "rogerbot", "linkedinbot",
                "embedly", "quora link preview", "showyoubot", "outbrain",
                "pinterest/0.", "developers.google.com/+/web/snippet",
                "slackbot", "vkShare", "W3C_Validator", "redditbot", "applebot",
                "whatsapp", "flipboard", "tumblr", "bitlybot", "skypeuripreview",
                "nuzzel", "discordbot", "google page speed", "qwantify",
                "pinterestbot", "bitrix link preview", "xing-contenttabreceiver",
                "telegrambot", "semrushbot", "mj12bot", "ahrefsbot", "dotbot"
            ];

            // 1. User Agent Check
            const lowerUa = ua.toLowerCase();
            if (bots.some(b => lowerUa.indexOf(b) !== -1)) return true;

            // 2. Headless Browser Check (WebDriver)
            if (navigator.webdriver) return true;

            // 3. PhantomJS / Headless Chrome specific properties
            if (window.callPhantom || window._phantom) return true;

            return false;
        }
    };

    // --- 4. LINK DECORATOR ---
    const Decorator = {
        isSkippable: function (href) {
            if (!href) return true;
            const h = href.trim().toLowerCase();
            return h.startsWith("#") || h.startsWith("mailto:") || h.startsWith("tel:") || h.startsWith("javascript:");
        },

        matchesAllowedDomain: function (url) {
            // 1. Check configured allowed list
            const allowed = (CONFIG.linkAllowedDomains || []);
            const host = (url.hostname || "").toLowerCase();

            if (allowed.length) {
                const manualMatch = allowed.some(d => {
                    const cleanD = d.trim().toLowerCase();
                    return cleanD && (host === cleanD || host.endsWith("." + cleanD));
                });
                if (manualMatch) return true;
            }

            // 2. Auto-Allow Subdomains of Current Site
            // Logic: if target hostname ends with current hostname (e.g. shop.site.com ends with site.com)
            // or if they share the same root domain (approximate).
            // Safe check: if target host ends with current host (handling www stripping)

            const currentHost = window.location.hostname.toLowerCase().replace(/^www\./, '');
            const targetHost = host.replace(/^www\./, '');

            // If target is subdomain of current (e.g. app.site.com -> site.com)
            if (targetHost.endsWith("." + currentHost)) return true;

            // If current is subdomain of target (e.g. site.com -> site.co.uk - wait, no)
            // Better: just check strict subdomain relationship.
            // If on www.site.com (site.com), allow app.site.com.

            return false;
        },

        decorateUrl: function (rawHref) {
            if (this.isSkippable(rawHref)) return null;

            let url;
            try { url = new URL(rawHref, window.location.href); } catch (e) { return null; }

            // Only outbound
            if (url.origin === window.location.origin) return null;

            // Allowed domain check
            if (!this.matchesAllowedDomain(url)) return null;

            // Signed URL skip
            if (CONFIG.linkSkipSigned) {
                const qs = url.searchParams;
                const bad = ["x-amz-signature", "signature", "sig", "token"];
                if (bad.some(k => qs.has(k) || qs.has(k.toUpperCase()))) return null;
            }

            const data = Store.getData();
            if (!data) return null;

            const keys = [
                'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
                'utm_id', 'utm_source_platform', 'utm_creative_format', 'utm_marketing_tactic',
                'gclid', 'fbclid', 'msclkid', 'ttclid', 'wbraid', 'gbraid',
                'twclid', 'li_fat_id', 'ScCid', 'epik'
            ];

            let changed = false;
            keys.forEach(k => {
                const isSnapchatKey = k === 'ScCid';
                const canonicalKey = isSnapchatKey ? 'sccid' : k;
                let val = data[getTouchStorageKey(k, 'lt')];
                if (!val) val = data[k]; // Try direct (if stored)

                // For Click IDs, they are just ids
                if (['gclid', 'fbclid', 'msclkid', 'ttclid', 'wbraid', 'gbraid', 'twclid', 'li_fat_id', 'ScCid', 'epik'].includes(k)) {
                    val = data[canonicalKey] || data[k] || data['lt_' + canonicalKey] || data['ft_' + canonicalKey];
                }

                if (val && !url.searchParams.has(k)) {
                    url.searchParams.set(k, val);
                    changed = true;
                }
            });

            if (CONFIG.linkAppendToken) {
                const tokenParam = CONFIG.tokenParam || 'ct_token';
                if (!url.searchParams.has(tokenParam)) {
                    Store.prepareSignedToken(data);
                    const token = Store.getSignedToken();
                    if (token) {
                        url.searchParams.set(tokenParam, token);
                        changed = true;
                    }
                }
            }

            return changed ? url.toString() : null;
        },

        install: function () {
            if (!CONFIG.linkDecorateEnabled) return;

            const handler = (evt) => {
                const a = evt.target.closest("a");
                if (!a) return;

                const href = a.getAttribute("href");
                const decorated = this.decorateUrl(href);

                if (decorated) {
                    a.href = decorated; // Update Just-In-Time
                }
            };

            document.addEventListener("mousedown", handler, true);
            document.addEventListener("touchstart", handler, { capture: true, passive: true });
        }
    };

    // --- 4.5 IDENTITY ---
    const Identity = {
        eventId: function (prefix = 'evt') {
            if (window.crypto && typeof window.crypto.randomUUID === 'function') {
                return window.crypto.randomUUID();
            }
            return prefix + '_' + Math.random().toString(36).slice(2) + Date.now().toString(36);
        },

        sessionId: function () {
            try {
                const existing = sessionStorage.getItem('ct_session_id');
                if (existing) return existing;
                const created = this.eventId('sess');
                sessionStorage.setItem('ct_session_id', created);
                Store.setCookie('ct_session_id', created, 1);
                return created;
            } catch (e) {
                const cookie = Store.getCookie('ct_session_id');
                if (cookie) return cookie;
                const created = this.eventId('sess');
                Store.setCookie('ct_session_id', created, 1);
                return created;
            }
        },

        visitorId: function () {
            try {
                const existing = localStorage.getItem('ct_visitor_id');
                if (existing) return existing;
                const created = this.eventId('vis');
                localStorage.setItem('ct_visitor_id', created);
                Store.setCookie('ct_visitor_id', created, 365);
                return created;
            } catch (e) {
                const cookie = Store.getCookie('ct_visitor_id');
                if (cookie) return cookie;
                const created = this.eventId('vis');
                Store.setCookie('ct_visitor_id', created, 365);
                return created;
            }
        },

        get: function () {
            const base = {
                session_id: this.sessionId(),
                visitor_id: this.visitorId()
            };
            // Merge SessionManager state when available (provides richer session data)
            const session = SessionManager.get();
            if (session) {
                base.session_id = session.session_id;
                base.session_number = session.session_number;
            }
            return base;
        }
    };

    // --- 4.5 SESSION MANAGER ---
    // Decoupled from attribution: tracks sessions based on 30-minute inactivity
    // timeout (Stape/GA4 model). Attribution signals no longer drive session count.
    const SESSION_TIMEOUT_MS = 30 * 60 * 1000; // 30 minutes
    const SESSION_STORAGE_KEY = 'ct_session';
    const SESSION_COOKIE_NAME = 'ct_session';

    const SessionManager = {
        _state: null,

        /**
         * Read session state from cookie + localStorage.
         * Returns null if nothing stored.
         */
        _read: function () {
            // 1. Try cookie
            const cookieRaw = Store.getCookie(SESSION_COOKIE_NAME);
            const cookieObj = cookieRaw ? Store.safeJsonParse(cookieRaw) : null;

            // 2. Try localStorage
            let lsObj = null;
            try {
                const raw = localStorage.getItem(SESSION_STORAGE_KEY);
                if (raw) lsObj = Store.safeJsonParse(raw);
            } catch (e) { }

            // Cookie takes precedence (first-party, server-readable)
            const state = cookieObj || lsObj || null;
            if (state && typeof state === 'object' && state.session_id) {
                return state;
            }
            return null;
        },

        /**
         * Persist session state to cookie + localStorage.
         */
        _write: function (state) {
            if (!state || typeof state !== 'object') return;
            const json = JSON.stringify(state);
            // Session cookie: expires when browser closes OR after 24h (whichever first).
            // The inactivity timeout is enforced in touch(), not via cookie expiry.
            Store.setCookie(SESSION_COOKIE_NAME, json, 1);
            try {
                localStorage.setItem(SESSION_STORAGE_KEY, json);
            } catch (e) { }
            this._state = state;
        },

        /**
         * Clear session state (consent denial).
         */
        clear: function () {
            Store.removeCookie(SESSION_COOKIE_NAME);
            try { localStorage.removeItem(SESSION_STORAGE_KEY); } catch (e) { }
            try { sessionStorage.removeItem(SESSION_STORAGE_KEY); } catch (e) { }
            this._state = null;
        },

        /**
         * One-time migration: seed session_number from old attribution session_count.
         */
        _migrateSeed: function () {
            const attribution = Store.getData();
            if (attribution && typeof attribution.session_count === 'number' && attribution.session_count > 0) {
                return attribution.session_count;
            }
            return 0;
        },

        /**
         * Create a brand-new session, incrementing session_number.
         */
        _createSession: function (previousNumber) {
            const now = Date.now();
            const state = {
                session_id: Identity.eventId('sess'),
                session_number: (previousNumber || 0) + 1,
                session_started_at: now,
                last_activity_at: now
            };
            this._write(state);

            if (DEBUG_ENABLED) {
                console.log('[ClickTrail] New session created:', state.session_id, 'number:', state.session_number);
            }

            return state;
        },

        /**
         * Core method: call on every page view / tracked event.
         * - If no session exists → create one (migrate seed from old session_count).
         * - If last_activity_at is older than 30 min → new session.
         * - Otherwise → reuse current session, update last_activity_at.
         */
        touch: function () {
            const now = Date.now();
            let state = this._state || this._read();

            if (!state) {
                // First-ever session — try to seed from old attribution session_count
                const seed = this._migrateSeed();
                state = this._createSession(seed);
                return state;
            }

            const lastActivity = Number(state.last_activity_at || 0);
            const elapsed = now - lastActivity;

            if (elapsed > SESSION_TIMEOUT_MS) {
                // Inactivity timeout → new session
                state = this._createSession(state.session_number || 0);
                return state;
            }

            // Active session — update heartbeat
            state.last_activity_at = now;
            this._write(state);
            return state;
        },

        /**
         * Get current session state without side effects.
         */
        get: function () {
            if (this._state) return this._state;
            const stored = this._read();
            if (stored) {
                this._state = stored;
                return stored;
            }
            return null;
        },

        /**
         * Get session fields formatted for dataLayer / payloads.
         * Keeps backward compat: session_count = session_number.
         */
        getPayload: function () {
            const state = this.get();
            if (!state) return {};
            return {
                session_id: state.session_id,
                session_number: state.session_number,
                session_count: state.session_number, // backward compat alias
                session_started_at: state.session_started_at,
                last_activity_at: state.last_activity_at
            };
        }
    };

    // --- 5. MAIN ATTRIBUTION LOGIC ---
    // Preserving original class logic but using Store
    class ClickTrailAttribution {
        constructor() {
            // Anti-Bot Protection
            if (BotDetector.isBot()) {
                if (DEBUG_ENABLED) {
                    console.log('[ClickTrail] Bot detected, attribution paused.');
                }
                return;
            }
            this.hasRunAttribution = false;
            this.init();
        }

        init() {
            const requiresConsent = CONFIG.requireConsent === true || CONFIG.requireConsent === '1';
            this.bindConsentListener();
            const consent = this.getConsent();

            if (consent && consent.resolved) {
                if (consent.marketing) {
                    this.runAttribution();
                    return;
                }

                this.handleConsentDenied();
                return;
            }

            if (requiresConsent) {
                return;
            }

            this.runAttribution();
        }

        bindConsentListener() {
            document.addEventListener('ct:consentResolved', (event) => {
                const detail = event && event.detail ? event.detail : {};
                if (detail.granted) {
                    if (!this.hasRunAttribution) {
                        this.runAttribution();
                    }
                    return;
                }

                this.handleConsentDenied();
            });
        }

        getConsent() {
            const bridge = window.ClickTrailConsent;
            if (
                bridge &&
                typeof bridge.isResolved === 'function' &&
                typeof bridge.isGranted === 'function' &&
                bridge.isResolved()
            ) {
                const granted = !!bridge.isGranted();
                return {
                    resolved: true,
                    marketing: granted,
                    analytics: granted
                };
            }

            try {
                const c = Store.getCookie(getConsentCookieName());
                if (!c) return null;

                const parsed = JSON.parse(c);
                return {
                    resolved: true,
                    marketing: !!(parsed && parsed.marketing),
                    analytics: !!(parsed && parsed.analytics)
                };
            } catch (e) {
                const raw = Store.getCookie(getConsentCookieName());
                const lowered = String(raw || '').trim().toLowerCase();
                if (lowered === 'granted' || lowered === '1' || lowered === 'true' || lowered === 'yes') {
                    return { resolved: true, marketing: true, analytics: true };
                }
                if (lowered === 'denied' || lowered === '0' || lowered === 'false' || lowered === 'no') {
                    return { resolved: true, marketing: false, analytics: false };
                }
                return null;
            }
        }

        canCapture() {
            const requiresConsent = CONFIG.requireConsent === true || CONFIG.requireConsent === '1';
            const consent = this.getConsent();

            if (consent && consent.resolved) {
                return !!consent.marketing;
            }

            return !requiresConsent;
        }

        handleConsentDenied() {
            const existingData = Store.getData() || {};
            Store.clearData();
            SessionManager.clear();
            Injector.clear();
            API.install({ withIdentity: false });
            this.hasRunAttribution = false;

            if (DEBUG_ENABLED && Object.keys(existingData).length) {
                console.log('[ClickTrail] Attribution cleared after consent denial.');
            }
        }

        runAttribution() {
            if (!this.canCapture()) {
                this.handleConsentDenied();
                return;
            }
            this.hasRunAttribution = true;
            const currentParams = Store.getQueryParams();
            const referrer = document.referrer;

            // Cross-domain token support
            if (CONFIG.linkAppendToken) {
                const tokenParam = CONFIG.tokenParam || 'ct_token';
                const tokenValue = currentParams[tokenParam];
                if (tokenValue) {
                    Store.verifySignedToken(tokenValue).then((tokenData) => {
                        if (!this.canCapture()) {
                            return;
                        }
                        if (!tokenData) {
                            return;
                        }

                        let stored = Store.getData() || {};
                        const now = new Date().toISOString();

                        // Merge token data without overwriting existing values.
                        Object.keys(tokenData).forEach((k) => {
                            if (!stored[k]) stored[k] = tokenData[k];
                        });
                        stored.lt_touch_timestamp = now;
                        stored.lt_landing_page = window.location.href;
                        Store.saveData(stored);
                        API.install();
                        Store.prepareSignedToken(stored);
                    });
                }
            }

            const touch = this.resolveTouch(currentParams, referrer);
            const hasAttributionSignal = touch.hasSignal;

            let storedData = Store.getData() || {};
            let shouldPersist = false;

            if (this.mergeTopLevelIdentifiers(storedData, BrowserIdentifiers.collect(currentParams))) {
                shouldPersist = true;
            }

            if (hasAttributionSignal) {
                const fields = touch.fields;
                const now = new Date().toISOString();

                // First Touch
                if (!this.hasFirstTouch(storedData)) {
                    this.applyTouch('ft', storedData, fields, now);
                }

                // Last Touch (Always update on signal)
                this.applyTouch('lt', storedData, fields, now);

                shouldPersist = true;
            }

            if (shouldPersist) {
                Store.saveData(storedData);
                storedData = Store.getData() || storedData;
            }

            // Touch session (creates or resumes based on 30-min inactivity)
            const session = SessionManager.touch();

            // Expose API
            API.install(); // Re-announce with fresh data

            // Run Injector
            Injector.install();

            // Run Decorator
            Decorator.install();

            // Push to DataLayer
            window.dataLayer = window.dataLayer || [];
            const identity = Identity.get();
            window.dataLayer.push({
                event: 'ct_page_view',
                event_id: Identity.eventId('pv'),
                session_id: session ? session.session_id : identity.session_id,
                session_number: session ? session.session_number : undefined,
                visitor_id: identity.visitor_id,
                ct_attribution: storedData
            });

            this.initFormListeners(storedData);
            this.initWhatsAppListener(storedData);
            Store.prepareSignedToken(storedData);
        }

        hasTouchQuerySignal(params) {
            return TOUCH_QUERY_KEYS.some((key) => !!params[key]) ||
                CLICK_ID_SIGNAL_KEYS.some((key) => !!params[key]);
        }

        resolveTouch(params, referrer) {
            if (this.hasTouchQuerySignal(params)) {
                const fields = this.mapQueryFields(params);
                return {
                    hasSignal: Object.keys(fields).length > 0,
                    fields: fields
                };
            }

            const fields = this.mapReferrerFields(referrer);
            return {
                hasSignal: Object.keys(fields).length > 0,
                fields: fields
            };
        }

        hasFirstTouch(data) {
            if (!data || typeof data !== 'object') {
                return false;
            }

            return TOUCH_FIELD_KEYS.some(function (key) { return !!data['ft_' + key]; }) ||
                CLICK_ID_KEYS.some(function (key) { return !!data['ft_' + key]; });
        }

        applyTouch(prefix, data, fields, timestamp) {
            // Apply mapped fields to data with prefix
            for (const [key, val] of Object.entries(fields)) {
                if (val) data[prefix + '_' + key] = val;
            }
            data[prefix + '_touch_timestamp'] = timestamp;
            if (prefix === 'ft' && !data[prefix + '_landing_page']) {
                data[prefix + '_landing_page'] = window.location.href;
            } else if (prefix === 'lt') {
                data[prefix + '_landing_page'] = window.location.href;
            }
        }

        mergeTopLevelIdentifiers(data, identifiers) {
            if (!data || typeof data !== 'object' || !identifiers || typeof identifiers !== 'object') {
                return false;
            }

            let changed = false;
            Object.entries(identifiers).forEach(([key, value]) => {
                if (!value) return;
                if (data[key] === value) return;
                data[key] = value;
                changed = true;
            });

            return changed;
        }

        mapQueryFields(params) {
            const out = {};

            TOUCH_QUERY_KEYS.forEach((queryKey) => {
                const fieldKey = TOUCH_QUERY_FIELD_MAP[queryKey];
                const value = sanitizeValue(params[queryKey] || '', 128);
                if (value) {
                    out[fieldKey] = value;
                }
            });

            CLICK_ID_KEYS.forEach((key) => {
                const value = sanitizeValue(
                    key === 'sccid'
                        ? (params.sccid || params.sc_click_id || '')
                        : (params[key] || ''),
                    128
                );
                if (value) {
                    out[key] = value;
                }
            });

            return out;
        }

        mapReferrerFields(referrer) {
            const externalReferrer = getExternalReferrerDetails(referrer);
            if (!externalReferrer) {
                return {};
            }

            const classification = classifyReferrerHost(externalReferrer.host);
            if (!classification) {
                return {};
            }

            return {
                source: sanitizeValue(classification.source, 128),
                medium: sanitizeValue(classification.medium, 128),
                referrer: externalReferrer.referrer
            };
        }

        initFormListeners(data) {
            // Listener logic (Contact Form 7, etc) - bridging to DataLayer for submission events
            document.addEventListener('wpcf7mailsent', (e) => {
                this.pushDL('cf7', e.detail.contactFormId, data);
            });
            // ... (other listeners from original file can be preserved or simplified)
        }

        pushDL(provider, id, data) {
            window.dataLayer.push({
                event: 'lead_submit',
                form_provider: provider,
                form_id: id,
                attribution: data
            });
        }

        initWhatsAppListener(data) {
            if (!CONFIG.enableWhatsapp) return;

            const requiresConsent = CONFIG.requireConsent === true || CONFIG.requireConsent === '1';
            const consent = this.getConsent();
            if (consent && consent.resolved && !consent.marketing) {
                return;
            }
            if (requiresConsent) {
                if (!consent || !consent.marketing) {
                    return;
                }
            }

            const appendAttr = CONFIG.whatsappAppendAttribution === true || CONFIG.whatsappAppendAttribution === '1';
            if (!appendAttr) return;

            const allowedHosts = ['wa.me', 'whatsapp.com', 'api.whatsapp.com', 'web.whatsapp.com'];

            const handler = (evt) => {
                const a = evt.target.closest('a');
                if (!a) return;

                const href = a.getAttribute('href');
                if (!href) return;

                let url;
                try {
                    url = new URL(href, window.location.href);
                } catch (e) {
                    return;
                }

                if (!allowedHosts.includes(url.hostname)) return;

                if (appendAttr && window.ClickTrail && typeof window.ClickTrail.getEncoded === 'function') {
                    const encoded = window.ClickTrail.getEncoded();
                    if (encoded) {
                        const text = url.searchParams.get('text') || '';
                        const glue = text ? '\n\n' : '';
                        url.searchParams.set('text', text + glue + 'CT:' + encoded);
                        a.href = url.toString();
                    }
                }
            };

            document.addEventListener('click', handler, true);
            document.addEventListener('touchstart', handler, { capture: true, passive: true });
        }
    }

    // Boot
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => new ClickTrailAttribution());
    } else {
        new ClickTrailAttribution();
    }

})();
