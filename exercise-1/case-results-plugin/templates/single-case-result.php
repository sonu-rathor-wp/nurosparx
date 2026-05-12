<?php
/**
 * Single Case Result template
 *
 * WordPress template hierarchy: single-{post_type}.php
 * This file should be placed inside your THEME (not the plugin).
 * We include it here as a reference / drop-in template.
 *
 * Displays:
 *  1. Full case details (settlement, duration, location, etc.)
 *  2. Featured image (if set).
 *  3. Post content / description.
 *  4. Schema.org structured data for SEO.
 *
 * @package CaseResults
 */

get_header();
?>

<?php if ( have_posts() ) : the_post(); ?>

	<main id="primary" class="site-main cr-single">

		<!-- Hero section with featured image -->
		<?php if ( has_post_thumbnail() ) : ?>
			<section class="cr-single-hero">
				<?php the_post_thumbnail( 'full', array( 'class' => 'cr-single-image' ) ); ?>
			</section>
		<?php endif; ?>

		<!-- Main content -->
		<article <?php post_class( 'cr-single-post' ); ?>>

			<!-- Header with case type badge and year -->
			<div class="cr-single-header">
				<div class="cr-container">

					<?php
					$post_id           = get_the_ID();
					$case_type_key     = get_post_meta( $post_id, \CaseResults\Meta_Boxes::PREFIX . 'case_type',         true );
					$settlement_amount = get_post_meta( $post_id, \CaseResults\Meta_Boxes::PREFIX . 'settlement_amount', true );
					$case_duration     = get_post_meta( $post_id, \CaseResults\Meta_Boxes::PREFIX . 'case_duration',     true );
					$client_city       = get_post_meta( $post_id, \CaseResults\Meta_Boxes::PREFIX . 'client_city',       true );
					$client_state      = get_post_meta( $post_id, \CaseResults\Meta_Boxes::PREFIX . 'client_state',      true );
					$case_year         = get_post_meta( $post_id, \CaseResults\Meta_Boxes::PREFIX . 'case_year',         true );

					$case_type_label = isset( \CaseResults\Meta_Boxes::CASE_TYPES[ $case_type_key ] )
						? \CaseResults\Meta_Boxes::CASE_TYPES[ $case_type_key ]
						: '';

					$formatted_amount = \CaseResults\Frontend::format_currency( $settlement_amount );
					$location = trim( $client_city . ', ' . $client_state, ', ' );
					?>

					<?php if ( ! empty( $case_type_label ) ) : ?>
						<span class="cr-badge cr-badge--<?php echo esc_attr( $case_type_key ); ?>">
							<?php echo esc_html( $case_type_label ); ?>
						</span>
					<?php endif; ?>

					<h1 class="cr-single-title entry-title" itemprop="name">
						<?php the_title(); ?>
					</h1>

					<?php if ( $case_year ) : ?>
						<span class="cr-single-year">
							<?php printf( esc_html__( 'Settled %s', 'case-results' ), esc_html( $case_year ) ); ?>
						</span>
					<?php endif; ?>

				</div>
			</div>

			<!-- Key details grid -->
			<section class="cr-single-details">
				<div class="cr-container">

					<div class="cr-details-grid">

						<!-- Settlement Amount -->
						<?php if ( $settlement_amount ) : ?>
							<div class="cr-detail-card">
								<div class="cr-detail-label">
									<?php esc_html_e( 'Settlement Amount', 'case-results' ); ?>
								</div>
								<div class="cr-detail-value" itemprop="priceRange">
									<?php echo esc_html( $formatted_amount ); ?>
								</div>
							</div>
						<?php endif; ?>

						<!-- Case Duration -->
						<?php if ( $case_duration ) : ?>
							<div class="cr-detail-card">
								<div class="cr-detail-label">
									<?php esc_html_e( 'Case Duration', 'case-results' ); ?>
								</div>
								<div class="cr-detail-value">
									<?php echo esc_html( sprintf( _n( '%d month', '%d months', intval( $case_duration ), 'case-results' ), intval( $case_duration ) ) ); ?>
								</div>
							</div>
						<?php endif; ?>

						<!-- Location -->
						<?php if ( $location ) : ?>
							<div class="cr-detail-card">
								<div class="cr-detail-label">
									<?php esc_html_e( 'Location', 'case-results' ); ?>
								</div>
								<div class="cr-detail-value" itemprop="areaServed">
									<?php echo esc_html( $location ); ?>
								</div>
							</div>
						<?php endif; ?>

					</div>

				</div>
			</section>

			<!-- Main content -->
			<section class="cr-single-content">
				<div class="cr-container">
					<div class="entry-content">
						<?php the_content(); ?>
					</div>
				</div>
			</section>

			<!-- Navigation: Previous/Next case -->
			<nav class="navigation posts-navigation">
				<div class="cr-container">
					<div class="nav-links">
						<?php
						$prev_post = get_previous_post();
						$next_post = get_next_post();

						if ( $prev_post ) {
							echo '<div class="nav-previous">';
							echo '<a href="' . esc_url( get_permalink( $prev_post ) ) . '">';
							echo esc_html__( '← Previous Case', 'case-results' );
							echo '</a>';
							echo '</div>';
						}

						if ( $next_post ) {
							echo '<div class="nav-next">';
							echo '<a href="' . esc_url( get_permalink( $next_post ) ) . '">';
							echo esc_html__( 'Next Case →', 'case-results' );
							echo '</a>';
							echo '</div>';
						}
						?>
					</div>
				</div>
			</nav>

		</article>

		<!-- Structured data (Schema.org JSON-LD) -->
		<?php echo \CaseResults\Schema::get_single( get_the_ID() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

	</main>

<?php endif; ?>

<?php get_footer();
