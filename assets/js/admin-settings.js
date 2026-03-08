(function () {
    'use strict';

    function setRowDimmed(row, dimmed) {
        if (!row) {
            return;
        }

        row.classList.toggle('clicktrail-row-dimmed', !!dimmed);
        row.setAttribute('aria-disabled', dimmed ? 'true' : 'false');

        row.querySelectorAll('input, select, textarea, button').forEach(function (control) {
            if (!control.hasAttribute('data-clicktrail-initial-disabled')) {
                control.setAttribute('data-clicktrail-initial-disabled', control.disabled ? '1' : '0');
            }

            var initiallyDisabled = control.getAttribute('data-clicktrail-initial-disabled') === '1';
            control.disabled = initiallyDisabled || !!dimmed;
        });
    }

    function bindDependency(controllerSelector, targetSelectors) {
        var controller = document.querySelector(controllerSelector);
        if (!controller) {
            return;
        }

        var targets = targetSelectors
            .map(function (selector) { return document.querySelector(selector); })
            .filter(Boolean);

        if (!targets.length) {
            return;
        }

        function sync() {
            var enabled = !!controller.checked;
            targets.forEach(function (row) {
                setRowDimmed(row, !enabled);
            });
        }

        controller.addEventListener('change', sync);
        sync();
    }

    function initCards() {
        document.querySelectorAll('[data-clicktrail-card-toggle]').forEach(function (button) {
            button.addEventListener('click', function () {
                var card = button.closest('.clicktrail-card');
                if (!card) {
                    return;
                }

                var expanded = button.getAttribute('aria-expanded') === 'true';
                button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                card.classList.toggle('is-collapsed', expanded);
            });
        });
    }

    function initDependencies() {
        bindDependency(
            'input[name="clicutcl_attribution_settings[enable_js_injection]"][type="checkbox"]',
            [
                'tr.clicutcl-field-inject-overwrite',
                'tr.clicutcl-field-inject-mutation-observer'
            ]
        );

        bindDependency(
            'input[name="clicutcl_attribution_settings[enable_link_decoration]"][type="checkbox"]',
            [
                'tr.clicutcl-field-link-allowed-domains',
                'tr.clicutcl-field-link-skip-signed',
                'tr.clicutcl-field-enable-cross-domain-token'
            ]
        );

        bindDependency(
            'input[name="clicutcl_attribution_settings[enable_whatsapp]"][type="checkbox"]',
            [
                'tr.clicutcl-field-whatsapp-append-attribution'
            ]
        );

        bindDependency(
            'input[name="clicutcl_consent_mode[enabled]"][type="checkbox"]',
            [
                'tr.clicutcl-field-consent-mode',
                'tr.clicutcl-field-consent-regions',
                'tr.clicutcl-field-consent-cmp-source',
                'tr.clicutcl-field-consent-timeout'
            ]
        );
    }

    function init() {
        if (!document.querySelector('.clicktrail-settings-wrap')) {
            return;
        }

        initCards();
        initDependencies();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
