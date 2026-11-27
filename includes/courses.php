<?php
/**
 * Courses, the library and who can open what (0.4.0).
 *
 *   T1  Course registry (option tbt_access_courses), seed, grant keys,
 *       admin screen Users → TBT Access courses.
 *   T2  Resolver: which courses a post belongs to, whether a user can open
 *       it, what the buy page offers, which product sells a course.
 *   T8  Access check tool (on the courses screen).
 *   T11 Upgrade routine (seed + enforcement default).
 *
 * Storage (no custom tables):
 *   option    tbt_access_courses        ordered list of course records
 *   option    tbt_access_enforce        'off' | 'on'  (default 'off')
 *   post meta _tbt_access_free          1 = open to everyone
 *
 * A course is a definition, not content: categories (subcategories included
 * at runtime), extra page and post IDs, and an "exclusive" flag. No lesson is
 * moved or edited. Admin text is English; customer text is Polish.
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * T1 — Registry
 * ---------------------------------------------------------------------- */

/**
 * The records seeded on first run (spec T1, Phase 0 corrections applied).
 *
 * @return array[]
 */
function tbt_access_seed_records() {
	return array(
		array(
			'key'         => 'library',
			'name'        => 'Cała biblioteka',
			'description' => '',
			'categories'  => array( 668, 497, 498, 507, 1738 ),
			'pages'       => array( 735, 803, 11160, 11231, 11246, 11252, 11262, 11269, 11274, 11283, 11290, 11297, 11303, 11311, 11334, 11343, 11352, 11360, 11370, 11396, 12750, 14982, 7668, 7680, 225614, 225646, 226096 ),
			'posts'       => array(),
			'exclusive'   => false,
		),
		array(
			'key'         => 'course-personal-productivity',
			'name'        => 'Personal Productivity',
			'description' => '',
			'categories'  => array( 669 ),
			'pages'       => array(),
			'posts'       => array(),
			'exclusive'   => false,
		),
		array(
			'key'         => 'course-man-and-society',
			'name'        => 'Man and Society',
			'description' => '',
			'categories'  => array( 870 ),
			'pages'       => array(),
			'posts'       => array(),
			'exclusive'   => false,
		),
		array(
			'key'         => 'course-persuasive-presentations',
			'name'        => 'Persuasive Presentations',
			'description' => '',
			'categories'  => array( 731 ),
			'pages'       => array( 11830 ),
			'posts'       => array( 11861 ),
			'exclusive'   => true,
		),
		array(
			'key'         => 'course-graphic-designers',
			'name'        => 'English for Graphic Designers',
			'description' => '',
			'categories'  => array( 1423 ),
			'pages'       => array( 37315 ),
			'posts'       => array( 37256 ),
			'exclusive'   => true,
		),
	);
}

/**
 * Whether a string is a valid course key: 'library' or 'course-<slug>'.
 *
 * @param mixed $key Candidate.
 * @return bool
 */
function tbt_access_is_course_key( $key ) {
	return is_string( $key ) && ( 'library' === $key || 1 === preg_match( '/^course-[a-z0-9]+(?:-[a-z0-9]+)*$/', $key ) );
}

/**
 * Normalise one stored record. Null when unusable.
 *
 * @param mixed $raw Stored record.
 * @return array|null
 */
function tbt_access_normalise_course( $raw ) {
	if ( ! is_array( $raw ) || ! tbt_access_is_course_key( $raw['key'] ?? null ) ) {
		return null;
	}

	$ids = static function ( $list ) {
		return array_values( array_unique( array_filter( array_map( 'absint', (array) $list ) ) ) );
	};

	return array(
		'key'         => (string) $raw['key'],
		'name'        => '' !== trim( (string) ( $raw['name'] ?? '' ) ) ? trim( (string) $raw['name'] ) : (string) $raw['key'],
		'description' => (string) ( $raw['description'] ?? '' ),
		'categories'  => $ids( $raw['categories'] ?? array() ),
		'pages'       => $ids( $raw['pages'] ?? array() ),
		'posts'       => $ids( $raw['posts'] ?? array() ),
		'exclusive'   => ! empty( $raw['exclusive'] ),
	);
}

/**
 * Every course record, in registry order, keyed by course key. Cached per
 * request; tbt_access_save_courses() flushes it.
 *
 * @param bool $flush Drop the cached copy.
 * @return array<string,array>
 */
function tbt_access_courses( $flush = false ) {
	static $cache = null;

	if ( $flush ) {
		$cache = null;
		return array();
	}

	if ( null !== $cache ) {
		return $cache;
	}

	$cache = array();
	$raw   = get_option( 'tbt_access_courses', array() );

	if ( is_array( $raw ) ) {
		foreach ( $raw as $record ) {
			$course = tbt_access_normalise_course( $record );

			if ( $course && ! isset( $cache[ $course['key'] ] ) ) {
				$cache[ $course['key'] ] = $course;
			}
		}
	}

	return $cache;
}

/**
 * One course record, or null.
 *
 * @param string $key Course key.
 * @return array|null
 */
function tbt_access_course( $key ) {
	$courses = tbt_access_courses();

	return $courses[ $key ] ?? null;
}

/**
 * Store the registry (ordered list) and drop every request cache built on it.
 *
 * @param array $courses Records, keyed or not.
 * @return void
 */
function tbt_access_save_courses( array $courses ) {
	$list = array();

	foreach ( $courses as $record ) {
		$course = tbt_access_normalise_course( $record );

		if ( $course ) {
			$list[] = $course;
		}
	}

	update_option( 'tbt_access_courses', $list, true );
	tbt_access_courses( true );
	tbt_access_resolver_flush();
}

/**
 * Whether content restriction is switched on.
 *
 * @return bool
 */
function tbt_access_enforcing() {
	return 'on' === get_option( 'tbt_access_enforce', 'off' );
}

/* -------------------------------------------------------------------------
 * T11 — Upgrade routine (activation, and on admin/front init because an
 * FTP deploy never runs the activation hook)
 * ---------------------------------------------------------------------- */

add_action( 'init', 'tbt_access_courses_upgrade', 1 );

/**
 * Seed the registry if the option is missing, and default enforcement to off.
 *
 * Only a missing option is seeded: a registry emptied by hand stays empty.
 * Both reads hit autoloaded options, so this costs nothing per request.
 *
 * @return void
 */
function tbt_access_courses_upgrade() {
	try {
		if ( false === get_option( 'tbt_access_courses' ) ) {
			add_option( 'tbt_access_courses', tbt_access_seed_records(), '', true );
			tbt_access_courses( true );
		}

		if ( false === get_option( 'tbt_access_enforce' ) ) {
			add_option( 'tbt_access_enforce', 'off', '', true );
		}
	} catch ( \Throwable $e ) {
		tbt_access_log( 'courses upgrade failed: ' . $e->getMessage() );
	}
}

/* -------------------------------------------------------------------------
 * T1 — Grant keys for products and profiles
 * ---------------------------------------------------------------------- */

add_filter( 'tbt_access_grant_keys', 'tbt_access_course_grant_keys' );

/**
 * Add the library and every course to the grant keys, labelled by name.
 *
 * @param array $keys Keys so far.
 * @return array
 */
function tbt_access_course_grant_keys( $keys ) {
	if ( ! is_array( $keys ) ) {
		$keys = array();
	}

	foreach ( tbt_access_courses() as $key => $course ) {
		$keys[ $key ] = $course['name'];
	}

	return $keys;
}

add_filter( 'tbt_access_grant_note_label', 'tbt_access_course_note_label', 10, 2 );

/**
 * Order-note wording for courses: course "Personal Productivity".
 *
 * @param string $label Default label.
 * @param string $key   Grant key.
 * @return string
 */
function tbt_access_course_note_label( $label, $key ) {
	$course = tbt_access_course( (string) $key );

	if ( ! $course ) {
		return $label;
	}

	return sprintf( '%s "%s"', 'library' === $course['key'] ? 'library' : 'course', $course['name'] );
}

add_action( 'woocommerce_product_options_general_product_data', 'tbt_access_course_duration_script', 20 );

/**
 * Choosing a course on a product switches the duration to lifetime.
 *
 * @return void
 */
function tbt_access_course_duration_script() {
	?>
	<script>
	( function () {
		var grant = document.getElementById( '_tbt_access_grant' );
		var unit = document.getElementById( '_tbt_access_duration_unit' );
		if ( ! grant || ! unit ) { return; }
		grant.addEventListener( 'change', function () {
			if ( 0 === grant.value.indexOf( 'course-' ) && 'lifetime' !== unit.value ) {
				unit.value = 'lifetime';
				unit.dispatchEvent( new Event( 'change' ) );
			}
		} );
		// Some screens enhance selects with selectWoo/jQuery; mirror its change event.
		if ( window.jQuery ) {
			window.jQuery( grant ).on( 'change', function () { grant.dispatchEvent( new Event( 'change' ) ); } );
		}
	} )();
	</script>
	<?php
}

/* -------------------------------------------------------------------------
 * T2 — Resolver
 * ---------------------------------------------------------------------- */

/**
 * Reset every per-request resolver cache (after the registry changes).
 *
 * @return void
 */
function tbt_access_resolver_flush() {
	$GLOBALS['tbt_access_resolver'] = array(
		'cat_map'  => null,
		'term'     => array(),
		'post'     => array(),
		'decision' => array(),
		'product'  => array(),
	);
}

/**
 * The per-request resolver cache.
 *
 * @return array
 */
function &tbt_access_resolver_cache() {
	if ( ! isset( $GLOBALS['tbt_access_resolver'] ) || ! is_array( $GLOBALS['tbt_access_resolver'] ) ) {
		tbt_access_resolver_flush();
	}

	return $GLOBALS['tbt_access_resolver'];
}

/**
 * Category ID → course keys (registry order), for the listed categories only.
 *
 * @return array<int,string[]>
 */
function tbt_access_category_map() {
	$cache = &tbt_access_resolver_cache();

	if ( null === $cache['cat_map'] ) {
		$map = array();

		foreach ( tbt_access_courses() as $key => $course ) {
			foreach ( $course['categories'] as $cat_id ) {
				$map[ $cat_id ][] = $key;
			}
		}

		$cache['cat_map'] = $map;
	}

	return $cache['cat_map'];
}

/**
 * Course keys a category reaches: its own and its ancestors' listings.
 *
 * @param int $term_id Category ID.
 * @return string[]
 */
function tbt_access_term_courses( $term_id ) {
	$cache   = &tbt_access_resolver_cache();
	$term_id = (int) $term_id;

	if ( isset( $cache['term'][ $term_id ] ) ) {
		return $cache['term'][ $term_id ];
	}

	$map  = tbt_access_category_map();
	$keys = array();

	foreach ( array_merge( array( $term_id ), array_map( 'intval', (array) get_ancestors( $term_id, 'category', 'taxonomy' ) ) ) as $id ) {
		if ( isset( $map[ $id ] ) ) {
			$keys = array_merge( $keys, $map[ $id ] );
		}
	}

	$cache['term'][ $term_id ] = array_values( array_unique( $keys ) );

	return $cache['term'][ $term_id ];
}

/**
 * Course keys of a post. Throws on error (callers decide fail-open/closed).
 *
 * @param int $post_id Post or page ID.
 * @return string[] In registry order.
 * @throws \RuntimeException When the categories cannot be read.
 */
function tbt_access_resolve_post_courses( $post_id ) {
	$cache   = &tbt_access_resolver_cache();
	$post_id = (int) $post_id;

	if ( isset( $cache['post'][ $post_id ] ) ) {
		return $cache['post'][ $post_id ];
	}

	$found = array();

	if ( $post_id > 0 ) {
		foreach ( tbt_access_courses() as $key => $course ) {
			if ( in_array( $post_id, $course['pages'], true ) || in_array( $post_id, $course['posts'], true ) ) {
				$found[ $key ] = true;
			}
		}

		$post_type = get_post_type( $post_id );

		if ( $post_type && is_object_in_taxonomy( $post_type, 'category' ) ) {
			$terms = get_the_terms( $post_id, 'category' );

			if ( is_wp_error( $terms ) ) {
				throw new \RuntimeException( 'categories of post ' . $post_id . ': ' . $terms->get_error_message() );
			}

			foreach ( (array) $terms as $term ) {
				if ( $term instanceof WP_Term ) {
					foreach ( tbt_access_term_courses( $term->term_id ) as $key ) {
						$found[ $key ] = true;
					}
				}
			}
		}
	}

	// Registry order.
	$ordered = array();

	foreach ( array_keys( tbt_access_courses() ) as $key ) {
		if ( isset( $found[ $key ] ) ) {
			$ordered[] = $key;
		}
	}

	$cache['post'][ $post_id ] = $ordered;

	return $ordered;
}

/**
 * Keys of every course definition containing the post. Never throws:
 * on error, logs and returns [] (callers that must fail closed use
 * tbt_access_decision()).
 *
 * @param int $post_id Post or page ID.
 * @return string[]
 */
function tbt_access_post_courses( int $post_id ): array {
	try {
		return tbt_access_resolve_post_courses( $post_id );
	} catch ( \Throwable $e ) {
		tbt_access_log( 'resolver failed: ' . $e->getMessage() );
		return array();
	}
}

/**
 * Whether a post is marked free.
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function tbt_access_is_free( $post_id ) {
	return '1' === (string) get_post_meta( (int) $post_id, '_tbt_access_free', true );
}

/**
 * The full access decision (decision 4), with the reason.
 *
 * Codes: free, editor, no-course, exclusive-grant, grant, exclusive-locked,
 * locked, error-open, error-locked.
 *
 * @param int $user_id User ID (0 = signed out).
 * @param int $post_id Post or page ID.
 * @return array{open:bool,code:string,courses:string[],via:string|null}
 */
function tbt_access_decision( $user_id, $post_id ) {
	$cache   = &tbt_access_resolver_cache();
	$user_id = (int) $user_id;
	$post_id = (int) $post_id;
	$id      = $user_id . ':' . $post_id;

	if ( isset( $cache['decision'][ $id ] ) ) {
		return $cache['decision'][ $id ];
	}

	$out = array(
		'open'    => true,
		'code'    => 'no-course',
		'courses' => array(),
		'via'     => null,
	);

	try {
		if ( tbt_access_is_free( $post_id ) ) {
			$out['code'] = 'free';
		} elseif ( $user_id > 0 && user_can( $user_id, 'edit_others_posts' ) ) {
			$out['code']    = 'editor';
			$out['courses'] = tbt_access_resolve_post_courses( $post_id );
		} else {
			$courses        = tbt_access_resolve_post_courses( $post_id );
			$out['courses'] = $courses;

			if ( $courses ) {
				$exclusive = array_values(
					array_filter(
						$courses,
						static function ( $key ) {
							$course = tbt_access_course( $key );
							return $course && $course['exclusive'];
						}
					)
				);

				$needed      = $exclusive ? $exclusive : $courses;
				$out['open'] = false;
				$out['code'] = $exclusive ? 'exclusive-locked' : 'locked';

				foreach ( $needed as $key ) {
					if ( $user_id > 0 && tbt_access_has_grant( $user_id, $key ) ) {
						$out['open'] = true;
						$out['code'] = $exclusive ? 'exclusive-grant' : 'grant';
						$out['via']  = $key;
						break;
					}
				}
			}
		}
	} catch ( \Throwable $e ) {
		// Decision 11: fail open on errors only while enforcement is off.
		tbt_access_log( sprintf( 'access decision for user %d, post %d failed: %s', $user_id, $post_id, $e->getMessage() ) );
		$open = ! tbt_access_enforcing();
		$out  = array(
			'open'    => $open,
			'code'    => $open ? 'error-open' : 'error-locked',
			'courses' => array(),
			'via'     => null,
		);
	}

	$cache['decision'][ $id ] = $out;

	return $out;
}

/**
 * Whether a user can open a post or page (decision 4).
 *
 * @param int $user_id User ID (0 = signed out).
 * @param int $post_id Post or page ID.
 * @return bool
 */
function tbt_access_can_view( int $user_id, int $post_id ): bool {
	$decision = tbt_access_decision( $user_id, $post_id );

	return $decision['open'];
}

/**
 * The product that sells a course (decision 6): the published product whose
 * TBT Access grant is that key. Lowest ID wins if several match (logged once
 * a day). Cached per request.
 *
 * @param string $key Course key.
 * @return int|null
 */
function tbt_access_course_product( string $key ) {
	$cache = &tbt_access_resolver_cache();

	if ( array_key_exists( $key, $cache['product'] ) ) {
		return $cache['product'][ $key ];
	}

	$product_id = null;

	try {
		if ( post_type_exists( 'product' ) ) {
			$ids = get_posts(
				array(
					'post_type'        => 'product',
					'post_status'      => 'publish',
					'fields'           => 'ids',
					'numberposts'      => 5,
					'orderby'          => 'ID',
					'order'            => 'ASC',
					'meta_key'         => '_tbt_access_grant', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'       => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'suppress_filters' => true,
					'no_found_rows'    => true,
				)
			);

			$ids = array_map( 'intval', (array) $ids );

			if ( $ids ) {
				$product_id = $ids[0];

				if ( count( $ids ) > 1 && false === get_transient( 'tbt_access_dup_product_' . md5( $key ) ) ) {
					set_transient( 'tbt_access_dup_product_' . md5( $key ), 1, DAY_IN_SECONDS );
					tbt_access_log( sprintf( 'several published products grant "%s" (%s); using %d.', $key, implode( ', ', $ids ), $product_id ) );
				}
			}
		}
	} catch ( \Throwable $e ) {
		tbt_access_log( 'course product lookup failed: ' . $e->getMessage() );
	}

	$cache['product'][ $key ] = $product_id;

	return $product_id;
}

/**
 * What the buy page offers for a post.
 *
 * Priority: an exclusive course → a course with a product (registry order)
 * → none (library, or courses not on sale).
 *
 * @param int $post_id Post or page ID.
 * @return array{type:string,course:string|null,product_id:int|null}
 */
function tbt_access_offer_for( int $post_id ): array {
	$courses = tbt_access_post_courses( $post_id );

	foreach ( $courses as $key ) {
		$course = tbt_access_course( $key );

		if ( $course && $course['exclusive'] ) {
			return array(
				'type'       => 'exclusive',
				'course'     => $key,
				'product_id' => null,
			);
		}
	}

	foreach ( $courses as $key ) {
		$product_id = tbt_access_course_product( $key );

		if ( $product_id ) {
			return array(
				'type'       => 'sale',
				'course'     => $key,
				'product_id' => $product_id,
			);
		}
	}

	return array(
		'type'       => 'none',
		'course'     => $courses ? $courses[0] : null,
		'product_id' => null,
	);
}

/**
 * Where a course starts: its first category page, else its first extra page
 * or post. Empty when nothing resolves.
 *
 * @param string $key Course key.
 * @return string
 */
function tbt_access_course_url( $key ) {
	$course = tbt_access_course( $key );

	if ( ! $course ) {
		return '';
	}

	foreach ( $course['categories'] as $cat_id ) {
		$url = get_category_link( $cat_id );

		if ( is_string( $url ) && '' !== $url ) {
			return $url;
		}
	}

	foreach ( array_merge( $course['pages'], $course['posts'] ) as $id ) {
		if ( 'publish' === get_post_status( $id ) ) {
			return (string) get_permalink( $id );
		}
	}

	return '';
}

/**
 * Published free lessons in some categories (subcategories included).
 *
 * @param int[] $cat_ids Category IDs.
 * @param int   $limit   Maximum.
 * @param int   $exclude Post ID to leave out.
 * @return int[]
 */
function tbt_access_free_lessons( array $cat_ids, $limit = 3, $exclude = 0 ) {
	$cat_ids = array_values( array_filter( array_map( 'absint', $cat_ids ) ) );

	if ( ! $cat_ids ) {
		return array();
	}

	return array_map(
		'intval',
		get_posts(
			array(
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'fields'           => 'ids',
				'numberposts'      => (int) $limit,
				'post__not_in'     => $exclude ? array( (int) $exclude ) : array(),
				'orderby'          => 'date',
				'order'            => 'ASC',
				'meta_key'         => '_tbt_access_free', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'tax_query'        => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy'         => 'category',
						'field'            => 'term_id',
						'terms'            => $cat_ids,
						'include_children' => true,
					),
				),
				'suppress_filters' => true,
				'no_found_rows'    => true,
			)
		)
	);
}

/* -------------------------------------------------------------------------
 * Grant holders (admin screens)
 * ---------------------------------------------------------------------- */

/**
 * Users with an ACTIVE grant, per key. One query; admin screens only.
 *
 * @return array<string,int[]>
 */
function tbt_access_grant_holders() {
	global $wpdb;

	static $holders = null;

	if ( null !== $holders ) {
		return $holders;
	}

	$holders = array();
	$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s", 'tbt_access_grants' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	foreach ( (array) $rows as $row ) {
		$grants = maybe_unserialize( $row->meta_value );

		if ( ! is_array( $grants ) ) {
			continue;
		}

		foreach ( $grants as $key => $grant ) {
			if ( ! is_array( $grant ) || ! array_key_exists( 'until', $grant ) ) {
				continue;
			}

			if ( null === $grant['until'] || (int) $grant['until'] > time() ) {
				$holders[ (string) $key ][] = (int) $row->user_id;
			}
		}
	}

	return $holders;
}

/* -------------------------------------------------------------------------
 * T1 / T8 / T9 — Admin screen: Users → TBT Access courses
 * ---------------------------------------------------------------------- */

add_action( 'admin_menu', 'tbt_access_courses_menu' );

/**
 * Register the screen.
 *
 * @return void
 */
function tbt_access_courses_menu() {
	add_users_page(
		__( 'TBT Access courses', 'tbt-access' ),
		__( 'TBT Access courses', 'tbt-access' ),
		'manage_options',
		'tbt-access-courses',
		'tbt_access_courses_screen'
	);
}

/**
 * URL of the courses screen.
 *
 * @param array $args Extra query args.
 * @return string
 */
function tbt_access_courses_screen_url( array $args = array() ) {
	return add_query_arg( array_merge( array( 'page' => 'tbt-access-courses' ), $args ), admin_url( 'users.php' ) );
}

/**
 * Parse "1, 2 3" into unique positive integers.
 *
 * @param string $text Raw input.
 * @return int[]
 */
function tbt_access_parse_id_list( $text ) {
	$parts = preg_split( '/[\s,;]+/', (string) $text, -1, PREG_SPLIT_NO_EMPTY );

	return array_values( array_unique( array_filter( array_map( 'absint', (array) $parts ) ) ) );
}

/**
 * Published (or private/draft) post count in some categories, subcategories
 * included, each post counted once.
 *
 * @param int[] $cat_ids Category IDs.
 * @return int
 */
function tbt_access_category_post_count( array $cat_ids ) {
	if ( ! $cat_ids ) {
		return 0;
	}

	$query = new WP_Query(
		array(
			'post_type'        => 'any',
			'post_status'      => 'publish',
			'fields'           => 'ids',
			'posts_per_page'   => 1,
			'tax_query'        => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy'         => 'category',
					'field'            => 'term_id',
					'terms'            => array_map( 'intval', $cat_ids ),
					'include_children' => true,
				),
			),
			'suppress_filters' => true,
		)
	);

	return (int) $query->found_posts;
}

/**
 * The screen itself.
 *
 * @return void
 */
function tbt_access_courses_screen() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only views.
	$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : '';
	// phpcs:enable

	echo '<div class="wrap tbt-access-admin">';

	if ( 'edit' === $view || 'add' === $view ) {
		tbt_access_courses_edit_view( 'add' === $view );
	} elseif ( 'import' === $view ) {
		echo '<h1>' . esc_html__( 'Import from WooCommerce Memberships', 'tbt-access' ) . '</h1>';
		printf( '<p><a href="%s">&larr; %s</a></p>', esc_url( tbt_access_courses_screen_url() ), esc_html__( 'Back to courses', 'tbt-access' ) );
		tbt_access_migration_preview();
	} else {
		tbt_access_courses_list_view();
	}

	echo '</div>';
	?>
	<style>
		.tbt-access-admin .tbt-access-cats{max-height:320px;overflow:auto;padding:8px 12px;border:1px solid #dcdcde;background:#fff;max-width:520px}
		.tbt-access-admin .tbt-access-cats ul.children{margin-left:18px}
		.tbt-access-admin .tbt-access-cats li{margin:2px 0}
		.tbt-access-admin .tbt-access-box{max-width:960px;margin:24px 0;padding:4px 20px 16px;border:1px solid #dcdcde;background:#fff}
		.tbt-access-admin .tbt-access-open{color:#008a20;font-weight:600}
		.tbt-access-admin .tbt-access-locked{color:#b32d2e;font-weight:600}
		.tbt-access-admin td.column-exclusive,.tbt-access-admin th.column-exclusive{width:80px}
	</style>
	<?php
}

/**
 * Admin notices for the courses screen (?tbt_msg=…).
 *
 * @return void
 */
function tbt_access_courses_notice() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
	$msg = isset( $_GET['tbt_msg'] ) ? sanitize_key( wp_unslash( $_GET['tbt_msg'] ) ) : '';

	$messages = array(
		'saved'      => array( 'success', __( 'Course saved.', 'tbt-access' ) ),
		'deleted'    => array( 'success', __( 'Course deleted. Nobody\'s grant was removed; the key just stops opening anything.', 'tbt-access' ) ),
		'enforce'    => array( 'success', __( 'Enforcement setting saved. Purge LiteSpeed\'s cache now.', 'tbt-access' ) ),
		'imported'   => array( 'success', __( 'Import finished. See the log below.', 'tbt-access' ) ),
		'error'      => array( 'error', __( 'Nothing was saved: the request could not be verified.', 'tbt-access' ) ),
		'notfound'   => array( 'error', __( 'That course does not exist.', 'tbt-access' ) ),
	);

	if ( isset( $messages[ $msg ] ) ) {
		printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $messages[ $msg ][0] ), esc_html( $messages[ $msg ][1] ) );
	}

	$problems = get_transient( 'tbt_access_course_problems_' . get_current_user_id() );

	if ( is_array( $problems ) && $problems ) {
		delete_transient( 'tbt_access_course_problems_' . get_current_user_id() );
		echo '<div class="notice notice-error"><p>' . esc_html__( 'The course was not saved:', 'tbt-access' ) . '</p><ul style="list-style:disc;margin-left:20px">';
		foreach ( $problems as $problem ) {
			echo '<li>' . esc_html( $problem ) . '</li>';
		}
		echo '</ul></div>';
	}
}

/**
 * Course list, enforcement, access check, import and log.
 *
 * @return void
 */
function tbt_access_courses_list_view() {
	$courses = tbt_access_courses();
	$holders = tbt_access_grant_holders();
	?>
	<h1 class="wp-heading-inline"><?php esc_html_e( 'TBT Access courses', 'tbt-access' ); ?></h1>
	<a href="<?php echo esc_url( tbt_access_courses_screen_url( array( 'view' => 'add' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Add course', 'tbt-access' ); ?></a>
	<hr class="wp-header-end">
	<?php tbt_access_courses_notice(); ?>

	<p><?php esc_html_e( 'A course is a definition: categories (with all their subcategories, now and later), extra pages and posts. A lesson in an exclusive course opens only with that course\'s grant. Any other lesson opens with a grant for any course it belongs to. Free lessons and editors always open.', 'tbt-access' ); ?></p>

	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Name', 'tbt-access' ); ?></th>
				<th><?php esc_html_e( 'Key', 'tbt-access' ); ?></th>
				<th><?php esc_html_e( 'Categories', 'tbt-access' ); ?></th>
				<th><?php esc_html_e( 'Extra items', 'tbt-access' ); ?></th>
				<th class="column-exclusive"><?php esc_html_e( 'Exclusive', 'tbt-access' ); ?></th>
				<th><?php esc_html_e( 'Product', 'tbt-access' ); ?></th>
				<th><?php esc_html_e( 'Holders', 'tbt-access' ); ?></th>
				<th></th>
			</tr>
		</thead>
		<tbody>
		<?php if ( ! $courses ) : ?>
			<tr><td colspan="8"><?php esc_html_e( 'No courses defined. Nothing is restricted.', 'tbt-access' ); ?></td></tr>
		<?php endif; ?>
		<?php foreach ( $courses as $key => $course ) : ?>
			<?php
			$names = array();
			foreach ( $course['categories'] as $cat_id ) {
				$term    = get_term( $cat_id, 'category' );
				$names[] = $term instanceof WP_Term ? $term->name . ' (' . $cat_id . ')' : sprintf( /* translators: %d: ID. */ __( 'missing category %d', 'tbt-access' ), $cat_id );
			}
			$product_id = tbt_access_course_product( $key );
			$count      = count( $holders[ $key ] ?? array() );
			$delete_msg = $count
				/* translators: %d: number of users. */
				? sprintf( __( '%d users hold this course. Delete the definition anyway? Their grants stay stored but stop opening anything.', 'tbt-access' ), $count )
				: __( 'Delete this course definition?', 'tbt-access' );
			?>
			<tr>
				<td><strong><a href="<?php echo esc_url( tbt_access_courses_screen_url( array( 'view' => 'edit', 'key' => $key ) ) ); ?>"><?php echo esc_html( $course['name'] ); ?></a></strong></td>
				<td><code><?php echo esc_html( $key ); ?></code></td>
				<td>
					<?php echo esc_html( $names ? implode( ', ', $names ) : '—' ); ?>
					<?php if ( $course['categories'] ) : ?>
						<br><span class="description">
						<?php
						/* translators: %d: number of posts. */
						echo esc_html( sprintf( __( '%d published items incl. subcategories', 'tbt-access' ), tbt_access_category_post_count( $course['categories'] ) ) );
						?>
						</span>
					<?php endif; ?>
				</td>
				<td><?php echo (int) ( count( $course['pages'] ) + count( $course['posts'] ) ); ?></td>
				<td class="column-exclusive"><?php echo $course['exclusive'] ? esc_html__( 'yes', 'tbt-access' ) : '—'; ?></td>
				<td>
					<?php if ( $product_id ) : ?>
						<a href="<?php echo esc_url( (string) get_edit_post_link( $product_id ) ); ?>"><?php echo esc_html( get_the_title( $product_id ) ); ?></a>
					<?php else : ?>
						<span class="description"><?php esc_html_e( 'not on sale', 'tbt-access' ); ?></span>
					<?php endif; ?>
				</td>
				<td><?php echo (int) $count; ?></td>
				<td>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm(<?php echo esc_attr( wp_json_encode( $delete_msg ) ); ?>);" style="margin:0">
						<input type="hidden" name="action" value="tbt_access_course_delete">
						<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>">
						<?php wp_nonce_field( 'tbt_access_course_delete_' . $key ); ?>
						<button type="submit" class="button-link button-link-delete"><?php esc_html_e( 'Delete', 'tbt-access' ); ?></button>
					</form>
				</td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>

	<div class="tbt-access-box">
		<h2><?php esc_html_e( 'Enforcement', 'tbt-access' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="tbt_access_enforce_save">
			<?php wp_nonce_field( 'tbt_access_enforce_save' ); ?>
			<p>
				<label><input type="radio" name="tbt_access_enforce" value="off" <?php checked( ! tbt_access_enforcing() ); ?>> <strong><?php esc_html_e( 'Off', 'tbt-access' ); ?></strong> — <?php esc_html_e( 'TBT Access restricts nothing (WooCommerce Memberships still does, while it is active).', 'tbt-access' ); ?></label><br>
				<label><input type="radio" name="tbt_access_enforce" value="on" <?php checked( tbt_access_enforcing() ); ?>> <strong><?php esc_html_e( 'On', 'tbt-access' ); ?></strong> — <?php esc_html_e( 'locked lessons show the buy page, lists show locks, REST shows excerpts only.', 'tbt-access' ); ?></label>
			</p>
			<?php if ( tbt_access_enforcing() && tbt_access_memberships_active() ) : ?>
				<div class="notice notice-warning inline"><p><?php esc_html_e( 'WooCommerce Memberships is still active. Deactivate it: the two should not restrict at the same time.', 'tbt-access' ); ?></p></div>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'Switch-over, in one sitting: run the import, check a few users with the access check, deactivate WooCommerce Memberships, set this to On, then purge LiteSpeed\'s cache. Rollback: Off + reactivate Memberships.', 'tbt-access' ); ?></p>
			<?php submit_button( __( 'Save enforcement', 'tbt-access' ), 'secondary', 'submit', false ); ?>
		</form>
	</div>

	<?php tbt_access_access_check_box(); ?>

	<div class="tbt-access-box">
		<h2><?php esc_html_e( 'Import from WooCommerce Memberships', 'tbt-access' ); ?></h2>
		<p><?php esc_html_e( 'Turns every active membership into a grant (open-ended → lifetime, dated → same end). Never shortens an existing grant, never changes Memberships data, and is safe to run twice. The first step is a preview that writes nothing.', 'tbt-access' ); ?></p>
		<p><a class="button button-primary" href="<?php echo esc_url( tbt_access_courses_screen_url( array( 'view' => 'import' ) ) ); ?>"><?php esc_html_e( 'Import from WooCommerce Memberships', 'tbt-access' ); ?></a></p>
		<?php tbt_access_migration_log_table(); ?>
	</div>
	<?php
}

/**
 * Whether WooCommerce Memberships is active.
 *
 * @return bool
 */
function tbt_access_memberships_active() {
	return function_exists( 'wc_memberships' ) || class_exists( 'WC_Memberships_Loader', false );
}

/**
 * Add / edit form.
 *
 * @param bool $is_new Whether this is a new course.
 * @return void
 */
function tbt_access_courses_edit_view( $is_new ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view.
	$key    = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
	$course = $is_new ? null : tbt_access_course( $key );

	if ( ! $is_new && ! $course ) {
		echo '<h1>' . esc_html__( 'TBT Access courses', 'tbt-access' ) . '</h1>';
		echo '<div class="notice notice-error"><p>' . esc_html__( 'That course does not exist.', 'tbt-access' ) . '</p></div>';
		return;
	}

	$draft = get_transient( 'tbt_access_course_draft_' . get_current_user_id() );
	if ( is_array( $draft ) ) {
		delete_transient( 'tbt_access_course_draft_' . get_current_user_id() );
	}

	$values = wp_parse_args(
		is_array( $draft ) ? $draft : (array) $course,
		array(
			'key'         => '',
			'name'        => '',
			'description' => '',
			'categories'  => array(),
			'pages'       => array(),
			'posts'       => array(),
			'exclusive'   => false,
		)
	);

	require_once ABSPATH . 'wp-admin/includes/template.php';
	?>
	<h1><?php echo $is_new ? esc_html__( 'Add course', 'tbt-access' ) : esc_html( sprintf( /* translators: %s: course name. */ __( 'Edit course: %s', 'tbt-access' ), $course['name'] ) ); ?></h1>
	<p><a href="<?php echo esc_url( tbt_access_courses_screen_url() ); ?>">&larr; <?php esc_html_e( 'Back to courses', 'tbt-access' ); ?></a></p>
	<?php tbt_access_courses_notice(); ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="tbt_access_course_save">
		<input type="hidden" name="original_key" value="<?php echo esc_attr( $is_new ? '' : $course['key'] ); ?>">
		<?php wp_nonce_field( 'tbt_access_course_save' ); ?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="tbt_course_name"><?php esc_html_e( 'Name', 'tbt-access' ); ?></label></th>
				<td><input type="text" class="regular-text" id="tbt_course_name" name="name" value="<?php echo esc_attr( $values['name'] ); ?>" required>
				<p class="description"><?php esc_html_e( 'Shown to customers as is.', 'tbt-access' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><label for="tbt_course_key"><?php esc_html_e( 'Key', 'tbt-access' ); ?></label></th>
				<td>
					<?php if ( $is_new ) : ?>
						<input type="text" class="regular-text code" id="tbt_course_key" name="key" value="<?php echo esc_attr( $values['key'] ); ?>" placeholder="course-…" pattern="library|course-[a-z0-9]+(-[a-z0-9]+)*">
						<p class="description"><?php esc_html_e( 'Leave empty to make it from the name (course-<name>). Cannot be changed after saving: grants and products store it.', 'tbt-access' ); ?></p>
					<?php else : ?>
						<code><?php echo esc_html( $course['key'] ); ?></code>
						<p class="description"><?php esc_html_e( 'Fixed: grants and products store it.', 'tbt-access' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="tbt_course_description"><?php esc_html_e( 'Description (Polish)', 'tbt-access' ); ?></label></th>
				<td><textarea class="large-text" rows="4" id="tbt_course_description" name="description"><?php echo esc_textarea( $values['description'] ); ?></textarea>
				<p class="description"><?php esc_html_e( '1–3 sentences for the buy page. Plain text; line breaks are kept.', 'tbt-access' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Categories', 'tbt-access' ); ?></th>
				<td>
					<div class="tbt-access-cats">
						<ul>
						<?php
						wp_terms_checklist(
							0,
							array(
								'taxonomy'      => 'category',
								'selected_cats' => array_map( 'intval', (array) $values['categories'] ),
								'checked_ontop' => false,
							)
						);
						?>
						</ul>
					</div>
					<p class="description"><?php esc_html_e( 'Every subcategory is included automatically, including ones added later. Tick only the top category of a course.', 'tbt-access' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="tbt_course_pages"><?php esc_html_e( 'Extra page IDs', 'tbt-access' ); ?></label></th>
				<td><input type="text" class="large-text code" id="tbt_course_pages" name="pages" value="<?php echo esc_attr( implode( ', ', (array) $values['pages'] ) ); ?>"></td>
			</tr>
			<tr>
				<th scope="row"><label for="tbt_course_posts"><?php esc_html_e( 'Extra post IDs', 'tbt-access' ); ?></label></th>
				<td><input type="text" class="large-text code" id="tbt_course_posts" name="posts" value="<?php echo esc_attr( implode( ', ', (array) $values['posts'] ) ); ?>">
				<p class="description"><?php esc_html_e( 'Comma-separated. Each ID must exist.', 'tbt-access' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Exclusive', 'tbt-access' ); ?></th>
				<td><label><input type="checkbox" name="exclusive" value="1" <?php checked( ! empty( $values['exclusive'] ) ); ?>> <?php esc_html_e( 'Only this course\'s grant opens it (not the library, not another course)', 'tbt-access' ); ?></label></td>
			</tr>
		</table>
		<?php submit_button( $is_new ? __( 'Add course', 'tbt-access' ) : __( 'Save course', 'tbt-access' ) ); ?>
	</form>
	<?php
}

add_action( 'admin_post_tbt_access_course_save', 'tbt_access_course_save' );

/**
 * Save a course (add or edit).
 *
 * @return void
 */
function tbt_access_course_save() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'tbt-access' ), 403 );
	}

	check_admin_referer( 'tbt_access_course_save' );

	// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised below.
	$original = isset( $_POST['original_key'] ) ? sanitize_text_field( wp_unslash( $_POST['original_key'] ) ) : '';
	$name     = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
	$key      = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
	$desc     = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';
	$cats     = isset( $_POST['post_category'] ) ? array_values( array_filter( array_map( 'absint', (array) wp_unslash( $_POST['post_category'] ) ) ) ) : array();
	$pages    = tbt_access_parse_id_list( isset( $_POST['pages'] ) ? wp_unslash( $_POST['pages'] ) : '' );
	$posts    = tbt_access_parse_id_list( isset( $_POST['posts'] ) ? wp_unslash( $_POST['posts'] ) : '' );
	$excl     = ! empty( $_POST['exclusive'] );
	// phpcs:enable

	$courses  = tbt_access_courses();
	$is_new   = '' === $original;
	$problems = array();

	if ( ! $is_new && ! isset( $courses[ $original ] ) ) {
		wp_safe_redirect( tbt_access_courses_screen_url( array( 'tbt_msg' => 'notfound' ) ) );
		exit;
	}

	if ( '' === $name ) {
		$problems[] = __( 'The name is required.', 'tbt-access' );
	}

	if ( $is_new ) {
		if ( '' === $key ) {
			$key = 'course-' . trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( remove_accents( $name ) ) ), '-' );
		}

		if ( ! tbt_access_is_course_key( $key ) ) {
			$problems[] = __( 'The key must be "library" or "course-" followed by lowercase letters, digits and single hyphens.', 'tbt-access' );
		} elseif ( isset( $courses[ $key ] ) || 'platform' === $key ) {
			/* translators: %s: key. */
			$problems[] = sprintf( __( 'The key %s is already used.', 'tbt-access' ), $key );
		}
	} else {
		$key = $original;
	}

	foreach ( $cats as $cat_id ) {
		if ( ! term_exists( (int) $cat_id, 'category' ) ) {
			/* translators: %d: ID. */
			$problems[] = sprintf( __( 'Category %d does not exist.', 'tbt-access' ), $cat_id );
		}
	}

	foreach ( $pages as $id ) {
		if ( 'page' !== get_post_type( $id ) ) {
			/* translators: %d: ID. */
			$problems[] = sprintf( __( 'Page %d does not exist (or is not a page).', 'tbt-access' ), $id );
		}
	}

	foreach ( $posts as $id ) {
		$type = get_post_type( $id );
		if ( ! $type || in_array( $type, array( 'revision', 'nav_menu_item', 'attachment' ), true ) ) {
			/* translators: %d: ID. */
			$problems[] = sprintf( __( 'Post %d does not exist.', 'tbt-access' ), $id );
		}
	}

	$record = array(
		'key'         => $key,
		'name'        => $name,
		'description' => $desc,
		'categories'  => $cats,
		'pages'       => $pages,
		'posts'       => $posts,
		'exclusive'   => $excl,
	);

	if ( $problems ) {
		set_transient( 'tbt_access_course_problems_' . get_current_user_id(), $problems, 5 * MINUTE_IN_SECONDS );
		set_transient( 'tbt_access_course_draft_' . get_current_user_id(), $record, 5 * MINUTE_IN_SECONDS );
		$back = $is_new ? array( 'view' => 'add' ) : array(
			'view' => 'edit',
			'key'  => $original,
		);
		wp_safe_redirect( tbt_access_courses_screen_url( $back ) );
		exit;
	}

	$courses[ $key ] = $record; // Edits keep their position; new ones go last.
	tbt_access_save_courses( $courses );

	wp_safe_redirect( tbt_access_courses_screen_url( array( 'tbt_msg' => 'saved' ) ) );
	exit;
}

add_action( 'admin_post_tbt_access_course_delete', 'tbt_access_course_delete' );

/**
 * Delete a course definition. Grants are never touched.
 *
 * @return void
 */
function tbt_access_course_delete() {
	$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'tbt-access' ), 403 );
	}

	check_admin_referer( 'tbt_access_course_delete_' . $key );

	$courses = tbt_access_courses();

	if ( ! isset( $courses[ $key ] ) ) {
		wp_safe_redirect( tbt_access_courses_screen_url( array( 'tbt_msg' => 'notfound' ) ) );
		exit;
	}

	unset( $courses[ $key ] );
	tbt_access_save_courses( $courses );

	wp_safe_redirect( tbt_access_courses_screen_url( array( 'tbt_msg' => 'deleted' ) ) );
	exit;
}

add_action( 'admin_post_tbt_access_enforce_save', 'tbt_access_enforce_save' );

/**
 * Save the enforcement switch.
 *
 * @return void
 */
function tbt_access_enforce_save() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'tbt-access' ), 403 );
	}

	check_admin_referer( 'tbt_access_enforce_save' );

	$value = isset( $_POST['tbt_access_enforce'] ) && 'on' === sanitize_key( wp_unslash( $_POST['tbt_access_enforce'] ) ) ? 'on' : 'off';
	update_option( 'tbt_access_enforce', $value, true );

	tbt_access_log( sprintf( 'enforcement set to %s by user %d.', $value, get_current_user_id() ) );

	wp_safe_redirect( tbt_access_courses_screen_url( array( 'tbt_msg' => 'enforce' ) ) );
	exit;
}

/* -------------------------------------------------------------------------
 * T8 — Access check tool
 * ---------------------------------------------------------------------- */

/**
 * Find a user from an ID, email or login.
 *
 * @param string $text Input.
 * @return WP_User|null
 */
function tbt_access_find_user( $text ) {
	$text = trim( (string) $text );

	if ( '' === $text ) {
		return null;
	}

	if ( ctype_digit( $text ) ) {
		$user = get_user_by( 'id', (int) $text );
	} elseif ( is_email( $text ) ) {
		$user = get_user_by( 'email', $text );
	} else {
		$user = get_user_by( 'login', $text );
	}

	return $user instanceof WP_User ? $user : null;
}

/**
 * Post ID from a pasted ID or same-site URL. 0 when not found.
 *
 * @param string $text Input.
 * @return int
 */
function tbt_access_find_post( $text ) {
	$text = trim( (string) $text );

	if ( '' === $text ) {
		return 0;
	}

	if ( ctype_digit( $text ) ) {
		return get_post( (int) $text ) ? (int) $text : 0;
	}

	$id = url_to_postid( $text );

	if ( ! $id ) {
		// ?p=123 / ?page_id=123 links.
		$query = (string) wp_parse_url( $text, PHP_URL_QUERY );
		parse_str( $query, $args );
		$id = absint( $args['p'] ?? $args['page_id'] ?? 0 );
	}

	return $id && get_post( $id ) ? (int) $id : 0;
}

/**
 * Human reason for a decision (admin English).
 *
 * @param array $decision Output of tbt_access_decision().
 * @return string
 */
function tbt_access_decision_reason( array $decision ) {
	$names = static function ( $keys ) {
		return implode(
			', ',
			array_map(
				static function ( $key ) {
					$course = tbt_access_course( $key );
					return $course ? $course['name'] . ' [' . $key . ']' : $key;
				},
				(array) $keys
			)
		);
	};

	$exclusive = array_values(
		array_filter(
			$decision['courses'],
			static function ( $key ) {
				$course = tbt_access_course( $key );
				return $course && $course['exclusive'];
			}
		)
	);

	switch ( $decision['code'] ) {
		case 'free':
			return __( 'Free lesson: open to everyone.', 'tbt-access' );
		case 'editor':
			return $decision['courses']
				/* translators: %s: course names. */
				? sprintf( __( 'Editor (can edit others\' posts): sees everything. Belongs to: %s.', 'tbt-access' ), $names( $decision['courses'] ) )
				: __( 'Editor (can edit others\' posts). Belongs to no course anyway.', 'tbt-access' );
		case 'no-course':
			return __( 'Belongs to no course definition: open.', 'tbt-access' );
		case 'grant':
		case 'exclusive-grant':
			/* translators: 1: course that opened it, 2: all its courses. */
			return sprintf( __( 'Opened by the grant for %1$s. Belongs to: %2$s.', 'tbt-access' ), $names( array( $decision['via'] ) ), $names( $decision['courses'] ) );
		case 'exclusive-locked':
			/* translators: %s: course names. */
			return sprintf( __( 'Exclusive course: needs a grant for %s. Nothing else opens it, not even the library.', 'tbt-access' ), $names( $exclusive ) );
		case 'locked':
			/* translators: %s: course names. */
			return sprintf( __( 'Needs an active grant for any of: %s.', 'tbt-access' ), $names( $decision['courses'] ) );
		case 'error-open':
			return __( 'Resolver error (see the PHP error log). Treated as open because enforcement is off.', 'tbt-access' );
		case 'error-locked':
			return __( 'Resolver error (see the PHP error log). Treated as locked because enforcement is on.', 'tbt-access' );
	}

	return '';
}

/**
 * The access check box on the courses screen.
 *
 * @return void
 */
function tbt_access_access_check_box() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only tool.
	$who       = isset( $_GET['tbt_check_user'] ) ? sanitize_text_field( wp_unslash( $_GET['tbt_check_user'] ) ) : '';
	$what      = isset( $_GET['tbt_check_url'] ) ? sanitize_text_field( wp_unslash( $_GET['tbt_check_url'] ) ) : '';
	$anonymous = ! empty( $_GET['tbt_check_anon'] );
	$ran       = isset( $_GET['tbt_check'] );
	// phpcs:enable
	?>
	<div class="tbt-access-box" id="tbt-access-check">
		<h2><?php esc_html_e( 'Access check', 'tbt-access' ); ?></h2>
		<p><?php esc_html_e( '"Why can\'t my student see this?" Pick a user (or signed out) and a URL or post ID. Uses the same rules as the site; works with enforcement off too.', 'tbt-access' ); ?></p>
		<form method="get" action="<?php echo esc_url( admin_url( 'users.php' ) ); ?>#tbt-access-check">
			<input type="hidden" name="page" value="tbt-access-courses">
			<input type="hidden" name="tbt_check" value="1">
			<p>
				<label for="tbt_check_user"><?php esc_html_e( 'User (ID, email or username)', 'tbt-access' ); ?></label><br>
				<input type="text" class="regular-text" id="tbt_check_user" name="tbt_check_user" value="<?php echo esc_attr( $who ); ?>">
				<label style="margin-left:12px"><input type="checkbox" name="tbt_check_anon" value="1" <?php checked( $anonymous ); ?>> <?php esc_html_e( 'Signed out', 'tbt-access' ); ?></label>
			</p>
			<p>
				<label for="tbt_check_url"><?php esc_html_e( 'URL or post ID', 'tbt-access' ); ?></label><br>
				<input type="text" class="large-text" id="tbt_check_url" name="tbt_check_url" value="<?php echo esc_attr( $what ); ?>">
			</p>
			<?php submit_button( __( 'Check', 'tbt-access' ), 'secondary', '', false ); ?>
		</form>
		<?php
		if ( $ran ) {
			$user    = $anonymous ? null : tbt_access_find_user( $who );
			$post_id = tbt_access_find_post( $what );

			if ( ! $anonymous && ! $user ) {
				echo '<p class="tbt-access-locked">' . esc_html__( 'User not found. Tick "Signed out" to check as a visitor.', 'tbt-access' ) . '</p>';
			} elseif ( ! $post_id ) {
				echo '<p class="tbt-access-locked">' . esc_html__( 'No post or page found for that URL or ID.', 'tbt-access' ) . '</p>';
			} else {
				$decision = tbt_access_decision( $user ? (int) $user->ID : 0, $post_id );
				$label    = $user ? sprintf( '%s (#%d, %s)', $user->display_name, $user->ID, $user->user_email ) : __( 'signed out', 'tbt-access' );
				printf(
					'<p><strong>%1$s</strong> → <a href="%2$s">%3$s</a> (#%4$d): <span class="%5$s">%6$s</span></p><p>%7$s</p>',
					esc_html( $label ),
					esc_url( (string) get_permalink( $post_id ) ),
					esc_html( get_the_title( $post_id ) ),
					(int) $post_id,
					$decision['open'] ? 'tbt-access-open' : 'tbt-access-locked',
					$decision['open'] ? esc_html__( 'Open', 'tbt-access' ) : esc_html__( 'Locked', 'tbt-access' ),
					esc_html( tbt_access_decision_reason( $decision ) )
				);

				if ( ! tbt_access_enforcing() && ! $decision['open'] ) {
					echo '<p class="description">' . esc_html__( 'Enforcement is off, so the site does not lock it yet.', 'tbt-access' ) . '</p>';
				}

				if ( $user ) {
					$active = array();
					foreach ( tbt_access_get_grants( (int) $user->ID ) as $key => $grant ) {
						if ( tbt_access_has_grant( (int) $user->ID, $key ) ) {
							$active[] = tbt_access_grant_label( $key ) . ' (' . ( null === $grant['until'] ? __( 'lifetime', 'tbt-access' ) : tbt_access_iso_date( $grant['until'] ) ) . ')';
						}
					}
					/* translators: %s: grants. */
					echo '<p class="description">' . esc_html( sprintf( __( 'Active grants: %s', 'tbt-access' ), $active ? implode( ', ', $active ) : __( 'none', 'tbt-access' ) ) ) . '</p>';
				}
			}
		}
		?>
	</div>
	<?php
}

/* -------------------------------------------------------------------------
 * T8 — Users list: "Courses" column and bulk grant / remove
 * ---------------------------------------------------------------------- */

add_filter( 'manage_users_columns', 'tbt_access_users_courses_column', 20 );

/**
 * Add "Courses" after "Platform until" (administrators only).
 *
 * @param array $columns Columns.
 * @return array
 */
function tbt_access_users_courses_column( $columns ) {
	if ( current_user_can( 'manage_options' ) ) {
		$columns['tbt_access_courses'] = __( 'Courses', 'tbt-access' );
	}

	return $columns;
}

add_filter( 'manage_users_custom_column', 'tbt_access_users_courses_value', 10, 3 );

/**
 * Short labels of active library/course grants; full names and dates on hover.
 *
 * @param string $output  Existing output.
 * @param string $column  Column key.
 * @param int    $user_id User ID.
 * @return string
 */
function tbt_access_users_courses_value( $output, $column, $user_id ) {
	if ( 'tbt_access_courses' !== $column ) {
		return $output;
	}

	$short = array();
	$full  = array();

	foreach ( tbt_access_courses() as $key => $course ) {
		if ( ! tbt_access_has_grant( (int) $user_id, $key ) ) {
			continue;
		}

		$until   = tbt_access_grant_until( (int) $user_id, $key );
		$short[] = 'library' === $key ? 'Biblioteka' : $course['name'];
		$full[]  = $course['name'] . ': ' . ( null === $until ? __( 'lifetime', 'tbt-access' ) : sprintf( /* translators: %s: date. */ __( 'until %s', 'tbt-access' ), tbt_access_iso_date( (int) $until ) ) );
	}

	if ( ! $short ) {
		return '—';
	}

	return sprintf( '<span title="%1$s">%2$s</span>', esc_attr( implode( "\n", $full ) ), esc_html( implode( ', ', $short ) ) );
}

add_filter( 'bulk_actions-users', 'tbt_access_users_bulk_actions' );

/**
 * Bulk actions: TBT Access: grant… / remove….
 *
 * @param array $actions Actions.
 * @return array
 */
function tbt_access_users_bulk_actions( $actions ) {
	if ( current_user_can( 'manage_options' ) && tbt_access_courses() ) {
		$actions['tbt_access_grant']  = __( 'TBT Access: grant…', 'tbt-access' );
		$actions['tbt_access_remove'] = __( 'TBT Access: remove…', 'tbt-access' );
	}

	return $actions;
}

add_action( 'restrict_manage_users', 'tbt_access_users_bulk_select' );

/**
 * The course select next to the bulk actions.
 *
 * @param string $which 'top' or 'bottom'.
 * @return void
 */
function tbt_access_users_bulk_select( $which = 'top' ) {
	if ( ! current_user_can( 'manage_options' ) || ! tbt_access_courses() ) {
		return;
	}

	$which = 'bottom' === $which ? 'bottom' : 'top';
	$id    = 'tbt_access_course_' . $which;
	?>
	<label class="screen-reader-text" for="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'TBT Access course', 'tbt-access' ); ?></label>
	<select name="<?php echo esc_attr( $id ); ?>" id="<?php echo esc_attr( $id ); ?>" style="float:none;margin:0 4px">
		<option value=""><?php esc_html_e( 'TBT Access course…', 'tbt-access' ); ?></option>
		<?php foreach ( tbt_access_courses() as $key => $course ) : ?>
			<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $course['name'] ); ?></option>
		<?php endforeach; ?>
	</select>
	<?php
}

add_filter( 'handle_bulk_actions-users', 'tbt_access_users_bulk_handle', 10, 3 );

/**
 * Grant (lifetime) or remove a course for the selected users. WordPress has
 * already checked the bulk-users nonce.
 *
 * @param string $sendback Redirect URL.
 * @param string $action   Action.
 * @param int[]  $user_ids Users.
 * @return string
 */
function tbt_access_users_bulk_handle( $sendback, $action, $user_ids ) {
	if ( ! in_array( $action, array( 'tbt_access_grant', 'tbt_access_remove' ), true ) ) {
		return $sendback;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		return $sendback;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- checked by WordPress (bulk-users).
	$top    = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
	$field  = $top === $action ? 'tbt_access_course_top' : 'tbt_access_course_bottom';
	$key    = isset( $_REQUEST[ $field ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $field ] ) ) : '';
	// phpcs:enable
	$sendback = remove_query_arg( array( 'tbt_bulk', 'tbt_bulk_n', 'tbt_bulk_key' ), $sendback );

	if ( ! tbt_access_course( $key ) ) {
		return add_query_arg( 'tbt_bulk', 'nocourse', $sendback );
	}

	$done = 0;

	foreach ( array_map( 'intval', (array) $user_ids ) as $user_id ) {
		if ( $user_id <= 0 || ! current_user_can( 'edit_user', $user_id ) ) {
			continue;
		}

		$current = tbt_access_grant_until( $user_id, $key );

		if ( 'tbt_access_grant' === $action ) {
			if ( null !== $current ) {
				tbt_access_set(
					$user_id,
					$key,
					null,
					array(
						'type' => 'admin',
						'by'   => get_current_user_id(),
					)
				);
			}
		} elseif ( false !== $current ) {
			tbt_access_set( $user_id, $key, false );
		}

		++$done;
	}

	return add_query_arg(
		array(
			'tbt_bulk'     => 'tbt_access_grant' === $action ? 'granted' : 'removed',
			'tbt_bulk_n'   => $done,
			'tbt_bulk_key' => $key,
		),
		$sendback
	);
}

add_action( 'admin_notices', 'tbt_access_users_bulk_notice' );

/**
 * Result of a bulk grant / remove.
 *
 * @return void
 */
function tbt_access_users_bulk_notice() {
	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
	if ( 'users.php' !== ( $GLOBALS['pagenow'] ?? '' ) || empty( $_GET['tbt_bulk'] ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$result = sanitize_key( wp_unslash( $_GET['tbt_bulk'] ) );
	$count  = isset( $_GET['tbt_bulk_n'] ) ? absint( $_GET['tbt_bulk_n'] ) : 0;
	$course = isset( $_GET['tbt_bulk_key'] ) ? tbt_access_course( sanitize_text_field( wp_unslash( $_GET['tbt_bulk_key'] ) ) ) : null;
	// phpcs:enable

	if ( 'nocourse' === $result ) {
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'TBT Access: pick a course in the select next to the bulk actions, then Apply again.', 'tbt-access' ) . '</p></div>';
		return;
	}

	$name = $course ? $course['name'] : '';
	$text = 'granted' === $result
		/* translators: 1: course, 2: count. */
		? sprintf( __( 'TBT Access: %1$s granted for life to %2$d users.', 'tbt-access' ), $name, $count )
		/* translators: 1: course, 2: count. */
		: sprintf( __( 'TBT Access: %1$s removed from %2$d users.', 'tbt-access' ), $name, $count );

	echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $text ) . '</p></div>';
}
