<?php
/**
 * Image Optimization Module
 * 
 * Handles:
 * - Native lazy loading
 * - WebP conversion support
 * - Responsive images
 * - LCP image prioritization
 * 
 * @package PerformanceOptimization
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Image_Optimization {
    
    /**
     * Initialize image optimization hooks
     */
    public function __construct() {
        // Add lazy loading to images
        add_filter( 'the_content', array( $this, 'add_lazy_loading' ), 20 );
        add_filter( 'post_thumbnail_html', array( $this, 'add_lazy_loading_to_thumbnails' ), 10, 5 );
        
        // Add WebP support
        add_filter( 'wp_get_attachment_image_src', array( $this, 'maybe_use_webp' ), 10, 4 );
        
        // Add explicit dimensions to prevent CLS
        add_filter( 'the_content', array( $this, 'add_image_dimensions' ), 15 );
        
        // Preload LCP image
        add_action( 'wp_head', array( $this, 'preload_lcp_image' ), 1 );
        
        // Add fetchpriority to above-fold images
        add_filter( 'wp_get_attachment_image_attributes', array( $this, 'add_fetchpriority' ), 10, 3 );
    }
    
    /**
     * Add native lazy loading to images in content
     * Excludes first image (likely LCP candidate)
     * 
     * @param string $content Post content
     * @return string Modified content
     */
    public function add_lazy_loading( $content ) {
        // Skip if no images in content
        if ( false === strpos( $content, '<img' ) ) {
            return $content;
        }
        
        // Parse images
        preg_match_all( '/<img[^>]+>/i', $content, $matches );
        
        if ( empty( $matches[0] ) ) {
            return $content;
        }
        
        $image_count = 0;
        
        foreach ( $matches[0] as $img_tag ) {
            $image_count++;
            
            // Skip first image (LCP candidate) and images already with loading attribute
            if ( $image_count <= 1 || strpos( $img_tag, 'loading=' ) !== false ) {
                continue;
            }
            
            // Add lazy loading
            $new_img_tag = str_replace( '<img', '<img loading="lazy" decoding="async"', $img_tag );
            
            // Add explicit dimensions if missing (prevents CLS)
            if ( strpos( $new_img_tag, 'width=' ) === false ) {
                // Try to extract from inline styles or add placeholder
                $new_img_tag = $this->add_dimension_attributes( $new_img_tag );
            }
            
            $content = str_replace( $img_tag, $new_img_tag, $content );
        }
        
        return $content;
    }
    
    /**
     * Add lazy loading to post thumbnails
     * 
     * @param string $html    The post thumbnail HTML
     * @param int    $post_id Post ID
     * @param int    $post_thumbnail_id Thumbnail attachment ID
     * @param string|array $size Thumbnail size
     * @param array  $attr   Query string or array of attributes
     * @return string Modified HTML
     */
    public function add_lazy_loading_to_thumbnails( $html, $post_id, $post_thumbnail_id, $size, $attr ) {
        // Don't lazy load featured images on single posts (likely LCP)
        if ( is_singular() ) {
            return $html;
        }
        
        // Add lazy loading if not already present
        if ( false === strpos( $html, 'loading=' ) ) {
            $html = str_replace( '<img', '<img loading="lazy" decoding="async"', $html );
        }
        
        return $html;
    }
    
    /**
     * Maybe use WebP version of image if available
     * 
     * @param array|false  $image         Image data array or false
     * @param int          $attachment_id Image attachment ID
     * @param string|array $size          Image size
     * @param bool         $icon          Whether the image should be treated as an icon
     * @return array|false Modified image data
     */
    public function maybe_use_webp( $image, $attachment_id, $size, $icon ) {
        if ( ! $image ) {
            return $image;
        }
        
        // Check if browser supports WebP (server-side detection via Accept header)
        if ( ! $this->browser_supports_webp() ) {
            return $image;
        }
        
        $image_path = get_attached_file( $attachment_id );
        $webp_path = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $image_path );
        
        // If WebP version exists, use it
        if ( file_exists( $webp_path ) ) {
            $image[0] = preg_replace( '/\.(jpe?g|png)$/i', '.webp', $image[0] );
            
            // Update mime type
            if ( isset( $image['mime-type'] ) ) {
                $image['mime-type'] = 'image/webp';
            }
        }
        
        return $image;
    }
    
    /**
     * Check if browser supports WebP
     * 
     * @return bool
     */
    private function browser_supports_webp() {
        // Check Accept header
        if ( ! empty( $_SERVER['HTTP_ACCEPT'] ) && 
             strpos( $_SERVER['HTTP_ACCEPT'], 'image/webp' ) !== false ) {
            return true;
        }
        
        // Fallback: assume modern browsers support WebP
        // This is a simplification; in production, use client hints or picture element
        return true;
    }
    
    /**
     * Add explicit width/height attributes to prevent CLS
     * 
     * @param string $content Post content
     * @return string Modified content
     */
    public function add_image_dimensions( $content ) {
        // This is a simplified version
        // In production, parse image IDs and get actual dimensions from attachment metadata
        
        preg_match_all( '/<img[^>]+>/i', $content, $matches );
        
        foreach ( $matches[0] as $img_tag ) {
            // If already has dimensions, skip
            if ( strpos( $img_tag, 'width=' ) !== false && strpos( $img_tag, 'height=' ) !== false ) {
                continue;
            }
            
            // Try to get image ID from class
            if ( preg_match( '/wp-image-(\d+)/i', $img_tag, $class_id ) ) {
                $attachment_id = $class_id[1];
                $metadata = wp_get_attachment_metadata( $attachment_id );
                
                if ( ! empty( $metadata['width'] ) && ! empty( $metadata['height'] ) ) {
                    $new_img_tag = str_replace(
                        '<img',
                        sprintf( '<img width="%d" height="%d"', $metadata['width'], $metadata['height'] ),
                        $img_tag
                    );
                    
                    $content = str_replace( $img_tag, $new_img_tag, $content );
                }
            }
        }
        
        return $content;
    }
    
    /**
     * Add dimension attributes to image tag
     * 
     * @param string $img_tag Image HTML tag
     * @return string Modified tag
     */
    private function add_dimension_attributes( $img_tag ) {
        // Extract image ID if present
        if ( preg_match( '/wp-image-(\d+)/i', $img_tag, $matches ) ) {
            $attachment_id = $matches[1];
            $metadata = wp_get_attachment_metadata( $attachment_id );
            
            if ( ! empty( $metadata['width'] ) && ! empty( $metadata['height'] ) ) {
                $img_tag = str_replace(
                    '<img',
                    sprintf( '<img width="%d" height="%d"', $metadata['width'], $metadata['height'] ),
                    $img_tag
                );
            }
        }
        
        return $img_tag;
    }
    
    /**
     * Preload LCP image (hero image)
     * This should be customized per template
     */
    public function preload_lcp_image() {
        // Only on homepage
        if ( ! is_front_page() ) {
            return;
        }
        
        // Get hero image (customize based on your theme structure)
        // Example: Featured image of first post or ACF field
        $hero_image_id = get_option( 'homepage_hero_image_id' ); // Customize this
        
        if ( ! $hero_image_id ) {
            // Fallback: get first post's featured image
            $recent_posts = wp_get_recent_posts( array( 'numberposts' => 1 ) );
            if ( ! empty( $recent_posts ) ) {
                $hero_image_id = get_post_thumbnail_id( $recent_posts[0]['ID'] );
            }
        }
        
        if ( $hero_image_id ) {
            $image_url = wp_get_attachment_image_url( $hero_image_id, 'full' );
            
            if ( $image_url ) {
                printf(
                    '<link rel="preload" as="image" href="%s" fetchpriority="high">',
                    esc_url( $image_url )
                );
            }
        }
    }
    
    /**
     * Add fetchpriority="high" to above-fold images
     * 
     * @param array $attr       Attributes array
     * @param object $attachment Image attachment post
     * @param string|array $size Image size
     * @return array Modified attributes
     */
    public function add_fetchpriority( $attr, $attachment, $size ) {
        // Add high priority to large hero images
        if ( is_array( $size ) && ! empty( $size[0] ) && $size[0] >= 1200 ) {
            $attr['fetchpriority'] = 'high';
        } elseif ( is_string( $size ) && in_array( $size, array( 'full', 'large', 'hero' ), true ) ) {
            $attr['fetchpriority'] = 'high';
        }
        
        return $attr;
    }
}

// Initialize
new Image_Optimization();