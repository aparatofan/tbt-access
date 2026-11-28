<?php
/**
 * Locked screen shown instead of a locked lesson (0.4.0 T5; redesigned in 0.4.3).
 *
 * A standalone page: the TBT header bar (as on /swipe/, with TBT Hub's
 * animated tree), then two columns, the lesson on the left and the offer on
 * the right (one column below 860 px). It prints its own <html> with
 * wp_head() and wp_footer(), so the styles load, but no theme template:
 * none of Divi's Theme Builder header, footer or lesson layout appears.
 *
 * Rendered by tbt_access_render_locked_screen(). Expects:
 *   $tbt_locked_post  WP_Post  The lesson.
 *   $tbt_locked_offer array    tbt_access_offer_for() (kept for compatibility).
 *   $tbt_locked_view  array    tbt_access_locked_view(): everything this page shows.
 *
 * All customer texts are in $tbt_texts below, so a wording change is a
 * one-line edit. {kontakt} is filled in at render time.
 *
 * @package TBT_Access
 */

defined( 'ABSPATH' ) || exit;

$tbt_texts = array(
	'hero_sale_line'     => 'Kurs The Blue Tree. Jednorazowa płatność, dostęp bez limitu czasu.',
	'hero_none_title'    => 'Biblioteka',
	'hero_none_line'     => 'Ponad 600 lekcji angielskiego.',
	'hero_excl_line'     => 'Kurs przydzielany przez nauczyciela.',
	'eyebrow'            => 'THE BLUE TREE',
	'back'               => 'Wróć do strony głównej',
	'pill_course'        => 'Lekcja w kursie',
	'pill_library'       => 'Lekcja z biblioteki',
	'lesson_label'       => 'Lekcja',
	'card_kicker'        => 'Ta lekcja jest częścią kursu',
	'card_note'          => 'Kupując kurs, zyskujesz dostęp do tej i pozostałych lekcji.',
	'card_button'        => 'Zobacz program kursu',
	'multi_lead_two'     => 'Ta lekcja jest dostępna w obu kursach. Wybierz ten, który bardziej Cię interesuje.',
	'multi_lead_many'    => 'Ta lekcja jest dostępna w kilku kursach. Wybierz ten, który najbardziej Cię interesuje.',
	'more_heading'       => 'Odkryj też inne kursy',
	'none_heading'       => 'Zacznij od kursu.',
	'none_text'          => 'Ta lekcja należy do biblioteki The Blue Tree. Możesz kupić jeden z kursów:',
	'none_owned_heading' => 'Ta lekcja należy do biblioteki The Blue Tree.',
	'none_owned_text'    => 'Dostęp do całej biblioteki przydziela nauczyciel – napisz na {kontakt}.',
	'excl_text'          => 'Ten kurs jest dostępny dla uczniów, którym przydzielił go nauczyciel. Jeśli to Twój kurs, zaloguj się. Jeśli jesteś zalogowany(-a) i nie masz dostępu, napisz do mnie: {kontakt}',
	'excl_button'        => 'Zaloguj się',
	'try_heading'        => 'Chcesz najpierw wypróbować lekcję?',
	'try_text'           => 'Wybierz temat i zobacz, jak wygląda nauka na The Blue Tree.',
	'signin_question'    => 'Masz już ten kurs?',
	'signin_link'        => 'Zaloguj się',
	'contact_email'      => 'hi@thebluetree.pl',
);

$tbt_post = $tbt_locked_post;
$tbt_view = wp_parse_args(
	isset( $tbt_locked_view ) && is_array( $tbt_locked_view ) ? $tbt_locked_view : array(),
	array(
		'variant'  => 'none',
		'course'   => null,
		'main'     => array(),
		'more'     => array(),
		'cards'    => array(),
		'free'     => array(),
		'products' => array(),
	)
);

$tbt_variant = in_array( $tbt_view['variant'], array( 'sale', 'none', 'exclusive' ), true ) ? $tbt_view['variant'] : 'none';
$tbt_course  = $tbt_view['course'] ? tbt_access_course( $tbt_view['course'] ) : null;
$tbt_guest   = ! is_user_logged_in();
$tbt_signin  = wp_login_url( (string) get_permalink( $tbt_post ) );

if ( 'exclusive' === $tbt_variant && ! $tbt_course ) {
	$tbt_variant = 'none';
}

// Header bar, per variant.
if ( 'exclusive' === $tbt_variant ) {
	$tbt_hero = array( $tbt_course['name'], $tbt_texts['hero_excl_line'] );
} elseif ( 'sale' === $tbt_variant && $tbt_course ) {
	$tbt_hero = array( $tbt_course['name'], $tbt_texts['hero_sale_line'] );
} else {
	// B, and A with two or more courses.
	$tbt_hero = array( $tbt_texts['hero_none_title'], $tbt_texts['hero_none_line'] );
}

// Lesson: featured image, its orientation, and the manual excerpt only.
$tbt_thumb_id = (int) get_post_thumbnail_id( $tbt_post );
$tbt_image    = $tbt_thumb_id ? get_the_post_thumbnail( $tbt_post, 'large', array( 'alt' => '' ) ) : '';
$tbt_meta     = $tbt_thumb_id ? wp_get_attachment_metadata( $tbt_thumb_id ) : array();
$tbt_portrait = is_array( $tbt_meta ) && ! empty( $tbt_meta['width'] ) && ! empty( $tbt_meta['height'] ) && (int) $tbt_meta['height'] > (int) $tbt_meta['width'];
$tbt_excerpt  = trim( wp_strip_all_tags( (string) $tbt_post->post_excerpt ) );
$tbt_pill     = 'none' === $tbt_variant ? $tbt_texts['pill_library'] : $tbt_texts['pill_course'];

/**
 * A text with {kontakt} replaced by the contact e-mail as a mailto: link.
 * antispambot() returns HTML entities, so the address goes through esc_attr()
 * / esc_html(), which keep entities, not esc_url(), which would break them.
 *
 * @param string $text Text with {kontakt}.
 * @return string HTML.
 */
$tbt_with_contact = static function ( $text ) use ( $tbt_texts ) {
	$email = antispambot( $tbt_texts['contact_email'] );
	$link  = '<a class="tbt-access__link" href="mailto:' . esc_attr( $email ) . '">' . esc_html( $email ) . '</a>';
	$parts = explode( '{kontakt}', (string) $text );

	return implode( $link, array_map( 'esc_html', $parts ) );
};

/**
 * One course card.
 *
 * @param string $key   Course key.
 * @param string $style 'featured' | 'standard' | 'more'.
 * @return void
 */
$tbt_card = static function ( $key, $style ) use ( $tbt_texts, $tbt_view ) {
	$course  = tbt_access_course( $key );
	$product = $tbt_view['products'][ $key ] ?? null;

	if ( ! $course || ! $product instanceof WC_Product ) {
		return;
	}

	$featured    = 'featured' === $style;
	$description = trim( (string) $course['description'] );
	$price       = (string) $product->get_price_html();
	$button      = 'more' === $style ? 'tbt-access__button--secondary' : 'tbt-access__button--primary';
	?>
	<article class="tbt-locked__card<?php echo $featured ? ' tbt-locked__card--featured' : ''; ?>">
		<?php if ( $featured ) : ?>
			<p class="tbt-locked__kicker"><?php echo esc_html( $tbt_texts['card_kicker'] ); ?></p>
		<?php endif; ?>
		<h3 class="tbt-locked__card-title"><?php echo esc_html( $course['name'] ); ?></h3>
		<?php if ( '' !== $description ) : ?>
			<p class="tbt-locked__card-text"><?php echo nl2br( esc_html( $description ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped, then line breaks. ?></p>
		<?php endif; ?>
		<?php if ( $featured ) : ?>
			<p class="tbt-locked__note"><?php echo esc_html( $tbt_texts['card_note'] ); ?></p>
		<?php endif; ?>
		<div class="tbt-locked__card-foot">
			<?php if ( '' !== $price ) : ?>
				<span class="tbt-locked__price"><?php echo wp_kses_post( $price ); ?></span>
			<?php endif; ?>
			<a class="tbt-access__button <?php echo esc_attr( $button ); ?> tbt-locked__button" href="<?php echo esc_url( $product->get_permalink() ); ?>"><?php echo esc_html( $tbt_texts['card_button'] ); ?></a>
		</div>
	</article>
	<?php
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
<div class="tbt-access tbt-locked">
	<div class="tbt-locked__top">
		<a class="tbt-access__link tbt-access__back tbt-locked__back" href="<?php echo esc_url( home_url( '/' ) ); ?>">
			<?php echo tbt_access_icon( 'back', 18 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
			<span><?php echo esc_html( $tbt_texts['back'] ); ?></span>
		</a>

		<header class="tbt-locked__bar">
			<div class="tbt-locked__bar-text">
				<p class="tbt-locked__eyebrow"><?php echo esc_html( $tbt_texts['eyebrow'] ); ?></p>
				<p class="tbt-locked__bar-title"><?php echo esc_html( $tbt_hero[0] ); ?></p>
				<p class="tbt-locked__bar-line"><?php echo esc_html( $tbt_hero[1] ); ?></p>
			</div>
			<?php if ( function_exists( 'tbt_hub_tree_shortcode' ) ) : ?>
				<div class="tbt-locked__bar-tree">
					<?php
					// Trusted markup from TBT Hub (inline SVG of the tree).
					echo tbt_hub_tree_shortcode( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						array(
							'width' => 'clamp(220px,16vw,280px)',
							'class' => 'tbt-locked__tree',
						)
					);
					?>
				</div>
			<?php endif; ?>
		</header>
	</div>

	<main class="tbt-locked__main">
		<section class="tbt-locked__lesson">
			<?php if ( '' !== $tbt_image ) : ?>
				<div class="tbt-locked__image<?php echo $tbt_portrait ? ' tbt-locked__image--portrait' : ''; ?>">
					<?php echo $tbt_image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- core markup. ?>
					<span class="tbt-locked__pill">
						<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>
						<span><?php echo esc_html( $tbt_pill ); ?></span>
					</span>
				</div>
			<?php endif; ?>
			<p class="tbt-locked__label"><?php echo esc_html( $tbt_texts['lesson_label'] ); ?></p>
			<h1 class="tbt-locked__title"><?php echo esc_html( get_the_title( $tbt_post ) ); ?></h1>
			<?php if ( '' !== $tbt_excerpt ) : ?>
				<p class="tbt-locked__excerpt"><?php echo nl2br( esc_html( $tbt_excerpt ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped, then line breaks. ?></p>
			<?php endif; ?>
		</section>

		<div class="tbt-locked__side">
			<?php if ( 'sale' === $tbt_variant ) : ?>

				<?php if ( 1 === count( $tbt_view['main'] ) ) : ?>
					<?php $tbt_card( $tbt_view['main'][0], 'featured' ); ?>
				<?php else : ?>
					<p class="tbt-locked__lead"><?php echo esc_html( 2 === count( $tbt_view['main'] ) ? $tbt_texts['multi_lead_two'] : $tbt_texts['multi_lead_many'] ); ?></p>
					<div class="tbt-locked__cards">
						<?php foreach ( $tbt_view['main'] as $tbt_key ) : ?>
							<?php $tbt_card( $tbt_key, 'standard' ); ?>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

			<?php elseif ( 'exclusive' === $tbt_variant ) : ?>

				<article class="tbt-locked__card tbt-locked__card--featured">
					<p class="tbt-locked__kicker"><?php echo esc_html( $tbt_texts['card_kicker'] ); ?></p>
					<h3 class="tbt-locked__card-title"><?php echo esc_html( $tbt_course['name'] ); ?></h3>
					<p class="tbt-locked__card-text"><?php echo $tbt_with_contact( $tbt_texts['excl_text'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts. ?></p>
					<?php if ( $tbt_guest ) : ?>
						<div class="tbt-locked__card-foot">
							<a class="tbt-access__button tbt-access__button--primary tbt-locked__button" href="<?php echo esc_url( $tbt_signin ); ?>"><?php echo esc_html( $tbt_texts['excl_button'] ); ?></a>
						</div>
					<?php endif; ?>
				</article>

			<?php else : ?>

				<section class="tbt-locked__box">
					<?php if ( $tbt_view['cards'] ) : ?>
						<h2 class="tbt-locked__box-heading"><?php echo esc_html( $tbt_texts['none_heading'] ); ?></h2>
						<p><?php echo esc_html( $tbt_texts['none_text'] ); ?></p>
					<?php else : ?>
						<h2 class="tbt-locked__box-heading"><?php echo esc_html( $tbt_texts['none_owned_heading'] ); ?></h2>
						<p><?php echo $tbt_with_contact( $tbt_texts['none_owned_text'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts. ?></p>
					<?php endif; ?>
				</section>
				<?php if ( $tbt_view['cards'] ) : ?>
					<div class="tbt-locked__cards">
						<?php foreach ( $tbt_view['cards'] as $tbt_key ) : ?>
							<?php $tbt_card( $tbt_key, 'standard' ); ?>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

			<?php endif; ?>

			<?php if ( 'exclusive' !== $tbt_variant ) : ?>

				<?php if ( $tbt_guest ) : ?>
					<p class="tbt-locked__signin">
						<?php echo esc_html( $tbt_texts['signin_question'] ); ?>
						<a class="tbt-access__link" href="<?php echo esc_url( $tbt_signin ); ?>"><?php echo esc_html( $tbt_texts['signin_link'] ); ?></a>
					</p>
				<?php endif; ?>

				<?php if ( $tbt_view['free'] ) : ?>
					<section class="tbt-locked__section tbt-locked__try">
						<h2 class="tbt-locked__section-heading"><?php echo esc_html( $tbt_texts['try_heading'] ); ?></h2>
						<p class="tbt-locked__section-text"><?php echo esc_html( $tbt_texts['try_text'] ); ?></p>
						<p class="tbt-locked__try-links">
							<?php
							$tbt_links = array();
							foreach ( $tbt_view['free'] as $tbt_free_id ) {
								$tbt_links[] = '<a class="tbt-access__link" href="' . esc_url( (string) get_permalink( $tbt_free_id ) ) . '">' . esc_html( get_the_title( $tbt_free_id ) ) . '</a>';
							}
							echo implode( ' · ', $tbt_links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
							?>
						</p>
					</section>
				<?php endif; ?>

				<?php if ( 'sale' === $tbt_variant && $tbt_view['more'] ) : ?>
					<section class="tbt-locked__section tbt-locked__more">
						<h2 class="tbt-locked__section-heading"><?php echo esc_html( $tbt_texts['more_heading'] ); ?></h2>
						<div class="tbt-locked__cards">
							<?php foreach ( $tbt_view['more'] as $tbt_key ) : ?>
								<?php $tbt_card( $tbt_key, 'more' ); ?>
							<?php endforeach; ?>
						</div>
					</section>
				<?php endif; ?>

			<?php endif; ?>
		</div>
	</main>
</div>
<?php wp_footer(); ?>
</body>
</html>
