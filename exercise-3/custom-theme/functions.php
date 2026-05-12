<?php
/**
 * Performance Optimization
 * 
 * @package PerformanceOptimization
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Load performance optimization modules
 */
function load_performance_modules() {
    $modules = array(
        'image-optimization',
        'asset-optimization',
        'critical-css',
        'helpers',
    );
    
    foreach ( $modules as $module ) {
        $file = get_template_directory() . '/inc/' . $module . '.php';
        
        if ( file_exists( $file ) ) {
            require_once $file;
        }
    }
}
add_action( 'after_setup_theme', 'load_performance_modules' );

/**
 * Enqueue optimized theme assets
 */
function enqueue_optimized_assets() {
    // Critical CSS is inlined via asset-optimization.php
    
    // Main stylesheet (non-critical, deferred)
    wp_enqueue_style(
        'main-styles',
        get_template_directory_uri() . '/assets/css/optimized.css',
        array(),
        filemtime( get_template_directory() . '/assets/css/optimized.css' ) // Cache busting
    );
    
    // Optimized JavaScript
    wp_enqueue_script(
        'main-scripts',
        get_template_directory_uri() . '/assets/js/main.js',
        array(), // No jQuery dependency
        filemtime( get_template_directory() . '/assets/js/main.js' ),
        true // Load in footer
    );
}
add_action( 'wp_enqueue_scripts', 'enqueue_optimized_assets' );

/**
 * Add DNS prefetch for external resources
 */
function add_dns_prefetch() {
    echo '<link rel="dns-prefetch" href="//fonts.googleapis.com">' . "\n";
    echo '<link rel="dns-prefetch" href="//www.google-analytics.com">' . "\n";
    echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
}
add_action( 'wp_head', 'add_dns_prefetch', 1 );