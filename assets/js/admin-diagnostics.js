(function () {
    'use strict';

    function post(url, data) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: new URLSearchParams(data).toString()
        });
    }

    function init() {
        const btn = document.getElementById('clicutcl-test-endpoint');
        const status = document.getElementById('clicutcl-test-endpoint-status');
        if (!btn || !status || !window.clicutclDiagnostics) return;

        btn.addEventListener('click', function (e) {
            e.preventDefault();
            status.textContent = 'Testing...';

            post(window.clicutclDiagnostics.ajaxUrl, {
                action: 'clicutcl_test_endpoint',
                nonce: window.clicutclDiagnostics.nonce
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (data && data.success) {
                        status.textContent = data.data && data.data.message ? data.data.message : 'OK';
                        return;
                    }
                    status.textContent = data && data.data && data.data.message ? data.data.message : 'Test failed';
                })
                .catch(function () {
                    status.textContent = 'Test failed';
                });
        });

        const copyBtn = document.getElementById('clicutcl-copy-payload');
        const copyStatus = document.getElementById('clicutcl-copy-payload-status');
        const payloadEl = document.getElementById('clicutcl-payload-sample');
        if (copyBtn && copyStatus && payloadEl) {
            copyBtn.addEventListener('click', function (e) {
                e.preventDefault();
                const text = payloadEl.textContent || '';
                if (!text) return;
                if (!navigator.clipboard) {
                    copyStatus.textContent = 'Clipboard unavailable';
                    return;
                }
                navigator.clipboard.writeText(text).then(function () {
                    copyStatus.textContent = 'Copied';
                }).catch(function () {
                    copyStatus.textContent = 'Copy failed';
                });
            });
        }

        const debugBtn = document.getElementById('clicutcl-debug-toggle');
        const debugStatus = document.getElementById('clicutcl-debug-status');
        if (debugBtn && debugStatus) {
            debugBtn.addEventListener('click', function (e) {
                e.preventDefault();
                debugStatus.textContent = 'Saving...';
                post(window.clicutclDiagnostics.ajaxUrl, {
                    action: 'clicutcl_toggle_debug',
                    nonce: window.clicutclDiagnostics.nonce,
                    mode: debugBtn.getAttribute('data-mode') || 'on'
                })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (data && data.success) {
                            debugStatus.textContent = data.data && data.data.message ? data.data.message : 'OK';
                            const mode = debugBtn.getAttribute('data-mode') || 'on';
                            if (mode === 'on') {
                                debugBtn.setAttribute('data-mode', 'off');
                                debugBtn.textContent = 'Disable Debug';
                            } else {
                                debugBtn.setAttribute('data-mode', 'on');
                                debugBtn.textContent = 'Enable 15 Minutes';
                            }
                            return;
                        }
                        debugStatus.textContent = data && data.data && data.data.message ? data.data.message : 'Failed';
                    })
                    .catch(function () {
                        debugStatus.textContent = 'Failed';
                    });
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
