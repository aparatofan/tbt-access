<?php
/**
 * Close open sign-ups and record where each account came from (0.2.2).
 *
 * Closed: WordPress's own registration (wp-login.php?action=register) and the
 * WooCommerce My account registration form, including direct POSTs to it.
 *
 * Never touched: WooCommerce checkout account creation (classic, block
 * checkout and the order-received "create account" block, Subscriptions and
 * Memberships purchases) and admin Users → Add New. In particular this file
 * does not use woocommerce_registration_errors, does not filter the checkout
 * sign-up/guest options and does not change wc_create_new_customer().
 *
 * One switch: the tbt_access_open_signup filter (default false). When it
 * returns true, T1–T3 step aside completely. T4 always runs.
 *
 * Checked against WordPress 6.8 and WooCommerce 5.5.4 and 11.3.0.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether open sign-up is switched on (Phase 3 may need it).
 *
 * @return bool
 */
function tbt_access_signup_open() {
	return (bool) apply_filters( 'tbt_access_open_signup', false );
}

/* -------------------------------------------------------------------------
 * T1 — Keep WordPress registration off
 * ---------------------------------------------------------------------- */

add_filter( 'option_users_can_register', 'tbt_access_filter_users_can_register' );

/**
 * Report registration as closed, whatever is stored.
 *
 * @param mixed $value Stored value.
 * @return mixed
 */
function tbt_access_filter_users_can_register( $value ) {
	return tbt_access_signup_open() ? $value : 0;
}

add_filter( 'pre_update_option_users_can_register', 'tbt_access_store_users_can_register' );

/**
 * Store 0 while the switch is off, so the saved value always matches the
 * (unticked) box and an old tick can't reopen registration later.
 *
 * @param mixed $value Value being saved.
 * @return mixed
 */
function tbt_access_store_users_can_register( $value ) {
	return tbt_access_signup_open() ? $value : 0;
}

add_action( 'admin_init', 'tbt_access_reset_users_can_register' );

/**
 * Overwrite a stored "1" left over from before TBT Access 0.2.2.
 *
 * update_option() compares the new value with get_option(), which the filter
 * above always reports as 0, so saving 0 over a stored 1 would be skipped.
 * The raw value is read with the read filter removed.
 *
 * @return void
 */
function tbt_access_reset_users_can_register() {
	if ( tbt_access_signup_open() ) {
		return;
	}

	remove_filter( 'option_users_can_register', 'tbt_access_filter_users_can_register' );
	$stored = get_option( 'users_can_register' );

	if ( $stored ) {
		update_option( 'users_can_register', 0 );
	}

	add_filter( 'option_users_can_register', 'tbt_access_filter_users_can_register' );
}

add_action( 'admin_notices', 'tbt_access_registration_notice' );

/**
 * Explain the closed registration on Settings → General.
 *
 * @return void
 */
function tbt_access_registration_notice() {
	if ( tbt_access_signup_open() ) {
		return;
	}

	if ( 'options-general.php' !== ( $GLOBALS['pagenow'] ?? '' ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	echo '<div class="notice notice-info"><p><strong>'
		. esc_html__( 'Registration is closed by TBT Access.', 'tbt-access' )
		. '</strong> '
		. esc_html__( 'Accounts are created at WooCommerce checkout or by you in Users → Add New. The "Anyone can register" box has no effect while TBT Access is active.', 'tbt-access' )
		. '</p></div>';
}

/* -------------------------------------------------------------------------
 * T2 — Send the old register URL to the sign-in screen
 * ---------------------------------------------------------------------- */

add_action( 'login_form_register', 'tbt_access_redirect_register' );

/**
 * wp-login.php?action=register → the Polish sign-in screen.
 *
 * login_form_register fires before wp-login.php's register branch, so neither
 * WordPress's form nor its "registration disabled" message is shown.
 *
 * @return void
 */
function tbt_access_redirect_register() {
	if ( tbt_access_signup_open() ) {
		return;
	}

	wp_safe_redirect( tbt_access_account_url(), 302, 'TBT Access' );
	exit;
}

/* -------------------------------------------------------------------------
 * T3 — Close the My account registration form
 * ---------------------------------------------------------------------- */

add_filter( 'pre_option_woocommerce_enable_myaccount_registration', 'tbt_access_myaccount_registration_option' );

/**
 * Report My account registration as disabled.
 *
 * @param mixed $pre False to use the stored value.
 * @return mixed
 */
function tbt_access_myaccount_registration_option( $pre ) {
	return tbt_access_signup_open() ? $pre : 'no';
}

add_action( 'init', 'tbt_access_remove_wc_registration_handler' );

/**
 * Detach WooCommerce's My account registration handler.
 *
 * WC_Form_Handler::process_registration (wp_loaded, 20 in WooCommerce 5.5.4
 * and 11.3.0) never checks woocommerce_enable_myaccount_registration, so a
 * direct POST would still create an account. Checkout doesn't use it.
 * Removed at the priority it was found at, and only if attached.
 *
 * @return void
 */
function tbt_access_remove_wc_registration_handler() {
	if ( tbt_access_signup_open() || ! class_exists( 'WC_Form_Handler' ) ) {
		return;
	}

	$callback = array( 'WC_Form_Handler', 'process_registration' );
	$priority = has_action( 'wp_loaded', $callback );

	if ( false !== $priority ) {
		remove_action( 'wp_loaded', $callback, $priority );
	}
}

/* -------------------------------------------------------------------------
 * T4 — Record where each account came from
 * ---------------------------------------------------------------------- */

add_action( 'user_register', 'tbt_access_record_created_via', 10, 1 );

/**
 * Save tbt_access_created_via for every new user. Never blocks, never throws.
 *
 * @param int $user_id New user ID.
 * @return void
 */
function tbt_access_record_created_via( $user_id ) {
	try {
		$user_id = (int) $user_id;

		if ( $user_id > 0 ) {
			add_user_meta( $user_id, 'tbt_access_created_via', tbt_access_created_via(), true );
		}
	} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		// Fail open: account creation must never depend on this.
	}
}

/**
 * Where the user being created right now comes from.
 *
 * Order matters: block checkout is a REST request, and classic non-AJAX
 * checkout runs on wp_loaded like the My account form.
 *
 * @return string cli|checkout|rest|my-account|wp-login|admin|other:/path
 */
function tbt_access_created_via() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- read-only classification.
	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		return 'cli';
	}

	if ( tbt_access_is_checkout_signup() ) {
		return 'checkout';
	}

	if ( tbt_access_is_rest_request() ) {
		return 'rest';
	}

	if ( doing_action( 'wp_loaded' ) && isset( $_POST['register'] ) ) {
		return 'my-account';
	}

	$pagenow = $GLOBALS['pagenow'] ?? '';

	if ( 'wp-login.php' === $pagenow ) {
		return 'wp-login';
	}

	if ( is_admin() && 'user-new.php' === $pagenow ) {
		return 'admin';
	}
	// phpcs:enable

	$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$path = is_string( $path ) ? preg_replace( '/[^\x21-\x7E]/', '', $path ) : '';

	return substr( 'other:' . $path, 0, 200 );
}

/**
 * Whether a WooCommerce checkout is creating this account.
 *
 * - Classic checkout (AJAX and non-AJAX): WC_Checkout::process_checkout()
 *   defines WOOCOMMERCE_CHECKOUT before process_customer() runs.
 * - Block checkout: Store API POST to /wc/store[/vN]/checkout.
 * - WooCommerce 11.x order-received "create account" block: posts
 *   create-account on the order-received page.
 *
 * @return bool
 */
function tbt_access_is_checkout_signup() {
	if ( defined( 'WOOCOMMERCE_CHECKOUT' ) && WOOCOMMERCE_CHECKOUT ) {
		return true;
	}

	if ( tbt_access_is_rest_request() ) {
		return (bool) preg_match( '#^/wc/store(/v\d+)?/checkout(/|$)#', tbt_access_rest_route() );
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only classification.
	if ( isset( $_POST['create-account'] ) && function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
		return true;
	}

	return false;
}

/**
 * The REST route of the current request, e.g. '/wc/store/v1/checkout'.
 *
 * @return string
 */
function tbt_access_rest_route() {
	if ( isset( $GLOBALS['wp'] ) && ! empty( $GLOBALS['wp']->query_vars['rest_route'] ) ) {
		return '/' . ltrim( (string) $GLOBALS['wp']->query_vars['rest_route'], '/' );
	}

	$path   = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	$prefix = '/' . trim( rest_get_url_prefix(), '/' ) . '/';
	$at     = strpos( $path, $prefix );

	return false === $at ? '' : '/' . substr( $path, $at + strlen( $prefix ) );
}

add_filter( 'manage_users_columns', 'tbt_access_users_columns' );

/**
 * Add "Created via" to Users (administrators only).
 *
 * @param array $columns Columns.
 * @return array
 */
function tbt_access_users_columns( $columns ) {
	if ( current_user_can( 'manage_options' ) ) {
		$columns['tbt_access_created_via'] = __( 'Created via', 'tbt-access' );
	}

	return $columns;
}

add_filter( 'manage_users_custom_column', 'tbt_access_users_column_value', 10, 3 );

/**
 * Print the "Created via" value; users from before 0.2.2 show "—".
 *
 * @param string $output  Existing output.
 * @param string $column  Column key.
 * @param int    $user_id User ID.
 * @return string
 */
function tbt_access_users_column_value( $output, $column, $user_id ) {
	if ( 'tbt_access_created_via' !== $column ) {
		return $output;
	}

	$via = get_user_meta( (int) $user_id, 'tbt_access_created_via', true );

	return '' === $via || ! is_string( $via ) ? '—' : esc_html( $via );
}
