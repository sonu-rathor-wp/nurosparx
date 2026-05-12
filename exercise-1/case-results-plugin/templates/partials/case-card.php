<?php
/**
 * Partial template: single case result card.
 *
 * Used by:
 *  - archive-case-result.php  (initial server-side render)
 *  - class-ajax-handler.php   (AJAX filter response)
 *  - class-frontend.php       (shortcode render)
 *
 
 *
 * Assumes: $post global is set (via the_post() or include inside WP_Query loop).
 *
 * @package CaseResults
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$post_id           = get_the_ID();
$case_type_key     = get_post_meta( $post_id, \CaseResults\Meta_Boxes::PREFIX . 'case_type',         true );
$settlement_amount = get_post_meta( $post_id, \CaseResults\Meta_Boxes::PREFIX . 'settlement_amount', true );
$case_duration     = get_post_meta( $post_id, \CaseResults\Meta_Boxes::PREFIX . 'case_duration',     true );
$client_city       = get_post_meta( $post_id, \CaseResults\Meta_Boxes::PREFIX . 'client_city',       true );
$client_state      = get_post_meta( $post_id, \CaseResults\Meta_Boxes::PREFIX . 'client_state',      true );
$case_year         = get_post_meta( $post_id, \CaseResults\Meta_Boxes::PREFIX . 'case_year',         true );

// Human-readable case type label.
$case_type_label = isset( \CaseResults\Meta_Boxes::CASE_TYPES[ $case_type_key ] )
	? \CaseResults\Meta_Boxes::CASE_TYPES[ $case_type_key ]
	: '';

// Formatted currency — uses our utility method.
$formatted_amount = \CaseResults\Frontend::format_currency( $settlement_amount );

// Location string.
$location = trim( $client_city . ', ' . $client_state, ', ' );
?>

<!--
  data-post-id and data-case-type are read by JS for GTM tracking.
  data-settlement is passed as an event parameter to GTM.
  WHY data attributes?  Clean separation of concerns — HTML carries the data,
  JS reads it without needing a separate AJAX call or inline script.
-->
<article
	class="cr-card"
	data-post-id="<?php echo esc_attr( $post_id ); ?>"
	data-case-type="<?php echo esc_attr( $case_type_key ); ?>"
	data-settlement="<?php echo esc_attr( $settlement_amount ); ?>"
	itemscope
	itemtype="https://schema.org/LegalService"
>
	<div class="cr-card-header">
		<?php if ( ! empty( $case_type_label ) ) : ?>
			<span class="cr-badge cr-badge--<?php echo esc_attr( $case_type_key ); ?>">
				<?php echo esc_html( $case_type_label ); ?>
			</span>
		<?php endif; ?>

		<?php if ( $case_year ) : ?>
			<span class="cr-card-year"><?php echo esc_html( $case_year ); ?></span>
		<?php endif; ?>
	</div>

	<div class="cr-card-body">
		<!-- Settlement amount — the primary conversion signal -->
		<div class="cr-settlement" itemprop="priceRange">
			<span class="cr-settlement-label"><?php esc_html_e( 'Settlement', 'case-results' ); ?></span>
			<span class="cr-settlement-amount"><?php echo esc_html( $formatted_amount ); ?></span>
		</div>

		<!-- Case title — typically "Car Accident - Downtown LA" etc. -->
		<h2 class="cr-card-title entry-title" itemprop="name">
			<a href="<?php the_permalink(); ?>" itemprop="url">
				<?php the_title(); ?>
			</a>
		</h2>

		<!-- Meta row: location and duration -->
		<div class="cr-card-meta">
			<?php if ( $location ) : ?>
				<span class="cr-meta-item cr-location" itemprop="areaServed">
					<svg aria-hidden="true" focusable="false" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
						<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"></path>
						<circle cx="12" cy="10" r="3"></circle>
					</svg>
					<?php echo esc_html( $location ); ?>
				</span>
			<?php endif; ?>

			<?php if ( $case_duration ) : ?>
				<span class="cr-meta-item cr-duration">
					<svg aria-hidden="true" focusable="false" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
						<circle cx="12" cy="12" r="10"></circle>
						<polyline points="12 6 12 12 16 14"></polyline>
					</svg>
					<?php
					echo esc_html(
						sprintf(
							// translators: %d = number of months.
							_n( '%d month', '%d months', $case_duration, 'case-results' ),
							$case_duration
						)
					);
					?>
				</span>
			<?php endif; ?>
		</div>
	</div>

	<div class="cr-card-footer">
		<!--
		  This <a> is the GTM tracking target.
		  JS listens for clicks on .cr-card-link inside .cr-card and fires the
		  'case_result_view' GTM event with the card's data attributes.
		-->
		<a href="<?php the_permalink(); ?>"
		   class="cr-card-link cr-btn"
		   aria-label="<?php echo esc_attr( sprintf( __( 'View case: %s', 'case-results' ), get_the_title() ) ); ?>">
			<?php esc_html_e( 'View Case Details', 'case-results' ); ?>
		</a>
	</div>
</article>
