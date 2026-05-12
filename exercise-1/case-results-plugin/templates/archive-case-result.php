<?php
/**
 * Archive template for Case Results CPT.
 *
 * WordPress template hierarchy: archive-{post_type}.php
 * This file should be placed inside your THEME (not the plugin).
 * We include it here as a reference / drop-in template.
 *
 * If you're using a block theme, create a corresponding block template instead.
 *
 * WHY in the theme, not the plugin?
 *  Templates control presentation, which is the theme's responsibility.
 *  The plugin owns data and business logic only.  Separating concerns keeps
 *  the plugin portable — install it on any theme without changes.
 *
 * @package CaseResults
 */

get_header();
?>

<main id="primary" class="site-main cr-archive">

	<header class="cr-archive-header">
		<div class="cr-container">
			<h1 class="cr-archive-title">
				<?php esc_html_e( 'Case Results', 'case-results' ); ?>
			</h1>
			<p class="cr-archive-subtitle">
				<?php esc_html_e( 'Real results for real people. Browse our successful case settlements.', 'case-results' ); ?>
			</p>
		</div>
	</header>

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

	<!-- ================================================================
	     Results Grid
	     The #cr-results-grid div is the AJAX target — JS replaces its
	     inner HTML on each filter change.
	     ================================================================ -->
	<section class="cr-results-section">
		<div class="cr-container">

			<!-- Initial server-rendered grid (no JS needed for first load) -->
			<div id="cr-results-grid" class="cr-grid">
				<?php if ( have_posts() ) : ?>
					<?php while ( have_posts() ) : the_post(); ?>
						<?php include plugin_dir_path( __FILE__ ) . 'partials/case-card.php'; ?>
					<?php endwhile; ?>
				<?php else : ?>
					<p class="cr-no-results">
						<?php esc_html_e( 'No case results found.', 'case-results' ); ?>
					</p>
				<?php endif; ?>
			</div>

			<!-- Pagination — updated via JS on filter changes -->
			<div id="cr-pagination" class="cr-pagination">
				<?php
				the_posts_pagination( array(
					'mid_size'  => 2,
					'prev_text' => __( '&laquo; Previous', 'case-results' ),
					'next_text' => __( 'Next &raquo;',     'case-results' ),
				) );
				?>
			</div>

		</div><!-- .cr-container -->
	</section>

	<!-- Schema.org structured data for the archive -->
	<?php
	global $wp_query;
	echo \CaseResults\Schema::get_archive( $wp_query ); // Already escaped inside JSON-LD.
	?>

</main>

<?php get_footer(); ?>
