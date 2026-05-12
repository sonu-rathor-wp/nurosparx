<?php
/**
 * Registers the "Case Results" Custom Post Type.
 *
 * WHY a class?  Encapsulation.  All CPT logic lives here; nothing leaks into
 * global scope.  Easier to unit-test and maintain.
 *
 * @package CaseResults
 */

namespace CaseResults;

// Guard against direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Post_Type {

	/**
	 * CPT slug — defined as a constant so it's referenced consistently across
	 * the whole plugin (queries, templates, rewrite rules).
	 */
	const SLUG = 'case-result';

	/**
	 * Constructor — hook register() into init.
	 *
	 * We separate the constructor from the actual registration so the
	 * activation hook (in the main file) can call register() directly before
	 * flush_rewrite_rules(), without triggering a second init hook later.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'register' ) );
	}

	/**
	 * Register the Custom Post Type.
	 *
	 * public so the activation hook can call it explicitly.
	 */
	public function register() {

		// Human-readable labels — WordPress uses these in the admin UI.
		$labels = array(
			'name'                  => _x( 'Case Results',         'Post Type General Name', 'case-results' ),
			'singular_name'         => _x( 'Case Result',          'Post Type Singular Name', 'case-results' ),
			'menu_name'             => __( 'Case Results',          'case-results' ),
			'name_admin_bar'        => __( 'Case Result',           'case-results' ),
			'add_new'               => __( 'Add New',               'case-results' ),
			'add_new_item'          => __( 'Add New Case Result',   'case-results' ),
			'edit_item'             => __( 'Edit Case Result',      'case-results' ),
			'view_item'             => __( 'View Case Result',      'case-results' ),
			'all_items'             => __( 'All Case Results',      'case-results' ),
			'search_items'          => __( 'Search Case Results',   'case-results' ),
			'not_found'             => __( 'No case results found', 'case-results' ),
			'not_found_in_trash'    => __( 'No case results found in Trash', 'case-results' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => true,   // Visible on frontend + admin.
			'publicly_queryable' => true,   // Allows ?post_type=case-result queries.
			'show_ui'            => true,   // Show in admin.
			'show_in_menu'       => true,   // Show in admin sidebar.
			'query_var'          => true,
			'rewrite'            => array(
				'slug'       => 'case-results', // Public URL: /case-results/slug
				'with_front' => false,           // Don't prepend /blog/.
			),
			'capability_type'    => 'post',
			'has_archive'        => true,   // Enables /case-results/ archive page.
			'hierarchical'       => false,  // Flat, like posts (not pages).
			'menu_position'      => 5,
			'menu_icon'          => 'dashicons-clipboard', // Admin sidebar icon.
			'supports'           => array(
				'title',        // The case title / headline.
				'editor',       // Optional long-form description.
				'thumbnail',    // Featured image (client photo, etc.).
				'revisions',    // Track editorial changes.
			),
			// REST API support — useful if we ever build a headless front-end
			// or use Gutenberg blocks.
			'show_in_rest'       => true,
		);

		register_post_type( self::SLUG, $args );
	}
}
