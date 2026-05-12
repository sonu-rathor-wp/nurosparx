<?php
/**
 * Handles AJAX requests for filtering Case Results by case type.
 *
 * WordPress AJAX flow:
 *   1. JS sends a POST to /wp-admin/admin-ajax.php with action=cr_filter_cases.
 *   2. WordPress fires wp_ajax_{action} (logged-in) or wp_ajax_nopriv_{action} (guests).
 *   3. Our callback validates, queries, renders, and echoes JSON.
 *   4. wp_die() ends execution cleanly (required by WP AJAX — prevents extra output).
 *
 * Security:
 *  - Nonce verification prevents CSRF from third-party sites.
 *  - Input whitelist: case_type is validated against our known list.
 *  - No raw SQL: we use WP_Query (parameterised under the hood).
 *
 * @package CaseResults
 */

namespace CaseResults;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Ajax_Handler {

	/** AJAX action name — must match the JS 'action' parameter. */
	const ACTION = 'cr_filter_cases';

	public function __construct() {
		// Register for both guests and logged-in users.
		add_action( 'wp_ajax_'        . self::ACTION, array( $this, 'handle' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * Handle the AJAX request.
	 */
	public function handle() {

		// ---------------------------------------------------------------
		// 1. Verify nonce — this proves the request came from our page.
		//    'cr_filter_nonce' matches the key we localised in Frontend.
		// ---------------------------------------------------------------
		check_ajax_referer( 'cr_filter_nonce', 'nonce' );

		// ---------------------------------------------------------------
		// 2. Validate & sanitise input.
		// ---------------------------------------------------------------
		$case_type = isset( $_POST['case_type'] ) ? sanitize_key( $_POST['case_type'] ) : '';
		$paged     = isset( $_POST['paged'] )     ? absint( $_POST['paged'] )           : 1;

		// Whitelist: if the value isn't in our list (and isn't 'all'), reject it.
		$allowed_types = array_keys( Meta_Boxes::CASE_TYPES );
		if ( ! empty( $case_type ) && 'all' !== $case_type && ! in_array( $case_type, $allowed_types, true ) ) {
			wp_send_json_error( array( 'message' => 'Invalid case type.' ), 400 );
		}

		// ---------------------------------------------------------------
		// 3. Build WP_Query args.
		//
		// WHY NOT use a raw SQL query?
		// WP_Query is parameterised; it protects against SQL injection and
		// respects WordPress filters (caching, pagination, etc.).
		// Raw SQL bypasses all of that and is harder to maintain.
		// And we can face performance issues.
		// ---------------------------------------------------------------
		$query_args = array(
			'post_type'      => Post_Type::SLUG,
			'post_status'    => 'publish',
			'posts_per_page' => 9,           // 3-column grid, 3 rows.
			'paged'          => $paged,
			'no_found_rows'  => false,       // We need found_posts for pagination.
			// Order by settlement amount (highest first) so high-value cases
			// appear at the top — important for conversion optimisation.
			'meta_key'       => Meta_Boxes::PREFIX . 'settlement_amount',
			'orderby'        => 'meta_value_num',
			'order'          => 'DESC',
		);

		// Apply case_type filter only when a specific type is selected.
		if ( ! empty( $case_type ) && 'all' !== $case_type ) {
			$query_args['meta_query'] = array(
				array(
					'key'     => Meta_Boxes::PREFIX . 'case_type',
					'value'   => $case_type,
					'compare' => '=',
				),
			);
		}

		$query = new \WP_Query( $query_args );

		// ---------------------------------------------------------------
		// 4. Build response.
		// ---------------------------------------------------------------
		$html            = '';
		$found_posts     = $query->found_posts;
		$max_num_pages   = $query->max_num_pages;

		if ( $query->have_posts() ) {
			ob_start(); // Buffer output so we can return it as a string.
			while ( $query->have_posts() ) {
				$query->the_post();
				// Re-use the same card partial used in the standard template.
				$template = CR_PLUGIN_DIR . 'templates/partials/case-card.php';
				if ( file_exists( $template ) ) {
					include $template;
				}
			}
			wp_reset_postdata(); // CRITICAL — restores global $post after custom query.
			$html = ob_get_clean();
		} else {
			$html = '<p class="cr-no-results">' . esc_html__( 'No cases found for the selected filter.', 'case-results' ) . '</p>';
		}

		wp_send_json_success( array(
			'html'          => $html,
			'found_posts'   => $found_posts,
			'max_num_pages' => $max_num_pages,
			'current_page'  => $paged,
		) );
	}
}
