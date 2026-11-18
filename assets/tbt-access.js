/**
 * TBT Access — signed-out account screens.
 *
 * 1. Show/hide on every password field ("Pokaż hasło" / "Ukryj hasło").
 * 2. Strength meter on the set-new-password form, using WordPress core's
 *    password-strength-meter (zxcvbn). The submit button stays disabled
 *    below the form's data-tbt-min-strength (2, "Średnie") or while the two
 *    fields differ. WooCommerce's own meter is dequeued on this screen and
 *    the site-wide woocommerce_min_password_strength is not touched.
 *
 * No dependencies besides wp.passwordStrength on the reset screen. Without
 * JavaScript everything still submits and WooCommerce's checks apply.
 */
( function () {
	'use strict';

	var HINTS = {
		weak: 'Za słabe. Wydłuż hasło albo dodaj kilka słów.',
		medium: 'Średnie. Dodaj cyfrę albo kilka słów, żeby było mocniejsze.',
		good: 'Dobre hasło.',
		best: 'Bardzo dobre hasło.'
	};

	/* ------------------------------------------------------------------ */
	/* Show / hide                                                         */
	/* ------------------------------------------------------------------ */

	function initReveal( root ) {
		var buttons = root.querySelectorAll( '[data-tbt-reveal]' );

		Array.prototype.forEach.call( buttons, function ( button ) {
			var input = document.getElementById( button.getAttribute( 'data-tbt-reveal' ) );
			if ( ! input ) {
				return;
			}

			button.addEventListener( 'click', function () {
				var show = 'password' === input.type;

				input.type = show ? 'text' : 'password';
				button.setAttribute( 'aria-pressed', show ? 'true' : 'false' );
				button.setAttribute( 'aria-label', show ? 'Ukryj hasło' : 'Pokaż hasło' );
			} );
		} );

		// Never submit a password as visible text into a browser's form history.
		Array.prototype.forEach.call( root.querySelectorAll( 'form' ), function ( form ) {
			form.addEventListener( 'submit', function () {
				Array.prototype.forEach.call( form.querySelectorAll( '[data-tbt-reveal]' ), function ( button ) {
					var input = document.getElementById( button.getAttribute( 'data-tbt-reveal' ) );
					if ( input && 'text' === input.type ) {
						input.type = 'password';
						button.setAttribute( 'aria-pressed', 'false' );
						button.setAttribute( 'aria-label', 'Pokaż hasło' );
					}
				} );
			} );
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Strength meter                                                      */
	/* ------------------------------------------------------------------ */

	function meterApi() {
		return window.wp && window.wp.passwordStrength && 'function' === typeof window.wp.passwordStrength.meter
			? window.wp.passwordStrength
			: null;
	}

	function disallowedList( form ) {
		var api = meterApi();
		var list = [];

		if ( api ) {
			if ( 'function' === typeof api.userInputDisallowedList ) {
				list = api.userInputDisallowedList();
			} else if ( 'function' === typeof api.userInputBlacklist ) {
				list = api.userInputBlacklist();
			}
		}

		var login = form.querySelector( 'input[name="reset_login"]' );
		if ( login && login.value ) {
			list = list.concat( login.value.split( /[@._-]+/ ) );
		}

		return list;
	}

	/**
	 * zxcvbn score 0–4, or -1 while zxcvbn is still loading (it loads
	 * asynchronously after the page) or unavailable.
	 */
	function score( form, password ) {
		var api = meterApi();
		if ( ! api ) {
			return -1;
		}

		// Passing the password as its own confirmation keeps a mismatch
		// (score 5) out of the strength reading; mismatch is checked apart.
		var value = api.meter( password, disallowedList( form ), password );

		return value >= 0 && value <= 4 ? value : -1;
	}

	function initReset( form ) {
		var first = form.querySelector( '[data-tbt-strength-input]' );
		var second = form.querySelector( '[data-tbt-repeat-input]' );
		var submit = form.querySelector( '[data-tbt-reset-submit]' );
		var meter = form.querySelector( '[data-tbt-strength]' );

		if ( ! first || ! second || ! submit || ! meter ) {
			return;
		}

		var bars = meter.querySelectorAll( '.tbt-access__bars span' );
		var hint = meter.querySelector( '.tbt-access__strength-hint' );
		var min = parseInt( form.getAttribute( 'data-tbt-min-strength' ), 10 );
		var fallback = false; // true when zxcvbn never arrives: gate on match only.

		if ( isNaN( min ) ) {
			min = 2;
		}

		function render( value ) {
			var level = '';
			var text = '';
			var lit = 0;

			if ( value >= 0 ) {
				if ( value <= 1 ) {
					level = 'weak';
					text = HINTS.weak;
					lit = 1;
				} else if ( 2 === value ) {
					level = 'medium';
					text = HINTS.medium;
					lit = 2;
				} else if ( 3 === value ) {
					level = 'strong';
					text = HINTS.good;
					lit = 3;
				} else {
					level = 'strong';
					text = HINTS.best;
					lit = 4;
				}
			}

			meter.hidden = '' === first.value || value < 0;
			meter.setAttribute( 'data-level', level );

			Array.prototype.forEach.call( bars, function ( bar, index ) {
				bar.classList.toggle( 'is-on', index < lit );
			} );

			if ( hint.textContent !== text ) {
				hint.textContent = text;
			}
		}

		function update() {
			var value = '' === first.value ? -1 : score( form, first.value );
			var strongEnough = fallback || value >= min;
			var match = '' !== first.value && first.value === second.value;

			render( value );
			submit.disabled = ! ( strongEnough && match );
		}

		first.addEventListener( 'input', update );
		second.addEventListener( 'input', update );

		// zxcvbn arrives after window load; re-check then. If it never does,
		// fall back to the match check so nobody is locked out.
		window.addEventListener( 'load', function () {
			update();

			var tries = 0;
			var timer = window.setInterval( function () {
				tries++;
				if ( 'function' === typeof window.zxcvbn || tries >= 20 ) {
					window.clearInterval( timer );
					fallback = 'function' !== typeof window.zxcvbn;
					update();
				}
			}, 250 );
		} );

		update();
	}

	/* ------------------------------------------------------------------ */

	function init() {
		var roots = document.querySelectorAll( '.tbt-access' );

		Array.prototype.forEach.call( roots, function ( root ) {
			initReveal( root );
			Array.prototype.forEach.call( root.querySelectorAll( 'form[data-tbt-reset]' ), initReset );
		} );
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
