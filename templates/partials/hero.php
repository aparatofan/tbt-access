<?php
/**
 * Tool Hero: the blue left column of every signed-out account screen.
 *
 * Expects $args:
 *   title (string)  Hero title.
 *   line  (string)  One sentence under the title.
 *   items (array)   List rows: [ 'text' => string, 'mark' => 'check'|'done'|'current'|'todo', 'number' => int ].
 *                   'check' rows are ticks; the others are the three recovery steps.
 *
 * @package TBT_Access
 */

defined( 'ABSPATH' ) || exit;

$tbt_items = isset( $args['items'] ) && is_array( $args['items'] ) ? $args['items'] : array();
$tbt_steps = ! empty( $tbt_items ) && 'check' !== ( $tbt_items[0]['mark'] ?? 'check' );
?>
<div class="tbt-access__hero">
	<div class="tbt-access__hero-head">
		<p class="tbt-access__eyebrow">THE BLUE TREE</p>
		<h1 class="tbt-access__hero-title"><?php echo esc_html( $args['title'] ?? '' ); ?></h1>
	</div>
	<p class="tbt-access__hero-line"><?php echo esc_html( $args['line'] ?? '' ); ?></p>

	<?php if ( $tbt_items ) : ?>
		<<?php echo $tbt_steps ? 'ol' : 'ul'; ?> class="tbt-access__hero-list">
			<?php foreach ( $tbt_items as $tbt_item ) : ?>
				<?php
				$tbt_mark = $tbt_item['mark'] ?? 'check';
				$tbt_aria = '';
				if ( 'current' === $tbt_mark ) {
					$tbt_aria = ' aria-current="step"';
				}
				?>
				<li class="tbt-access__hero-item tbt-access__hero-item--<?php echo esc_attr( $tbt_mark ); ?>"<?php echo $tbt_aria; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fixed string. ?>>
					<?php if ( 'check' === $tbt_mark || 'done' === $tbt_mark ) : ?>
						<?php echo tbt_access_icon( 'check', 'done' === $tbt_mark ? 26 : 22, 'tbt-access__hero-mark' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static SVG. ?>
					<?php else : ?>
						<span class="tbt-access__step" aria-hidden="true"><?php echo (int) ( $tbt_item['number'] ?? 0 ); ?></span>
					<?php endif; ?>
					<span><?php echo esc_html( $tbt_item['text'] ?? '' ); ?></span>
				</li>
			<?php endforeach; ?>
		</<?php echo $tbt_steps ? 'ol' : 'ul'; ?>>
	<?php endif; ?>

	<img class="tbt-access__tree" src="<?php echo esc_url( TBT_ACCESS_URL . 'assets/tbt-tree-white.svg' ); ?>" width="96" height="84" alt="" aria-hidden="true" />
</div>
