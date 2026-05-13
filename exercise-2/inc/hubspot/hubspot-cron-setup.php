<?php
/**
 * NuroSparX — Cron, DB Setup & Lifecycle Hooks
 *
 * Handles everything related to the INTEGRATION LIFECYCLE:
 *   → Theme activation: create DB table
 *   → Theme deactivation: clear scheduled cron jobs
 *   → Every 30 minutes: retry pending leads via WP-Cron
 *   → Daily: GDPR purge of old records
 *
 * WHY separate this from the integration class?
 *   Lifecycle hooks (activation, cron scheduling) are infrastructure concerns.
 *   The integration class (class-hubspot-integration.php) is a business logic concern.
 *   Mixing them violates Single Responsibility Principle and makes testing harder.
 *
 *   A unit test for HubSpot submission logic doesn't need to know about cron.
 *   A test for the cron schedule doesn't need a live HubSpot connection.
 *
 * @package NuroSparX
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// =============================================================================
// 1. THEME ACTIVATION — DB Table Creation
// =============================================================================

/**
 * Self-healing setup — runs on every page load via a cheap version check.
 *
 * WHY not rely only on after_switch_theme?
 *
 * Problem 1 — Child themes:
 *   after_switch_theme fires when switching FROM one theme TO another.
 *   With a child theme, activating/deactivating doesn't always fire it
 *   reliably. The hook also requires that our bootstrap file was already
 *   loaded — which it wasn't when plugins_loaded was used (Bug 1).
 *
 * Problem 2 — Manual file deployment:
 *   Developers often upload files via FTP/SFTP without going through
 *   wp-admin Appearance → Themes. after_switch_theme never fires.
 *
 * Solution — version check on every load:
 *   get_option('nurosparx_db_version') returns false if the option doesn't
 *   exist (table never created) or a different version (schema needs update).
 *   This check is a single DB read — negligible performance cost.
 *   dbDelta() only runs when actually needed.
 *
 * This pattern is used by WooCommerce, EDD, and most mature plugins/themes.
 */
function nurosparx_maybe_run_setup(): void {
    // Only run if DB version doesn't match — prevents running on every request
    if ( get_option( 'nurosparx_db_version' ) !== NuroSparX_Lead_DB::SCHEMA_VERSION ) {
        $lead_db = new NuroSparX_Lead_DB();
        $lead_db->create_table(); // dbDelta — safe to run multiple times
    }

    // Cron: schedule if not already scheduled (also safe to call every load)
    nurosparx_schedule_cron();
}
// Runs early on every page load — after our classes are included in bootstrap
add_action( 'after_setup_theme', 'nurosparx_maybe_run_setup', 20 ); // Priority 20 = after bootstrap's priority 10

/**
 * Keep after_switch_theme as well — belt AND suspenders.
 * When it DOES fire (normal theme switch), run setup immediately.
 */
add_action( 'after_switch_theme', 'nurosparx_run_activation' );

function nurosparx_run_activation(): void {
    // Instantiate the DB class and create the table
    $lead_db = new NuroSparX_Lead_DB();
    $lead_db->create_table();

    // Schedule the retry cron (if not already scheduled)
    nurosparx_schedule_cron();
}

// =============================================================================
// 2. THEME DEACTIVATION — Cleanup
// =============================================================================

/**
 * Clear scheduled cron jobs when the theme is switched away.
 *
 * WHY?
 * WP-Cron events are stored in the options table and persist even after
 * a theme is deactivated. Orphaned cron jobs waste CPU on every wp-cron.php
 * execution. Always clean up your scheduled events on deactivation.
 *
 * NOTE: We do NOT drop the DB table on deactivation.
 * The user may switch themes temporarily and switch back.
 * Dropping their lead backup data would be unrecoverable and catastrophic.
 * Table removal should be explicit (uninstall/delete), never on deactivation.
 */
add_action( 'switch_theme', 'nurosparx_run_deactivation' );

function nurosparx_run_deactivation(): void {
    nurosparx_clear_cron();
}

// =============================================================================
// 3. WP-CRON — Custom Interval Registration
// =============================================================================

/**
 * Register a "every 30 minutes" cron interval.
 *
 * WHY 30 minutes?
 *   WP built-ins: hourly / twicedaily / daily — all too slow for lead recovery.
 *   A lead that fails HubSpot sync should retry within 30 minutes, not an hour.
 *   30 minutes balances responsiveness with API load.
 *
 * WHY register via cron_schedules filter?
 *   WordPress has no built-in 30-minute interval.
 *   This filter lets us add our own without conflicting with other plugins.
 */
add_filter( 'cron_schedules', 'nurosparx_add_cron_intervals' );

function nurosparx_add_cron_intervals( array $schedules ): array {
    $schedules['nurosparx_30min'] = [
        'interval' => 30 * MINUTE_IN_SECONDS, // WP constant: 60 seconds × 30
        'display'  => __( 'Every 30 Minutes (NuroSparX HubSpot Retry)', 'nurosparx' ),
    ];
    return $schedules;
}

/**
 * Schedule the HubSpot retry cron event.
 *
 * wp_next_scheduled() checks if the event is already scheduled.
 * Without this check, calling wp_schedule_event() multiple times would
 * create duplicate cron entries — a common WordPress bug.
 */
function nurosparx_schedule_cron(): void {
    if ( ! wp_next_scheduled( 'nurosparx_retry_hubspot_sync' ) ) {
        wp_schedule_event(
            time(),                      // Start immediately
            'nurosparx_30min',           // Our custom interval
            'nurosparx_retry_hubspot_sync' // Hook name (called by WP-Cron runner)
        );
    }
}

/**
 * Unschedule all instances of the retry cron.
 *
 * wp_clear_scheduled_hook() removes ALL scheduled instances of the hook,
 * including any that are queued but haven't fired yet.
 * More thorough than wp_unschedule_event() which needs a timestamp.
 */
function nurosparx_clear_cron(): void {
    wp_clear_scheduled_hook( 'nurosparx_retry_hubspot_sync' );
}

// =============================================================================
// 4. GDPR — Automatic Data Purge
// =============================================================================

/**
 * Daily GDPR purge — delete backup leads older than 90 days.
 *
 * WHY 90 days?
 *   Industry standard for lead data retention.
 *   Long enough to catch HubSpot outages (even extended ones).
 *   Short enough to meet most GDPR "no longer than necessary" interpretations.
 *   Adjust per client's DPA (Data Processing Agreement).
 *
 * WHY piggyback on wp_scheduled_delete (WordPress's built-in daily event)?
 *   wp_scheduled_delete runs daily to clean up WordPress's own scheduled posts.
 *   Piggybacking means we don't add another daily cron event — one less DB query
 *   per day. The hook already exists; we just add our action to it.
 *
 * Alternative: create a dedicated 'nurosparx_gdpr_purge' event.
 * Use this approach if you need to control the exact time of day the purge runs.
 */
add_action( 'wp_scheduled_delete', 'nurosparx_gdpr_purge' );

function nurosparx_gdpr_purge(): void {
    $lead_db = new NuroSparX_Lead_DB();
    $deleted = $lead_db->purge_old_records( 90 );

    if ( $deleted > 0 && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
        error_log( "[NuroSparX][GDPR] Purged {$deleted} leads older than 90 days." );
    }
}

// =============================================================================
// 5. GDPR — Personal Data Eraser (WordPress Privacy Tools Integration)
// =============================================================================

/**
 * Register our data eraser with WordPress's built-in privacy tools.
 *
 * WHY?
 * WordPress 4.9.6+ includes a Privacy → Erase Personal Data admin screen.
 * When a user submits an erasure request, WordPress calls all registered
 * erasers. We hook into this to erase from our backup table too.
 *
 * This means site admins can handle GDPR right-to-erasure requests entirely
 * from wp-admin → Tools → Erase Personal Data — no custom tooling needed.
 */
add_filter( 'wp_privacy_personal_data_erasers', 'nurosparx_register_privacy_eraser' );

function nurosparx_register_privacy_eraser( array $erasers ): array {
    $erasers['nurosparx-lead-backup'] = [
        'eraser_friendly_name' => __( 'NuroSparX Lead Backup', 'nurosparx' ),
        'callback'             => 'nurosparx_erase_lead_data',
    ];
    return $erasers;
}

/**
 * Erase all backup lead data for a given email address.
 *
 * WordPress calls this with the requestor's email address.
 * We delete from our backup table and report how many rows were removed.
 *
 * @param  string $email  Email from the erasure request
 * @param  int    $page   Pagination (we process all at once — no pagination needed)
 * @return array          WordPress-required response format
 */
function nurosparx_erase_lead_data( string $email, int $page = 1 ): array {
    $lead_db = new NuroSparX_Lead_DB();
    $deleted = $lead_db->erase_by_email( $email );

    return [
        'items_removed'  => $deleted > 0,
        'items_retained' => false,
        'messages'       => $deleted > 0
            ? [ sprintf( __( 'Removed %d lead backup record(s) for this email.', 'nurosparx' ), $deleted ) ]
            : [],
        'done'           => true,
    ];
}
