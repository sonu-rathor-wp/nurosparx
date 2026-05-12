<?php
/**
 * Plugin Name:     Case Results
 * Plugin URI:      https://example.com/case-results
 * Description:     Custom Post Type for legal firm case results with marketing analytics integration.
 * Version:         1.0.0
 * Author:          Sonu Rathor
 * Author URI:      https://example.com
 * Text Domain:     case-results
 * Domain Path:     /languages
 *
 * @package CaseResults
 */

// Exit immediately if accessed directly — security first.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Constants — centralise paths so every include uses them, never hard-codes.
// ---------------------------------------------------------------------------
define( 'CR_VERSION',     '1.0.0' );
define( 'CR_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'CR_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'CR_PLUGIN_FILE', __FILE__ );

// ---------------------------------------------------------------------------
// Autoload — require each module in order of dependency.
// ---------------------------------------------------------------------------
require_once CR_PLUGIN_DIR . 'includes/class-post-type.php';
require_once CR_PLUGIN_DIR . 'includes/class-meta-boxes.php';
require_once CR_PLUGIN_DIR . 'includes/class-ajax-handler.php';
require_once CR_PLUGIN_DIR . 'includes/class-frontend.php';
require_once CR_PLUGIN_DIR . 'includes/class-schema.php';

// ---------------------------------------------------------------------------
// Bootstrap — instantiate only after all files are loaded.
// ---------------------------------------------------------------------------
add_action( 'plugins_loaded', 'cr_bootstrap' );

/**
 * Initialise all plugin classes.
 *
 */
function cr_bootstrap() {
	new CaseResults\Post_Type();
	new CaseResults\Meta_Boxes();
	new CaseResults\Ajax_Handler();
	new CaseResults\Frontend();
}

// ---------------------------------------------------------------------------
// Activation hook — flush rewrite rules so the CPT slug works immediately.
// ---------------------------------------------------------------------------
register_activation_hook( CR_PLUGIN_FILE, 'cr_activate' );
function cr_activate() {
	// Instantiate the post type so its rewrite rules are registered …
	$cpt = new CaseResults\Post_Type();
	$cpt->register(); // public method so we can call it directly here.
	// … then flush so WordPress picks them up.
	flush_rewrite_rules();
}

// ---------------------------------------------------------------------------
// Deactivation hook — flush again so the CPT slug is removed cleanly.
// ---------------------------------------------------------------------------
register_deactivation_hook( CR_PLUGIN_FILE, 'cr_deactivate' );
function cr_deactivate() {
	flush_rewrite_rules();
}
