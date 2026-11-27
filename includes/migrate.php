<?php
/**
 * Import from WooCommerce Memberships (0.4.0 T9).
 *
 * Reads user memberships straight from the posts table (post type
 * wc_user_membership, author = member, parent = plan), so it works whether
 * Memberships is active or already deactivated. Memberships data is only
 * read, never changed.
 *
 * Phase 0.6: the only statuses in the database are wcm-active, wcm-expired,
 * wcm-cancelled and wcm-paused. Only wcm-active is imported (paused = no
 * access today). _end_date is a UTC "Y-m-d H:i:s" string, empty when the
 * membership is open-ended → lifetime (decision 9).
 *
 * Storage: option tbt_access_migration_log (last 20 runs).
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plan ID → grant key. Plans not listed are not imported
 * (366754 How to say it: course dropped, 27 Nov 2026).
 *
 * @return array<int,string>
 */
function tbt_access_migration_plan_map() {
	$map = array(
		10988 => 'library',  // VIP Student Access.
		28834 => 'library',  // VIP Teacher Access.
		28429 => 'library',  // Teacher Access.
		28833 => 'library',  // Student Access.
		10786 => 'library',
		10796 => 'library',
		10799 => 'library',
		11815 => 'course-persuasive-presentations',
		37312 => 'course-graphic-designers',
	);

	$map = apply_filters( 'tbt_access_migration_plan_map', $map );

	return is_array( $map ) ? $map : array();
}

/**
 * Is a candidate end at least as good as the current one?
 * Lifetime (null) beats any date; a later date beats an earlier one.
 *
 * @param int|null|false $current Current until (false = none).
 * @param int|null       $new     Candidate until.
 * @return bool True when $current is equal or better than $new.
 */
function tbt_access_until_covers( $current, $new ) {
	if ( false === $current ) {
		return false;
	}

	if ( null === $current ) {
		return true;
	}

	return null !== $new && (int) $current >= (int) $new;
}

/**
 * Parse a stored _end_date (UTC). Null = open-ended; false = unreadable.
 *
 * @param mixed $raw Meta value.
 * @return int|null|false
 */
function tbt_access_parse_end_date( $raw ) {
	$raw = trim( (string) $raw );

	if ( '' === $raw || '0000-00-00 00:00:00' === $raw ) {
		return null;
	}

	$date = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $raw, new DateTimeZone( 'UTC' ) );

	return $date ? $date->getTimestamp() : false;
}

/**
 * Build the import plan: one row per active membership, plus the merged grant
 * per user and key (several memberships can map to the same key).
 *
 * @return array{rows:array[],grants:array[],total:int}
 */
function tbt_access_migration_plan() {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$memberships = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT ID, post_author, post_parent FROM {$wpdb->posts} WHERE post_type = %s AND post_status = %s ORDER BY post_parent, post_author, ID",
			'wc_user_membership',
			'wcm-active'
		)
	);

	$map    = tbt_access_migration_plan_map();
	$keys   = tbt_access_grant_keys();
	$rows   = array();
	$grants = array();

	foreach ( (array) $memberships as $m ) {
		$user_id = (int) $m->post_author;
		$plan_id = (int) $m->post_parent;
		$key     = $map[ $plan_id ] ?? '';
		$until   = tbt_access_parse_end_date( get_post_meta( (int) $m->ID, '_end_date', true ) );
		$skip    = '';

		if ( '' === $key ) {
			$skip = 'plan not imported';
		} elseif ( ! isset( $keys[ $key ] ) ) {
			$skip = 'course "' . $key . '" is not defined';
		} elseif ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			$skip = 'user does not exist';
		} elseif ( false === $until ) {
			$skip = 'unreadable end date';
		}

		$row = array(
			'membership' => (int) $m->ID,
			'user_id'    => $user_id,
			'plan_id'    => $plan_id,
			'key'        => $key,
			'until'      => false === $until ? null : $until,
			'skip'       => $skip,
		);

		$rows[] = $row;

		if ( '' !== $skip ) {
			continue;
		}

		$id = $user_id . '|' . $key;

		if ( ! isset( $grants[ $id ] ) || ! tbt_access_until_covers( $grants[ $id ]['until'], $until ) ) {
			$grants[ $id ] = array(
				'user_id' => $user_id,
				'key'     => $key,
				'until'   => $until,
				'plan_id' => $plan_id,
			);
		}
	}

	// Compare each merged grant with what the user already has.
	foreach ( $grants as $id => $grant ) {
		$current                     = tbt_access_grant_until( $grant['user_id'], $grant['key'] );
		$grants[ $id ]['current']    = $current;
		$grants[ $id ]['has_better'] = tbt_access_until_covers( $current, $grant['until'] );
	}

	return array(
		'rows'   => $rows,
		'grants' => $grants,
		'total'  => count( $rows ),
	);
}

/**
 * Plan name (works with Memberships deactivated: plans are posts).
 *
 * @param int $plan_id Plan ID.
 * @return string
 */
function tbt_access_plan_name( $plan_id ) {
	$title = get_the_title( (int) $plan_id );

	return '' !== $title ? $title : '#' . (int) $plan_id;
}

/**
 * Admin text for an until value.
 *
 * @param int|null|false $until Until.
 * @return string
 */
function tbt_access_until_text( $until ) {
	if ( false === $until ) {
		return '—';
	}

	if ( null === $until ) {
		return __( 'lifetime', 'tbt-access' );
	}

	$text = tbt_access_iso_date( (int) $until );

	return (int) $until > time() ? $text : sprintf( /* translators: %s: date. */ __( 'expired %s', 'tbt-access' ), $text );
}

/**
 * Step 1: preview (no writes), with the Import button.
 *
 * @return void
 */
function tbt_access_migration_preview() {
	try {
		$plan = tbt_access_migration_plan();
	} catch ( \Throwable $e ) {
		tbt_access_log( 'migration preview failed: ' . $e->getMessage() );
		echo '<div class="notice notice-error"><p>' . esc_html__( 'The preview failed. See the PHP error log.', 'tbt-access' ) . '</p></div>';
		return;
	}

	$per_plan = array();
	foreach ( $plan['rows'] as $row ) {
		$per_plan[ $row['plan_id'] ] = ( $per_plan[ $row['plan_id'] ] ?? 0 ) + 1;
	}

	$to_write = count(
		array_filter(
			$plan['grants'],
			static fn( $g ) => ! $g['has_better']
		)
	);
	?>
	<p><?php esc_html_e( 'Preview only: nothing has been written yet.', 'tbt-access' ); ?></p>
	<p>
		<?php
		/* translators: 1: memberships, 2: grants to write. */
		echo esc_html( sprintf( __( '%1$d active memberships (wcm-active). %2$d grants will be written; the rest are skipped or already covered.', 'tbt-access' ), $plan['total'], $to_write ) );
		?>
	</p>
	<ul style="list-style:disc;margin-left:20px">
		<?php foreach ( $per_plan as $plan_id => $count ) : ?>
			<?php $key = tbt_access_migration_plan_map()[ $plan_id ] ?? ''; ?>
			<li><?php echo esc_html( sprintf( '%s (%d): %d → %s', tbt_access_plan_name( $plan_id ), $plan_id, $count, '' !== $key ? $key : __( 'not imported', 'tbt-access' ) ) ); ?></li>
		<?php endforeach; ?>
	</ul>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="tbt_access_import">
		<?php wp_nonce_field( 'tbt_access_import' ); ?>
		<?php submit_button( __( 'Import now', 'tbt-access' ), 'primary', 'submit', false, $plan['total'] ? array() : array( 'disabled' => 'disabled' ) ); ?>
	</form>

	<table class="widefat striped" style="margin-top:20px">
		<thead>
			<tr>
				<th><?php esc_html_e( 'User', 'tbt-access' ); ?></th>
				<th><?php esc_html_e( 'Plan', 'tbt-access' ); ?></th>
				<th><?php esc_html_e( 'Grant', 'tbt-access' ); ?></th>
				<th><?php esc_html_e( 'Until', 'tbt-access' ); ?></th>
				<th><?php esc_html_e( 'Already has', 'tbt-access' ); ?></th>
				<th><?php esc_html_e( 'Result', 'tbt-access' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( ! $plan['rows'] ) : ?>
			<tr><td colspan="6"><?php esc_html_e( 'No active memberships found.', 'tbt-access' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $plan['rows'] as $row ) : ?>
			<?php
			$user   = get_userdata( $row['user_id'] );
			$merged = $plan['grants'][ $row['user_id'] . '|' . $row['key'] ] ?? null;

			if ( '' !== $row['skip'] ) {
				$result = __( 'skipped: ', 'tbt-access' ) . $row['skip'];
			} elseif ( $merged && $merged['has_better'] ) {
				$result = __( 'skipped: already equal or better', 'tbt-access' );
			} elseif ( $merged && ! tbt_access_until_covers( $row['until'], $merged['until'] ) ) {
				$result = __( 'covered by another membership of this user', 'tbt-access' );
			} else {
				$result = __( 'will be written', 'tbt-access' );
			}
			?>
			<tr>
				<td>
					<?php if ( $user ) : ?>
						<a href="<?php echo esc_url( (string) get_edit_user_link( $user->ID ) ); ?>"><?php echo esc_html( $user->display_name ); ?></a><br><span class="description"><?php echo esc_html( $user->user_email ); ?></span>
					<?php else : ?>
						#<?php echo (int) $row['user_id']; ?>
					<?php endif; ?>
				</td>
				<td><?php echo esc_html( tbt_access_plan_name( $row['plan_id'] ) ); ?></td>
				<td><?php echo '' !== $row['key'] ? '<code>' . esc_html( $row['key'] ) . '</code>' : '—'; ?></td>
				<td><?php echo esc_html( tbt_access_until_text( $row['until'] ) ); ?></td>
				<td><?php echo esc_html( $merged ? tbt_access_until_text( $merged['current'] ) : '—' ); ?></td>
				<td><?php echo esc_html( $result ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

add_action( 'admin_post_tbt_access_import', 'tbt_access_import' );

/**
 * Step 2: write the grants (never shortening one) and log the run.
 *
 * @return void
 */
function tbt_access_import() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'tbt-access' ), 403 );
	}

	check_admin_referer( 'tbt_access_import' );

	$log = array(
		'time'      => time(),
		'admin'     => get_current_user_id(),
		'total'     => 0,
		'written'   => 0,
		'covered'   => 0,
		'skipped'   => 0,
		'per_plan'  => array(),
		'errors'    => 0,
	);

	try {
		$plan         = tbt_access_migration_plan();
		$log['total'] = $plan['total'];

		foreach ( $plan['rows'] as $row ) {
			if ( '' !== $row['skip'] ) {
				++$log['skipped'];
			}
		}

		foreach ( $plan['grants'] as $grant ) {
			try {
				// Read again: never shorten, even if something changed since the preview.
				$current = tbt_access_grant_until( $grant['user_id'], $grant['key'] );

				if ( tbt_access_until_covers( $current, $grant['until'] ) ) {
					++$log['covered'];
					continue;
				}

				tbt_access_set(
					$grant['user_id'],
					$grant['key'],
					$grant['until'],
					array(
						'type' => 'memberships',
						'plan' => (int) $grant['plan_id'],
						'by'   => get_current_user_id(),
					)
				);

				++$log['written'];
				$log['per_plan'][ $grant['plan_id'] ] = ( $log['per_plan'][ $grant['plan_id'] ] ?? 0 ) + 1;
			} catch ( \Throwable $e ) {
				++$log['errors'];
				tbt_access_log( sprintf( 'import of %s for user %d failed: %s', $grant['key'], $grant['user_id'], $e->getMessage() ) );
			}
		}
	} catch ( \Throwable $e ) {
		++$log['errors'];
		tbt_access_log( 'import failed: ' . $e->getMessage() );
	}

	$history   = get_option( 'tbt_access_migration_log', array() );
	$history   = is_array( $history ) ? $history : array();
	$history[] = $log;
	update_option( 'tbt_access_migration_log', array_slice( $history, -20 ), false );

	tbt_access_log( sprintf( 'Memberships import by user %d: %d memberships, %d grants written, %d already covered, %d skipped, %d errors.', $log['admin'], $log['total'], $log['written'], $log['covered'], $log['skipped'], $log['errors'] ) );

	wp_safe_redirect( tbt_access_courses_screen_url( array( 'tbt_msg' => 'imported' ) ) . '#tbt-access-log' );
	exit;
}

/**
 * The import log on the courses screen.
 *
 * @return void
 */
function tbt_access_migration_log_table() {
	$history = get_option( 'tbt_access_migration_log', array() );

	if ( ! is_array( $history ) || ! $history ) {
		echo '<p class="description" id="tbt-access-log">' . esc_html__( 'No import has been run yet.', 'tbt-access' ) . '</p>';
		return;
	}
	?>
	<h3 id="tbt-access-log"><?php esc_html_e( 'Import log', 'tbt-access' ); ?></h3>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Date', 'tbt-access' ); ?></th>
				<th><?php esc_html_e( 'Admin', 'tbt-access' ); ?></th>
				<th><?php esc_html_e( 'Memberships', 'tbt-access' ); ?></th>
				<th><?php esc_html_e( 'Written (per plan)', 'tbt-access' ); ?></th>
				<th><?php esc_html_e( 'Already covered', 'tbt-access' ); ?></th>
				<th><?php esc_html_e( 'Skipped', 'tbt-access' ); ?></th>
				<th><?php esc_html_e( 'Errors', 'tbt-access' ); ?></th>
			</tr>
		</thead>
		<tbody>
		<?php foreach ( array_reverse( $history ) as $run ) : ?>
			<?php
			$admin = get_userdata( (int) ( $run['admin'] ?? 0 ) );
			$plans = array();
			foreach ( (array) ( $run['per_plan'] ?? array() ) as $plan_id => $count ) {
				$plans[] = tbt_access_plan_name( (int) $plan_id ) . ': ' . (int) $count;
			}
			?>
			<tr>
				<td><?php echo esc_html( wp_date( 'Y-m-d H:i', (int) ( $run['time'] ?? 0 ) ) ); ?></td>
				<td><?php echo esc_html( $admin ? $admin->display_name : '#' . (int) ( $run['admin'] ?? 0 ) ); ?></td>
				<td><?php echo (int) ( $run['total'] ?? 0 ); ?></td>
				<td><?php echo (int) ( $run['written'] ?? 0 ); ?><?php echo $plans ? '<br><span class="description">' . esc_html( implode( ', ', $plans ) ) . '</span>' : ''; ?></td>
				<td><?php echo (int) ( $run['covered'] ?? 0 ); ?></td>
				<td><?php echo (int) ( $run['skipped'] ?? 0 ); ?></td>
				<td><?php echo (int) ( $run['errors'] ?? 0 ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}
