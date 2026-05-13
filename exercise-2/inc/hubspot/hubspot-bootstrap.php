<?php
/**
 * NuroSparX — functions.php Integration
 *
 * This is the SINGLE ENTRY POINT for the entire HubSpot integration.
 * Everything loads from here. One require, everything works.
 *
 * In your child theme's functions.php, add ONE line:
 *
 *   require_once get_stylesheet_directory() . '/inc/hubspot/hubspot-bootstrap.php';
 *   require_once get_template_directory() . '/inc/hubspot/hubspot-bootstrap.php';
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WP-CONFIG.PHP — Add these constants ABOVE "That's all, stop editing!"
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *   define( 'HUBSPOT_ACCESS_TOKEN', 'pat-na1-xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx' );
 *   define( 'HUBSPOT_PORTAL_ID',    '12345678' );
 *   define( 'HUBSPOT_FORM_GUID',    'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx' );
 *
 *   // Enable debug logging (development only — NEVER in production)
 *   define( 'WP_DEBUG',     true );
 *   define( 'WP_DEBUG_LOG', true );   // Logs to /wp-content/debug.log
 *
 * WHY wp-config.php and not the DB or an options page?
 *   → Not tracked by Git (.gitignore typically excludes wp-config.php)
 *   → Not exposed via any WordPress REST or admin endpoint
 *   → Per-environment: local/staging/production each have different values
 *   → No encryption needed — the file itself is the security boundary
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * CONTACT FORM 7 SETUP
 * ─────────────────────────────────────────────────────────────────────────────
 *
 * Install Contact Form 7 (free, wordpress.org/plugins/contact-form-7/)
 *
 * The form post ID (e.g. '82') is in the published shortcode:
 *   [contact-form-7 id="82" title="Contact form"]
 *
 * Update CF7_FORM_ID constant in class-hubspot-integration.php to match yours.
 *
 * @package NuroSparX
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

add_action( 'after_setup_theme', 'nurosparx_load_hubspot_integration' );

function nurosparx_load_hubspot_integration(): void {

    // CF7 check — function_exists('wpcf7') is reliable at this hook point
    // because plugins are loaded before after_setup_theme fires.
    if ( ! function_exists( 'wpcf7' ) ) {
        add_action( 'admin_notices', function () {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>NuroSparX HubSpot Integration:</strong> ';
            echo 'Contact Form 7 is required but not active. Please install and activate it.';
            echo '</p></div>';
        } );
        return;
    }

    // ── Load all integration files ────────────────────────────────────────
    
    $base = get_stylesheet_directory() . '/inc/hubspot/';

    /**
     * Load order matters:
     * 1. Classes with no dependencies first (Rate Limiter, Phone Transformer)
     * 2. Classes that depend on others second (Lead DB)
     * 3. Main integration class last (depends on all three above)
     * 4. Infrastructure files (cron, admin) — no class dependencies
     */
    require_once $base . 'class-rate-limiter.php';
    require_once $base . 'class-phone-transformer.php';
    require_once $base . 'class-lead-db.php';
    require_once $base . 'class-hubspot-integration.php';
    require_once $base . 'hubspot-cron-setup.php';
    require_once $base . 'hubspot-admin-page.php';

    // ── Instantiate with dependency injection ─────────────────────────────

    /**
     * WHY dependency injection (passing objects in)?
     *
     * Option A — Hardcoded dependencies (bad):
     *   class HubSpot { function __construct() { $this->db = new LeadDB(); } }
     *   → Cannot test without a real database connection
     *   → Cannot swap LeadDB for a mock/stub in tests
     *   → Violates Dependency Inversion Principle
     *
     * Option B — Dependency injection (good, what we do):
     *   $integration = new HubSpot( new RateLimiter(), new LeadDB(), new PhoneTransformer() );
     *   → In tests: pass mock objects
     *   → LeadDB can be replaced with a different storage backend
     *   → Each class is independently instantiable and testable
     *
     * For a full project: use a DI container (e.g. PHP-DI, Pimple, or a
     * simple service locator pattern). For this scope, manual injection is clean.
     */
    new NuroSparX_HubSpot_Integration(
        new NuroSparX_Rate_Limiter(
            3,    // Max 3 submissions
            3600  // Per hour (3600 seconds)
        ),
        new NuroSparX_Lead_DB(),
        new NuroSparX_Phone_Transformer()
    );
}
