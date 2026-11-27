<?php
/**
 * Buy page shown instead of a locked lesson's content (0.4.0 T5).
 *
 * Since 0.4.1 it is the post content only: tbt_access_locked_html() renders
 * it into the_content, and the theme's own template (Divi Theme Builder
 * header, footer, title, featured image) stays around it. Expects:
 *   $tbt_locked_post  WP_Post  The lesson.
 *   $tbt_locked_offer array    tbt_access_offer_for(): type sale|none|exclusive, course, product_id.
 *
 * All customer texts are in $tbt_texts below, so a wording change is a
 * one-line edit. {name} and {kontakt} are filled in at render time.
 *
 * @package TBT_Access
 */

defined( 'ABSPATH' ) || exit;

$tbt_texts = array(
	// A — course on sale.
	'sale_heading'    => 'Ta lekcja jest częścią kursu {name}.',
	'sale_button'     => 'Kup kurs',
	'sale_note'       => 'Jednorazowa płatność, dostęp bez limitu czasu.',
	// B — not on sale yet.
	'none_heading'    => 'Oferta w przygotowaniu.',
	'none_text'       => 'Ta lekcja jest w bibliotece The Blue Tree, która nie jest jeszcze w sprzedaży. Sprawdź kursy, które możesz kupić już teraz:',
	'none_button'     => 'Zobacz kurs',
	'none_free'       => 'Darmowe lekcje z tej kategorii:',
	// C — manual course.
	'excl_heading'    => 'Ten kurs jest dostępny dla uczniów, którym przydzielił go nauczyciel.',
	'excl_text'       => 'Jeśli to Twój kurs, zaloguj się. Jeśli jesteś zalogowany(-a) i nie masz dostępu, napisz do mnie: {kontakt}',
	'excl_button'     => 'Zaloguj się',
	// Shared.
	'signin_question' => 'Masz już ten kurs?',
	'signin_link'     => 'Zaloguj się',
	'eyebrow'         => 'THE BLUE TREE',
);

$tbt_post    = $tbt_locked_post;
$tbt_offer   = $tbt_locked_offer;
$tbt_course  = $tbt_offer['course'] ? tbt_access_course( $tbt_offer['course'] ) : null;
$tbt_signin  = wp_login_url( (string) get_permalink( $tbt_post ) );
$tbt_guest   = ! is_user_logged_in();
$tbt_type    = in_array( $tbt_offer['type'], array( 'sale', 'none', 'exclusive' ), true ) ? $tbt_offer['type'] : 'none';
$tbt_product = ( 'sale' === $tbt_type && $tbt_offer['product_id'] && function_exists( 'wc_get_product' ) ) ? wc_get_product( $tbt_offer['product_id'] ) : null;

if ( 'sale' === $tbt_type && ! $tbt_product instanceof WC_Product ) {
	$tbt_type = 'none';
}

/**
 * A plain-text course description with line breaks kept.
 *
 * @param array|null $course Course record.
 * @return string HTML.
 */
$tbt_description = static function ( $course ) {
	$text = $course ? trim( (string) $course['description'] ) : '';

	return '' === $text ? '' : '<p class="tbt-locked__description">' . nl2br( esc_html( $text ) ) . '</p>';
};

$tbt_signin_line = static function () use ( $tbt_texts, $tbt_signin ) {
	printf(
		'<p class="tbt-locked__signin">%1$s <a href="%2$s">%3$s</a></p>',
		esc_html( $tbt_texts['signin_question'] ),
		esc_url( $tbt_signin ),
		esc_html( $tbt_texts['signin_link'] )
	);
};
?>
<div class="tbt-locked">
	<div class="tbt-locked__wrap">

		<?php if ( 'sale' === $tbt_type ) : ?>

			<section class="tbt-locked__panel tbt-locked__panel--sale">
				<p class="tbt-locked__eyebrow"><?php echo esc_html( $tbt_texts['eyebrow'] ); ?></p>
				<h2 class="tbt-locked__heading"><?php echo esc_html( str_replace( '{name}', $tbt_course ? $tbt_course['name'] : '', $tbt_texts['sale_heading'] ) ); ?></h2>
				<?php echo $tbt_description( $tbt_course ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?>
				<div class="tbt-locked__buy">
					<span class="tbt-locked__price"><?php echo wp_kses_post( $tbt_product->get_price_html() ); ?></span>
					<a class="tbt-locked__button tbt-locked__button--light" href="<?php echo esc_url( tbt_access_buy_url( $tbt_product->get_id() ) ); ?>" rel="nofollow"><?php echo esc_html( $tbt_texts['sale_button'] ); ?></a>
				</div>
				<p class="tbt-locked__note"><?php echo esc_html( $tbt_texts['sale_note'] ); ?></p>
				<?php if ( $tbt_guest ) : ?>
					<?php $tbt_signin_line(); ?>
				<?php endif; ?>
			</section>

		<?php elseif ( 'exclusive' === $tbt_type ) : ?>

			<?php
			$tbt_email   = (string) get_option( 'admin_email' );
			$tbt_contact = '<a href="' . esc_url( 'mailto:' . antispambot( $tbt_email ) ) . '">' . esc_html( antispambot( $tbt_email ) ) . '</a>';
			$tbt_parts   = explode( '{kontakt}', $tbt_texts['excl_text'], 2 );
			?>
			<section class="tbt-locked__panel tbt-locked__panel--exclusive">
				<p class="tbt-locked__eyebrow"><?php echo esc_html( $tbt_texts['eyebrow'] ); ?></p>
				<h2 class="tbt-locked__heading"><?php echo esc_html( $tbt_texts['excl_heading'] ); ?></h2>
				<p class="tbt-locked__description">
					<?php
					echo esc_html( $tbt_parts[0] );
					if ( isset( $tbt_parts[1] ) ) {
						echo $tbt_contact . esc_html( $tbt_parts[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
					}
					?>
				</p>
				<?php if ( $tbt_guest ) : ?>
					<div class="tbt-locked__buy">
						<a class="tbt-locked__button tbt-locked__button--light" href="<?php echo esc_url( $tbt_signin ); ?>"><?php echo esc_html( $tbt_texts['excl_button'] ); ?></a>
					</div>
				<?php endif; ?>
			</section>

		<?php else : ?>

			<?php
			// Courses on sale (non-exclusive, with a product).
			$tbt_cards = array();
			foreach ( tbt_access_courses() as $tbt_key => $tbt_c ) {
				if ( $tbt_c['exclusive'] ) {
					continue;
				}
				$tbt_pid = tbt_access_course_product( $tbt_key );
				$tbt_p   = $tbt_pid && function_exists( 'wc_get_product' ) ? wc_get_product( $tbt_pid ) : null;
				if ( ! $tbt_p instanceof WC_Product ) {
					continue;
				}
				// "Zobacz kurs" opens the course's product page (0.4.1).
				$tbt_cards[] = array(
					'course'  => $tbt_c,
					'product' => $tbt_p,
					'url'     => (string) $tbt_p->get_permalink(),
				);
			}

			// Up to 3 free lessons from the lesson's top-level category.
			$tbt_free_ids = array();
			$tbt_cats     = wp_get_post_categories( $tbt_post->ID );
			if ( $tbt_cats ) {
				$tbt_chain    = get_ancestors( (int) $tbt_cats[0], 'category', 'taxonomy' );
				$tbt_top      = $tbt_chain ? (int) end( $tbt_chain ) : (int) $tbt_cats[0];
				$tbt_free_ids = tbt_access_free_lessons( array( $tbt_top ), 3, $tbt_post->ID );
			}
			?>
			<section class="tbt-locked__panel tbt-locked__panel--none">
				<p class="tbt-locked__eyebrow"><?php echo esc_html( $tbt_texts['eyebrow'] ); ?></p>
				<h2 class="tbt-locked__heading"><?php echo esc_html( $tbt_texts['none_heading'] ); ?></h2>
				<p class="tbt-locked__description"><?php echo esc_html( $tbt_texts['none_text'] ); ?></p>
				<?php if ( $tbt_guest ) : ?>
					<?php $tbt_signin_line(); ?>
				<?php endif; ?>
			</section>

			<?php if ( $tbt_cards ) : ?>
				<div class="tbt-locked__cards">
					<?php foreach ( $tbt_cards as $tbt_card ) : ?>
						<article class="tbt-locked__card">
							<h3 class="tbt-locked__card-title"><?php echo esc_html( $tbt_card['course']['name'] ); ?></h3>
							<?php echo $tbt_description( $tbt_card['course'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?>
							<div class="tbt-locked__card-foot">
								<span class="tbt-locked__price"><?php echo wp_kses_post( $tbt_card['product']->get_price_html() ); ?></span>
								<a class="tbt-locked__button" href="<?php echo esc_url( $tbt_card['url'] ); ?>"><?php echo esc_html( $tbt_texts['none_button'] ); ?></a>
							</div>
						</article>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( $tbt_free_ids ) : ?>
				<section class="tbt-locked__free">
					<h3 class="tbt-locked__free-title"><?php echo esc_html( $tbt_texts['none_free'] ); ?></h3>
					<ul class="tbt-locked__free-list">
						<?php foreach ( $tbt_free_ids as $tbt_free_id ) : ?>
							<li><a href="<?php echo esc_url( (string) get_permalink( $tbt_free_id ) ); ?>"><?php echo esc_html( get_the_title( $tbt_free_id ) ); ?></a></li>
						<?php endforeach; ?>
					</ul>
				</section>
			<?php endif; ?>

		<?php endif; ?>

	</div>
</div>
