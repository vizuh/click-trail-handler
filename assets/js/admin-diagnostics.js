(function () {
    'use strict';

    function getString(key, fallback) {
        if (window.clicutclDiagnostics && window.clicutclDiagnostics.strings && window.clicutclDiagnostics.strings[key]) {
            return window.clicutclDiagnostics.strings[key];
        }

        return fallback;
    }

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
            status.textContent = getString('testing', 'Testing...');

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
                    status.textContent = data && data.data && data.data.message ? data.data.message : getString('test_failed', 'Test failed');
                })
                .catch(function () {
                    status.textContent = getString('test_failed', 'Test failed');
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
                    copyStatus.textContent = getString('clipboard_unavailable', 'Clipboard unavailable');
                    return;
                }
                navigator.clipboard.writeText(text).then(function () {
                    copyStatus.textContent = getString('copied', 'Copied');
                }).catch(function () {
                    copyStatus.textContent = getString('copy_failed', 'Copy failed');
                });
            });
        }

        const debugBtn = document.getElementById('clicutcl-debug-toggle');
        const debugStatus = document.getElementById('clicutcl-debug-status');
        if (debugBtn && debugStatus) {
            debugBtn.addEventListener('click', function (e) {
                e.preventDefault();
                debugStatus.textContent = getString('saving', 'Saving...');
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
                                debugBtn.textContent = getString('disable_debug', 'Disable Debug');
                            } else {
                                debugBtn.setAttribute('data-mode', 'on');
                                debugBtn.textContent = getString('enable_debug_window', 'Enable 15 Minutes');
                            }
                            return;
                        }
                        debugStatus.textContent = data && data.data && data.data.message ? data.data.message : getString('failed', 'Failed');
                    })
                    .catch(function () {
                        debugStatus.textContent = getString('failed', 'Failed');
                    });
            });
        }

        const purgeBtn = document.getElementById('clicutcl-purge-data');
        const purgeStatus = document.getElementById('clicutcl-purge-data-status');
        if (purgeBtn && purgeStatus) {
            purgeBtn.addEventListener('click', function (e) {
                e.preventDefault();

                const ok = window.confirm(getString('confirm_purge', 'Purge local tracking data now? This cannot be undone.'));
                if (!ok) return;

                purgeStatus.textContent = getString('purging', 'Purging...');
                post(window.clicutclDiagnostics.ajaxUrl, {
                    action: 'clicutcl_purge_tracking_data',
                    nonce: window.clicutclDiagnostics.nonce
                })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (data && data.success) {
                            purgeStatus.textContent = data.data && data.data.message ? data.data.message : getString('purged', 'Purged');
                            return;
                        }
                        purgeStatus.textContent = data && data.data && data.data.message ? data.data.message : getString('purge_failed', 'Purge failed');
                    })
                    .catch(function () {
                        purgeStatus.textContent = getString('purge_failed', 'Purge failed');
                    });
            });
        }

        const scanBtn = document.getElementById('clicutcl-run-conflict-scan');
        const scanStatus = document.getElementById('clicutcl-conflict-scan-status');
        const scanResults = document.getElementById('clicutcl-conflict-scan-results');
        if (scanBtn && scanStatus && scanResults) {
            scanBtn.addEventListener('click', function (e) {
                e.preventDefault();
                scanStatus.textContent = getString('running_scan', 'Scanning...');
                post(window.clicutclDiagnostics.ajaxUrl, {
                    action: 'clicutcl_conflict_scan',
                    nonce: window.clicutclDiagnostics.nonce
                })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (data && data.success) {
                            scanStatus.textContent = data.data && data.data.message ? data.data.message : '';
                            scanResults.innerHTML = data.data && data.data.html ? data.data.html : '';
                            return;
                        }
                        scanStatus.textContent = data && data.data && data.data.message ? data.data.message : getString('scan_failed', 'Conflict scan failed.');
                    })
                    .catch(function () {
                        scanStatus.textContent = getString('scan_failed', 'Conflict scan failed.');
                    });
            });
        }

        const exportBtn = document.getElementById('clicutcl-settings-export');
        const exportStatus = document.getElementById('clicutcl-settings-export-status');
        if (exportBtn && exportStatus) {
            exportBtn.addEventListener('click', function (e) {
                e.preventDefault();
                exportStatus.textContent = getString('exporting', 'Preparing backup...');
                post(window.clicutclDiagnostics.ajaxUrl, {
                    action: 'clicutcl_export_settings_backup',
                    nonce: window.clicutclDiagnostics.nonce
                })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (!data || !data.success) {
                            throw new Error(data && data.data && data.data.message ? data.data.message : getString('export_failed', 'Backup export failed.'));
                        }

                        const blob = new Blob([JSON.stringify(data.data.snapshot || {}, null, 2)], { type: 'application/json' });
                        const url = window.URL.createObjectURL(blob);
                        const link = document.createElement('a');
                        link.href = url;
                        link.download = data.data && data.data.filename ? data.data.filename : 'clicktrail-backup.json';
                        document.body.appendChild(link);
                        link.click();
                        document.body.removeChild(link);
                        window.URL.revokeObjectURL(url);
                        exportStatus.textContent = data.data && data.data.message ? data.data.message : '';
                    })
                    .catch(function (error) {
                        exportStatus.textContent = error && error.message ? error.message : getString('export_failed', 'Backup export failed.');
                    });
            });
        }

        const importInput = document.getElementById('clicutcl-settings-import-file');
        const importBtn = document.getElementById('clicutcl-settings-import');
        const importStatus = document.getElementById('clicutcl-settings-import-status');
        if (importInput && importBtn && importStatus) {
            importBtn.addEventListener('click', function (e) {
                e.preventDefault();
                const file = importInput.files && importInput.files[0];
                if (!file) {
                    importStatus.textContent = getString('choose_backup', 'Choose a ClickTrail backup file first.');
                    return;
                }

                const ok = window.confirm(getString('confirm_import', 'Restore this ClickTrail backup now? Current settings will be replaced.'));
                if (!ok) {
                    return;
                }

                const reader = new FileReader();
                reader.onload = function () {
                    importStatus.textContent = getString('importing', 'Importing backup...');
                    post(window.clicutclDiagnostics.ajaxUrl, {
                        action: 'clicutcl_import_settings_backup',
                        nonce: window.clicutclDiagnostics.nonce,
                        snapshot: String(reader.result || '')
                    })
                        .then(function (res) { return res.json(); })
                        .then(function (data) {
                            if (data && data.success) {
                                importStatus.textContent = data.data && data.data.message ? data.data.message : '';
                                return;
                            }
                            importStatus.textContent = data && data.data && data.data.message ? data.data.message : getString('import_failed', 'Backup import failed.');
                        })
                        .catch(function () {
                            importStatus.textContent = getString('import_failed', 'Backup import failed.');
                        });
                };
                reader.readAsText(file);
            });
        }

        const lookupInput = document.getElementById('clicutcl-woo-order-id');
        const lookupBtn = document.getElementById('clicutcl-woo-order-lookup');
        const lookupStatus = document.getElementById('clicutcl-woo-order-lookup-status');
        const lookupResults = document.getElementById('clicutcl-woo-order-lookup-results');
        if (lookupInput && lookupBtn && lookupStatus && lookupResults) {
            lookupBtn.addEventListener('click', function (e) {
                e.preventDefault();
                lookupStatus.textContent = getString('looking_up', 'Looking up order...');
                post(window.clicutclDiagnostics.ajaxUrl, {
                    action: 'clicutcl_lookup_woo_order_trace',
                    nonce: window.clicutclDiagnostics.nonce,
                    order_id: lookupInput.value || ''
                })
                    .then(function (res) { return res.json(); })
                    .then(function (data) {
                        if (data && data.success) {
                            lookupStatus.textContent = data.data && data.data.message ? data.data.message : '';
                            lookupResults.innerHTML = data.data && data.data.html ? data.data.html : '';
                            return;
                        }
                        lookupStatus.textContent = data && data.data && data.data.message ? data.data.message : getString('lookup_failed', 'Order lookup failed.');
                    })
                    .catch(function () {
                        lookupStatus.textContent = getString('lookup_failed', 'Order lookup failed.');
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
