<?php
/**
 * Schema.org Structured Data for Case Results.
 *
 * We use JSON-LD (not Microdata) because:
 *  - It's Google's recommended format.
 *  - It lives in <script> tags, not tangled with HTML.
 *  - Easier to maintain and validate.
 *
 * @package CaseResults
 */

namespace CaseResults;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Schema {

	/**
	 * Generate JSON-LD structured data for a single Case Result post.
	 *
	 * @param  int $post_id  The post ID.
	 * @return string        A <script type="application/ld+json"> block.
	 */
	public static function get_single( $post_id ) {
		$post              = get_post( $post_id );
		$case_type_key     = get_post_meta( $post_id, Meta_Boxes::PREFIX . 'case_type',         true );
		$settlement_amount = get_post_meta( $post_id, Meta_Boxes::PREFIX . 'settlement_amount', true );
		$case_duration     = get_post_meta( $post_id, Meta_Boxes::PREFIX . 'case_duration',     true );
		$client_city       = get_post_meta( $post_id, Meta_Boxes::PREFIX . 'client_city',       true );
		$client_state      = get_post_meta( $post_id, Meta_Boxes::PREFIX . 'client_state',      true );
		$case_year         = get_post_meta( $post_id, Meta_Boxes::PREFIX . 'case_year',         true );

		// Human-readable case type for schema description.
		$case_type_label = isset( Meta_Boxes::CASE_TYPES[ $case_type_key ] )
			? Meta_Boxes::CASE_TYPES[ $case_type_key ]
			: '';

		$schema = array(
			'@context'    => 'https://schema.org',
			'@type'       => 'LegalService',   // Best-fit type for case results.
			'name'        => get_the_title( $post_id ),
			'description' => sprintf(
				// translators: 1: case type, 2: settlement amount, 3: city, 4: state.
				__( '%1$s case settled for $%2$s in %3$s, %4$s.', 'case-results' ),
				esc_html( $case_type_label ),
				number_format( (float) $settlement_amount ),
				esc_html( $client_city ),
				esc_html( $client_state )
			),
			'url'         => get_permalink( $post_id ),
			'areaServed'  => array(
				'@type'           => 'Place',
				'name'            => $client_city . ', ' . $client_state,
				'address'         => array(
					'@type'           => 'PostalAddress',
					'addressLocality' => $client_city,
					'addressRegion'   => $client_state,
					'addressCountry'  => 'US',
				),
			),
		);

		// Only add priceRange if we have a value — don't expose empty data to Google.
		if ( $settlement_amount ) {
			$schema['priceRange'] = '$' . number_format( (float) $settlement_amount );
		}

		// Add datePublished if we have a case year.
		if ( $case_year ) {
			$schema['datePublished'] = $case_year . '-01-01';
		}

		// wp_json_encode with JSON_UNESCAPED_SLASHES for cleaner URLs in output.
		$json = wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );

		return '<script type="application/ld+json">' . "\n" . $json . "\n" . '</script>' . "\n";
	}

	/**
	 * Generate JSON-LD for the archive page (a list of LegalService items).
	 *
	 * @param  \WP_Query $query  The archive query object.
	 * @return string
	 */
	public static function get_archive( $query ) {
		$items = array();

		if ( $query->have_posts() ) {
			foreach ( $query->posts as $post ) {
				$settlement = get_post_meta( $post->ID, Meta_Boxes::PREFIX . 'settlement_amount', true );
				$case_type  = get_post_meta( $post->ID, Meta_Boxes::PREFIX . 'case_type',         true );
				$items[] = array(
					'@type'      => 'LegalService',
					'name'       => $post->post_title,
					'url'        => get_permalink( $post->ID ),
					'priceRange' => $settlement ? '$' . number_format( (float) $settlement ) : '',
					'description'=> isset( Meta_Boxes::CASE_TYPES[ $case_type ] ) ? Meta_Boxes::CASE_TYPES[ $case_type ] : '',
				);
			}
		}

		$schema = array(
			'@context'        => 'https://schema.org',
			'@type'           => 'ItemList',
			'name'            => __( 'Legal Case Results', 'case-results' ),
			'description'     => __( 'Recent successful case results from our legal team.', 'case-results' ),
			'numberOfItems'   => count( $items ),
			'itemListElement' => $items,
		);

		$json = wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );

		return '<script type="application/ld+json">' . "\n" . $json . "\n" . '</script>' . "\n";
	}
}
