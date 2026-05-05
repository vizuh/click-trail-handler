/**
 * ClickTrail Setup Wizard
 *
 * Handles step navigation, toggle state, and summary updates.
 * No framework dependency — vanilla JS only.
 */
( function () {
	'use strict';

	/** Which step was shown before step 3 (2 if ads checked, 1 if skipped). */
	var prevStep = 1;

	/**
	 * Show the requested panel, hide all others, update the step indicator.
	 *
	 * @param {number} n Step number (1-3).
	 */
	function goToStep( n ) {
		document.querySelectorAll( '.clicutcl-wizard__panel' ).forEach( function ( panel ) {
			var panelNum = parseInt( panel.dataset.panel, 10 );
			if ( panelNum === n ) {
				panel.removeAttribute( 'hidden' );
				panel.scrollIntoView( { behavior: 'smooth', block: 'start' } );
			} else {
				panel.setAttribute( 'hidden', '' );
			}
		} );

		document.querySelectorAll( '.clicutcl-wizard__step' ).forEach( function ( step ) {
			var stepNum = parseInt( step.dataset.step, 10 );
			step.classList.remove( 'is-active', 'is-complete' );
			if ( stepNum === n ) {
				step.classList.add( 'is-active' );
			} else if ( stepNum < n ) {
				step.classList.add( 'is-complete' );
			}
		} );
	}

	/**
	 * Read the GA4 input value and update the Step 3 summary line.
	 */
	function updateSummaryGa4() {
		var input  = document.getElementById( 'clicutcl-ga4-id' );
		var line   = document.getElementById( 'clicutcl-wizard-ga4-line' );
		var text   = document.getElementById( 'clicutcl-wizard-ga4-text' );
		if ( ! input || ! line || ! text ) {
			return;
		}
		var val = ( input.value || '' ).trim().toUpperCase();
		if ( val && /^G-[A-Z0-9]+$/.test( val ) ) {
			text.textContent = 'GA4: ' + val;
			line.removeAttribute( 'hidden' );
		} else {
			line.setAttribute( 'hidden', '' );
		}
	}

	/**
	 * Validate the GA4 input.  Shows an error style if the value is non-empty
	 * but not a valid G-XXXXXXXXXX ID.  Empty is always valid (optional field).
	 *
	 * @return {boolean} Whether the value is valid (or empty).
	 */
	function validateGa4() {
		var input = document.getElementById( 'clicutcl-ga4-id' );
		if ( ! input ) {
			return true;
		}
		var val = input.value.trim();
		if ( '' === val || /^G-[A-Z0-9]+$/i.test( val ) ) {
			input.classList.remove( 'is-invalid' );
			return true;
		}
		input.classList.add( 'is-invalid' );
		input.focus();
		return false;
	}

	/**
	 * Bootstrap all wizard interactions once the DOM is ready.
	 */
	function init() {
		var form     = document.getElementById( 'clicutcl-wizard-form' );
		var adsCheck = document.getElementById( 'clicutcl-run-paid-ads' );

		if ( ! form ) {
			return;
		}

		// --- Navigation buttons ------------------------------------------

		form.addEventListener( 'click', function ( e ) {
			var btn = e.target.closest( '[data-action]' );
			if ( ! btn ) {
				return;
			}

			var action = btn.dataset.action;

			if ( 'next' === action ) {
				var nextStep = parseInt( btn.dataset.next, 10 );

				// If the "next" button on step 1, decide whether to show step 2.
				if ( btn.dataset.nextNoAds ) {
					if ( ! validateGa4() ) {
						return;
					}
					var noAdsStep = parseInt( btn.dataset.nextNoAds, 10 );
					if ( adsCheck && adsCheck.checked ) {
						prevStep = 1;
						updateSummaryGa4();
						goToStep( nextStep ); // show step 2
					} else {
						prevStep = 1;
						updateSummaryGa4();
						goToStep( noAdsStep ); // skip to step 3
					}
					return;
				}

				// Normal next (step 2 → step 3).
				prevStep = 2;
				updateSummaryGa4();
				goToStep( nextStep );
				return;
			}

			if ( 'back' === action ) {
				goToStep( parseInt( btn.dataset.back, 10 ) );
				return;
			}

			// "back-smart" is used on step 3 — returns to wherever we came from.
			if ( 'back-smart' === action ) {
				goToStep( prevStep );
			}
		} );

		// --- Toggle rows (step 2) ----------------------------------------

		document.querySelectorAll( '.clicutcl-wizard__toggle-row' ).forEach( function ( row ) {
			var cb = row.querySelector( '.clicutcl-wizard__toggle-input' );
			if ( ! cb ) {
				return;
			}
			// Sync visual state on change.
			cb.addEventListener( 'change', function () {
				row.classList.toggle( 'is-checked', cb.checked );
			} );
			// Clicking the switch or text area of the row also toggles the checkbox
			// because it is all wrapped in a <label>, which the browser handles.
		} );

		// --- GA4 field live feedback -------------------------------------

		var ga4Input = document.getElementById( 'clicutcl-ga4-id' );
		if ( ga4Input ) {
			ga4Input.addEventListener( 'input', function () {
				// Clear invalid style as user types.
				if ( ga4Input.classList.contains( 'is-invalid' ) ) {
					var val = ga4Input.value.trim();
					if ( '' === val || /^G-[A-Z0-9]+$/i.test( val ) ) {
						ga4Input.classList.remove( 'is-invalid' );
					}
				}
			} );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
