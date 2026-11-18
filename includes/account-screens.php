<?php
/**
 * TBT-style signed-out account screens (0.2.0).
 *
 * WooCommerce still does all the work: its form handlers, nonces, endpoints,
 * emails and redirects are untouched. This file only changes what is rendered
 * on /my-account/ (and its password pages) for signed-out visitors, and turns
 * WooCommerce's English notices into Polish messages inside the card.
 *
 * Checked against WooCommerce 5.5.4 and 11.3.0.
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Which screen are we on?
 * ---------------------------------------------------------------------- */

/**
 * Whether this request is a signed-out My account screen (login, forgot
 * password, check email, set new password).
 *
 * @return bool
 */
function tbt_access_is_signed_out_account_screen() {
	return ! is_user_logged_in()
		&& function_exists( 'is_account_page' )
		&& is_account_page();
}

/**
 * Whether this request is the lost-password endpoint (forgot / check email /
 * set new password).
 *
 * @return bool
 */
function tbt_access_is_lost_password_screen() {
	return function_exists( 'is_lost_password_page' ) && is_lost_password_page();
}

/**
 * The lost-password URL, honouring a renamed endpoint.
 *
 * @return string
 */
function tbt_access_lost_password_url() {
	$fallback = tbt_access_account_endpoint_url( 'lost-password' );

	return function_exists( 'wc_lostpassword_url' ) ? wc_lostpassword_url( $fallback ) : $fallback;
}

/* -------------------------------------------------------------------------
 * T2 — Replace the signed-out content of the account page
 * ---------------------------------------------------------------------- */

add_filter( 'the_content', 'tbt_access_account_content', 999 );

/**
 * Swap the whole Divi layout of the account page for the TBT panel.
 *
 * Priority 999 runs after wpautop, shortcodes and Divi's own wrapping, so the
 * Divi "MY DASHBOARD" section and the [plugin_delete_me] text go too. The
 * site header and footer are outside the_content and stay.
 *
 * Renders once per request, and never while <head> is being built or an
 * excerpt is being generated: SEO and sharing plugins run the_content there,
 * and rendering the shortcode in that pass would consume WooCommerce's
 * notices before the real render.
 *
 * @param string $content Page content.
 * @return string
 */
function tbt_access_account_content( $content ) {
	static $rendered = false;

	if ( $rendered || ! tbt_access_is_signed_out_account_screen() ) {
		return $content;
	}

	if ( doing_action( 'wp_head' ) || doing_filter( 'get_the_excerpt' ) || doing_filter( 'wp_trim_excerpt' ) ) {
		return $content;
	}

	if ( (int) get_the_ID() !== (int) wc_get_page_id( 'myaccount' ) ) {
		return $content;
	}

	$rendered = true;

	return '<div class="tbt-access">' . do_shortcode( '[woocommerce_my_account]' ) . '</div>';
}

add_action( 'wp', 'tbt_access_suppress_wc_notices' );

/**
 * Stop WooCommerce printing its own (English) notices on these screens. The
 * templates show Polish messages inside the card instead.
 *
 * The check-email template prints its notice directly; the plugin's override
 * of that template simply leaves it out.
 *
 * @return void
 */
function tbt_access_suppress_wc_notices() {
	if ( ! tbt_access_is_signed_out_account_screen() ) {
		return;
	}

	$hooks = array(
		'woocommerce_before_customer_login_form',
		'woocommerce_before_lost_password_form',
		'woocommerce_before_reset_password_form',
	);

	foreach ( $hooks as $hook ) {
		$priority = has_action( $hook, 'woocommerce_output_all_notices' );
		if ( false !== $priority ) {
			remove_action( $hook, 'woocommerce_output_all_notices', $priority );
		}
	}
}

/* -------------------------------------------------------------------------
 * T3 — Template overrides
 * ---------------------------------------------------------------------- */

add_filter( 'woocommerce_locate_template', 'tbt_access_locate_template', 20, 2 );

/**
 * Serve the plugin's four account templates to signed-out visitors.
 *
 * Signed-in visitors (who can also reach the lost-password endpoint) keep
 * WooCommerce's templates, since the TBT styles only load for signed-out
 * screens.
 *
 * @param string $template      Resolved template path.
 * @param string $template_name Template name relative to the templates folder.
 * @return string
 */
function tbt_access_locate_template( $template, $template_name ) {
	$ours = array(
		'myaccount/form-login.php',
		'myaccount/form-lost-password.php',
		'myaccount/lost-password-confirmation.php',
		'myaccount/form-reset-password.php',
	);

	if ( ! in_array( $template_name, $ours, true ) || ! tbt_access_is_signed_out_account_screen() ) {
		return $template;
	}

	$path = TBT_ACCESS_DIR . 'templates/' . $template_name;

	return file_exists( $path ) ? $path : $template;
}

/**
 * Include a plugin partial.
 *
 * @param string $name Partial file name without extension.
 * @param array  $args Variables for the partial.
 * @return void
 */
function tbt_access_partial( $name, array $args = array() ) {
	$path = TBT_ACCESS_DIR . 'templates/partials/' . $name . '.php';

	if ( file_exists( $path ) ) {
		include $path;
	}
}

/* -------------------------------------------------------------------------
 * T6 — Messages in Polish
 * ---------------------------------------------------------------------- */

add_filter( 'authenticate', 'tbt_access_capture_auth_error', PHP_INT_MAX, 1 );

/**
 * Remember why a sign-in failed.
 *
 * Read-only, at the last priority, so it sees the final result of every
 * authenticate callback. Unlike wp_login_failed, it also sees the
 * empty_username and empty_password codes.
 *
 * @param WP_User|WP_Error|null $user Authentication result.
 * @return WP_User|WP_Error|null Unchanged.
 */
function tbt_access_capture_auth_error( $user ) {
	if ( is_wp_error( $user ) ) {
		$GLOBALS['tbt_access_auth_error'] = (string) $user->get_error_code();
	}

	return $user;
}

/**
 * Plain text of every queued WooCommerce notice of one type.
 *
 * Handles the array shape (WooCommerce 3.9+) and the older string shape.
 *
 * @param string $type Notice type.
 * @return string[]
 */
function tbt_access_wc_notice_texts( $type ) {
	if ( ! function_exists( 'wc_get_notices' ) ) {
		return array();
	}

	$texts = array();

	foreach ( (array) wc_get_notices( $type ) as $notice ) {
		$text    = is_array( $notice ) ? ( $notice['notice'] ?? '' ) : $notice;
		$texts[] = trim( wp_strip_all_tags( (string) $text ) );
	}

	return $texts;
}

/**
 * Whether a WooCommerce string (as translated on this site) is among the
 * queued notices.
 *
 * Comparing against WooCommerce's own translation keeps the match working
 * with the site's en_GB language pack.
 *
 * @param string[] $texts  Queued notice texts.
 * @param string   $source WooCommerce source string.
 * @return bool
 */
function tbt_access_has_wc_notice( array $texts, $source ) {
	// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.WP.I18n.TextDomainMismatch -- matching WooCommerce's own strings.
	$translated = trim( wp_strip_all_tags( __( $source, 'woocommerce' ) ) );

	return in_array( $translated, $texts, true ) || in_array( $source, $texts, true );
}

/**
 * A string from the POST body, unslashed, or ''.
 *
 * @param string $key Field name.
 * @return string
 */
function tbt_access_post_field( $key ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read only to explain WooCommerce's own (nonce-checked) result.
	if ( ! isset( $_POST[ $key ] ) || ! is_string( $_POST[ $key ] ) ) {
		return '';
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	return wp_unslash( $_POST[ $key ] );
}

/**
 * Work out the message for the current screen, then clear WooCommerce's
 * notice queue so its English notices never show here or on the next page.
 *
 * Computed once per request, the first time a template asks.
 *
 * @return array{message:?array, field:string}
 *         message: [ type: error|info|success, title, html ] or null.
 *         field:   id of the input to highlight, or ''.
 */
function tbt_access_screen_state() {
	static $state = null;

	if ( null !== $state ) {
		return $state;
	}

	$errors = tbt_access_wc_notice_texts( 'error' );
	$state  = array(
		'message' => null,
		'field'   => '',
	);

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- detecting which form was posted.
	$posted = $_POST;

	if ( ! empty( $errors ) ) {
		if ( isset( $posted['login'], $posted['username'] ) ) {
			$state = tbt_access_login_error( $errors );
		} elseif ( isset( $posted['wc_reset_password'], $posted['password_1'] ) ) {
			$state = tbt_access_reset_error( $errors );
		} elseif ( isset( $posted['wc_reset_password'], $posted['user_login'] ) ) {
			$state = tbt_access_lost_password_error( $errors );
		} elseif ( tbt_access_is_key_error( $errors ) ) {
			$state = tbt_access_expired_link_state();
		} else {
			$state['message'] = tbt_access_generic_error();
		}
	} elseif ( '' !== tbt_access_query_arg( 'password-reset' ) ) {
		$state['message'] = array(
			'type'  => 'success',
			'title' => 'Hasło zmienione.',
			'html'  => 'Zaloguj się nowym hasłem.',
		);
	}

	if ( function_exists( 'wc_clear_notices' ) ) {
		wc_clear_notices();
	}

	return $state;
}

/**
 * Whether the invalid / expired reset-key notice is queued.
 *
 * @param string[] $errors Queued error texts.
 * @return bool
 */
function tbt_access_is_key_error( array $errors ) {
	return tbt_access_has_wc_notice( $errors, 'This key is invalid or has already been used. Please reset your password again if needed.' );
}

/**
 * State for an invalid or expired reset link (shown on the Forgot screen).
 *
 * @return array
 */
function tbt_access_expired_link_state() {
	return array(
		'message' => array(
			'type'  => 'error',
			'title' => 'Ten link wygasł albo został już użyty.',
			'html'  => 'Wyślij sobie nowy.',
		),
		'field'   => '',
	);
}

/**
 * The fallback error.
 *
 * @return array
 */
function tbt_access_generic_error() {
	return array(
		'type'  => 'error',
		'title' => 'Nie udało się. Spróbuj jeszcze raz.',
		'html'  => '',
	);
}

/**
 * Message for a failed sign-in.
 *
 * WooCommerce rejects an empty username itself, before wp_signon() runs, so
 * that case is read from the posted field. Everything else comes from the
 * authenticate result captured above.
 *
 * @param string[] $errors Queued error texts.
 * @return array
 */
function tbt_access_login_error( array $errors ) {
	$code = (string) ( $GLOBALS['tbt_access_auth_error'] ?? '' );

	if ( '' === trim( tbt_access_post_field( 'username' ) ) || 'empty_username' === $code ) {
		return array(
			'message' => array(
				'type'  => 'error',
				'title' => 'Wpisz e-mail lub nazwę użytkownika.',
				'html'  => '',
			),
			'field'   => 'username',
		);
	}

	switch ( $code ) {
		case 'incorrect_password':
			return array(
				'message' => array(
					'type'  => 'error',
					'title' => 'Hasło nie pasuje do tego konta.',
					'html'  => sprintf(
						'Spróbuj jeszcze raz albo <a href="%s">ustaw nowe hasło</a>.',
						esc_url( tbt_access_lost_password_url() )
					),
				),
				'field'   => 'password',
			);

		case 'invalid_username':
		case 'invalid_email':
			return array(
				'message' => array(
					'type'  => 'error',
					'title' => 'Nie znamy takiego konta.',
					'html'  => 'Sprawdź pisownię albo zaloguj się adresem e-mail.',
				),
				'field'   => 'username',
			);

		case 'empty_password':
			return array(
				'message' => array(
					'type'  => 'error',
					'title' => 'Wpisz hasło.',
					'html'  => '',
				),
				'field'   => 'password',
			);
	}

	return array(
		'message' => tbt_access_generic_error(),
		'field'   => '',
	);
}

/**
 * Message for a failed "send me a link" request.
 *
 * @param string[] $errors Queued error texts.
 * @return array
 */
function tbt_access_lost_password_error( array $errors ) {
	if ( '' === trim( tbt_access_post_field( 'user_login' ) ) ) {
		return array(
			'message' => array(
				'type'  => 'error',
				'title' => 'Wpisz e-mail lub nazwę użytkownika.',
				'html'  => '',
			),
			'field'   => 'user_login',
		);
	}

	if ( tbt_access_has_wc_notice( $errors, 'Invalid username or email.' ) ) {
		return array(
			'message' => array(
				'type'  => 'error',
				'title' => 'Nie znaleźliśmy takiego konta.',
				'html'  => 'Sprawdź pisownię albo napisz na <a href="mailto:hi@thebluetree.pl">hi@thebluetree.pl</a>.',
			),
			'field'   => 'user_login',
		);
	}

	return array(
		'message' => tbt_access_generic_error(),
		'field'   => '',
	);
}

/**
 * Message for a failed password reset.
 *
 * @param string[] $errors Queued error texts.
 * @return array
 */
function tbt_access_reset_error( array $errors ) {
	if ( tbt_access_is_key_error( $errors ) ) {
		return tbt_access_expired_link_state();
	}

	$pass_1 = tbt_access_post_field( 'password_1' );

	if ( '' !== $pass_1 && $pass_1 !== tbt_access_post_field( 'password_2' ) ) {
		return array(
			'message' => array(
				'type'  => 'error',
				'title' => 'Hasła się różnią.',
				'html'  => 'Wpisz to samo hasło w obu polach.',
			),
			'field'   => 'password_2',
		);
	}

	return array(
		'message' => tbt_access_generic_error(),
		'field'   => '',
	);
}

/**
 * Attributes that mark an input as the one in error.
 *
 * @param string $field_id Input id.
 * @return string Safe attribute string (leading space) or ''.
 */
function tbt_access_invalid_attrs( $field_id ) {
	$state = tbt_access_screen_state();

	if ( $state['field'] !== $field_id ) {
		return '';
	}

	return ' aria-invalid="true" aria-describedby="tbt-access-message"';
}

/**
 * The value to keep in the username field after a failed attempt.
 *
 * @return string
 */
function tbt_access_posted_username() {
	return trim( tbt_access_post_field( 'username' ) );
}

/* -------------------------------------------------------------------------
 * T1 / T5 — Assets
 * ---------------------------------------------------------------------- */

add_action( 'wp_enqueue_scripts', 'tbt_access_enqueue_account_assets', 20 );

/**
 * Load the screen styles, script and fonts on signed-out account screens
 * only, and swap WooCommerce's English strength meter for the plugin's own
 * on the password pages.
 *
 * @return void
 */
function tbt_access_enqueue_account_assets() {
	if ( ! tbt_access_is_signed_out_account_screen() ) {
		return;
	}

	// Divi already loads Roboto 400/500/700 and Roboto Slab. These screens
	// also need Roboto 600 (buttons, labels) and Roboto Mono (hero).
	wp_enqueue_style(
		'tbt-access-fonts',
		'https://fonts.googleapis.com/css2?family=Roboto:wght@600&family=Roboto+Mono:wght@500;700&display=swap',
		array(),
		null // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Google Fonts URL must stay exact.
	);

	$deps = wp_style_is( 'tbt-tokens', 'registered' ) ? array( 'tbt-tokens' ) : array();

	wp_enqueue_style(
		'tbt-access-screens',
		TBT_ACCESS_URL . 'assets/tbt-access.css',
		$deps,
		tbt_access_asset_version( 'assets/tbt-access.css' )
	);

	// The one deliberate rule outside .tbt-access: hide the Divi header's
	// navigation (menu, cart, search, mobile menu) so the sign-in screens are
	// distraction-free. The logo stays and still links home. Inline so it
	// only ever exists on these screens.
	wp_add_inline_style( 'tbt-access-screens', '#main-header #et-top-navigation{display:none!important}' );

	$script_deps = array();

	if ( tbt_access_is_lost_password_screen() ) {
		// The plugin's meter replaces WooCommerce's. The site-wide
		// woocommerce_min_password_strength (default 3) is left alone, so
		// checkout and Edit account keep their rule; the minimum of 2 for
		// this screen lives in tbt-access.js.
		wp_dequeue_script( 'wc-password-strength-meter' );
		$script_deps[] = 'password-strength-meter';
	}

	wp_enqueue_script(
		'tbt-access-screens',
		TBT_ACCESS_URL . 'assets/tbt-access.js',
		$script_deps,
		tbt_access_asset_version( 'assets/tbt-access.js' ),
		true
	);
}

/**
 * Cache-busting version for a bundled asset (file mtime, else plugin version).
 *
 * @param string $relative_path Path relative to the plugin folder.
 * @return string
 */
function tbt_access_asset_version( $relative_path ) {
	$mtime = @filemtime( TBT_ACCESS_DIR . $relative_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

	return $mtime ? TBT_ACCESS_VERSION . '.' . $mtime : TBT_ACCESS_VERSION;
}

/* -------------------------------------------------------------------------
 * Shared markup helpers
 * ---------------------------------------------------------------------- */

/**
 * Print a message box.
 *
 * @param array|null $message [ type, title, html ] from tbt_access_screen_state().
 * @return void
 */
function tbt_access_print_message( $message ) {
	if ( empty( $message ) ) {
		return;
	}

	$type = in_array( $message['type'], array( 'error', 'info', 'success' ), true ) ? $message['type'] : 'error';
	$role = 'error' === $type ? 'alert' : 'status';
	$icon = 'error' === $type ? 'alert' : 'check';

	printf(
		'<div id="tbt-access-message" class="tbt-access__message tbt-access__message--%1$s" role="%2$s">%3$s<div class="tbt-access__message-body"><strong>%4$s</strong>%5$s</div></div>',
		esc_attr( $type ),
		esc_attr( $role ),
		tbt_access_icon( $icon, 20 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
		esc_html( $message['title'] ),
		'' !== $message['html'] ? ' <span>' . wp_kses( $message['html'], array( 'a' => array( 'href' => true ) ) ) . '</span>' : '' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- kses'd above.
	);
}

/**
 * A password input with the show/hide button.
 *
 * Wrapped in a <div>, not a <span>, and without WooCommerce's
 * `woocommerce-Input` class, so woocommerce.js does not add its own English
 * toggle as well.
 *
 * @param array $args id, name, autocomplete, extra (raw attribute string).
 * @return void
 */
function tbt_access_password_field( array $args ) {
	$args = wp_parse_args(
		$args,
		array(
			'id'           => '',
			'name'         => '',
			'autocomplete' => 'current-password',
			'extra'        => '',
		)
	);

	printf(
		'<div class="tbt-access__password"><input class="tbt-access__input" type="password" id="%1$s" name="%2$s" autocomplete="%3$s"%4$s%5$s /><button type="button" class="tbt-access__reveal" data-tbt-reveal="%1$s" aria-controls="%1$s" aria-pressed="false" aria-label="Pokaż hasło">%6$s%7$s</button></div>',
		esc_attr( $args['id'] ),
		esc_attr( $args['name'] ),
		esc_attr( $args['autocomplete'] ),
		tbt_access_invalid_attrs( $args['id'] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup.
		$args['extra'], // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from fixed strings by callers.
		tbt_access_icon( 'eye', 20, 'tbt-access__icon-show' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
		tbt_access_icon( 'eye-off', 20, 'tbt-access__icon-hide' ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG.
	);
}

/**
 * Inline stroke icons, decorative only.
 *
 * @param string $name  Icon name.
 * @param int    $size  Pixel size.
 * @param string $class Extra class.
 * @return string
 */
function tbt_access_icon( $name, $size = 20, $class = '' ) {
	$paths = array(
		'check'   => '<circle cx="12" cy="12" r="10"/><path d="M8 12.5l2.5 2.5L16 9.5"/>',
		'alert'   => '<circle cx="12" cy="12" r="10"/><path d="M12 7.5v5.5"/><path d="M12 16.5h.01"/>',
		'eye'     => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
		'eye-off' => '<path d="M3 3l18 18"/><path d="M10.6 5.1A10.8 10.8 0 0 1 12 5c6.5 0 10 7 10 7a17.7 17.7 0 0 1-3.2 4.1"/><path d="M6.6 6.6C3.8 8.4 2 12 2 12s3.5 7 10 7a9.7 9.7 0 0 0 5.4-1.6"/><path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>',
		'back'    => '<path d="M15 18l-6-6 6-6"/>',
		'mail'    => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 7l9 6 9-6"/>',
	);

	if ( ! isset( $paths[ $name ] ) ) {
		return '';
	}

	return sprintf(
		'<svg class="tbt-access__icon %1$s" width="%2$d" height="%2$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%3$s</svg>',
		esc_attr( $class ),
		(int) $size,
		$paths[ $name ]
	);
}
