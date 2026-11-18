<?php
/**
 * TBT Access override of WooCommerce myaccount/form-login.php.
 *
 * Artboards 1 (Sign in), 2 (error state) and 3 (Signed out). Posts exactly
 * what WC_Form_Handler::process_login() reads: username, password,
 * rememberme, woocommerce-login-nonce, login, plus the `redirect` field TBT
 * Access prints on woocommerce_login_form_end. Every WooCommerce hook of the
 * original template is kept. The registration column is left out: sign-ups
 * are Phase 3.
 *
 * Based on WooCommerce template version 9.9.0 (and 4.1.0 in 5.5.x).
 *
 * @package TBT_Access
 */

defined( 'ABSPATH' ) || exit;

$tbt_state = tbt_access_screen_state();

// The signed-out variant is for the page logout lands on; after a failed
// attempt from it, show the normal sign-in screen with the error.
$tbt_signed_out = '' !== tbt_access_query_arg( 'tbt_signed_out' )
	&& 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

if ( $tbt_signed_out ) {
	$tbt_hero = array(
		'title' => 'Do zobaczenia',
		'line'  => 'Dzięki za dzisiejszą naukę na The Blue Tree.',
		'items' => array(
			array( 'text' => 'Twoje notatki i wyniki są zapisane' ),
			array( 'text' => 'Następnym razem wrócisz do nauki' ),
		),
	);

	if ( empty( $tbt_state['message'] ) ) {
		$tbt_state['message'] = array(
			'type'  => 'info',
			'title' => 'Wylogowano.',
			'html'  => 'Możesz zamknąć tę kartę.',
		);
	}
} else {
	$tbt_hero = array(
		'title' => 'Logowanie',
		'line'  => 'Twoje lekcje, notatki i narzędzia do nauki w jednym miejscu.',
		'items' => array(
			array( 'text' => 'Lekcje, mapy myśli i fiszki' ),
			array( 'text' => 'Notatki z zajęć i prace domowe' ),
			array( 'text' => 'Twoje wyniki i punkty' ),
		),
	);
}

do_action( 'woocommerce_before_customer_login_form' );
?>
<div class="tbt-access__panel">
	<?php tbt_access_partial( 'hero', $tbt_hero ); ?>

	<form class="tbt-access__column tbt-access__form woocommerce-form woocommerce-form-login" method="post" novalidate>

		<?php if ( $tbt_signed_out ) : ?>
			<?php tbt_access_print_message( $tbt_state['message'] ); ?>
			<div class="tbt-access__intro">
				<h2 class="tbt-access__heading">Zaloguj się ponownie</h2>
				<p class="tbt-access__lede">Adresem e-mail lub nazwą użytkownika.</p>
			</div>
		<?php else : ?>
			<div class="tbt-access__intro">
				<h2 class="tbt-access__heading">Witaj ponownie</h2>
				<p class="tbt-access__lede">Zaloguj się adresem e-mail lub nazwą użytkownika.</p>
			</div>
			<?php tbt_access_print_message( $tbt_state['message'] ); ?>
		<?php endif; ?>

		<?php do_action( 'woocommerce_login_form_start' ); ?>

		<div class="tbt-access__fields">
			<div class="tbt-access__field">
				<label class="tbt-access__label" for="username">E-mail lub nazwa użytkownika</label>
				<input class="tbt-access__input" type="text" name="username" id="username" autocomplete="username" placeholder="imie@przyklad.pl" value="<?php echo esc_attr( tbt_access_posted_username() ); ?>"<?php echo tbt_access_invalid_attrs( 'username' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup. ?> />
			</div>

			<div class="tbt-access__field">
				<label class="tbt-access__label" for="password">Hasło</label>
				<?php
				tbt_access_password_field(
					array(
						'id'           => 'password',
						'name'         => 'password',
						'autocomplete' => 'current-password',
					)
				);
				?>
			</div>

			<?php do_action( 'woocommerce_login_form' ); ?>

			<div class="tbt-access__row">
				<label class="tbt-access__check" for="rememberme">
					<input type="checkbox" name="rememberme" id="rememberme" value="forever" />
					<span>Nie wylogowuj mnie</span>
				</label>
				<a class="tbt-access__link" href="<?php echo esc_url( tbt_access_lost_password_url() ); ?>">Nie pamiętasz hasła?</a>
			</div>
		</div>

		<?php wp_nonce_field( 'woocommerce-login', 'woocommerce-login-nonce' ); ?>
		<button type="submit" class="tbt-access__button tbt-access__button--primary woocommerce-form-login__submit" name="login" value="Zaloguj się">Zaloguj się</button>

		<?php do_action( 'woocommerce_login_form_end' ); ?>

		<?php if ( ! $tbt_signed_out ) : ?>
			<p class="tbt-access__footer">
				<span>Nie masz jeszcze konta?</span>
				<a class="tbt-access__link" href="https://calendly.com/the-blue-tree/first-class">Umów się na pierwszą lekcję</a>
			</p>
		<?php endif; ?>
	</form>
</div>
<?php
do_action( 'woocommerce_after_customer_login_form' );
