(function () {
    if (!window.clicutclSiteHealth) return;
    const debug = !!window.clicutclSiteHealth.debug;

    fetch(window.clicutclSiteHealth.ajaxUrl, {
        method: "POST",
        credentials: "same-origin",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded; charset=UTF-8"
        },
        body: new URLSearchParams({
            action: "clicutcl_sitehealth_ping",
            nonce: window.clicutclSiteHealth.nonce
        }).toString()
    }).catch(() => {
        if (debug) {
            console.warn('[ClickTrail] SiteHealth ping failed');
        }
    });
})();
