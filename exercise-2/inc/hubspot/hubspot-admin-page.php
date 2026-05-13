<?php
/**
 * NuroSparX — HubSpot Leads Admin Page
 *
 * Adds a "HubSpot Leads" entry under the Tools menu in wp-admin.
 * Shows backup leads with their sync status.
 *
 * WHY admin visibility?
 *   When HubSpot fails and the cron retry also fails permanently,
 *   someone needs to know. This page gives the ops team:
 *     → A list of all backup leads
 *     → Status per lead (pending, synced, failed_permanently)
 *     → Manual "Mark as Synced" action for leads manually synced
 *     → Manual "Trigger Retry" action for emergency re-tries
 *
 * WHY Tools menu, not a custom top-level menu?
 *   This is a utility page, not a core content area.
 *   Tools is the correct menu for diagnostic and data-management screens.
 *   A top-level menu icon is earned by frequently-used features — not this.
 *
 * WHY NOT a full WP_List_Table implementation?
 *   WP_List_Table adds bulk actions, column sorting, and pagination —
 *   all valuable for a production tool. For this assignment's scope,
 *   a clean simple table demonstrates the same competency.
 *   See "What I'd do with more time" in README.md.
 *
 * @package NuroSparX
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// =============================================================================
// Register Admin Page
// =============================================================================

add_action( 'admin_menu', 'nurosparx_register_leads_admin_page' );

function nurosparx_register_leads_admin_page(): void {
    add_submenu_page(
        'tools.php',                        // Parent: Tools menu
        'HubSpot Lead Backup',              // Page <title>
        'HubSpot Lead Backup',              // Menu label
        'manage_options',                   // Capability: admins only
        'nurosparx-hs-leads',              // Menu slug
        'nurosparx_render_leads_admin_page' // Callback
    );
}

// =============================================================================
// Handle Admin Actions (Mark Synced / Trigger Retry)
// =============================================================================

/**
 * Process manual admin actions before the page renders.
 *
 * WHY early in the request (before output)?
 * We need to redirect after the action (Post/Redirect/Get pattern).
 * Once any HTML has been output, wp_redirect() causes a "headers already sent" error.
 * admin_init fires early — before any HTML output.
 */
add_action( 'admin_init', 'nurosparx_handle_leads_admin_actions' );

function nurosparx_handle_leads_admin_actions(): void {
    // Only process our page's actions
    if ( ! isset( $_GET['page'] ) || $_GET['page'] !== 'nurosparx-hs-leads' ) {
        return;
    }

    if ( ! current_user_can( 'manage_options' ) ) {
        return;
    }

    $action  = sanitize_key( $_GET['ns_action'] ?? '' );
    $lead_id = absint( $_GET['lead_id'] ?? 0 );
    $nonce   = sanitize_key( $_GET['_wpnonce'] ?? '' );

    if ( ! $action || ! $lead_id ) {
        return;
    }

    // Verify nonce before any action
    if ( ! wp_verify_nonce( $nonce, 'nurosparx_lead_action_' . $lead_id ) ) {
        wp_die( 'Security check failed.', 'Error', [ 'response' => 403 ] );
    }

    $lead_db = new NuroSparX_Lead_DB();

    if ( $action === 'mark_synced' ) {
        $lead_db->mark_synced( $lead_id );
        $redirect_message = 'marked_synced';
    } elseif ( $action === 'trigger_retry' ) {
        // Re-flag as pending so cron picks it up (reset retry count)
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'nurosparx_lead_backup',
            [ 'status' => 'pending', 'retry_count' => 0 ],
            [ 'id' => $lead_id ],
            [ '%s', '%d' ],
            [ '%d' ]
        );
        $redirect_message = 'retry_queued';
    } else {
        return;
    }

    // Post/Redirect/Get — prevents re-submission on page refresh
    wp_safe_redirect(
        add_query_arg(
            [ 'page' => 'nurosparx-hs-leads', 'ns_notice' => $redirect_message ],
            admin_url( 'tools.php' )
        )
    );
    exit;
}

// =============================================================================
// Render Admin Page
// =============================================================================

function nurosparx_render_leads_admin_page(): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You do not have permission to view this page.', 'nurosparx' ) );
    }

    $lead_db = new NuroSparX_Lead_DB();

    // Filter by status if requested
    $status_filter = sanitize_key( $_GET['ns_status'] ?? 'all' );
    $leads         = $lead_db->get_recent( 50, $status_filter );

    // Count by status for the summary
    $all     = $lead_db->get_recent( 999, 'all' );
    $pending = array_filter( $all, fn( $l ) => $l->status === 'pending' );
    $synced  = array_filter( $all, fn( $l ) => $l->status === 'synced' );
    $failed  = array_filter( $all, fn( $l ) => $l->status === 'failed_permanently' );

    ?>
    <div class="wrap">
        <h1><?php esc_html_e( 'HubSpot Lead Backup', 'nurosparx' ); ?></h1>

        <?php
        // Show admin notices for completed actions
        $notice = sanitize_key( $_GET['ns_notice'] ?? '' );
        if ( $notice === 'marked_synced' ) {
            echo '<div class="notice notice-success is-dismissible"><p>Lead marked as synced.</p></div>';
        } elseif ( $notice === 'retry_queued' ) {
            echo '<div class="notice notice-info is-dismissible"><p>Lead queued for retry. Cron will process it within 30 minutes.</p></div>';
        }
        ?>

        <p class="description">
            These leads were stored locally when HubSpot was unavailable.
            The cron job retries <strong>pending</strong> leads every 30 minutes (max 5 attempts).
        </p>

        <?php /* ── Status Summary ── */ ?>
        <div style="display:flex;gap:1rem;margin:1rem 0;">
            <?php
            $summary = [
                [ 'label' => 'Total',     'count' => count( $all ),     'color' => '#007cba' ],
                [ 'label' => 'Pending',   'count' => count( $pending ),  'color' => '#d63638' ],
                [ 'label' => 'Synced',    'count' => count( $synced ),   'color' => '#00a32a' ],
                [ 'label' => 'Failed',    'count' => count( $failed ),   'color' => '#8c5e00' ],
            ];
            foreach ( $summary as $s ) {
                printf(
                    '<div style="background:#fff;border:1px solid #c3c4c7;border-top:4px solid %s;padding:.75rem 1.25rem;min-width:100px;text-align:center;border-radius:4px;">
                        <strong style="font-size:1.5rem;display:block;">%d</strong>
                        <span style="color:#666;">%s</span>
                    </div>',
                    esc_attr( $s['color'] ),
                    (int) $s['count'],
                    esc_html( $s['label'] )
                );
            }
            ?>
        </div>

        <?php /* ── Status Filter Tabs ── */ ?>
        <ul class="subsubsub" style="margin-bottom:1rem;">
            <?php
            $base_url = admin_url( 'tools.php?page=nurosparx-hs-leads' );
            $filters  = [
                'all'                  => 'All',
                'pending'              => 'Pending',
                'synced'               => 'Synced',
                'failed_permanently'   => 'Failed',
            ];
            $tabs = [];
            foreach ( $filters as $slug => $label ) {
                $url     = add_query_arg( 'ns_status', $slug, $base_url );
                $current = ( $status_filter === $slug ) ? ' class="current"' : '';
                $tabs[]  = sprintf( '<li><a href="%s"%s>%s</a></li>', esc_url( $url ), $current, esc_html( $label ) );
            }
            echo implode( ' | ', $tabs );
            ?>
        </ul>

        <?php /* ── Leads Table ── */ ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:40px;"><?php esc_html_e( 'ID', 'nurosparx' ); ?></th>
                    <th><?php esc_html_e( 'Name', 'nurosparx' ); ?></th>
                    <th><?php esc_html_e( 'Email', 'nurosparx' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'nurosparx' ); ?></th>
                    <th><?php esc_html_e( 'Retries', 'nurosparx' ); ?></th>
                    <th><?php esc_html_e( 'UTM Source', 'nurosparx' ); ?></th>
                    <th><?php esc_html_e( 'Created', 'nurosparx' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'nurosparx' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if ( empty( $leads ) ) : ?>
                    <tr>
                        <td colspan="8" style="text-align:center;padding:2rem;color:#666;">
                            <?php esc_html_e( 'No leads found.', 'nurosparx' ); ?>
                        </td>
                    </tr>
                <?php else : ?>
                    <?php foreach ( $leads as $lead ) :
                        $nonce = wp_create_nonce( 'nurosparx_lead_action_' . $lead->id );
                        $status_colours = [
                            'pending'              => '#d63638',
                            'synced'               => '#00a32a',
                            'failed_permanently'   => '#8c5e00',
                        ];
                        $status_colour = $status_colours[ $lead->status ] ?? '#666';
                    ?>
                        <tr>
                            <td><?php echo (int) $lead->id; ?></td>
                            <td><?php echo esc_html( $lead->full_name ); ?></td>
                            <td><?php echo esc_html( $lead->email ); ?></td>
                            <td>
                                <span style="color:<?php echo esc_attr( $status_colour ); ?>;font-weight:600;">
                                    <?php echo esc_html( ucfirst( str_replace( '_', ' ', $lead->status ) ) ); ?>
                                </span>
                            </td>
                            <td><?php echo (int) $lead->retry_count; ?>/5</td>
                            <td><?php echo esc_html( $lead->utm_source ?: '—' ); ?></td>
                            <td><?php echo esc_html( $lead->created_at ); ?></td>
                            <td>
                                <?php if ( $lead->status !== 'synced' ) : ?>
                                    <a href="<?php echo esc_url( add_query_arg( [
                                        'page'       => 'nurosparx-hs-leads',
                                        'ns_action'  => 'mark_synced',
                                        'lead_id'    => $lead->id,
                                        '_wpnonce'   => $nonce,
                                    ], admin_url( 'tools.php' ) ) ); ?>" style="margin-right:.5rem;">
                                        Mark Synced
                                    </a>
                                    <a href="<?php echo esc_url( add_query_arg( [
                                        'page'       => 'nurosparx-hs-leads',
                                        'ns_action'  => 'trigger_retry',
                                        'lead_id'    => $lead->id,
                                        '_wpnonce'   => $nonce,
                                    ], admin_url( 'tools.php' ) ) ); ?>">
                                        Retry Now
                                    </a>
                                <?php else : ?>
                                    <span style="color:#999;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php if ( $lead->error_reason ) : ?>
                            <tr style="background:#fff8f0!important;">
                                <td colspan="8" style="font-size:.82rem;color:#8c5e00;padding:.25rem 1rem .5rem;">
                                    <strong>Last error:</strong> <?php echo esc_html( $lead->error_reason ); ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <p class="description" style="margin-top:1rem;">
            <strong>Tip:</strong> If HubSpot is back online, click "Retry Now" on any pending lead
            to queue it for the next cron run, or wait up to 30 minutes for automatic retry.
        </p>
    </div>
    <?php
}
