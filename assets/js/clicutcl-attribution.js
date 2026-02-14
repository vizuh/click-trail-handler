(function () {
    'use strict';

    const CONFIG = window.clicutcl_config || {
        cookieName: 'attribution',
        cookieDays: 90,
        requireConsent: true
    };
    const DEBUG_ENABLED = !!CONFIG.debug;
    const CLICK_ID_KEYS = ['gclid', 'fbclid', 'msclkid', 'ttclid', 'wbraid', 'gbraid'];

    function pickClickId(raw, key) {
        if (!raw || typeof raw !== 'object') return '';
        return raw[key] || raw[`lt_${key}`] || raw[`ft_${key}`] || '';
    }

    function withCanonicalClickIds(raw) {
        const out = Object.assign({}, raw || {});
        CLICK_ID_KEYS.forEach((key) => {
            const value = pickClickId(raw, key);
            if (value && !out[key]) out[key] = String(value);
        });
        return out;
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
                params[decodeURIComponent(m[1])] = decodeURIComponent(m[2]);
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

        getData: function () {
            // 1. Try Cookie
            const cookieRaw = this.getCookie(CONFIG.cookieName);
            const cookieObj = cookieRaw ? this.safeJsonParse(cookieRaw) : null;

            // 2. Try LocalStorage
            let lsObj = null;
            try {
                const lsRaw = localStorage.getItem(CONFIG.cookieName);
                lsObj = lsRaw ? this.safeJsonParse(lsRaw) : null;
            } catch (e) { }

            // 3. Merge (Cookie takes precedence over LS, but current logic usually keeps them in sync)
            return Object.assign({}, lsObj || {}, cookieObj || {});
        },

        saveData: function (data) {
            const dataStr = JSON.stringify(data);
            this.setCookie(CONFIG.cookieName, dataStr, CONFIG.cookieDays);
            try {
                localStorage.setItem(CONFIG.cookieName, dataStr);
            } catch (e) { }
        },

        buildToken: function (data) {
            const normalized = withCanonicalClickIds(data || {});
            const allow = [
                'ft_source', 'ft_medium', 'ft_campaign',
                'lt_source', 'lt_medium', 'lt_campaign',
                'gclid', 'fbclid', 'msclkid', 'ttclid', 'wbraid', 'gbraid'
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

        encodeToken: function (data) {
            const token = this.buildToken(data);
            if (!token) return '';
            return this.base64UrlEncode(JSON.stringify(token));
        },

        decodeToken: function (token) {
            const raw = this.base64UrlDecode(token);
            if (!raw) return null;
            const obj = this.safeJsonParse(raw);
            if (!obj || !obj.data || !obj.ts) return null;
            const maxDays = parseInt(CONFIG.tokenMaxAgeDays || CONFIG.cookieDays || 90, 10);
            const maxAge = maxDays * 24 * 60 * 60;
            const age = Math.floor(Date.now() / 1000) - parseInt(obj.ts, 10);
            if (age < 0 || age > maxAge) return null;
            return obj.data;
        }
    };

    // --- 2. PUBLIC API ---
    const API = {
        install: function () {
            window.ClickTrail = {
                getData: () => Store.getData(),
                getField: (key) => {
                    const d = Store.getData();
                    return (d && d[key] != null) ? String(d[key]) : "";
                },
                getEncoded: () => {
                    const d = Store.getData();
                    return Store.base64UrlEncode(JSON.stringify(d || {}));
                }
            };

            // Site Health Timestamp
            try { localStorage.setItem('clicutcl_js_last_seen', String(Date.now())); } catch (e) { }

            // Fire ready event
            document.dispatchEvent(new CustomEvent("ct_ready", { detail: { data: Store.getData() } }));
        }
    };

    // --- 3. FORM INJECTOR ---
    const Injector = {
        findInputs: function (names) {
            const selectors = names.map(n => `input[name="${CSS.escape(n)}"], textarea[name="${CSS.escape(n)}"], select[name="${CSS.escape(n)}"]`);
            return Array.from(document.querySelectorAll(selectors.join(",")));
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

            // Mapping: Attribution Key -> Field Names
            const map = [
                // First Touch
                ["ft_source", ["ct_ft_source", "utm_source"]],
                ["ft_medium", ["ct_ft_medium", "utm_medium"]],
                ["ft_campaign", ["ct_ft_campaign", "utm_campaign"]],
                ["ft_term", ["ct_ft_term", "utm_term"]],
                ["ft_content", ["ct_ft_content", "utm_content"]],
                // Last Touch
                ["lt_source", ["ct_lt_source"]],
                ["lt_medium", ["ct_lt_medium"]],
                ["lt_campaign", ["ct_lt_campaign"]],
                ["lt_term", ["ct_lt_term"]],
                ["lt_content", ["ct_lt_content"]],
                // IDs
                ["gclid", ["ct_gclid", "gclid"]],
                ["fbclid", ["ct_fbclid", "fbclid"]],
                ["msclkid", ["ct_msclkid", "msclkid"]],
                ["ttclid", ["ct_ttclid", "ttclid"]],
                // Meta
                ["referrer", ["ct_referrer"]],
                ["first_landing_page", ["ct_landing"]]
            ];

            map.forEach(([key, fieldNames]) => {
                const val = data[key];
                if (val == null || val === "") return;
                const inputs = this.findInputs(fieldNames);
                inputs.forEach(inp => this.setIfEmpty(inp, String(val)));
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
                "utm_source", "utm_medium", "utm_campaign", "utm_term", "utm_content",
                "gclid", "fbclid", "msclkid", "ttclid", "wbraid", "gbraid"
            ];

            let changed = false;
            keys.forEach(k => {
                let val = data['lt_' + k.replace('utm_', '')]; // Try lt_source for utm_source
                if (!val) val = data[k]; // Try direct (if stored)

                // For Click IDs, they are just ids
                if (['gclid', 'fbclid', 'msclkid', 'ttclid', 'wbraid', 'gbraid'].includes(k)) {
                    val = data[k] || data['lt_' + k] || data['ft_' + k];
                }

                if (val && !url.searchParams.has(k)) {
                    url.searchParams.set(k, val);
                    changed = true;
                }
            });

            if (CONFIG.linkAppendToken) {
                const tokenParam = CONFIG.tokenParam || 'ct_token';
                if (!url.searchParams.has(tokenParam)) {
                    const token = Store.encodeToken(data);
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
            this.init();
        }

        init() {
            const requiresConsent = CONFIG.requireConsent === true || CONFIG.requireConsent === '1';

            if (requiresConsent) {
                const consent = this.getConsent();
                if (consent && consent.marketing) {
                    this.runAttribution();
                    return;
                }

                const maybeRun = (event) => {
                    const preferences = event.detail || {};
                    if (preferences.marketing) {
                        this.runAttribution();
                        window.removeEventListener('ct_consent_updated', maybeRun);
                        window.removeEventListener('consent_granted', maybeRun);
                    }
                };

                window.addEventListener('ct_consent_updated', maybeRun);
                window.addEventListener('consent_granted', maybeRun);
                return;
            }

            this.runAttribution();
        }

        getConsent() {
            try {
                const c = Store.getCookie('ct_consent');
                return c ? JSON.parse(c) : null;
            } catch (e) { return null; }
        }

        runAttribution() {
            const currentParams = Store.getQueryParams();
            const referrer = document.referrer;

            // Cross-domain token support
            if (CONFIG.linkAppendToken) {
                const tokenParam = CONFIG.tokenParam || 'ct_token';
                const tokenValue = currentParams[tokenParam];
                if (tokenValue) {
                    const tokenData = Store.decodeToken(tokenValue);
                    if (tokenData) {
                        let stored = Store.getData() || {};
                        const now = new Date().toISOString();
                        // Merge token data without overwriting existing values
                        Object.keys(tokenData).forEach((k) => {
                            if (!stored[k]) stored[k] = tokenData[k];
                        });
                        stored.lt_touch_timestamp = now;
                        stored.lt_landing_page = window.location.href;
                        Store.saveData(stored);
                    }
                }
            }

            // Logic to determine if we have new attribution
            const hasAttributionSignal = this.checkSignal(currentParams, referrer);

            let storedData = Store.getData() || {};

            if (hasAttributionSignal) {
                const fields = this.mapFields(currentParams, referrer);
                const now = new Date().toISOString();

                // First Touch
                if (!this.hasFirstTouch(storedData)) {
                    this.applyTouch('ft', storedData, fields, now);
                }

                // Last Touch (Always update on signal)
                this.applyTouch('lt', storedData, fields, now);

                storedData.session_count = (storedData.session_count || 0) + 1;

                Store.saveData(storedData);
            }

            // Expose API
            API.install(); // Re-announce with fresh data

            // Run Injector
            Injector.install();

            // Run Decorator
            Decorator.install();

            // Push to DataLayer
            window.dataLayer = window.dataLayer || [];
            window.dataLayer.push({
                event: 'ct_page_view',
                ct_attribution: storedData
            });

            this.initFormListeners(storedData);
            this.initWhatsAppListener(storedData);
        }

        checkSignal(params, referrer) {
            const keys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid', 'msclkid', 'ttclid', 'wbraid', 'gbraid'];
            if (keys.some(k => params[k])) return true;

            // Check referrer (if external)
            if (referrer && referrer.indexOf(window.location.hostname) === -1) return true;

            return false;
        }

        hasFirstTouch(data) {
            return !!(data && (
                data.ft_source ||
                data.ft_medium ||
                data.ft_campaign ||
                data.ft_gclid ||
                data.ft_fbclid ||
                data.ft_msclkid ||
                data.ft_ttclid ||
                data.ft_wbraid ||
                data.ft_gbraid
            ));
        }

        applyTouch(prefix, data, fields, timestamp) {
            // Apply mapped fields to data with prefix
            for (const [key, val] of Object.entries(fields)) {
                if (val) data[`${prefix}_${key}`] = val;
            }
            data[`${prefix}_touch_timestamp`] = timestamp;
            if (prefix === 'ft' && !data[`${prefix}_landing_page`]) {
                data[`${prefix}_landing_page`] = window.location.href;
            } else if (prefix === 'lt') {
                data[`${prefix}_landing_page`] = window.location.href;
            }
        }

        mapFields(params, referrer) {
            // Standardize params to internal keys
            const out = {
                source: params.utm_source || '',
                medium: params.utm_medium || '',
                campaign: params.utm_campaign || '',
                term: params.utm_term || '',
                content: params.utm_content || '',
                gclid: params.gclid,
                fbclid: params.fbclid,
                msclkid: params.msclkid,
                ttclid: params.ttclid,
                wbraid: params.wbraid,
                gbraid: params.gbraid
            };

            // Referrer logic
            if (referrer && referrer.indexOf(window.location.hostname) === -1) {
                if (!out.source) {
                    // Simple referrer parsing could go here (e.g. google.com -> source=google, medium=organic)
                    // For now just store raw referrer
                    out.referrer = referrer;
                }
            }
            return out;
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
            if (requiresConsent) {
                const consent = this.getConsent();
                if (!consent || !consent.marketing) {
                    return;
                }
            }

            const appendAttr = CONFIG.whatsappAppendAttribution === true || CONFIG.whatsappAppendAttribution === '1';
            const allowedHosts = ['wa.me', 'whatsapp.com', 'api.whatsapp.com', 'web.whatsapp.com'];

            const getSafeAttribution = () => {
                const raw = withCanonicalClickIds(Store.getData() || {});
                const allowed = [
                    'ft_source', 'ft_medium', 'ft_campaign',
                    'lt_source', 'lt_medium', 'lt_campaign',
                    'gclid', 'fbclid', 'msclkid', 'ttclid', 'wbraid', 'gbraid'
                ];
                const out = {};
                allowed.forEach((key) => {
                    if (raw[key]) {
                        const val = String(raw[key]);
                        out[key] = val.length > 128 ? val.slice(0, 128) : val;
                    }
                });
                return out;
            };

            const normalizeTarget = (url) => {
                const host = url.hostname;
                const path = (url.pathname || '/').replace(/\d+/g, 'redacted');
                return {
                    type: host,
                    path: path.startsWith('/') ? path : '/' + path
                };
            };

            const shouldEmit = (key) => {
                try {
                    const last = sessionStorage.getItem('ct_wa_last');
                    if (last) {
                        const parts = last.split('|');
                        const lastTs = parseInt(parts[0], 10) || 0;
                        const lastKey = parts[1] || '';
                        if (lastKey === key && (Date.now() - lastTs) < 1500) {
                            return false;
                        }
                    }
                    sessionStorage.setItem('ct_wa_last', String(Date.now()) + '|' + key);
                } catch (e) { }
                return true;
            };

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

                const target = normalizeTarget(url);
                const dedupeKey = target.type + '|' + target.path;

                if (!shouldEmit(dedupeKey)) return;

                window.dataLayer = window.dataLayer || [];
                window.dataLayer.push({
                    event: 'wa_click',
                    page_path: window.location.pathname || '/',
                    wa_target_type: target.type,
                    wa_target_path: target.path,
                    attribution: getSafeAttribution()
                });
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
