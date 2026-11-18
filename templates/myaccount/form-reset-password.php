<?php
/**
 * TBT Access override of WooCommerce myaccount/form-reset-password.php.
 *
 * Artboard 6 (Set a new password). Posts what
 * WC_Form_Handler::process_reset_password() reads: password_1, password_2,
 * reset_key, reset_login, wc_reset_password, woocommerce-reset-password-nonce.
 * In WooCommerce 11.x reset_key may carry a signed bridge token instead of
 * the raw key; it is passed through unchanged either way.
 *
 * The strength meter (tbt-access.js, WordPress core zxcvbn) disables the
 * button below "Średnie" (score 2) or while the two fields differ. Without
 * JavaScript the button stays enabled and WooCommerce's checks still apply.
 *
 * Based on WooCommerce template version 9.2.0 (and 3.5.5 in 5.5.x).
 *
 * @package TBT_Access
 */

defined( 'ABSPATH' ) || exit;

$tbt_state = tbt_access_screen_state();
$tbt_user  = get_user_by( 'login', (string) ( $args['login'] ?? '' ) );
$tbt_email = $tbt_user ? $tbt_user->user_email : (string) ( $args['login'] ?? '' );

do_action( 'woocommerce_before_reset_password_form' );
?>
<div class="tbt-access__panel">
	<?php
	tbt_access_partial(
		'hero',
		array(
			'title' => 'Nowe hasło',
			'line'  => 'Ostatni krok. Ustaw hasło i wracasz do nauki.',
			'items' => array(
				array( 'text' => 'Wpisz swój e-mail', 'mark' => 'done', 'number' => 1 ),
				array( 'text' => 'Otwórz link z wiadomości', 'mark' => 'done', 'number' => 2 ),
				array( 'text' => 'Ustaw nowe hasło', 'mark' => 'current', 'number' => 3 ),
			),
		)
	);
	?>

	<form class="tbt-access__column tbt-access__form woocommerce-ResetPassword lost_reset_password" method="post" novalidate data-tbt-reset data-tbt-min-strength="2">
		<div class="tbt-access__intro">
			<h2 class="tbt-access__heading">Ustaw nowe hasło</h2>
			<p class="tbt-access__lede">Dla konta <strong><?php echo esc_html( $tbt_email ); ?></strong></p>
		</div>

		<?php tbt_access_print_message( $tbt_state['message'] ); ?>

		<div class="tbt-access__fields">
			<div class="tbt-access__field">
				<label class="tbt-access__label" for="password_1">Nowe hasło</label>
				<?php
				tbt_access_password_field(
					array(
						'id'           => 'password_1',
						'name'         => 'password_1',
						'autocomplete' => 'new-password',
						'extra'        => ' data-tbt-strength-input aria-describedby="tbt-access-strength"',
					)
				);
				?>
				<div class="tbt-access__strength" id="tbt-access-strength" data-tbt-strength hidden>
					<div class="tbt-access__bars" aria-hidden="true">
						<span></span><span></span><span></span><span></span>
					</div>
					<p class="tbt-access__strength-hint" aria-live="polite"></p>
				</div>
			</div>

			<div class="tbt-access__field">
				<label class="tbt-access__label" for="password_2">Powtórz nowe hasło</label>
				<?php
				tbt_access_password_field(
					array(
						'id'           => 'password_2',
						'name'         => 'password_2',
						'autocomplete' => 'new-password',
						'extra'        => ' data-tbt-repeat-input',
					)
				);
				?>
			</div>
		</div>

		<input type="hidden" name="reset_key" value="<?php echo esc_attr( $args['key'] ?? '' ); ?>" />
		<input type="hidden" name="reset_login" value="<?php echo esc_attr( $args['login'] ?? '' ); ?>" />

		<?php do_action( 'woocommerce_resetpassword_form' ); ?>

		<input type="hidden" name="wc_reset_password" value="true" />
		<?php wp_nonce_field( 'reset_password', 'woocommerce-reset-password-nonce' ); ?>
		<button type="submit" class="tbt-access__button tbt-access__button--primary" value="Zapisz nowe hasło" data-tbt-reset-submit>Zapisz nowe hasło</button>
	</form>
</div>
<?php
do_action( 'woocommerce_after_reset_password_form' );
