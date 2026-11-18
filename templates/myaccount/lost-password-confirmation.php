<?php
/**
 * TBT Access override of WooCommerce myaccount/lost-password-confirmation.php.
 *
 * Artboard 5 (Check your email). WooCommerce's own template prints an English
 * notice directly; this one does not. Both confirmation-message hooks are
 * kept.
 *
 * Based on WooCommerce template version 3.9.0.
 *
 * @package TBT_Access
 */

defined( 'ABSPATH' ) || exit;

// Clears WooCommerce's notice queue.
tbt_access_screen_state();
?>
<div class="tbt-access__panel">
	<?php
	tbt_access_partial(
		'hero',
		array(
			'title' => 'Nowe hasło',
			'line'  => 'Każdemu się zdarza. Dwie minuty i wracasz do nauki.',
			'items' => array(
				array( 'text' => 'Wpisz swój e-mail', 'mark' => 'done', 'number' => 1 ),
				array( 'text' => 'Otwórz link z wiadomości', 'mark' => 'current', 'number' => 2 ),
				array( 'text' => 'Ustaw nowe hasło', 'mark' => 'todo', 'number' => 3 ),
			),
		)
	);
	?>

	<div class="tbt-access__column">
		<div class="tbt-access__tile" aria-hidden="true">
			<?php echo tbt_access_icon( 'mail', 28 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
		</div>

		<?php do_action( 'woocommerce_before_lost_password_confirmation_message' ); ?>

		<div class="tbt-access__intro" role="status">
			<h2 class="tbt-access__heading">Sprawdź skrzynkę</h2>
			<p class="tbt-access__lede">Wysłaliśmy wiadomość z linkiem do ustawienia nowego hasła. Link działa przez 24 godziny.</p>
		</div>

		<?php do_action( 'woocommerce_after_lost_password_confirmation_message' ); ?>

		<div class="tbt-access__note">
			<strong>Nic nie przyszło?</strong>
			<span>Poczekaj chwilę i zajrzyj do folderów Spam i Oferty.</span>
			<span>Nadal nic? <a class="tbt-access__link" href="<?php echo esc_url( tbt_access_lost_password_url() ); ?>">Wyślij link ponownie</a> albo napisz na <a class="tbt-access__link" href="mailto:hi@thebluetree.pl">hi@thebluetree.pl</a>.</span>
		</div>

		<a class="tbt-access__button tbt-access__button--secondary" href="<?php echo esc_url( tbt_access_account_url() ); ?>">Wróć do logowania</a>
	</div>
</div>
