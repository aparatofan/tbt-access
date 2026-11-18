<?php
/**
 * Plugin Name:       TBT Access
 * Description:       One sign-in page and one set of redirects for The Blue Tree. Replaces the theme's legacy login code at runtime.
 * Version:           0.2.1
 * Requires at least: 6.0
 * Requires PHP:      8.0
 * Author:            TBT
 * License:           GPL-2.0-or-later
 * Text Domain:       tbt-access
 */

defined( 'ABSPATH' ) || exit;

define( 'TBT_ACCESS_VERSION', '0.2.1' );
define( 'TBT_ACCESS_DIR', plugin_dir_path( __FILE__ ) );
define( 'TBT_ACCESS_URL', plugin_dir_url( __FILE__ ) );

require_once TBT_ACCESS_DIR . 'includes/account-screens.php';

/*
 * Rules this file implements:
 *
 *   - The only sign-in page is WooCommerce's /my-account/.
 *   - After sign-in: back to where the person started if that URL is safe,
 *     otherwise administrators go to wp-admin and everyone else to /vip/.
 *   - After sign-out: the sign-in screen with a "Wylogowano." banner.
 *   - Signed-out account screens are TBT-styled and in Polish
 *     (includes/account-screens.php).
 *
 * The theme's functions.php is never edited. Its legacy login code is unhooked
 * at runtime, so deactivating this plugin restores the old behaviour exactly.
 *
 * Not registered with TBT Hub: Hub entries link to an admin screen, and this
 * plugin has none.
 */

/* -------------------------------------------------------------------------
 * T2 — Unhook the theme's legacy login code
 * ---------------------------------------------------------------------- */

add_action( 'after_setup_theme', 'tbt_access_unhook_theme', 99 );

/**
 * Remove the theme's login, logout and registration callbacks.
 *
 * Priority 99 on after_setup_theme: the child theme's functions.php has loaded
 * by then. Each hook is removed only if it is actually attached, and at the
 * priority it was found at.
 *
 * @return void
 */
function tbt_access_unhook_theme() {
	$legacy = array(
		array( 'login_redirect', 'my_login_redirect' ),
		array( 'woocommerce_login_redirect', 'wc_custom_user_redirect' ),
		array( 'wp_logout', 'osc_redirect_external_after_logout' ),
		array( 'wpcf7_before_send_mail', 'thebluetree_user_from_registration' ),
	);

	foreach ( $legacy as list( $hook, $callback ) ) {
		$priority = has_filter( $hook, $callback );

		if ( false !== $priority ) {
			remove_filter( $hook, $callback, $priority );
		}
	}
}

add_action( 'init', 'tbt_access_silence_theme_shortcodes', 20 );

/**
 * Replace the theme's late-redirect shortcodes with empty ones.
 *
 * Both try wp_redirect() inside page content, after headers are sent. Pages
 * that still contain them must render nothing rather than raw shortcode text,
 * so they are re-registered (after the theme's own registration at 10) instead
 * of removed.
 *
 * @return void
 */
function tbt_access_silence_theme_shortcodes() {
	add_shortcode( 'bluetree_check_logged', '__return_empty_string' );
	add_shortcode( 'pastuser_fullname', '__return_empty_string' );
}

/* -------------------------------------------------------------------------
 * URL helpers
 * ---------------------------------------------------------------------- */

/**
 * The My account page URL.
 *
 * @return string
 */
function tbt_access_account_url() {
	if ( function_exists( 'wc_get_page_permalink' ) ) {
		$url = wc_get_page_permalink( 'myaccount' );
		if ( $url ) {
			return $url;
		}
	}

	return home_url( '/my-account/' );
}

/**
 * URL of a My account endpoint, honouring a renamed endpoint slug.
 *
 * @param string $endpoint Endpoint key, e.g. 'lost-password'.
 * @return string
 */
function tbt_access_account_endpoint_url( $endpoint ) {
	$base = tbt_access_account_url();

	if ( function_exists( 'wc_get_endpoint_url' ) ) {
		return wc_get_endpoint_url( $endpoint, '', $base );
	}

	return trailingslashit( $base ) . $endpoint . '/';
}

/**
 * Normalised path of a URL for comparisons.
 *
 * Query string and fragment are dropped, the path is decoded and lowercased,
 * repeated slashes collapse, and the trailing slash is removed. The site root
 * is '/'.
 *
 * @param string $url Absolute or root-relative URL.
 * @return string
 */
function tbt_access_path( $url ) {
	$path = wp_parse_url( (string) $url, PHP_URL_PATH );

	if ( ! is_string( $path ) || '' === $path ) {
		return '/';
	}

	$path = strtolower( rawurldecode( $path ) );
	$path = preg_replace( '#/+#', '/', $path );

	return '/' . trim( $path, '/' );
}

/**
 * Paths that are never a useful place to return to after sign-in.
 *
 * @return string[]
 */
function tbt_access_blocked_paths() {
	$urls = array(
		site_url( 'wp-login.php' ),
		home_url( '/wp-login.php' ),
		home_url( '/logowanie/' ),
		home_url( '/stop/' ),
		home_url( '/do-zobaczenia/' ),
		home_url( '/contents/' ),
		tbt_access_account_url(),
		tbt_access_account_endpoint_url( 'lost-password' ),
		tbt_access_account_endpoint_url( 'customer-logout' ),
	);

	return array_values( array_unique( array_map( 'tbt_access_path', $urls ) ) );
}

/**
 * Whether a normalised path is inside wp-admin.
 *
 * @param string $path Output of tbt_access_path().
 * @return bool
 */
function tbt_access_is_admin_path( $path ) {
	$admin = tbt_access_path( admin_url() );

	return str_starts_with( $path . '/', $admin . '/' );
}

/**
 * Validate a return URL.
 *
 * Accepts a same-site URL (per wp_validate_redirect()) that is not one of the
 * blocked sign-in / sign-out / welcome pages. When a user is given, wp-admin
 * URLs are also rejected unless that user has manage_options.
 *
 * @param mixed        $url  Candidate URL.
 * @param WP_User|null $user User the URL is for, or null to skip the capability check.
 * @return string The validated URL, or '' when unsafe.
 */
function tbt_access_safe_return( $url, $user = null ) {
	if ( ! is_string( $url ) ) {
		return '';
	}

	$url = trim( $url );
	if ( '' === $url ) {
		return '';
	}

	$valid = wp_validate_redirect( $url, '' );
	if ( '' === $valid ) {
		return '';
	}

	$path = tbt_access_path( $valid );

	if ( in_array( $path, tbt_access_blocked_paths(), true ) ) {
		return '';
	}

	if ( $user instanceof WP_User && ! user_can( $user, 'manage_options' ) && tbt_access_is_admin_path( $path ) ) {
		return '';
	}

	return $valid;
}

/**
 * A string from the query string, unslashed, or ''.
 *
 * @param string $key Query parameter.
 * @return string
 */
function tbt_access_query_arg( $key ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation hint, validated before use.
	if ( ! isset( $_GET[ $key ] ) || ! is_string( $_GET[ $key ] ) ) {
		return '';
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by callers.
	return wp_unslash( $_GET[ $key ] );
}

/* -------------------------------------------------------------------------
 * T3 — One destination resolver
 * ---------------------------------------------------------------------- */

/**
 * Where a user goes after signing in.
 *
 * 1. The requested URL, if it is safe for this user.
 * 2. wp-admin for users with manage_options.
 * 3. /vip/ (filterable via tbt_access_default_destination).
 *
 * @param WP_User $user      The user who just signed in.
 * @param string  $requested The URL they asked to return to.
 * @return string
 */
function tbt_access_destination( WP_User $user, string $requested = '' ): string {
	$safe = tbt_access_safe_return( $requested, $user );
	if ( '' !== $safe ) {
		return $safe;
	}

	if ( user_can( $user, 'manage_options' ) ) {
		return admin_url();
	}

	/**
	 * Default post-sign-in destination for non-administrators.
	 *
	 * @param string  $url  Default URL (/vip/).
	 * @param WP_User $user The user who just signed in.
	 */
	return (string) apply_filters( 'tbt_access_default_destination', home_url( '/vip/' ), $user );
}

/* -------------------------------------------------------------------------
 * T4 — Sign-in redirects
 * ---------------------------------------------------------------------- */

add_filter( 'login_redirect', 'tbt_access_login_redirect', 999, 3 );

/**
 * wp-login.php sign-in.
 *
 * @param string           $redirect_to           Default destination.
 * @param string           $requested_redirect_to The redirect_to that was requested.
 * @param WP_User|WP_Error $user                  The user, or an error on failure.
 * @return string
 */
function tbt_access_login_redirect( $redirect_to, $requested_redirect_to, $user ) {
	if ( ! $user instanceof WP_User ) {
		return $redirect_to; // Failed sign-in: leave untouched.
	}

	return tbt_access_destination( $user, (string) $requested_redirect_to );
}

add_filter( 'woocommerce_login_redirect', 'tbt_access_wc_login_redirect', 999, 2 );

/**
 * My account sign-in.
 *
 * WooCommerce passes the posted `redirect` field, else the referring page,
 * else the My account page. The last two normally resolve to /my-account/
 * (the query string is ignored), which the resolver rejects, giving /vip/.
 *
 * @param string  $redirect Requested destination.
 * @param WP_User $user     The user who just signed in.
 * @return string
 */
function tbt_access_wc_login_redirect( $redirect, $user ) {
	if ( ! $user instanceof WP_User ) {
		return $redirect;
	}

	return tbt_access_destination( $user, is_string( $redirect ) ? $redirect : '' );
}

/* -------------------------------------------------------------------------
 * T5 — Carry the return path into the My account form
 * ---------------------------------------------------------------------- */

add_action( 'woocommerce_login_form_end', 'tbt_access_login_form_redirect_field' );

/**
 * Print the `redirect` field WooCommerce's process_login() reads.
 *
 * Neither WooCommerce 5.5 nor 11.x prints one in myaccount/form-login.php.
 * Limited to the My account page: the checkout login form prints its own.
 *
 * @return void
 */
function tbt_access_login_form_redirect_field() {
	if ( function_exists( 'is_account_page' ) && ! is_account_page() ) {
		return;
	}

	$target = tbt_access_safe_return( tbt_access_query_arg( 'redirect_to' ) );
	if ( '' === $target ) {
		return;
	}

	printf( '<input type="hidden" name="redirect" value="%s" />', esc_attr( $target ) );
}

/* -------------------------------------------------------------------------
 * T6 — Every front-end "sign in" link points to /my-account/
 * ---------------------------------------------------------------------- */

add_filter( 'login_url', 'tbt_access_login_url', 10, 3 );

/**
 * Point wp_login_url() at the My account page on the front end.
 *
 * Left alone in wp-admin, AJAX and REST, for forced re-authentication, and for
 * wp-admin or interim-login returns, so admin sign-in, the session-expired
 * popup and Jetpack keep using wp-login.php.
 *
 * @param string $login_url    Default login URL.
 * @param string $redirect     Return URL, possibly empty.
 * @param bool   $force_reauth Whether re-authentication is forced.
 * @return string
 */
function tbt_access_login_url( $login_url, $redirect, $force_reauth ) {
	if ( is_admin() || wp_doing_ajax() || tbt_access_is_rest_request() || $force_reauth ) {
		return $login_url;
	}

	$redirect = (string) $redirect;

	if ( str_contains( $redirect, '/wp-admin' ) || str_contains( $redirect, 'interim-login' ) ) {
		return $login_url;
	}

	$account = tbt_access_account_url();

	if ( '' === $redirect ) {
		return $account;
	}

	return add_query_arg( 'redirect_to', rawurlencode( $redirect ), $account );
}

/**
 * Whether this is a REST API request.
 *
 * @return bool
 */
function tbt_access_is_rest_request() {
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return true;
	}

	return function_exists( 'wp_is_serving_rest_request' ) && wp_is_serving_rest_request();
}

/* -------------------------------------------------------------------------
 * T7 — Retire the old sign-in pages (302 in this release)
 * ---------------------------------------------------------------------- */

add_action( 'template_redirect', 'tbt_access_template_redirect', 5 );

/**
 * Send old sign-in pages to My account, and signed-in visitors straight
 * through a sign-in link.
 *
 * 302 for now; becomes 301 once confirmed live.
 *
 * @return void
 */
function tbt_access_template_redirect() {
	$requested = tbt_access_query_arg( 'redirect_to' );

	// A sign-in link clicked while already signed in goes straight through.
	if ( '' !== $requested && is_user_logged_in() && tbt_access_is_account_root() ) {
		wp_safe_redirect( tbt_access_destination( wp_get_current_user(), $requested ), 302, 'TBT Access' );
		exit;
	}

	if ( ! is_page() ) {
		return;
	}

	$page = get_queried_object();
	if ( ! $page instanceof WP_Post ) {
		return;
	}

	// Never redirect the My account page itself.
	if ( function_exists( 'wc_get_page_id' ) && (int) wc_get_page_id( 'myaccount' ) === (int) $page->ID ) {
		return;
	}

	$target = tbt_access_retired_target( $page );
	if ( null === $target ) {
		return;
	}

	list( $url, $carries_return ) = $target;

	if ( $carries_return ) {
		$safe = tbt_access_safe_return( $requested );
		if ( '' !== $safe ) {
			$url = add_query_arg( 'redirect_to', rawurlencode( $safe ), $url );
		}
	}

	wp_safe_redirect( $url, 302, 'TBT Access' );
	exit;
}

/**
 * Where a retired page now lives.
 *
 * @param WP_Post $page The requested page.
 * @return array{0:string,1:bool}|null [ URL, whether to carry redirect_to ], or null if not retired.
 */
function tbt_access_retired_target( WP_Post $page ) {
	if ( 'membership-login/password-reset' === get_page_uri( $page ) ) {
		return array( tbt_access_account_endpoint_url( 'lost-password' ), true );
	}

	$to_account = array(
		'logowanie',
		'membership-login',
		'login_2',
		'mp-login-page',
		'membership-registration',
		'membership-dashboard',
	);

	if ( in_array( $page->post_name, $to_account, true ) ) {
		return array( tbt_access_account_url(), true );
	}

	if ( 'contents' === $page->post_name ) {
		return array( home_url( '/vip/' ), false );
	}

	return null;
}

/**
 * Whether the request is the My account page itself, not an endpoint.
 *
 * @return bool
 */
function tbt_access_is_account_root() {
	if ( ! function_exists( 'is_account_page' ) || ! function_exists( 'is_wc_endpoint_url' ) ) {
		return false;
	}

	return is_account_page() && ! is_wc_endpoint_url();
}

/* -------------------------------------------------------------------------
 * T8 — [tbt_signin_button]
 * ---------------------------------------------------------------------- */

add_shortcode( 'tbt_signin_button', 'tbt_access_signin_button' );

/**
 * A sign-in button that returns the visitor to where they came from.
 *
 * Return target, first safe match wins: ?redirect_to, ?r (a post ID, as
 * WooCommerce Memberships passes it), then a same-site referer.
 * Signed-in visitors get nothing.
 *
 * @param array|string $atts Shortcode attributes.
 * @return string
 */
function tbt_access_signin_button( $atts ) {
	if ( is_user_logged_in() ) {
		return '';
	}

	$atts = shortcode_atts( array( 'label' => 'Zaloguj się' ), $atts, 'tbt_signin_button' );

	$url    = tbt_access_account_url();
	$target = tbt_access_signin_target();

	if ( '' !== $target ) {
		$url = add_query_arg( 'redirect_to', rawurlencode( $target ), $url );
	}

	tbt_access_enqueue_button_style();

	return sprintf(
		'<div class="tbt-tool tbt-access-signin"><a class="tbt-button tbt-button--primary" href="%s">%s</a></div>',
		esc_url( $url ),
		esc_html( $atts['label'] )
	);
}

/**
 * The page a sign-in button should return to, or ''.
 *
 * @return string
 */
function tbt_access_signin_target() {
	$candidates = array( tbt_access_query_arg( 'redirect_to' ) );

	$post_id = absint( tbt_access_query_arg( 'r' ) );
	if ( $post_id ) {
		$candidates[] = (string) get_permalink( $post_id );
	}

	$candidates[] = (string) wp_get_referer();

	foreach ( $candidates as $candidate ) {
		$safe = tbt_access_safe_return( $candidate );
		if ( '' !== $safe ) {
			return $safe;
		}
	}

	return '';
}

add_action( 'wp_enqueue_scripts', 'tbt_access_maybe_enqueue_button_style', 20 );

/**
 * Load the button style in <head> on pages whose content holds the shortcode.
 *
 * A plain string search, because Divi modules can wrap or encode the
 * shortcode in ways has_shortcode() misses. Pages where the button arrives
 * some other way still get the style, printed in the footer instead.
 *
 * @return void
 */
function tbt_access_maybe_enqueue_button_style() {
	if ( is_user_logged_in() || ! is_singular() ) {
		return;
	}

	$post = get_queried_object();

	if ( $post instanceof WP_Post && str_contains( $post->post_content, '[tbt_signin_button' ) ) {
		tbt_access_enqueue_button_style();
	}
}

/**
 * Enqueue the button style: Hub's tbt-components when present, with a small
 * literal fallback for the button when Hub is not active.
 *
 * @return void
 */
function tbt_access_enqueue_button_style() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;

	$hub = wp_style_is( 'tbt-components', 'registered' );

	wp_register_style( 'tbt-access', false, $hub ? array( 'tbt-components' ) : array(), TBT_ACCESS_VERSION );

	// .tbt-tool carries tool-page padding; a lone button needs none.
	$css = '.tbt-tool.tbt-access-signin{padding:0;background:transparent}';

	if ( ! $hub ) {
		$css .= '.tbt-access-signin .tbt-button{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:12px 32px;border:1px solid #0856C9;border-radius:999px;background:#0856C9;color:#FFFFFF;font-size:15px;font-weight:700;line-height:1.2;letter-spacing:.08em;text-transform:uppercase;text-decoration:none;white-space:nowrap}'
			. '.tbt-access-signin .tbt-button:hover{border-color:#063F94;background:#063F94;color:#FFFFFF}'
			. '.tbt-access-signin .tbt-button:focus-visible{outline:2px solid #0856C9;outline-offset:2px}';
	}

	wp_add_inline_style( 'tbt-access', $css );
	wp_enqueue_style( 'tbt-access' );
}

/* -------------------------------------------------------------------------
 * Sign-out destination (0.2.0: the sign-in screen with a banner)
 * ---------------------------------------------------------------------- */

add_filter( 'logout_redirect', 'tbt_access_logout_destination', 999 );

/*
 * WooCommerce 11.x logs out from the My account endpoint with wp_logout()
 * directly and redirects to this filter's value, so logout_redirect never
 * runs there. On 5.5.x the endpoint goes through wp-login.php and this
 * filter only fills in the link, which logout_redirect then overrides.
 */
add_filter( 'woocommerce_logout_default_redirect_url', 'tbt_access_logout_destination', 999 );

/**
 * Where everyone goes after signing out: the sign-in screen, which shows the
 * "Wylogowano." banner when it sees tbt_signed_out=1.
 *
 * @return string
 */
function tbt_access_logout_destination() {
	return add_query_arg( 'tbt_signed_out', '1', tbt_access_account_url() );
}

/* -------------------------------------------------------------------------
 * T10 — Hide the deletion link from signed-out visitors
 * ---------------------------------------------------------------------- */

add_filter( 'do_shortcode_tag', 'tbt_access_hide_delete_me', 10, 2 );

/**
 * Blank [plugin_delete_me] for signed-out visitors.
 *
 * @param string $output Shortcode output.
 * @param string $tag    Shortcode tag.
 * @return string
 */
function tbt_access_hide_delete_me( $output, $tag ) {
	if ( 'plugin_delete_me' === $tag && ! is_user_logged_in() ) {
		return '';
	}

	return $output;
}
