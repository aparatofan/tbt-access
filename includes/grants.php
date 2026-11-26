<?php
/**
 * Platform access passes (0.3.0).
 *
 * A pass is an ordinary one-off WooCommerce product. Buying it gives access
 * until a date, buying again adds time, and a reminder email goes out before
 * the end date. No subscriptions, no stored cards.
 *
 *   T1  Grants engine and the capability bridge.
 *   T2  Product setting: TBT Access → Grants / Duration.
 *   T3  Grant on payment (payment complete, processing, completed).
 *   T3b An account is required at checkout when the cart holds a pass.
 *   T4  Refunds and cancellations.
 *   T5  Reminder emails (daily WP-Cron).
 *   T6  My account line.
 *   T7  Admin: user profile section and Users list column.
 *
 * Storage (no custom tables):
 *   user meta    tbt_access_grants    { key: { until, orders[], updated } }
 *                                     until = UTC timestamp, or null = lifetime
 *   user meta    tbt_access_reminded  [ "platform|<until>|7", "platform|<until>|0", … ]
 *   product meta _tbt_access_grant    grant key, e.g. "platform"
 *   product meta _tbt_access_duration "1 months", "12 months", "7 days", "lifetime"
 *   order meta   _tbt_access_applied  { user_id, lines[], reversed, time }
 *
 * Rules: grants only ever ADD capabilities (never remove one), expiry is a
 * timestamp comparison at request time (cron only sends emails), and every
 * error is logged and swallowed so checkout, sign-in and pages never break.
 * Orders are read and written only through WC_Order methods (HPOS-safe).
 *
 * Customer-facing text is Polish; admin text is English.
 * Checked against WooCommerce 5.5.4 and 11.3.0.
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Shared helpers
 * ---------------------------------------------------------------------- */

/**
 * Log an error to the PHP error log. Never throws.
 *
 * @param string $message Message.
 * @return void
 */
function tbt_access_log( $message ) {
	try {
		error_log( 'TBT Access: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		// Nothing else to do.
	}
}

/**
 * Polish date with a genitive month, e.g. "26 stycznia 2027".
 *
 * Hard-coded months: the site locale is en_GB, so date_i18n() would print
 * English month names.
 *
 * @param int $timestamp UTC timestamp.
 * @return string
 */
function tbt_access_pl_date( $timestamp ) {
	$months = array(
		1  => 'stycznia',
		2  => 'lutego',
		3  => 'marca',
		4  => 'kwietnia',
		5  => 'maja',
		6  => 'czerwca',
		7  => 'lipca',
		8  => 'sierpnia',
		9  => 'września',
		10 => 'października',
		11 => 'listopada',
		12 => 'grudnia',
	);

	$timestamp = (int) $timestamp;

	return wp_date( 'j', $timestamp ) . ' ' . $months[ (int) wp_date( 'n', $timestamp ) ] . ' ' . wp_date( 'Y', $timestamp );
}

/**
 * ISO date (site timezone) for admin screens and order notes.
 *
 * @param int $timestamp UTC timestamp.
 * @return string
 */
function tbt_access_iso_date( $timestamp ) {
	return (string) wp_date( 'Y-m-d', (int) $timestamp );
}

/**
 * End of a calendar day (23:59:59, site timezone) as a UTC timestamp.
 *
 * Used for dates set by hand on the profile: "until 26 January" means access
 * through the whole of that day.
 *
 * @param string $ymd Date as Y-m-d.
 * @return int|false
 */
function tbt_access_end_of_day( $ymd ) {
	$ymd  = (string) $ymd;
	$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, wp_timezone() );

	if ( ! $date || $date->format( 'Y-m-d' ) !== $ymd ) {
		return false;
	}

	return $date->setTime( 23, 59, 59 )->getTimestamp();
}

/* -------------------------------------------------------------------------
 * T1 — Grants engine
 * ---------------------------------------------------------------------- */

/**
 * Known grant keys and their admin labels.
 *
 * 0.4.0 adds library and course keys through the filter.
 *
 * @return array<string,string>
 */
function tbt_access_grant_keys() {
	$keys = array( 'platform' => __( 'Platform access', 'tbt-access' ) );
	$keys = apply_filters( 'tbt_access_grant_keys', $keys );

	return is_array( $keys ) ? $keys : array( 'platform' => __( 'Platform access', 'tbt-access' ) );
}

/**
 * Admin label of a grant key.
 *
 * @param string $key Grant key.
 * @return string
 */
function tbt_access_grant_label( $key ) {
	$keys = tbt_access_grant_keys();

	return isset( $keys[ $key ] ) ? (string) $keys[ $key ] : (string) $key;
}

/**
 * All grants of a user, normalised. Cached per request.
 *
 * @param int  $user_id User ID.
 * @param bool $flush   Drop the cached copy (after a write) and return [].
 * @return array<string,array{until:int|null,orders:int[],updated:int}>
 */
function tbt_access_get_grants( $user_id, $flush = false ) {
	static $cache = array();

	$user_id = (int) $user_id;

	if ( $flush ) {
		unset( $cache[ $user_id ] );
		return array();
	}

	if ( isset( $cache[ $user_id ] ) ) {
		return $cache[ $user_id ];
	}

	$out = array();

	if ( $user_id > 0 ) {
		$raw = get_user_meta( $user_id, 'tbt_access_grants', true );

		if ( is_array( $raw ) ) {
			foreach ( $raw as $key => $grant ) {
				if ( ! is_string( $key ) || ! is_array( $grant ) || ! array_key_exists( 'until', $grant ) ) {
					continue;
				}

				$out[ $key ] = array(
					'until'   => null === $grant['until'] ? null : (int) $grant['until'],
					'orders'  => array_values( array_filter( array_map( 'intval', (array) ( $grant['orders'] ?? array() ) ) ) ),
					'updated' => (int) ( $grant['updated'] ?? 0 ),
				);
			}
		}
	}

	$cache[ $user_id ] = $out;

	return $out;
}

/**
 * Store a user's grants and drop the request cache.
 *
 * @param int   $user_id User ID.
 * @param array $grants  Output-shaped array from tbt_access_get_grants().
 * @return void
 */
function tbt_access_save_grants( $user_id, array $grants ) {
	$user_id = (int) $user_id;

	if ( $grants ) {
		update_user_meta( $user_id, 'tbt_access_grants', $grants );
	} else {
		delete_user_meta( $user_id, 'tbt_access_grants' );
	}

	tbt_access_get_grants( $user_id, true );
}

/**
 * The stored end of a grant.
 *
 * @param int    $user_id User ID.
 * @param string $key     Grant key.
 * @return int|null|false Timestamp, null for lifetime, false for no grant.
 */
function tbt_access_grant_until( $user_id, $key ) {
	try {
		$grants = tbt_access_get_grants( (int) $user_id );

		return isset( $grants[ $key ] ) ? $grants[ $key ]['until'] : false;
	} catch ( \Throwable $e ) {
		tbt_access_log( 'reading grants failed: ' . $e->getMessage() );
		return false;
	}
}

/**
 * Whether a grant is active right now (lifetime, or until is in the future).
 *
 * @param int    $user_id User ID.
 * @param string $key     Grant key.
 * @return bool
 */
function tbt_access_has_grant( $user_id, $key ) {
	$until = tbt_access_grant_until( $user_id, $key );

	if ( false === $until ) {
		return false;
	}

	return null === $until || $until > time();
}

/**
 * Parse a duration string.
 *
 * Valid: "N days", "N months", "N years" with N from 1 to 999, or "lifetime".
 *
 * @param mixed $duration Duration string.
 * @return array{lifetime:bool,n:int,unit:string}|null Null when invalid.
 */
function tbt_access_parse_duration( $duration ) {
	$duration = strtolower( trim( (string) $duration ) );

	if ( 'lifetime' === $duration ) {
		return array(
			'lifetime' => true,
			'n'        => 0,
			'unit'     => 'lifetime',
		);
	}

	if ( preg_match( '/^([1-9][0-9]{0,2})\s+(days|months|years)$/', $duration, $m ) ) {
		return array(
			'lifetime' => false,
			'n'        => (int) $m[1],
			'unit'     => $m[2],
		);
	}

	return null;
}

/**
 * Move a timestamp by N calendar units in the site timezone.
 *
 * @param int    $timestamp UTC timestamp.
 * @param int    $n         Positive to add, negative to subtract.
 * @param string $unit      days|months|years.
 * @return int
 */
function tbt_access_shift( $timestamp, $n, $unit ) {
	$date  = ( new DateTimeImmutable( '@' . (int) $timestamp ) )->setTimezone( wp_timezone() );
	$moved = $date->modify( sprintf( '%+d %s', (int) $n, $unit ) );

	return $moved ? $moved->getTimestamp() : (int) $timestamp;
}

/**
 * Extend a grant by a duration (decision 5).
 *
 * New until = later of (now, current until) + duration. "lifetime" sets
 * until to null, and nothing here ever shortens a lifetime grant.
 *
 * @param int    $user_id  User ID.
 * @param string $key      Grant key.
 * @param string $duration Duration string.
 * @param int    $order_id Order that paid for it (recorded in orders[]).
 * @return int|null The new until (null = lifetime). Unchanged on error.
 */
function tbt_access_extend( $user_id, $key, $duration, $order_id = 0 ) {
	$user_id = (int) $user_id;
	$parsed  = tbt_access_parse_duration( $duration );

	if ( $user_id <= 0 || ! $parsed ) {
		tbt_access_log( sprintf( 'extend skipped: user %d, key "%s", duration "%s".', $user_id, $key, $duration ) );
		$until = tbt_access_grant_until( $user_id, $key );
		return false === $until ? null : $until;
	}

	$grants  = tbt_access_get_grants( $user_id );
	$current = $grants[ $key ] ?? array(
		'until'   => false,
		'orders'  => array(),
		'updated' => 0,
	);

	$already_lifetime = isset( $grants[ $key ] ) && null === $grants[ $key ]['until'];

	if ( $parsed['lifetime'] || $already_lifetime ) {
		$until = null;
	} else {
		$base  = max( time(), (int) $current['until'] );
		$until = tbt_access_shift( $base, $parsed['n'], $parsed['unit'] );
	}

	$orders = $current['orders'];
	$order_id = (int) $order_id;

	if ( $order_id > 0 && ! in_array( $order_id, $orders, true ) ) {
		$orders[] = $order_id;
	}

	$grants[ $key ] = array(
		'until'   => $until,
		'orders'  => $orders,
		'updated' => time(),
	);

	tbt_access_save_grants( $user_id, $grants );

	return $until;
}

/**
 * Admin override: set a grant to an exact end, lifetime, or remove it.
 *
 * @param int            $user_id User ID.
 * @param string         $key     Grant key.
 * @param int|null|false $until   Timestamp, null = lifetime, false = remove.
 * @return void
 */
function tbt_access_set( $user_id, $key, $until ) {
	try {
		$user_id = (int) $user_id;

		if ( $user_id <= 0 ) {
			return;
		}

		$grants = tbt_access_get_grants( $user_id );

		if ( false === $until ) {
			unset( $grants[ $key ] );
		} else {
			$grants[ $key ] = array(
				'until'   => null === $until ? null : (int) $until,
				'orders'  => $grants[ $key ]['orders'] ?? array(),
				'updated' => time(),
			);
		}

		tbt_access_save_grants( $user_id, $grants );
	} catch ( \Throwable $e ) {
		tbt_access_log( 'set failed: ' . $e->getMessage() );
	}
}

/**
 * Capabilities a platform grant supplies (decision 2).
 *
 * The `teacher` role's capabilities whose names start with "tbt", read at
 * runtime, plus `manage_tbt_notes`. Notes gives that one to individual users
 * (TBT Notes → Teacher usernames), not to the role, and Notes, Points,
 * Results, the Homework queue and Hub's "Teacher Tools" menu all check it.
 *
 * @return string[]
 */
function tbt_access_platform_caps() {
	static $from_role = null;

	if ( null === $from_role ) {
		$from_role = array();
		$role      = get_role( 'teacher' );

		if ( $role ) {
			foreach ( (array) $role->capabilities as $cap => $granted ) {
				if ( $granted && str_starts_with( (string) $cap, 'tbt' ) ) {
					$from_role[] = (string) $cap;
				}
			}
		} elseif ( false === get_transient( 'tbt_access_no_teacher_role' ) ) {
			// Log once a day, not on every request.
			set_transient( 'tbt_access_no_teacher_role', 1, DAY_IN_SECONDS );
			tbt_access_log( 'the "teacher" role is missing, so platform passes only supply manage_tbt_notes.' );
		}
	}

	$caps = array_values( array_unique( array_merge( $from_role, array( 'manage_tbt_notes' ) ) ) );
	$caps = apply_filters( 'tbt_access_platform_caps', $caps );

	return is_array( $caps ) ? array_values( array_filter( $caps, 'is_string' ) ) : array();
}

add_filter( 'user_has_cap', 'tbt_access_grant_caps', 20, 4 );

/**
 * Capability bridge: an active platform grant adds the platform capabilities.
 *
 * Never sets anything to false (decision 3), so access set up by hand or
 * through a role is never taken away.
 *
 * @param array   $allcaps All capabilities of the user.
 * @param array   $caps    Primitive capabilities being checked.
 * @param array   $args    Arguments of the check.
 * @param WP_User $user    The user.
 * @return array
 */
function tbt_access_grant_caps( $allcaps, $caps, $args, $user ) {
	try {
		$user_id = isset( $user->ID ) ? (int) $user->ID : 0;

		if ( $user_id <= 0 || ! is_array( $allcaps ) ) {
			return $allcaps;
		}

		$platform = tbt_access_platform_caps();

		// Only look the grant up when the check is about a platform capability.
		if ( ! array_intersect( (array) $caps, $platform ) ) {
			return $allcaps;
		}

		if ( tbt_access_has_grant( $user_id, 'platform' ) ) {
			foreach ( $platform as $cap ) {
				$allcaps[ $cap ] = true;
			}
		}
	} catch ( \Throwable $e ) {
		tbt_access_log( 'capability bridge failed: ' . $e->getMessage() );
	}

	return $allcaps;
}

/* -------------------------------------------------------------------------
 * T2 — Product setting
 * ---------------------------------------------------------------------- */

/**
 * The grant a product sells, checking the parent of a variation too.
 *
 * @param WC_Product $product Product or variation.
 * @return array{key:string,duration:string,product_id:int}|null
 */
function tbt_access_product_grant( $product ) {
	if ( ! $product instanceof WC_Product ) {
		return null;
	}

	$candidates = array( $product );

	if ( $product->is_type( 'variation' ) && $product->get_parent_id() ) {
		$parent = wc_get_product( $product->get_parent_id() );

		if ( $parent instanceof WC_Product ) {
			$candidates[] = $parent;
		}
	}

	$keys = tbt_access_grant_keys();

	foreach ( $candidates as $candidate ) {
		$key      = (string) $candidate->get_meta( '_tbt_access_grant' );
		$duration = (string) $candidate->get_meta( '_tbt_access_duration' );

		if ( '' !== $key && isset( $keys[ $key ] ) && tbt_access_parse_duration( $duration ) ) {
			return array(
				'key'        => $key,
				'duration'   => $duration,
				'product_id' => (int) $candidate->get_id(),
			);
		}
	}

	return null;
}

add_action( 'woocommerce_product_options_general_product_data', 'tbt_access_product_fields' );

/**
 * Print the TBT Access group on Product data → General (virtual products).
 *
 * @return void
 */
function tbt_access_product_fields() {
	global $product_object;

	$product  = $product_object instanceof WC_Product ? $product_object : wc_get_product( get_the_ID() );
	$grant    = $product ? (string) $product->get_meta( '_tbt_access_grant' ) : '';
	$parsed   = $product ? tbt_access_parse_duration( $product->get_meta( '_tbt_access_duration' ) ) : null;
	$n        = $parsed && ! $parsed['lifetime'] ? $parsed['n'] : 1;
	$unit     = $parsed ? $parsed['unit'] : 'months';
	$options  = array( '' => __( '— none —', 'tbt-access' ) ) + tbt_access_grant_keys();
	$units    = array(
		'days'     => __( 'days', 'tbt-access' ),
		'months'   => __( 'months', 'tbt-access' ),
		'years'    => __( 'years', 'tbt-access' ),
		'lifetime' => __( 'lifetime', 'tbt-access' ),
	);

	echo '<div class="options_group show_if_virtual tbt-access-product">';
	echo '<p class="form-field"><strong>' . esc_html__( 'TBT Access', 'tbt-access' ) . '</strong></p>';

	woocommerce_wp_select(
		array(
			'id'          => '_tbt_access_grant',
			'label'       => __( 'Grants', 'tbt-access' ),
			'options'     => $options,
			'value'       => $grant,
			'desc_tip'    => true,
			'description' => __( 'What buying this product gives the customer. Buying again adds the duration to what they already have.', 'tbt-access' ),
		)
	);
	?>
	<p class="form-field tbt-access-duration-field">
		<label for="_tbt_access_duration_n"><?php esc_html_e( 'Duration', 'tbt-access' ); ?></label>
		<input type="number" id="_tbt_access_duration_n" name="_tbt_access_duration_n" value="<?php echo esc_attr( (string) $n ); ?>" min="1" max="999" step="1" style="width:6em;<?php echo 'lifetime' === $unit ? 'display:none;' : ''; ?>">
		<select id="_tbt_access_duration_unit" name="_tbt_access_duration_unit" style="width:auto;">
			<?php foreach ( $units as $value => $label ) : ?>
				<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $unit, $value ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
	</p>
	<script>
	( function () {
		var unit = document.getElementById( '_tbt_access_duration_unit' );
		var n = document.getElementById( '_tbt_access_duration_n' );
		if ( ! unit || ! n ) { return; }
		unit.addEventListener( 'change', function () {
			n.style.display = 'lifetime' === unit.value ? 'none' : '';
		} );
	} )();
	</script>
	<?php
	echo '</div>';
}

add_action( 'woocommerce_admin_process_product_object', 'tbt_access_save_product_fields' );

/**
 * Save the TBT Access fields through the product object.
 *
 * WooCommerce has already verified its meta box nonce before this action.
 * An invalid value saves as none and leaves an admin notice.
 *
 * @param WC_Product $product Product being saved.
 * @return void
 */
function tbt_access_save_product_fields( $product ) {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by WooCommerce (woocommerce_meta_nonce).
	try {
		if ( ! $product instanceof WC_Product || ! isset( $_POST['_tbt_access_grant'] ) ) {
			return;
		}

		$grant = sanitize_key( wp_unslash( $_POST['_tbt_access_grant'] ) );
		$unit  = isset( $_POST['_tbt_access_duration_unit'] ) ? sanitize_key( wp_unslash( $_POST['_tbt_access_duration_unit'] ) ) : '';
		$n     = isset( $_POST['_tbt_access_duration_n'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['_tbt_access_duration_n'] ) ) ) : '';

		if ( '' === $grant ) {
			$product->delete_meta_data( '_tbt_access_grant' );
			$product->delete_meta_data( '_tbt_access_duration' );
			return;
		}

		$duration = 'lifetime' === $unit ? 'lifetime' : $n . ' ' . $unit;
		$keys     = tbt_access_grant_keys();
		$problem  = '';

		if ( ! isset( $keys[ $grant ] ) ) {
			$problem = __( 'TBT Access: unknown grant. The product was saved without a grant.', 'tbt-access' );
		} elseif ( ! tbt_access_parse_duration( $duration ) ) {
			$problem = __( 'TBT Access: the duration must be a whole number from 1 to 999 with days, months or years, or lifetime. The product was saved without a grant.', 'tbt-access' );
		} elseif ( ! $product->is_virtual() ) {
			$problem = __( 'TBT Access: only virtual products can grant access. Tick "Virtual" and save again. The product was saved without a grant.', 'tbt-access' );
		}

		if ( '' !== $problem ) {
			$product->delete_meta_data( '_tbt_access_grant' );
			$product->delete_meta_data( '_tbt_access_duration' );
			set_transient( 'tbt_access_product_notice_' . get_current_user_id(), $problem, 5 * MINUTE_IN_SECONDS );
			return;
		}

		$product->update_meta_data( '_tbt_access_grant', $grant );
		$product->update_meta_data( '_tbt_access_duration', $duration );
	} catch ( \Throwable $e ) {
		tbt_access_log( 'saving product fields failed: ' . $e->getMessage() );
	}
	// phpcs:enable
}

add_action( 'admin_notices', 'tbt_access_product_notice' );

/**
 * Show the "saved without a grant" notice after a product save.
 *
 * @return void
 */
function tbt_access_product_notice() {
	$name    = 'tbt_access_product_notice_' . get_current_user_id();
	$message = get_transient( $name );

	if ( ! is_string( $message ) || '' === $message ) {
		return;
	}

	delete_transient( $name );
	echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
}

/* -------------------------------------------------------------------------
 * T3 — Grant on payment
 * ---------------------------------------------------------------------- */

// Processing too: a virtual (not downloadable) product never auto-completes,
// and a bank transfer marked "Processing" by hand fires neither of the others.
add_action( 'woocommerce_payment_complete', 'tbt_access_order_paid', 20, 1 );
add_action( 'woocommerce_order_status_processing', 'tbt_access_order_paid', 20, 1 );
add_action( 'woocommerce_order_status_completed', 'tbt_access_order_paid', 20, 1 );

/**
 * Hook wrapper for T3. Never throws.
 *
 * @param int $order_id Order ID.
 * @return void
 */
function tbt_access_order_paid( $order_id ) {
	try {
		tbt_access_apply_order( (int) $order_id );
	} catch ( \Throwable $e ) {
		tbt_access_log( sprintf( 'applying order %d failed: %s', (int) $order_id, $e->getMessage() ) );
	}
}

/**
 * The grant lines of an order.
 *
 * @param WC_Order $order Order.
 * @return array<int,array{key:string,duration:string,product_id:int,qty:int}>
 */
function tbt_access_order_grant_lines( $order ) {
	$lines = array();

	foreach ( $order->get_items( 'line_item' ) as $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			continue;
		}

		$grant = tbt_access_product_grant( $item->get_product() );

		if ( $grant ) {
			$grant['qty'] = max( 1, (int) $item->get_quantity() );
			$lines[]      = $grant;
		}
	}

	return $lines;
}

/**
 * Take a short per-order lock, so two requests (gateway callback and the
 * thank-you page, say) can't both extend the grant.
 *
 * INSERT IGNORE on the unique option_name is atomic; add_option() is not
 * (it uses ON DUPLICATE KEY UPDATE). A lock older than five minutes is stale.
 *
 * @param int $order_id Order ID.
 * @return bool True when the lock is held.
 */
function tbt_access_lock( $order_id ) {
	global $wpdb;

	$name = 'tbt_access_lock_' . (int) $order_id;

	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	$taken = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, (string) time() ) );

	if ( 1 === (int) $taken ) {
		return true;
	}

	$since = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );

	if ( $since > 0 && $since < time() - 5 * MINUTE_IN_SECONDS ) {
		$wpdb->update( $wpdb->options, array( 'option_value' => (string) time() ), array( 'option_name' => $name ) );
		return true;
	}
	// phpcs:enable

	return false;
}

/**
 * Release a per-order lock.
 *
 * @param int $order_id Order ID.
 * @return void
 */
function tbt_access_unlock( $order_id ) {
	global $wpdb;

	$wpdb->delete( $wpdb->options, array( 'option_name' => 'tbt_access_lock_' . (int) $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}

/**
 * Apply an order's grants once (idempotent via _tbt_access_applied).
 *
 * @param int $order_id Order ID.
 * @return void
 */
function tbt_access_apply_order( $order_id ) {
	if ( ! function_exists( 'wc_get_order' ) ) {
		return;
	}

	$order = wc_get_order( $order_id );

	if ( ! $order instanceof WC_Order || $order->get_meta( '_tbt_access_applied' ) ) {
		return;
	}

	$lines = tbt_access_order_grant_lines( $order );

	if ( ! $lines || ! tbt_access_lock( $order_id ) ) {
		return;
	}

	try {
		// Read again under the lock: another request may have just applied it.
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order || $order->get_meta( '_tbt_access_applied' ) ) {
			return;
		}

		$user_id = (int) $order->get_customer_id();

		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			$order->add_order_note( "TBT Access: order has no customer account — grant not applied. Set it by hand on the user's profile." );
			$order->update_meta_data(
				'_tbt_access_applied',
				array(
					'user_id'  => 0,
					'lines'    => array(),
					'skipped'  => 'no-customer',
					'reversed' => false,
					'time'     => time(),
				)
			);
			$order->save();
			return;
		}

		$applied = array();

		foreach ( $lines as $line ) {
			$before = tbt_access_grant_until( $user_id, $line['key'] );
			$after  = $before;

			for ( $i = 0; $i < $line['qty']; $i++ ) {
				$after = tbt_access_extend( $user_id, $line['key'], $line['duration'], (int) $order_id );
			}

			$line['until_before'] = $before;
			$line['until_after']  = $after;
			$applied[]            = $line;

			$order->add_order_note( tbt_access_applied_note( $line ) );
		}

		$order->update_meta_data(
			'_tbt_access_applied',
			array(
				'user_id'  => $user_id,
				'lines'    => $applied,
				'reversed' => false,
				'time'     => time(),
			)
		);
		$order->save();
	} finally {
		tbt_access_unlock( $order_id );
	}
}

/**
 * Order note for an applied line, e.g.
 * "TBT Access: platform access until 2027-01-26 (+1 months)."
 *
 * @param array $line Applied line.
 * @return string
 */
function tbt_access_applied_note( array $line ) {
	$label = strtolower( tbt_access_grant_label( $line['key'] ) );

	if ( null === $line['until_after'] ) {
		if ( 'lifetime' !== $line['duration'] && null === $line['until_before'] ) {
			return sprintf( 'TBT Access: %s — lifetime (already lifetime before this order, so +%s changed nothing).', $label, $line['duration'] );
		}

		return sprintf( 'TBT Access: %s — lifetime.', $label );
	}

	$times = $line['qty'] > 1 ? ' ×' . $line['qty'] : '';

	return sprintf( 'TBT Access: %1$s until %2$s (+%3$s%4$s).', $label, tbt_access_iso_date( (int) $line['until_after'] ), $line['duration'], $times );
}

/* -------------------------------------------------------------------------
 * T3b — An account is required when the cart holds a pass (decision 12)
 * ---------------------------------------------------------------------- */

/**
 * Whether the cart holds a product that grants access.
 *
 * @return bool
 */
function tbt_access_cart_has_grant() {
	if ( ! did_action( 'wp_loaded' ) || ! function_exists( 'WC' ) ) {
		return false;
	}

	$wc = WC();

	if ( ! $wc || empty( $wc->cart ) ) {
		return false;
	}

	foreach ( $wc->cart->get_cart() as $item ) {
		if ( isset( $item['data'] ) && tbt_access_product_grant( $item['data'] ) ) {
			return true;
		}
	}

	return false;
}

add_filter( 'woocommerce_checkout_registration_required', 'tbt_access_require_account_for_pass', 20 );
add_filter( 'woocommerce_checkout_registration_enabled', 'tbt_access_require_account_for_pass', 20 );

/**
 * Guest checkout stays on for everything else, but a pass is attached to an
 * account, so a signed-out buyer with a pass in the cart must create one.
 *
 * Both filters are read by classic checkout (form and processing) and by the
 * block checkout and Store API in 11.x, so one pair covers all of them.
 * "Enabled" is forced too: required-but-disabled would show WooCommerce's
 * "You must be logged in to checkout" instead of the account fields.
 *
 * @param mixed $value Value from the store settings.
 * @return mixed
 */
function tbt_access_require_account_for_pass( $value ) {
	try {
		if ( ! is_user_logged_in() && tbt_access_cart_has_grant() ) {
			return true;
		}
	} catch ( \Throwable $e ) {
		tbt_access_log( 'checkout account check failed: ' . $e->getMessage() );
	}

	return $value;
}

add_action( 'woocommerce_before_checkout_registration_form', 'tbt_access_checkout_account_note' );

/**
 * Explain the account requirement above the account fields (classic checkout).
 *
 * @return void
 */
function tbt_access_checkout_account_note() {
	try {
		if ( is_user_logged_in() || ! tbt_access_cart_has_grant() ) {
			return;
		}

		$login = add_query_arg( 'redirect_to', rawurlencode( wc_get_checkout_url() ), tbt_access_account_url() );

		printf(
			'<p class="tbt-access-checkout-note">%1$s <a href="%2$s">%3$s</a></p>',
			esc_html( 'Dostęp do platformy jest przypisany do konta, dlatego przy tym zamówieniu założymy je dla Ciebie.' ),
			esc_url( $login ),
			esc_html( 'Masz już konto? Zaloguj się.' )
		);
	} catch ( \Throwable $e ) {
		tbt_access_log( 'checkout note failed: ' . $e->getMessage() );
	}
}

/* -------------------------------------------------------------------------
 * T4 — Refunds and cancellations
 * ---------------------------------------------------------------------- */

add_action( 'woocommerce_order_status_refunded', 'tbt_access_order_reversed', 20, 1 );
add_action( 'woocommerce_order_status_cancelled', 'tbt_access_order_reversed', 20, 1 );

/**
 * Hook wrapper for T4. Never throws.
 *
 * @param int $order_id Order ID.
 * @return void
 */
function tbt_access_order_reversed( $order_id ) {
	try {
		tbt_access_reverse_order( (int) $order_id );
	} catch ( \Throwable $e ) {
		tbt_access_log( sprintf( 'reversing order %d failed: %s', (int) $order_id, $e->getMessage() ) );
	}
}

/**
 * Undo what an order granted, once.
 *
 * Timed lines: subtract the duration (× quantity) from the current until,
 * never earlier than now. Lifetime lines: remove the grant only if this order
 * is what made it lifetime. A lifetime grant is never shortened by a timed
 * refund.
 *
 * @param int $order_id Order ID.
 * @return void
 */
function tbt_access_reverse_order( $order_id ) {
	if ( ! function_exists( 'wc_get_order' ) ) {
		return;
	}

	$order   = wc_get_order( $order_id );
	$applied = $order instanceof WC_Order ? $order->get_meta( '_tbt_access_applied' ) : null;

	if ( ! is_array( $applied ) || ! empty( $applied['reversed'] ) || empty( $applied['lines'] ) || ! tbt_access_lock( $order_id ) ) {
		return;
	}

	try {
		$order   = wc_get_order( $order_id );
		$applied = $order instanceof WC_Order ? $order->get_meta( '_tbt_access_applied' ) : null;

		if ( ! is_array( $applied ) || ! empty( $applied['reversed'] ) || empty( $applied['lines'] ) ) {
			return;
		}

		$user_id = (int) ( $applied['user_id'] ?? 0 );
		$status  = $order->get_status();

		foreach ( array_reverse( (array) $applied['lines'] ) as $line ) {
			$key    = (string) ( $line['key'] ?? '' );
			$label  = strtolower( tbt_access_grant_label( $key ) );
			$parsed = tbt_access_parse_duration( $line['duration'] ?? '' );

			if ( '' === $key || ! $parsed ) {
				continue;
			}

			$current = tbt_access_grant_until( $user_id, $key );

			if ( false === $current ) {
				$order->add_order_note( sprintf( 'TBT Access: order %1$s — the user has no %2$s any more, nothing to change.', $status, $label ) );
				continue;
			}

			if ( $parsed['lifetime'] ) {
				$made_lifetime = array_key_exists( 'until_before', $line ) && null !== $line['until_before'];

				if ( null === $current && $made_lifetime ) {
					tbt_access_set( $user_id, $key, false );
					$order->add_order_note( sprintf( "TBT Access: order %1\$s — lifetime %2\$s removed. Set it by hand on the user's profile if it should stay.", $status, $label ) );
				} else {
					$order->add_order_note( sprintf( 'TBT Access: order %1$s — %2$s not changed (this order did not make it lifetime).', $status, $label ) );
				}
				continue;
			}

			if ( null === $current ) {
				$order->add_order_note( sprintf( 'TBT Access: order %1$s — %2$s is lifetime, not changed.', $status, $label ) );
				continue;
			}

			$qty = max( 1, (int) ( $line['qty'] ?? 1 ) );
			$new = (int) $current;

			for ( $i = 0; $i < $qty; $i++ ) {
				$new = tbt_access_shift( $new, -$parsed['n'], $parsed['unit'] );
			}

			$new = max( time(), $new );
			tbt_access_set( $user_id, $key, $new );

			$times = $qty > 1 ? ' ×' . $qty : '';
			$order->add_order_note( sprintf( 'TBT Access: order %1$s — %2$s moved back to %3$s (−%4$s%5$s).', $status, $label, tbt_access_iso_date( $new ), $line['duration'], $times ) );
		}

		$applied['reversed']    = true;
		$applied['reversed_at'] = time();
		$order->update_meta_data( '_tbt_access_applied', $applied );
		$order->save();
	} finally {
		tbt_access_unlock( $order_id );
	}
}

add_action( 'woocommerce_order_partially_refunded', 'tbt_access_order_partially_refunded', 20, 1 );

/**
 * Partial refunds change nothing automatically; the note says so.
 *
 * @param int $order_id Order ID.
 * @return void
 */
function tbt_access_order_partially_refunded( $order_id ) {
	try {
		$order   = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		$applied = $order instanceof WC_Order ? $order->get_meta( '_tbt_access_applied' ) : null;

		if ( is_array( $applied ) && empty( $applied['reversed'] ) && ! empty( $applied['lines'] ) ) {
			$order->add_order_note( "TBT Access: partial refund — access not changed. Adjust it by hand on the user's profile if needed." );
		}
	} catch ( \Throwable $e ) {
		tbt_access_log( sprintf( 'partial refund note for order %d failed: %s', (int) $order_id, $e->getMessage() ) );
	}
}

/* -------------------------------------------------------------------------
 * T5 — Reminder emails
 * ---------------------------------------------------------------------- */

add_action( 'init', 'tbt_access_schedule_daily' );
add_action( 'tbt_access_daily', 'tbt_access_run_daily' );

/**
 * Schedule the daily event (activation, and self-healed on init).
 *
 * First run at the next 08:00 site time.
 *
 * @return void
 */
function tbt_access_schedule_daily() {
	try {
		if ( wp_next_scheduled( 'tbt_access_daily' ) ) {
			return;
		}

		$now   = new DateTimeImmutable( 'now', wp_timezone() );
		$first = $now->setTime( 8, 0 );

		if ( $first <= $now ) {
			$first = $first->modify( '+1 day' );
		}

		wp_schedule_event( $first->getTimestamp(), 'daily', 'tbt_access_daily' );
	} catch ( \Throwable $e ) {
		tbt_access_log( 'scheduling the daily event failed: ' . $e->getMessage() );
	}
}

/**
 * Clear the daily event (deactivation).
 *
 * @return void
 */
function tbt_access_unschedule_daily() {
	wp_clear_scheduled_hook( 'tbt_access_daily' );
}

/**
 * Daily run: send the 7-days-before and on-the-day reminders.
 *
 * @return void
 */
function tbt_access_run_daily() {
	try {
		$today = new DateTimeImmutable( 'today', wp_timezone() );
		$in_7  = $today->modify( '+7 days' )->format( 'Y-m-d' );
		$today = $today->format( 'Y-m-d' );

		$user_ids = get_users(
			array(
				'meta_key' => 'tbt_access_grants', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'fields'   => 'ID',
				'number'   => -1,
			)
		);

		foreach ( $user_ids as $user_id ) {
			try {
				tbt_access_maybe_remind( (int) $user_id, $today, $in_7 );
			} catch ( \Throwable $e ) {
				tbt_access_log( sprintf( 'reminder for user %d failed: %s', (int) $user_id, $e->getMessage() ) );
			}
		}
	} catch ( \Throwable $e ) {
		tbt_access_log( 'daily run failed: ' . $e->getMessage() );
	}
}

/**
 * Send one user's reminder if today is the day, once per grant and date.
 *
 * @param int    $user_id User ID.
 * @param string $today   Today, Y-m-d site time.
 * @param string $in_7    Today + 7 days, Y-m-d site time.
 * @return void
 */
function tbt_access_maybe_remind( $user_id, $today, $in_7 ) {
	$until = tbt_access_grant_until( $user_id, 'platform' );

	if ( ! is_int( $until ) ) {
		return; // No grant, or lifetime.
	}

	$day = tbt_access_iso_date( $until );

	if ( $day === $in_7 ) {
		$which = 7;
	} elseif ( $day === $today ) {
		$which = 0;
	} else {
		return;
	}

	$tag  = 'platform|' . $until . '|' . $which;
	$sent = get_user_meta( $user_id, 'tbt_access_reminded', true );
	$sent = is_array( $sent ) ? $sent : array();

	if ( in_array( $tag, $sent, true ) ) {
		return;
	}

	if ( tbt_access_send_reminder( $user_id, $which ) ) {
		$sent[] = $tag;
		update_user_meta( $user_id, 'tbt_access_reminded', array_slice( $sent, -20 ) );
	}
}

/**
 * Send a reminder email now.
 *
 * @param int $user_id User ID.
 * @param int $which   7 = seven days before, 0 = on the day.
 * @return bool Whether the mail was handed over for sending.
 */
function tbt_access_send_reminder( $user_id, $which ) {
	$user  = get_userdata( (int) $user_id );
	$until = tbt_access_grant_until( (int) $user_id, 'platform' );

	if ( ! $user || ! is_email( $user->user_email ) || ! is_int( $until ) ) {
		return false;
	}

	$name = trim( (string) $user->first_name );
	$name = '' !== $name ? $name : (string) $user->display_name;
	$date = '<strong>' . esc_html( tbt_access_pl_date( $until ) ) . '</strong>';
	$url  = esc_url( tbt_access_renewal_url( (int) $user_id, 'platform' ) );
	$hi   = 'Dzień dobry, ' . esc_html( $name ) . '!';
	$bye  = 'Pozdrawiam,<br>Mariusz';

	if ( 7 === (int) $which ) {
		$subject    = 'Twój dostęp do platformy TBT kończy się za 7 dni';
		$paragraphs = array(
			$hi,
			'Twój dostęp do platformy The Blue Tree (Notes i narzędzia dla nauczyciela) kończy się ' . $date . '.',
			'Przedłuż go tutaj: <strong><a href="' . $url . '">Przedłuż dostęp</a></strong>',
			'Twoje materiały nie znikają po wygaśnięciu dostępu. Wracają, gdy tylko go odnowisz.',
			$bye,
		);
	} else {
		$subject    = 'Twój dostęp do platformy TBT wygasa dziś';
		$paragraphs = array(
			$hi,
			'Dziś, ' . $date . ', kończy się Twój dostęp do platformy The Blue Tree.',
			'Możesz go odnowić w każdej chwili: <strong><a href="' . $url . '">Odnów dostęp</a></strong>',
			'Wszystko, co przygotowałeś(-aś), czeka na Ciebie.',
			$bye,
		);
	}

	$html = '<p>' . implode( '</p><p>', $paragraphs ) . '</p>';

	return tbt_access_mail( $user->user_email, $subject, $html );
}

/**
 * Send an HTML email in the shop's style, or plain HTML without WooCommerce.
 *
 * @param string $to      Recipient.
 * @param string $subject Subject.
 * @param string $html    Body HTML.
 * @return bool
 */
function tbt_access_mail( $to, $subject, $html ) {
	try {
		if ( function_exists( 'WC' ) && WC() && method_exists( WC(), 'mailer' ) ) {
			$mailer  = WC()->mailer();
			$message = $mailer->wrap_message( 'Dostęp do platformy TBT', $html );

			return (bool) $mailer->send( $to, $subject, $message );
		}
	} catch ( \Throwable $e ) {
		tbt_access_log( 'WooCommerce mailer failed, falling back to wp_mail: ' . $e->getMessage() );
	}

	return (bool) wp_mail( $to, $subject, '<html><body>' . $html . '</body></html>', array( 'Content-Type: text/html; charset=UTF-8' ) );
}

/**
 * Where to renew: the product from the most recent order that extended this
 * grant (if it is still published), otherwise /vip/.
 *
 * @param int    $user_id User ID.
 * @param string $key     Grant key.
 * @return string
 */
function tbt_access_renewal_url( $user_id, $key ) {
	try {
		$grants = tbt_access_get_grants( (int) $user_id );
		$orders = isset( $grants[ $key ] ) ? $grants[ $key ]['orders'] : array();

		foreach ( array_reverse( $orders ) as $order_id ) {
			$order   = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
			$applied = $order instanceof WC_Order ? $order->get_meta( '_tbt_access_applied' ) : null;

			if ( ! is_array( $applied ) || empty( $applied['lines'] ) ) {
				continue;
			}

			foreach ( (array) $applied['lines'] as $line ) {
				$product_id = (int) ( $line['product_id'] ?? 0 );

				if ( ( $line['key'] ?? '' ) === $key && $product_id && 'publish' === get_post_status( $product_id ) ) {
					$url = get_permalink( $product_id );

					if ( $url ) {
						return $url;
					}
				}
			}
		}
	} catch ( \Throwable $e ) {
		tbt_access_log( 'renewal link failed: ' . $e->getMessage() );
	}

	return home_url( '/vip/' );
}

/* -------------------------------------------------------------------------
 * T6 — My account line
 * ---------------------------------------------------------------------- */

add_action( 'wp_enqueue_scripts', 'tbt_access_enqueue_pass_style', 20 );

/**
 * A few inline rules for the My account line and the checkout note.
 *
 * @return void
 */
function tbt_access_enqueue_pass_style() {
	$account  = is_user_logged_in() && function_exists( 'is_account_page' ) && is_account_page();
	$checkout = ! is_user_logged_in() && function_exists( 'is_checkout' ) && is_checkout();

	if ( ! $account && ! $checkout ) {
		return;
	}

	wp_register_style( 'tbt-access-pass', false, array(), TBT_ACCESS_VERSION );
	wp_enqueue_style( 'tbt-access-pass' );
	wp_add_inline_style(
		'tbt-access-pass',
		'.tbt-access-pass{display:flex;flex-wrap:wrap;align-items:center;gap:6px 16px;margin:24px 0;padding:14px 18px;'
		. 'border:1px solid var(--tbt-border,#D8E3F0);border-left:4px solid var(--tbt-blue,#0856C9);'
		. 'border-radius:var(--tbt-radius-control,12px);background:var(--tbt-selected-bg,#EDF4FF);'
		. 'color:var(--tbt-ink,#1F2937);font-family:var(--tbt-font-ui,"Roboto",sans-serif);font-size:16px;line-height:1.5}'
		. '.tbt-access-pass--expired{border-left-color:var(--tbt-error,#C62828);background:var(--tbt-error-bg,#FFF1F1)}'
		. '.tbt-access-pass .tbt-access-pass__link{font-weight:600;color:var(--tbt-blue,#0856C9);text-decoration:underline}'
		. '.tbt-access-checkout-note{margin:0 0 12px;padding:10px 14px;border-radius:var(--tbt-radius-control,12px);'
		. 'background:var(--tbt-selected-bg,#EDF4FF);color:var(--tbt-ink,#1F2937)}'
	);
}

add_action( 'woocommerce_account_dashboard', 'tbt_access_account_pass_line' );

/**
 * "Dostęp do platformy: …" on the signed-in My account dashboard.
 *
 * Shown only to users who have or had a platform grant.
 *
 * @return void
 */
function tbt_access_account_pass_line() {
	try {
		$user_id = get_current_user_id();
		$until   = tbt_access_grant_until( $user_id, 'platform' );

		if ( $user_id <= 0 || false === $until ) {
			return;
		}

		$state = 'active';
		$link  = '';

		if ( null === $until ) {
			$text = 'Dostęp do platformy: bezterminowy';
		} elseif ( $until > time() ) {
			$text = 'Dostęp do platformy: do ' . tbt_access_pl_date( $until );
			$link = 'Przedłuż';
		} else {
			$state = 'expired';
			$text  = 'Dostęp do platformy wygasł ' . tbt_access_pl_date( $until );
			$link  = 'Odnów';
		}

		printf(
			'<div class="tbt-access-pass tbt-access-pass--%1$s" role="status"><span>%2$s</span>%3$s</div>',
			esc_attr( $state ),
			esc_html( $text ),
			'' === $link ? '' : sprintf(
				'<a class="tbt-access-pass__link" href="%1$s">%2$s</a>',
				esc_url( tbt_access_renewal_url( $user_id, 'platform' ) ),
				esc_html( $link )
			)
		);
	} catch ( \Throwable $e ) {
		tbt_access_log( 'My account line failed: ' . $e->getMessage() );
	}
}

/* -------------------------------------------------------------------------
 * T7 — Admin: user profile and Users list
 * ---------------------------------------------------------------------- */

add_action( 'show_user_profile', 'tbt_access_profile_section' );
add_action( 'edit_user_profile', 'tbt_access_profile_section' );

/**
 * "TBT Access" section on the user profile (administrators only).
 *
 * @param WP_User $user User being edited.
 * @return void
 */
function tbt_access_profile_section( $user ) {
	if ( ! $user instanceof WP_User || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$user_id  = (int) $user->ID;
	$until    = tbt_access_grant_until( $user_id, 'platform' );
	$grants   = tbt_access_get_grants( $user_id );
	$orders   = isset( $grants['platform'] ) ? $grants['platform']['orders'] : array();
	$lifetime = null === $until;
	$date     = is_int( $until ) ? tbt_access_iso_date( $until ) : '';

	if ( false === $until ) {
		$status = __( 'none', 'tbt-access' );
	} elseif ( $lifetime ) {
		$status = __( 'lifetime', 'tbt-access' );
	} elseif ( $until > time() ) {
		/* translators: %s: date. */
		$status = sprintf( __( 'until %s', 'tbt-access' ), $date );
	} else {
		/* translators: %s: date. */
		$status = sprintf( __( 'expired %s', 'tbt-access' ), $date );
	}

	$test_url = wp_nonce_url(
		add_query_arg(
			array(
				'action'  => 'tbt_access_test_reminder',
				'user_id' => $user_id,
			),
			admin_url( 'admin-post.php' )
		),
		'tbt_access_test_reminder_' . $user_id
	);
	?>
	<h2><?php esc_html_e( 'TBT Access', 'tbt-access' ); ?></h2>
	<?php wp_nonce_field( 'tbt_access_profile_' . $user_id, 'tbt_access_profile_nonce' ); ?>
	<input type="hidden" name="tbt_access_until_was" value="<?php echo esc_attr( $date ); ?>">
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><?php esc_html_e( 'Platform access', 'tbt-access' ); ?></th>
			<td>
				<p><strong><?php echo esc_html( $status ); ?></strong></p>
				<p>
					<label for="tbt_access_until"><?php esc_html_e( 'Access until (inclusive)', 'tbt-access' ); ?></label><br>
					<input type="date" id="tbt_access_until" name="tbt_access_until" value="<?php echo esc_attr( $date ); ?>" <?php disabled( $lifetime ); ?>>
				</p>
				<p>
					<label><input type="checkbox" id="tbt_access_lifetime" name="tbt_access_lifetime" value="1" <?php checked( $lifetime ); ?>> <?php esc_html_e( 'Lifetime', 'tbt-access' ); ?></label>
				</p>
				<?php if ( false !== $until ) : ?>
					<p>
						<label><input type="checkbox" name="tbt_access_remove" value="1"> <?php esc_html_e( 'Remove platform access', 'tbt-access' ); ?></label>
					</p>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( 'Only adds access. Roles and capabilities set by hand are never taken away. To shorten or end a pass, pick an earlier date (yesterday ends it now) or tick Remove.', 'tbt-access' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Orders', 'tbt-access' ); ?></th>
			<td>
				<?php
				if ( ! $orders ) {
					echo '—';
				} else {
					$links = array();
					foreach ( array_reverse( $orders ) as $order_id ) {
						$order   = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
						$links[] = $order instanceof WC_Order
							? sprintf( '<a href="%1$s">#%2$s</a> (%3$s)', esc_url( $order->get_edit_order_url() ), esc_html( $order->get_order_number() ), esc_html( wc_get_order_status_name( $order->get_status() ) ) )
							: '#' . (int) $order_id;
					}
					echo wp_kses_post( implode( ', ', $links ) );
				}
				?>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php esc_html_e( 'Reminder', 'tbt-access' ); ?></th>
			<td>
				<?php if ( is_int( $until ) ) : ?>
					<a class="button" href="<?php echo esc_url( $test_url ); ?>"><?php esc_html_e( 'Send reminder now (test)', 'tbt-access' ); ?></a>
				<?php else : ?>
					<button type="button" class="button" disabled><?php esc_html_e( 'Send reminder now (test)', 'tbt-access' ); ?></button>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( 'Sends the "7 days before" email to this user now. It is not recorded, so the scheduled reminder still goes out.', 'tbt-access' ); ?></p>
			</td>
		</tr>
	</table>
	<script>
	( function () {
		var box = document.getElementById( 'tbt_access_lifetime' );
		var date = document.getElementById( 'tbt_access_until' );
		if ( ! box || ! date ) { return; }
		box.addEventListener( 'change', function () { date.disabled = box.checked; } );
	} )();
	</script>
	<?php
}

add_action( 'personal_options_update', 'tbt_access_profile_save' );
add_action( 'edit_user_profile_update', 'tbt_access_profile_save' );

/**
 * Save the profile section through tbt_access_set().
 *
 * Remove wins over Lifetime, Lifetime wins over the date. An unchanged date
 * keeps the exact stored time; a new date means "through the end of that day".
 *
 * @param int $user_id User being saved.
 * @return void
 */
function tbt_access_profile_save( $user_id ) {
	try {
		$user_id = (int) $user_id;

		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		$nonce = isset( $_POST['tbt_access_profile_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['tbt_access_profile_nonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, 'tbt_access_profile_' . $user_id ) ) {
			return;
		}

		$current = tbt_access_grant_until( $user_id, 'platform' );

		if ( ! empty( $_POST['tbt_access_remove'] ) ) {
			tbt_access_set( $user_id, 'platform', false );
			return;
		}

		if ( ! empty( $_POST['tbt_access_lifetime'] ) ) {
			if ( null !== $current ) {
				tbt_access_set( $user_id, 'platform', null );
			}
			return;
		}

		$date = isset( $_POST['tbt_access_until'] ) ? sanitize_text_field( wp_unslash( $_POST['tbt_access_until'] ) ) : '';
		$was  = isset( $_POST['tbt_access_until_was'] ) ? sanitize_text_field( wp_unslash( $_POST['tbt_access_until_was'] ) ) : '';

		if ( '' === $date || ( $date === $was && is_int( $current ) ) ) {
			return; // Nothing new. Use Remove to take access away.
		}

		$until = tbt_access_end_of_day( $date );

		if ( false !== $until ) {
			tbt_access_set( $user_id, 'platform', $until );
		}
	} catch ( \Throwable $e ) {
		tbt_access_log( 'saving the profile section failed: ' . $e->getMessage() );
	}
}

add_action( 'admin_post_tbt_access_test_reminder', 'tbt_access_test_reminder' );

/**
 * "Send reminder now (test)": Email 1 to the user, not recorded.
 *
 * @return void
 */
function tbt_access_test_reminder() {
	$user_id = isset( $_GET['user_id'] ) ? absint( $_GET['user_id'] ) : 0;

	check_admin_referer( 'tbt_access_test_reminder_' . $user_id );

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'tbt-access' ), 403 );
	}

	$sent = false;

	try {
		$sent = tbt_access_send_reminder( $user_id, 7 );
	} catch ( \Throwable $e ) {
		tbt_access_log( 'test reminder failed: ' . $e->getMessage() );
	}

	$back = get_edit_user_link( $user_id );
	$back = $back ? $back : admin_url( 'users.php' );

	wp_safe_redirect( add_query_arg( 'tbt_access_test', $sent ? 'sent' : 'failed', $back ) );
	exit;
}

add_action( 'admin_notices', 'tbt_access_test_reminder_notice' );

/**
 * Result of the test reminder, on the profile screen.
 *
 * @return void
 */
function tbt_access_test_reminder_notice() {
	$pagenow = $GLOBALS['pagenow'] ?? '';

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
	$result = isset( $_GET['tbt_access_test'] ) ? sanitize_key( wp_unslash( $_GET['tbt_access_test'] ) ) : '';

	if ( '' === $result || ! in_array( $pagenow, array( 'user-edit.php', 'profile.php' ), true ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( 'sent' === $result ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'TBT Access: test reminder sent.', 'tbt-access' ) . '</p></div>';
	} else {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'TBT Access: the test reminder could not be sent (no timed platform access, no valid email address, or the mailer failed).', 'tbt-access' ) . '</p></div>';
	}
}

add_filter( 'manage_users_columns', 'tbt_access_users_platform_column' );

/**
 * Add "Platform until" to Users (administrators only).
 *
 * @param array $columns Columns.
 * @return array
 */
function tbt_access_users_platform_column( $columns ) {
	if ( current_user_can( 'manage_options' ) ) {
		$columns['tbt_access_platform_until'] = __( 'Platform until', 'tbt-access' );
	}

	return $columns;
}

add_filter( 'manage_users_custom_column', 'tbt_access_users_platform_value', 10, 3 );

/**
 * Print "Platform until": a date, "expired {date}", "lifetime" or "—".
 *
 * @param string $output  Existing output.
 * @param string $column  Column key.
 * @param int    $user_id User ID.
 * @return string
 */
function tbt_access_users_platform_value( $output, $column, $user_id ) {
	if ( 'tbt_access_platform_until' !== $column ) {
		return $output;
	}

	$until = tbt_access_grant_until( (int) $user_id, 'platform' );

	if ( false === $until ) {
		return '—';
	}

	if ( null === $until ) {
		return esc_html__( 'lifetime', 'tbt-access' );
	}

	if ( $until > time() ) {
		return esc_html( tbt_access_iso_date( $until ) );
	}

	/* translators: %s: date. */
	return esc_html( sprintf( __( 'expired %s', 'tbt-access' ), tbt_access_iso_date( $until ) ) );
}
