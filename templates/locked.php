<?php
/**
 * Locked screen shown instead of a locked lesson (0.4.0 T5, full page since 0.4.2).
 *
 * A standalone page in the 0.2.0 sign-in layout: gradient hero with the
 * white TBT tree on the left, a card column on the right, centred on the
 * canvas. It prints its own <html> with wp_head() and wp_footer(), so the
 * styles load, but no theme template: none of Divi's Theme Builder header,
 * footer or lesson layout appears. Same page for all three variants.
 *
 * Rendered by tbt_access_render_locked_screen(). Expects:
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
	// Hero (left panel), per variant.
	'hero_sale_title' => '{name}',
	'hero_sale_line'  => 'Kurs The Blue Tree. Jednorazowa płatność, dostęp bez limitu czasu.',
	'hero_none_title' => 'Biblioteka',
	'hero_none_line'  => 'Lekcje The Blue Tree. Oferta w przygotowaniu.',
	'hero_excl_title' => '{name}',
	'hero_excl_line'  => 'Kurs przydzielany przez nauczyciela.',
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
	'back'            => 'Wróć do strony głównej',
	'lesson_label'    => 'Lekcja',
	'signin_question' => 'Masz już ten kurs?',
	'signin_link'     => 'Zaloguj się',
);

$tbt_post    = $tbt_locked_post;
$tbt_offer   = $tbt_locked_offer;
$tbt_course  = $tbt_offer['course'] ? tbt_access_course( $tbt_offer['course'] ) : null;
$tbt_name    = $tbt_course ? $tbt_course['name'] : '';
$tbt_signin  = wp_login_url( (string) get_permalink( $tbt_post ) );
$tbt_guest   = ! is_user_logged_in();
$tbt_type    = in_array( $tbt_offer['type'], array( 'sale', 'none', 'exclusive' ), true ) ? $tbt_offer['type'] : 'none';
$tbt_product = ( 'sale' === $tbt_type && $tbt_offer['product_id'] && function_exists( 'wc_get_product' ) ) ? wc_get_product( $tbt_offer['product_id'] ) : null;

if ( 'sale' === $tbt_type && ! $tbt_product instanceof WC_Product ) {
	$tbt_type = 'none';
}

$tbt_hero_key = array(
	'sale'      => 'sale',
	'none'      => 'none',
	'exclusive' => 'excl',
)[ $tbt_type ];

$tbt_fill = static fn( $text ) => str_replace( '{name}', $tbt_name, $text );

$tbt_hero = array(
	'title' => $tbt_fill( $tbt_texts[ 'hero_' . $tbt_hero_key . '_title' ] ),
	'line'  => $tbt_fill( $tbt_texts[ 'hero_' . $tbt_hero_key . '_line' ] ),
	'items' => array(),
);

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
		'<p class="tbt-locked__signin">%1$s <a class="tbt-access__link" href="%2$s">%3$s</a></p>',
		esc_html( $tbt_texts['signin_question'] ),
		esc_url( $tbt_signin ),
		esc_html( $tbt_texts['signin_link'] )
	);
};
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<main class="tbt-access tbt-locked">
	<div class="tbt-access__panel">
		<?php tbt_access_partial( 'hero', $tbt_hero ); ?>

		<div class="tbt-access__column tbt-locked__column">
			<a class="tbt-access__link tbt-access__back" href="<?php echo esc_url( home_url( '/' ) ); ?>">
				<?php echo tbt_access_icon( 'back', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
				<span><?php echo esc_html( $tbt_texts['back'] ); ?></span>
			</a>

			<div class="tbt-locked__lesson">
				<?php if ( has_post_thumbnail( $tbt_post ) ) : ?>
					<div class="tbt-locked__image">
						<?php echo get_the_post_thumbnail( $tbt_post, 'medium_large', array( 'alt' => '' ) ); ?>
						<span class="tbt-locked__lock" aria-hidden="true">
							<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" focusable="false"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
						</span>
					</div>
				<?php endif; ?>
				<p class="tbt-locked__label"><?php echo esc_html( $tbt_texts['lesson_label'] ); ?></p>
				<h2 class="tbt-access__heading tbt-locked__title"><?php echo esc_html( get_the_title( $tbt_post ) ); ?></h2>
			</div>

			<?php if ( 'sale' === $tbt_type ) : ?>

				<section class="tbt-locked__offer">
					<p class="tbt-locked__offer-heading"><?php echo esc_html( $tbt_fill( $tbt_texts['sale_heading'] ) ); ?></p>
					<?php echo $tbt_description( $tbt_course ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?>
					<div class="tbt-locked__buy">
						<span class="tbt-locked__price"><?php echo wp_kses_post( $tbt_product->get_price_html() ); ?></span>
						<a class="tbt-access__button tbt-access__button--primary tbt-locked__button" href="<?php echo esc_url( $tbt_product->get_permalink() ); ?>"><?php echo esc_html( $tbt_texts['sale_button'] ); ?></a>
					</div>
					<p class="tbt-locked__note"><?php echo esc_html( $tbt_texts['sale_note'] ); ?></p>
				</section>
				<?php if ( $tbt_guest ) : ?>
					<?php $tbt_signin_line(); ?>
				<?php endif; ?>

			<?php elseif ( 'exclusive' === $tbt_type ) : ?>

				<?php
				$tbt_email   = antispambot( (string) get_option( 'admin_email' ) );
				$tbt_contact = '<a class="tbt-access__link" href="' . esc_url( 'mailto:' . $tbt_email ) . '">' . esc_html( $tbt_email ) . '</a>';
				$tbt_parts   = explode( '{kontakt}', $tbt_texts['excl_text'], 2 );
				?>
				<section class="tbt-locked__offer">
					<p class="tbt-locked__offer-heading"><?php echo esc_html( $tbt_texts['excl_heading'] ); ?></p>
					<p class="tbt-locked__description">
						<?php
						echo esc_html( $tbt_parts[0] );
						if ( isset( $tbt_parts[1] ) ) {
							echo $tbt_contact . esc_html( $tbt_parts[1] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
						}
						?>
					</p>
					<?php if ( $tbt_guest ) : ?>
						<a class="tbt-access__button tbt-access__button--primary tbt-locked__button" href="<?php echo esc_url( $tbt_signin ); ?>"><?php echo esc_html( $tbt_texts['excl_button'] ); ?></a>
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
					if ( $tbt_p instanceof WC_Product ) {
						$tbt_cards[] = array(
							'course'  => $tbt_c,
							'product' => $tbt_p,
						);
					}
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
				<section class="tbt-locked__offer">
					<p class="tbt-locked__offer-heading"><?php echo esc_html( $tbt_texts['none_heading'] ); ?></p>
					<p class="tbt-locked__description"><?php echo esc_html( $tbt_texts['none_text'] ); ?></p>
				</section>

				<?php if ( $tbt_cards ) : ?>
					<div class="tbt-locked__cards">
						<?php foreach ( $tbt_cards as $tbt_card ) : ?>
							<article class="tbt-locked__card">
								<h3 class="tbt-locked__card-title"><?php echo esc_html( $tbt_card['course']['name'] ); ?></h3>
								<?php echo $tbt_description( $tbt_card['course'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the closure. ?>
								<div class="tbt-locked__card-foot">
									<span class="tbt-locked__price"><?php echo wp_kses_post( $tbt_card['product']->get_price_html() ); ?></span>
									<a class="tbt-access__button tbt-access__button--primary tbt-locked__button" href="<?php echo esc_url( $tbt_card['product']->get_permalink() ); ?>"><?php echo esc_html( $tbt_texts['none_button'] ); ?></a>
								</div>
							</article>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<?php if ( $tbt_free_ids ) : ?>
					<section class="tbt-locked__free">
						<p class="tbt-locked__free-title"><?php echo esc_html( $tbt_texts['none_free'] ); ?></p>
						<ul class="tbt-locked__free-list">
							<?php foreach ( $tbt_free_ids as $tbt_free_id ) : ?>
								<li><a class="tbt-access__link" href="<?php echo esc_url( (string) get_permalink( $tbt_free_id ) ); ?>"><?php echo esc_html( get_the_title( $tbt_free_id ) ); ?></a></li>
							<?php endforeach; ?>
						</ul>
					</section>
				<?php endif; ?>

				<?php if ( $tbt_guest ) : ?>
					<?php $tbt_signin_line(); ?>
				<?php endif; ?>

			<?php endif; ?>
		</div>
	</div>
</main>
<?php wp_footer(); ?>
</body>
</html>
