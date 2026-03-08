(function () {
    'use strict';

    function setToggleLabel(checkbox) {
        if (!checkbox) {
            return;
        }

        var wrapper = checkbox.closest('.clicktrail-toggle-wrapper');
        var label = wrapper ? wrapper.querySelector('[data-clicktrail-toggle-label]') : null;
        if (!label) {
            return;
        }

        var enabledLabel = label.getAttribute('data-enabled-label') || 'Enabled';
        var disabledLabel = label.getAttribute('data-disabled-label') || 'Disabled';
        label.textContent = checkbox.checked ? enabledLabel : disabledLabel;
    }

    function setRowDimmed(row, dimmed) {
        if (!row) {
            return;
        }

        row.classList.toggle('clicktrail-row-dimmed', !!dimmed);
        row.setAttribute('aria-disabled', dimmed ? 'true' : 'false');
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

    function initToggleLabels() {
        document.querySelectorAll('.clicktrail-toggle input[type="checkbox"]').forEach(function (checkbox) {
            setToggleLabel(checkbox);
            checkbox.addEventListener('change', function () {
                setToggleLabel(checkbox);
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
        initToggleLabels();
        initDependencies();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
