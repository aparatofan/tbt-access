<?php
/**
 * TBT Access override of WooCommerce myaccount/form-lost-password.php.
 *
 * Artboard 4 (Forgot password). Posts what
 * WC_Form_Handler::process_lost_password() reads: user_login,
 * wc_reset_password, woocommerce-lost-password-nonce. Also shown, with the
 * "link expired" message, when a reset link is invalid or already used.
 *
 * Based on WooCommerce template version 9.2.0 (and 3.5.2 in 5.5.x).
 *
 * @package TBT_Access
 */

defined( 'ABSPATH' ) || exit;

$tbt_state = tbt_access_screen_state();

do_action( 'woocommerce_before_lost_password_form' );
?>
<div class="tbt-access__panel">
	<?php
	tbt_access_partial(
		'hero',
		array(
			'title' => 'Nowe hasło',
			'line'  => 'Każdemu się zdarza. Dwie minuty i wracasz do nauki.',
			'items' => array(
				array( 'text' => 'Wpisz swój e-mail', 'mark' => 'current', 'number' => 1 ),
				array( 'text' => 'Otwórz link z wiadomości', 'mark' => 'todo', 'number' => 2 ),
				array( 'text' => 'Ustaw nowe hasło', 'mark' => 'todo', 'number' => 3 ),
			),
		)
	);
	?>

	<form class="tbt-access__column tbt-access__form woocommerce-ResetPassword lost_reset_password" method="post" novalidate>
		<a class="tbt-access__back tbt-access__link" href="<?php echo esc_url( tbt_access_account_url() ); ?>">
			<?php echo tbt_access_icon( 'back', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
			<span>Wróć do logowania</span>
		</a>

		<div class="tbt-access__intro">
			<h2 class="tbt-access__heading">Nie pamiętasz hasła?</h2>
			<p class="tbt-access__lede">Wpisz e-mail lub nazwę użytkownika, których używasz na The Blue Tree. Wyślemy ci link do ustawienia nowego hasła.</p>
		</div>

		<?php tbt_access_print_message( $tbt_state['message'] ); ?>

		<div class="tbt-access__field">
			<label class="tbt-access__label" for="user_login">E-mail lub nazwa użytkownika</label>
			<input class="tbt-access__input" type="text" name="user_login" id="user_login" autocomplete="username" placeholder="imie@przyklad.pl" value="<?php echo esc_attr( trim( tbt_access_post_field( 'user_login' ) ) ); ?>"<?php echo tbt_access_invalid_attrs( 'user_login' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed markup. ?> />
		</div>

		<?php do_action( 'woocommerce_lostpassword_form' ); ?>

		<input type="hidden" name="wc_reset_password" value="true" />
		<?php wp_nonce_field( 'lost_password', 'woocommerce-lost-password-nonce' ); ?>
		<button type="submit" class="tbt-access__button tbt-access__button--primary" value="Wyślij link">Wyślij link</button>

		<p class="tbt-access__footer">Wiadomość nie dotarła po kilku minutach? Sprawdź folder spam albo napisz na <a class="tbt-access__link" href="mailto:hi@thebluetree.pl">hi@thebluetree.pl</a>.</p>
	</form>
</div>
<?php
do_action( 'woocommerce_after_lost_password_form' );
