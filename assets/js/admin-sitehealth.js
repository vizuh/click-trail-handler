(function () {
    if (!window.clicutclSiteHealth) return;

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
        console.warn('ClickTrail SiteHealth Ping Failed');
    });
})();
