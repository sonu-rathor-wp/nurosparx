<?php
/**
 * CSS & JavaScript Optimization Module
 * 
 * Handles:
 * - Deferred JavaScript loading
 * - Critical CSS inlining
 * - Delayed third-party scripts
 * - Footer-based asset loading
 * 
 * @package PerformanceOptimization
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Asset_Optimization {
    
    /**
     * Initialize asset optimization hooks
     */
    public function __construct() {
        // Defer JavaScript
        add_filter( 'script_loader_tag', array( $this, 'defer_scripts' ), 10, 3 );
        
        // Inline critical CSS
        add_action( 'wp_head', array( $this, 'inline_critical_css' ), 5 );
        
        // Defer non-critical CSS
        add_filter( 'style_loader_tag', array( $this, 'defer_non_critical_css' ), 10, 4 );
        
        // Delay third-party scripts
        add_action( 'wp_footer', array( $this, 'delay_third_party_scripts' ), 999 );
        
        // Remove unnecessary assets
        add_action( 'wp_enqueue_scripts', array( $this, 'remove_unnecessary_assets' ), 100 );
        
        // Disable emojis
        add_action( 'init', array( $this, 'disable_emojis' ) );
    }
    
    /**
     * Add defer attribute to non-critical scripts
     * 
     * @param string $tag    Script tag
     * @param string $handle Script handle
     * @param string $src    Script source URL
     * @return string Modified tag
     */
    public function defer_scripts( $tag, $handle, $src ) {
        // Scripts that should NOT be deferred (critical for initial render)
        $exclude_defer = array(
            'jquery-core',          // jQuery needed early for some themes
            'gtm-script',          // GTM should load early but async
            'critical-inline-js',  // Critical JS
        );
        
        // Scripts that should be async instead of defer
        $async_scripts = array(
            'google-analytics',
            'gtag',
            'facebook-pixel',
        );
        
        // Already has defer/async?
        if ( strpos( $tag, 'defer' ) !== false || strpos( $tag, 'async' ) !== false ) {
            return $tag;
        }
        
        // Exclude critical scripts
        if ( in_array( $handle, $exclude_defer, true ) ) {
            return $tag;
        }
        
        // Add async to analytics scripts
        if ( in_array( $handle, $async_scripts, true ) ) {
            return str_replace( ' src', ' async src', $tag );
        }
        
        // Add defer to everything else
        return str_replace( ' src', ' defer src', $tag );
    }
    
    /**
     * Inline critical CSS in head
     */
    public function inline_critical_css() {
        $critical_css_file = get_template_directory() . '/assets/css/critical.css';
        
        if ( file_exists( $critical_css_file ) ) {
            $critical_css = file_get_contents( $critical_css_file );
            
            // Minify on the fly (basic minification)
            $critical_css = preg_replace( '/\s+/', ' ', $critical_css );
            $critical_css = str_replace( array( ' {', '{ ', ' }', '} ', ': ', ' :', '; ', ' ;', ', ', ' ,' ), 
                                       array( '{', '{', '}', '}', ':', ':', ';', ';', ',', ',' ), 
                                       $critical_css );
            
            echo '<style id="critical-css">' . $critical_css . '</style>' . "\n";
        }
    }
    
    /**
     * Defer non-critical CSS using media attribute trick
     * 
     * @param string $html   Link tag HTML
     * @param string $handle Style handle
     * @param string $href   Stylesheet URL
     * @param string $media  Media attribute
     * @return string Modified tag
     */
    public function defer_non_critical_css( $html, $handle, $href, $media ) {
        // Critical CSS that should load immediately
        $critical_styles = array(
            'critical-css',
            'inline-critical',
        );
        
        if ( in_array( $handle, $critical_styles, true ) ) {
            return $html;
        }
        
        // Defer non-critical CSS
        $html = str_replace( "media='all'", "media='print' onload=\"this.media='all'; this.onload=null;\"", $html );
        $html = str_replace( 'media="all"', 'media="print" onload="this.media=\'all\'; this.onload=null;"', $html );
        
        // Add noscript fallback
        $noscript = '<noscript>' . str_replace( array( 'media="print"', "media='print'", 'onload="this.media=\'all\'; this.onload=null;"', "onload=\"this.media='all'; this.onload=null;\"" ), 
                                                array( 'media="all"', 'media="all"', '', '' ), 
                                                $html ) . '</noscript>';
        
        return $html . $noscript;
    }
    
    /**
     * Delay third-party scripts until user interaction
     */
    public function delay_third_party_scripts() {
        ?>
        <script id="delayed-scripts">
        /**
         * Delayed Third-Party Script Loader
         * Delays heavy scripts until user interaction or timeout
         */
        (function() {
            let scriptsLoaded = false;
            
            // Scripts to delay (customize based on your site)
            const delayedScripts = [
                { id: 'plerdy-heatmap', src: 'https://a.plerdy.com/public/js/click/main.js' },
                { id: 'zoho-salesiq', src: 'https://salesiq.zoho.com/widget' },
                // Add more third-party scripts here
            ];
            
            // Load scripts function
            function loadDelayedScripts() {
                if (scriptsLoaded) return;
                scriptsLoaded = true;
                
                delayedScripts.forEach(function(scriptData) {
                    let script = document.createElement('script');
                    script.id = scriptData.id;
                    script.src = scriptData.src;
                    script.async = true;
                    document.body.appendChild(script);
                });
                
                console.log('Delayed scripts loaded');
            }
            
            // Trigger events
            const events = ['mousedown', 'mousemove', 'touchstart', 'scroll', 'keydown'];
            
            // Load on first user interaction
            events.forEach(function(event) {
                window.addEventListener(event, function() {
                    loadDelayedScripts();
                    // Remove listeners after first trigger
                    events.forEach(function(e) {
                        window.removeEventListener(e, loadDelayedScripts);
                    });
                }, { passive: true, once: true });
            });
            
            // Fallback: load after 5 seconds if no interaction
            setTimeout(loadDelayedScripts, 5000);
        })();
        </script>
        <?php
    }
    
    /**
     * Remove unnecessary WordPress assets
     */
    public function remove_unnecessary_assets() {
        // Remove emoji scripts
        remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
        remove_action( 'wp_print_styles', 'print_emoji_styles' );
        
        // Remove jQuery Migrate (if not needed)
        if ( ! is_admin() ) {
            wp_deregister_script( 'jquery-migrate' );
        }
        
        // Remove WordPress embeds script (if not using oEmbeds)
        wp_deregister_script( 'wp-embed' );
        
        // Remove block library CSS if not using Gutenberg blocks
        // Uncomment if your site doesn't use blocks:
        // wp_dequeue_style( 'wp-block-library' );
        // wp_dequeue_style( 'wp-block-library-theme' );
        // wp_dequeue_style( 'wc-block-style' );
    }
    
    /**
     * Disable WordPress emojis completely
     */
    public function disable_emojis() {
        remove_action( 'admin_print_styles', 'print_emoji_styles' );
        remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
        remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
        remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
        remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
        
        // Remove from TinyMCE
        add_filter( 'tiny_mce_plugins', function( $plugins ) {
            return is_array( $plugins ) ? array_diff( $plugins, array( 'wpemoji' ) ) : array();
        });
        
        // Remove DNS prefetch
        add_filter( 'emoji_svg_url', '__return_false' );
    }
}

// Initialize
new Asset_Optimization();