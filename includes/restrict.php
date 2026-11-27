<?php
/**
 * Content restriction (0.4.0; content swap reworked in 0.4.1).
 *
 *   T3  Enforcement: a locked lesson keeps the theme's own template and only
 *       its content becomes the buy page (noindex); everywhere else a locked
 *       post's content is its excerpt (archives, blog modules, home, search,
 *       feeds, REST); an admin-bar note for editors.
 *   T4  Locked look in lists: post_class tbt-locked / tbt-free + CSS.
 *   T5  Buy page (templates/locked.php) and the "buy course" link handler.
 *   T6  Free lesson switch (meta box, list column, filter).
 *   T10 My account: "Twoje kursy".
 *
 * Everything in T3/T4 runs only while tbt_access_enforce = on. Errors are
 * logged; with enforcement on, a lesson whose access can't be decided shows
 * the buy page, never the lesson (decision 11).
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/**
 * Post types the buy page can replace.
 *
 * @return string[]
 */
function tbt_access_restricted_post_types() {
	$types = apply_filters( 'tbt_access_restricted_post_types', array( 'post', 'page' ) );

	return is_array( $types ) ? $types : array( 'post', 'page' );
}

/**
 * Pages that are never locked, whatever a definition says (WooCommerce pages
 * and the front page). Phase 0.4 found none inside a definition; this is a
 * safety net.
 *
 * @return int[]
 */
function tbt_access_never_lock_ids() {
	$ids = array( (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) );

	if ( function_exists( 'wc_get_page_id' ) ) {
		foreach ( array( 'cart', 'checkout', 'myaccount', 'shop', 'terms' ) as $page ) {
			$ids[] = (int) wc_get_page_id( $page );
		}
	}

	return array_values( array_filter( $ids, static fn( $id ) => $id > 0 ) );
}

/**
 * Whether the current viewer is locked out of a post (enforcement aside).
 *
 * @param int $post_id Post ID.
 * @return bool
 */
function tbt_access_is_locked_for_viewer( $post_id ) {
	$post_id = (int) $post_id;

	if ( $post_id <= 0 || in_array( $post_id, tbt_access_never_lock_ids(), true ) ) {
		return false;
	}

	if ( ! in_array( get_post_type( $post_id ), tbt_access_restricted_post_types(), true ) ) {
		return false;
	}

	return ! tbt_access_can_view( get_current_user_id(), $post_id );
}

/**
 * A plain-text excerpt that never runs the page builder: the manual excerpt,
 * else the first 55 words of the content with shortcodes and tags removed.
 *
 * @param WP_Post $post Post.
 * @return string
 */
function tbt_access_safe_excerpt( WP_Post $post ) {
	$text = has_excerpt( $post ) ? (string) $post->post_excerpt : (string) $post->post_content;

	// Strip every [shortcode] (Divi's included, registered or not) and tags.
	$text = preg_replace( '/\[\/?[A-Za-z0-9_\-]+(?:\s[^\]]*)?\]/', ' ', $text );
	$text = wp_strip_all_tags( (string) $text, true );

	return wp_trim_words( $text, 55, '…' );
}

/* -------------------------------------------------------------------------
 * T3 — Locked content: buy page in the lesson's own template, excerpts
 * everywhere else (0.4.1)
 *
 * The theme renders a locked lesson exactly as usual (Divi Theme Builder
 * header, footer, title and featured image, with their styles). Only the
 * post content is swapped, by one late the_content filter:
 *
 *   - the lesson being viewed (singular)      → the buy page (templates/locked.php)
 *   - every other place a locked post's content is printed (archives, Divi
 *     blog modules, home, search, feeds, REST, AJAX pagination)
 *                                            → its plain excerpt
 * ---------------------------------------------------------------------- */

add_action( 'template_redirect', 'tbt_access_prepare_locked_singular', 15 );

/**
 * On a locked lesson: remember it, set the headers, load the buy-page styles
 * and close its comments. The page itself is rendered by the theme.
 *
 * Priority 15: after the 0.1.0 sign-in redirects (5) and WooCommerce's own
 * template_redirect work (10).
 *
 * @return void
 */
function tbt_access_prepare_locked_singular() {
	if ( ! tbt_access_enforcing() || is_admin() || is_feed() || is_embed() || ! is_singular() ) {
		return;
	}

	$post = get_queried_object();

	if ( ! $post instanceof WP_Post ) {
		return;
	}

	try {
		$locked = tbt_access_is_locked_for_viewer( $post->ID );
	} catch ( \Throwable $e ) {
		tbt_access_log( 'enforcement failed on post ' . $post->ID . ': ' . $e->getMessage() );
		$locked = true; // Enforcement is on: fail closed on content.
	}

	if ( ! $locked ) {
		return;
	}

	$GLOBALS['tbt_access_locked_main'] = (int) $post->ID;

	header( 'X-Robots-Tag: noindex', true );

	if ( is_user_logged_in() ) {
		nocache_headers();
	}

	add_filter( 'wp_robots', 'tbt_access_robots_noindex', 99 );
	add_filter( 'body_class', static fn( $classes ) => array_merge( (array) $classes, array( 'tbt-locked-page' ) ) );

	// Comments under a locked lesson stay hidden too.
	$id = (int) $post->ID;
	add_filter( 'comments_open', static fn( $open, $post_id ) => (int) $post_id === $id ? false : $open, 99, 2 );
	add_filter( 'pings_open', static fn( $open, $post_id ) => (int) $post_id === $id ? false : $open, 99, 2 );
	add_filter( 'get_comments_number', static fn( $count, $post_id ) => (int) $post_id === $id ? 0 : $count, 99, 2 );
	add_filter( 'comments_array', static fn( $comments, $post_id ) => (int) $post_id === $id ? array() : $comments, 99, 2 );

	tbt_access_enqueue_locked_style();
}

add_filter( 'the_content', 'tbt_access_filter_content', 999999 );

/**
 * Swap the content of locked posts.
 *
 * Priority 999999: after Divi's builder output, shortcodes, wpautop and the
 * other content filters, so none of the lesson survives. Runs on the front
 * end, in REST, feeds and AJAX (Divi blog module pagination); never on
 * admin screens.
 *
 * @param string $content Rendered content.
 * @return string
 */
function tbt_access_filter_content( $content ) {
	if ( ! tbt_access_enforcing() || ( is_admin() && ! wp_doing_ajax() ) ) {
		return $content;
	}

	$post = get_post();

	if ( ! $post instanceof WP_Post ) {
		return $content;
	}

	try {
		$locked = tbt_access_is_locked_for_viewer( $post->ID );
	} catch ( \Throwable $e ) {
		tbt_access_log( 'content filter failed on post ' . $post->ID . ': ' . $e->getMessage() );
		$locked = true;
	}

	if ( ! $locked ) {
		return $content;
	}

	$main = (int) ( $GLOBALS['tbt_access_locked_main'] ?? 0 );

	// The lesson being viewed gets the buy page; excerpt generation, <head>
	// (SEO / sharing plugins) and every other post get the excerpt.
	if ( $main === (int) $post->ID && ! doing_action( 'wp_head' ) && ! doing_filter( 'get_the_excerpt' ) && ! doing_filter( 'wp_trim_excerpt' ) ) {
		return tbt_access_locked_html( $post );
	}

	return tbt_access_excerpt_html( $post );
}

/**
 * A locked post's excerpt as content HTML.
 *
 * @param WP_Post $post Post.
 * @return string
 */
function tbt_access_excerpt_html( WP_Post $post ) {
	$excerpt = tbt_access_safe_excerpt( $post );

	return '' === $excerpt ? '' : '<p class="tbt-locked-excerpt">' . esc_html( $excerpt ) . '</p>';
}

/**
 * The buy page (templates/locked.php) as a string.
 *
 * @param WP_Post $post The locked lesson.
 * @return string
 */
function tbt_access_locked_html( WP_Post $post ) {
	$offer = array(
		'type'       => 'none',
		'course'     => null,
		'product_id' => null,
	);

	try {
		$offer = tbt_access_offer_for( (int) $post->ID );
	} catch ( \Throwable $e ) {
		tbt_access_log( 'offer for post ' . $post->ID . ' failed: ' . $e->getMessage() );
	}

	$template = TBT_ACCESS_DIR . 'templates/locked.php';

	if ( ! file_exists( $template ) ) {
		return tbt_access_excerpt_html( $post );
	}

	tbt_access_enqueue_locked_style(); // Footer fallback if template_redirect didn't run.

	ob_start();

	try {
		$tbt_locked_post  = $post;
		$tbt_locked_offer = $offer;
		include $template;
	} catch ( \Throwable $e ) {
		ob_end_clean();
		tbt_access_log( 'buy page for post ' . $post->ID . ' failed: ' . $e->getMessage() );
		return tbt_access_excerpt_html( $post );
	}

	return (string) ob_get_clean();
}

/**
 * noindex in the robots meta tag (WordPress 5.7+).
 *
 * @param array $robots Directives.
 * @return array
 */
function tbt_access_robots_noindex( $robots ) {
	$robots            = (array) $robots;
	$robots['noindex'] = true;
	unset( $robots['index'] );

	return $robots;
}

/**
 * Styles for the buy page.
 *
 * @return void
 */
function tbt_access_enqueue_locked_style() {
	$deps = wp_style_is( 'tbt-tokens', 'registered' ) ? array( 'tbt-tokens' ) : array();

	wp_enqueue_style(
		'tbt-access-locked',
		TBT_ACCESS_URL . 'assets/tbt-access-locked.css',
		$deps,
		tbt_access_asset_version( 'assets/tbt-access-locked.css' )
	);
}

/* -------------------------------------------------------------------------
 * T5 — "KUP KURS": add to cart once, go to checkout
 * ---------------------------------------------------------------------- */

/**
 * The buy link for a course product.
 *
 * @param int $product_id Product ID.
 * @return string
 */
function tbt_access_buy_url( $product_id ) {
	return add_query_arg( 'tbt_buy', (int) $product_id, home_url( '/' ) );
}

add_action( 'wp_loaded', 'tbt_access_handle_buy', 30 );

/**
 * ?tbt_buy=<product>: put the course in the cart (once, quantity 1) and go to
 * checkout. Runs after WooCommerce's own add-to-cart handler (wp_loaded 20),
 * so the cart is loaded. A product that can't be bought goes to its page.
 *
 * @return void
 */
function tbt_access_handle_buy() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- an add-to-cart link, like WooCommerce's own ?add-to-cart=.
	$product_id = isset( $_GET['tbt_buy'] ) ? absint( $_GET['tbt_buy'] ) : 0;

	if ( ! $product_id || is_admin() || ! function_exists( 'WC' ) || ! function_exists( 'wc_get_product' ) ) {
		return;
	}

	try {
		$product = wc_get_product( $product_id );

		if ( ! $product instanceof WC_Product || 'publish' !== $product->get_status() || ! tbt_access_product_grant( $product ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}

		if ( null === WC()->cart && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}

		$cart = WC()->cart;

		if ( ! $cart || ! $product->is_purchasable() ) {
			wp_safe_redirect( get_permalink( $product_id ) );
			exit;
		}

		$in_cart = false;

		foreach ( $cart->get_cart() as $item ) {
			if ( (int) ( $item['product_id'] ?? 0 ) === $product_id || (int) ( $item['variation_id'] ?? 0 ) === $product_id ) {
				$in_cart = true;
				break;
			}
		}

		if ( ! $in_cart && ! $cart->add_to_cart( $product_id, 1 ) ) {
			wp_safe_redirect( get_permalink( $product_id ) );
			exit;
		}

		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	} catch ( \Throwable $e ) {
		tbt_access_log( 'buy link for product ' . $product_id . ' failed: ' . $e->getMessage() );
	}
}

/* -------------------------------------------------------------------------
 * T3 — REST: excerpt instead of content for locked posts
 * ---------------------------------------------------------------------- */

add_filter( 'rest_prepare_post', 'tbt_access_rest_prepare', 20, 2 );
add_filter( 'rest_prepare_page', 'tbt_access_rest_prepare', 20, 2 );

/**
 * Phase 0.8: /wp-json/wp/v2/posts returned full lessons to anyone. A locked
 * post's content.rendered becomes its excerpt; raw content (edit context)
 * already needs edit rights.
 *
 * @param WP_REST_Response $response Response.
 * @param WP_Post          $post     Post.
 * @return WP_REST_Response
 */
function tbt_access_rest_prepare( $response, $post ) {
	if ( ! tbt_access_enforcing() || ! $response instanceof WP_REST_Response || ! $post instanceof WP_Post ) {
		return $response;
	}

	try {
		$locked = tbt_access_is_locked_for_viewer( $post->ID );
	} catch ( \Throwable $e ) {
		tbt_access_log( 'REST check failed on post ' . $post->ID . ': ' . $e->getMessage() );
		$locked = true;
	}

	if ( ! $locked ) {
		return $response;
	}

	$data = $response->get_data();

	if ( isset( $data['content'] ) && is_array( $data['content'] ) ) {
		$excerpt = tbt_access_safe_excerpt( $post );

		$data['content']['rendered'] = '' !== $excerpt ? '<p>' . esc_html( $excerpt ) . '</p>' : '';
		$data['content']['protected'] = true;
		unset( $data['content']['raw'], $data['content']['block_version'] );

		$response->set_data( $data );
	}

	return $response;
}

/* -------------------------------------------------------------------------
 * T3 — Admin-bar note for editors
 * ---------------------------------------------------------------------- */

add_action( 'admin_bar_menu', 'tbt_access_admin_bar_note', 100 );

/**
 * "TBT Access: locked for others (course: …)" on content editors can see but
 * others can't.
 *
 * @param WP_Admin_Bar $bar Admin bar.
 * @return void
 */
function tbt_access_admin_bar_note( $bar ) {
	try {
		if ( is_admin() || ! is_singular() || ! current_user_can( 'edit_others_posts' ) ) {
			return;
		}

		$post = get_queried_object();

		if ( ! $post instanceof WP_Post || tbt_access_is_free( $post->ID ) || in_array( (int) $post->ID, tbt_access_never_lock_ids(), true ) ) {
			return;
		}

		$courses = tbt_access_post_courses( (int) $post->ID );

		if ( ! $courses || ! in_array( $post->post_type, tbt_access_restricted_post_types(), true ) ) {
			return;
		}

		$exclusive = array_filter(
			$courses,
			static function ( $key ) {
				$course = tbt_access_course( $key );
				return $course && $course['exclusive'];
			}
		);

		$names = array_map(
			static function ( $key ) {
				$course = tbt_access_course( $key );
				return $course ? $course['name'] : $key;
			},
			$exclusive ? $exclusive : $courses
		);

		$title = sprintf( 'TBT Access: locked for others (course: %s)', implode( ', ', $names ) );

		if ( ! tbt_access_enforcing() ) {
			$title .= ' — enforcement off';
		}

		$bar->add_node(
			array(
				'id'    => 'tbt-access-locked',
				'title' => esc_html( $title ),
				'href'  => current_user_can( 'manage_options' ) ? tbt_access_courses_screen_url() : false,
			)
		);
	} catch ( \Throwable $e ) {
		tbt_access_log( 'admin bar note failed: ' . $e->getMessage() );
	}
}

/* -------------------------------------------------------------------------
 * T4 — Locked look in lists
 * ---------------------------------------------------------------------- */

add_filter( 'post_class', 'tbt_access_post_class', 20, 3 );

/**
 * tbt-locked on posts the viewer can't open, tbt-free on free lessons.
 * Same for signed-in and signed-out visitors (pass holders see courses
 * greyed out too).
 *
 * @param string[] $classes Classes.
 * @param string[] $class   Extra classes requested.
 * @param int      $post_id Post ID.
 * @return string[]
 */
function tbt_access_post_class( $classes, $class, $post_id ) {
	if ( is_admin() || ! tbt_access_enforcing() ) {
		return $classes;
	}

	try {
		$post_id = (int) $post_id;

		if ( tbt_access_is_free( $post_id ) ) {
			$classes[] = 'tbt-free';
		} elseif ( tbt_access_is_locked_for_viewer( $post_id ) ) {
			$classes[] = 'tbt-locked';
		}
	} catch ( \Throwable $e ) {
		tbt_access_log( 'post_class failed on post ' . (int) $post_id . ': ' . $e->getMessage() );
	}

	return $classes;
}

add_action( 'wp_enqueue_scripts', 'tbt_access_enqueue_list_style', 20 );

/**
 * The list styles: small, inline, front end only, only while enforcing.
 *
 * Phase 0.7: VIP cards are Divi blog modules, <article class="et_pb_post …">
 * with the image in .et_pb_image_container (which also holds TBT's
 * .tbt-actions overlay). The lock and the "Za darmo" pill sit top left, as
 * ::before, so they never replace .tbt-actions. Everything is wrapped in :where() so theme rules still win where they
 * set the same property.
 *
 * @return void
 */
function tbt_access_enqueue_list_style() {
	if ( ! tbt_access_enforcing() ) {
		return;
	}

	$lock = rawurlencode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>' );

	$media = ':where(.tbt-locked, .tbt-free) :where(.et_pb_image_container, .post-thumbnail, .entry-featured-image-url)';

	$css = $media . '{position:relative}'
		. ':where(.tbt-locked) :where(.et_pb_image_container, .post-thumbnail, .entry-featured-image-url) img{filter:saturate(.25);opacity:.6;transition:filter .2s ease,opacity .2s ease}'
		. ':where(.tbt-locked):hover :where(.et_pb_image_container, .post-thumbnail, .entry-featured-image-url) img{filter:saturate(.6);opacity:.85}'
		. ':where(.tbt-locked) :where(.et_pb_image_container, .post-thumbnail){position:relative}'
		. ':where(.tbt-locked) :where(.et_pb_image_container, .post-thumbnail)::before{content:"";position:absolute;top:10px;left:10px;z-index:2;width:32px;height:32px;border-radius:999px;'
		. 'background:var(--tbt-ink,#1F2937) url("data:image/svg+xml,' . $lock . '") center/18px 18px no-repeat;box-shadow:0 2px 6px rgba(0,0,0,.2);pointer-events:none}'
		. ':where(.tbt-free) :where(.et_pb_image_container, .post-thumbnail)::before{content:"Za darmo";position:absolute;top:10px;left:10px;z-index:2;padding:4px 12px;border-radius:999px;'
		. 'background:var(--tbt-success,#16803C);color:#FFFFFF;font:600 12px/1.4 var(--tbt-font-ui,"Roboto",sans-serif);letter-spacing:.04em;box-shadow:0 2px 6px rgba(0,0,0,.2);pointer-events:none}'
		// No image: put the marker before the title instead.
		. ':where(.tbt-locked):not(:has(.et_pb_image_container, .post-thumbnail)) :where(.entry-title)::before{content:"🔒 ";font-size:.85em}'
		. ':where(.tbt-free):not(:has(.et_pb_image_container, .post-thumbnail)) :where(.entry-title)::after{content:"Za darmo";display:inline-block;margin-left:8px;padding:2px 10px;border-radius:999px;vertical-align:middle;'
		. 'background:var(--tbt-success,#16803C);color:#FFFFFF;font:600 12px/1.4 var(--tbt-font-ui,"Roboto",sans-serif)}';

	wp_register_style( 'tbt-access-lists', false, array(), TBT_ACCESS_VERSION );
	wp_enqueue_style( 'tbt-access-lists' );
	wp_add_inline_style( 'tbt-access-lists', $css );
}

/* -------------------------------------------------------------------------
 * T6 — Free lesson switch
 * ---------------------------------------------------------------------- */

add_action( 'add_meta_boxes', 'tbt_access_free_meta_box' );

/**
 * Side box on posts and pages (classic and block editor).
 *
 * @return void
 */
function tbt_access_free_meta_box() {
	foreach ( array( 'post', 'page' ) as $type ) {
		add_meta_box( 'tbt-access-free', __( 'TBT Access', 'tbt-access' ), 'tbt_access_free_meta_box_html', $type, 'side', 'default' );
	}
}

/**
 * The checkbox, plus which courses the post belongs to.
 *
 * @param WP_Post $post Post.
 * @return void
 */
function tbt_access_free_meta_box_html( $post ) {
	wp_nonce_field( 'tbt_access_free_' . $post->ID, 'tbt_access_free_nonce' );
	?>
	<p><label><input type="checkbox" name="tbt_access_free" value="1" <?php checked( tbt_access_is_free( $post->ID ) ); ?>> <?php esc_html_e( 'Free lesson (open to everyone)', 'tbt-access' ); ?></label></p>
	<?php
	$names = array();
	foreach ( tbt_access_post_courses( (int) $post->ID ) as $key ) {
		$course  = tbt_access_course( $key );
		$names[] = $course ? $course['name'] . ( $course['exclusive'] ? ' (exclusive)' : '' ) : $key;
	}
	?>
	<p class="description">
		<?php
		echo esc_html(
			$names
				/* translators: %s: course names. */
				? sprintf( __( 'Belongs to: %s.', 'tbt-access' ), implode( ', ', $names ) )
				: __( 'Belongs to no course: open to everyone anyway.', 'tbt-access' )
		);
		?>
	</p>
	<?php
}

add_action( 'save_post', 'tbt_access_free_save', 10, 2 );

/**
 * Save the switch.
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post.
 * @return void
 */
function tbt_access_free_save( $post_id, $post ) {
	$nonce = isset( $_POST['tbt_access_free_nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['tbt_access_free_nonce'] ) ) : '';

	if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'tbt_access_free_' . $post_id ) ) {
		return;
	}

	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}

	if ( ! empty( $_POST['tbt_access_free'] ) ) {
		update_post_meta( $post_id, '_tbt_access_free', '1' );
	} else {
		delete_post_meta( $post_id, '_tbt_access_free' );
	}
}

add_filter( 'manage_posts_columns', 'tbt_access_free_column', 10, 2 );
add_filter( 'manage_pages_columns', 'tbt_access_free_column' );

/**
 * "Free" column on Posts and Pages.
 *
 * @param array  $columns   Columns.
 * @param string $post_type Post type (posts only).
 * @return array
 */
function tbt_access_free_column( $columns, $post_type = 'page' ) {
	if ( in_array( $post_type, array( 'post', 'page' ), true ) ) {
		$columns['tbt_access_free'] = __( 'Free', 'tbt-access' );
	}

	return $columns;
}

add_action( 'manage_posts_custom_column', 'tbt_access_free_column_value', 10, 2 );
add_action( 'manage_pages_custom_column', 'tbt_access_free_column_value', 10, 2 );

/**
 * Print the Free column.
 *
 * @param string $column  Column key.
 * @param int    $post_id Post ID.
 * @return void
 */
function tbt_access_free_column_value( $column, $post_id ) {
	if ( 'tbt_access_free' === $column ) {
		echo tbt_access_is_free( $post_id ) ? '<span class="dashicons dashicons-unlock" title="' . esc_attr__( 'Free lesson', 'tbt-access' ) . '"></span>' : '—';
	}
}

add_filter( 'views_edit-post', 'tbt_access_free_view' );
add_filter( 'views_edit-page', 'tbt_access_free_view' );

/**
 * "Free lessons (N)" quick filter link.
 *
 * @param array $views Views.
 * @return array
 */
function tbt_access_free_view( $views ) {
	$screen = get_current_screen();
	$type   = $screen && $screen->post_type ? $screen->post_type : 'post';

	$count = ( new WP_Query(
		array(
			'post_type'      => $type,
			'post_status'    => 'any',
			'fields'         => 'ids',
			'posts_per_page' => 1,
			'meta_key'       => '_tbt_access_free', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		)
	) )->found_posts;

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
	$current = ! empty( $_GET['tbt_free'] );

	$views['tbt_access_free'] = sprintf(
		'<a href="%1$s"%2$s>%3$s <span class="count">(%4$d)</span></a>',
		esc_url( add_query_arg( array( 'post_type' => $type, 'tbt_free' => 1 ), admin_url( 'edit.php' ) ) ),
		$current ? ' class="current" aria-current="page"' : '',
		esc_html__( 'Free lessons', 'tbt-access' ),
		(int) $count
	);

	return $views;
}

add_action( 'pre_get_posts', 'tbt_access_free_filter' );

/**
 * Apply ?tbt_free=1 on the Posts / Pages list.
 *
 * @param WP_Query $query Query.
 * @return void
 */
function tbt_access_free_filter( $query ) {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list filter.
	if ( ! is_admin() || ! $query->is_main_query() || empty( $_GET['tbt_free'] ) || 'edit.php' !== ( $GLOBALS['pagenow'] ?? '' ) ) {
		return;
	}

	$query->set( 'meta_key', '_tbt_access_free' );
	$query->set( 'meta_value', '1' );
}

/* -------------------------------------------------------------------------
 * T10 — My account: "Twoje kursy"
 * ---------------------------------------------------------------------- */

add_action( 'woocommerce_account_dashboard', 'tbt_access_account_courses', 11 );

/**
 * Under the platform line: active library and course grants, with links.
 *
 * @return void
 */
function tbt_access_account_courses() {
	try {
		$user_id = get_current_user_id();

		if ( $user_id <= 0 ) {
			return;
		}

		$rows = array();

		foreach ( tbt_access_courses() as $key => $course ) {
			if ( ! tbt_access_has_grant( $user_id, $key ) ) {
				continue;
			}

			$until  = tbt_access_grant_until( $user_id, $key );
			$rows[] = array(
				'name' => $course['name'],
				'when' => null === $until ? 'bezterminowo' : 'do ' . tbt_access_pl_date( (int) $until ),
				'url'  => tbt_access_course_url( $key ),
			);
		}

		if ( ! $rows ) {
			return;
		}

		echo '<div class="tbt-access-pass tbt-access-courses"><strong class="tbt-access-courses__title">Twoje kursy</strong><ul class="tbt-access-courses__list">';

		foreach ( $rows as $row ) {
			printf(
				'<li><span>%1$s · %2$s</span>%3$s</li>',
				esc_html( $row['name'] ),
				esc_html( $row['when'] ),
				'' !== $row['url'] ? ' <a class="tbt-access-pass__link" href="' . esc_url( $row['url'] ) . '">Przejdź do kursu</a>' : ''
			);
		}

		echo '</ul></div>';
	} catch ( \Throwable $e ) {
		tbt_access_log( 'My account courses failed: ' . $e->getMessage() );
	}
}
