<?php
/**
 * Frontend logic: asset enqueuing, shortcode, and utility functions.
 *
 * The shortcode [case_results] renders the 5 most recent high-value cases
 * (settlement > $100,000) in a responsive grid.  This gives editors the
 * flexibility to embed the widget on any page (homepage, landing pages, etc.)
 * without requiring a developer each time.
 *
 * @package CaseResults
 */

namespace CaseResults;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Frontend {

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_shortcode( 'case_results',    array( $this, 'render_shortcode' ) );
		// Load plugin template for single CPT posts (works with both classic + block themes)
		add_filter( 'single_template', array( $this, 'load_plugin_template' ) );
		//Archive CPT template loader
		add_filter( 'template_include', array( $this, 'load_archive_template' ) );
		// Extra hook for block themes: ensure CSS loads on single case posts
		add_action( 'wp', array( $this, 'enqueue_single_case_assets' ) );
	}

	// -----------------------------------------------------------------------
	// Enqueue CSS + JS
	// -----------------------------------------------------------------------

	public function enqueue_assets() {
		// Only enqueue on pages that need it — the CPT archive, single posts,
		// or any page that uses the shortcode.  This avoids loading 2 extra
		// files on every page of the site.
		if ( ! is_singular( Post_Type::SLUG ) &&
			 ! is_post_type_archive( Post_Type::SLUG ) &&
			 ! $this->page_has_shortcode() ) {
			return;
		}

		// Stylesheet.
		wp_enqueue_style(
			'case-results-style',
			CR_PLUGIN_URL . 'assets/css/case-results.css',
			array(),        // No dependencies.
			CR_VERSION      // Cache-busting via version string.
		);

		// Main script — depends on jQuery (already in WordPress core).
		wp_enqueue_script(
			'case-results-script',
			CR_PLUGIN_URL . 'assets/js/case-results.js',
			array( 'jquery' ),
			CR_VERSION,
			true            // Load in footer — avoids render-blocking.
		);

		// Pass PHP data to JS via wp_localize_script.
		wp_localize_script(
			'case-results-script',
			'CaseResultsData',   // JS global variable name.
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				'nonce'      => wp_create_nonce( 'cr_filter_nonce' ),
				'action'     => Ajax_Handler::ACTION,
				// i18n strings for AJAX UI feedback. Define them here so they can be translated and easily updated. 
				'i18n'       => array(
					'loading'  => __( 'Loading…',                    'case-results' ),
					'noResults'=> __( 'No cases found.',              'case-results' ),
					'error'    => __( 'An error occurred. Try again.','case-results' ),
				),
			)
		);
	}

	/**
	 * Check if the current page's content contains our shortcode.
	 * Used to conditionally enqueue assets.
	 */
	private function page_has_shortcode() {
		global $post;
		return is_a( $post, 'WP_Post' ) && has_shortcode( $post->post_content, 'case_results' );
	}

	/**
	 * Load plugin template for single case results.
	 * 
	 * WordPress template hierarchy:
	 *  1. Theme: /wp-content/themes/your-theme/single-case-result.php
	 *  2. Plugin: /wp-content/plugins/case-results-plugin/templates/single-case-result.php
	 *
	 * This filter ensures the plugin template is used if the theme doesn't provide one.
	 *
	 * @param  string $template The currently selected template file.
	 * @return string           The plugin template if single case result, otherwise the passed template.
	 */
	public function load_plugin_template( $template ) {
		// Only for single case result posts
		if ( is_singular( Post_Type::SLUG ) ) {
			// Check if theme has its own single-case-result.php
			$theme_template = locate_template( 'single-case-result.php' );
			
			// If theme has it, use theme version (theme takes priority)
			if ( $theme_template ) {
				return $theme_template;
			}
			
			// Otherwise, use the plugin template
			$plugin_template = CR_PLUGIN_DIR . 'templates/single-case-result.php';
			if ( file_exists( $plugin_template ) ) {
				return $plugin_template;
			}
		}
		
		return $template;
	}

	public function load_archive_template( $template ) {

			// Only for CPT archive
			if ( is_post_type_archive( 'case-result' ) ) {

					// 1. Check theme first (override support)
					$theme_template = locate_template( 'archive-case-result.php' );

					if ( ! empty( $theme_template ) ) {
							return $theme_template;
					}

					// 2. Fallback to plugin template
					$plugin_template = plugin_dir_path( __FILE__ ) . '../templates/archive-case-result.php';

					if ( file_exists( $plugin_template ) ) {
							return $plugin_template;
					}
			}

			return $template;
	}
	/**
	 * Extra asset enqueuing for block themes that bypass PHP template system.
	 * Hooked on 'wp' (after WordPress query is ready).
	 */
	public function enqueue_single_case_assets() {
		// Force enqueue if this is a single case post
		if ( is_singular( Post_Type::SLUG ) ) {
			// Re-call enqueue to ensure CSS/JS loads even on block templates
			$this->enqueue_assets();
		}
	}

	// -----------------------------------------------------------------------
	// Shortcode: [case_results limit="5" min_amount="100000"]
	// -----------------------------------------------------------------------

	/**
	 * Render the high-value case results grid.
	 *
	 * Shortcode attributes allow editors to override defaults without code.
	 *
	 * @param  array  $atts  Shortcode attributes.
	 * @return string        HTML output.
	 */
	public function render_shortcode( $atts ) {
		// Merge user-supplied attrs with our defaults.
		$atts = shortcode_atts(
			array(
				'limit'      => 5,       // How many cases to show.
				'min_amount' => 0,  // Only show cases worth > $100k.
				'case_type'  => '',      // Optional: filter to a single type.
			),
			$atts,
			'case_results'
		);

		// Sanitise shortcode input — treat everything as potentially untrusted.
		$limit      = absint( $atts['limit'] );
		$min_amount = absint( $atts['min_amount'] );
		$case_type  = sanitize_key( $atts['case_type'] );

		$query_args = array(
			'post_type'      => Post_Type::SLUG,
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'no_found_rows'  => true,     // No pagination needed in shortcode.
			// meta_key + orderby: order by settlement amount (highest first).
			// IMPORTANT: meta_value_num treats the value as a number, not a
			// string — "1000000" > "99999" numerically but "9" > "1000000"
			// alphabetically.  Always use _num for numeric fields.
			'meta_key'       => Meta_Boxes::PREFIX . 'settlement_amount',
			'orderby'        => 'meta_value_num',
			'order'          => 'DESC',
			// Filter to cases above the minimum amount.
			// We use a meta_query with NUMERIC compare to avoid N+1 PHP loops.
			'meta_query'     => array(
				array(
					'key'     => Meta_Boxes::PREFIX . 'settlement_amount',
					'value'   => $min_amount,
					'compare' => '>=',
					'type'    => 'NUMERIC',
				),
			),
		);

		// Optionally narrow to a specific case type.
		if ( ! empty( $case_type ) && array_key_exists( $case_type, Meta_Boxes::CASE_TYPES ) ) {
			// Add a second condition — combine with AND relation.
			$query_args['meta_query']['relation'] = 'AND';
			$query_args['meta_query'][]           = array(
				'key'     => Meta_Boxes::PREFIX . 'case_type',
				'value'   => $case_type,
				'compare' => '=',
			);
		}

		$query = new \WP_Query( $query_args );

		// Use output buffering to return HTML as a string (required by shortcodes).
		ob_start();
		if ( $query->have_posts() ) { ?>
			
			<!-- ================================================================
				Filter Bar
				Uses data-type attributes to pass values to AJAX handler.
			================================================================ -->
			<section class="cr-filter-bar" aria-label="<?php esc_attr_e( 'Filter cases by type', 'case-results' ); ?>">
				<div class="cr-container">
					<div class="cr-filter-buttons" role="group" aria-label="<?php esc_attr_e( 'Case type filter', 'case-results' ); ?>">
						<button class="cr-filter-btn active" data-type="all" aria-pressed="true">
							<?php esc_html_e( 'All Cases', 'case-results' ); ?>
						</button>
						<?php foreach ( \CaseResults\Meta_Boxes::CASE_TYPES as $value => $label ) : ?>
							<button class="cr-filter-btn" data-type="<?php echo esc_attr( $value ); ?>" aria-pressed="false">
								<?php echo esc_html( $label ); ?>
							</button>
						<?php endforeach; ?>
					</div>
				</div>
			</section>
			<div id="cr-shortcode-grid" class="cr-grid cr-shortcode-grid">

				<?php
				while ( $query->have_posts() ) {
					$query->the_post();
					$template = CR_PLUGIN_DIR . 'templates/partials/case-card.php';
					if ( file_exists( $template ) ) {
						include $template;
					}
				} ?>
			</div>
			<?php 
			wp_reset_postdata();
		} else {
			echo '<p class="cr-no-results">' . esc_html__( 'No qualifying case results found.', 'case-results' ) . '</p>';
		}

		return ob_get_clean();
	}

	// -----------------------------------------------------------------------
	// Static utility: format currency
	// -----------------------------------------------------------------------

	/**
	 * Format a raw integer as a USD currency string.
	 *
	 * @param  int|string $amount  Raw amount (e.g. 250000).
	 * @return string              Formatted string (e.g. "$250,000").
	 */
	public static function format_currency( $amount ) {
		if ( empty( $amount ) || ! is_numeric( $amount ) ) {
			return __( 'Undisclosed', 'case-results' );
		}
		return '$' . number_format( (float) $amount );
	}
}
