<?php
/**
 * NuroSparX — Lead Database (Fallback Storage)
 *
 * Manages the wp_nurosparx_lead_backup table:
 *   → save()    Store a lead when HubSpot is unavailable
 *   → retry()   Sync pending leads (called by WP-Cron)
 *   → purge()   GDPR-compliant deletion of old records
 *
 * WHY a dedicated DB table (not wp_options or post meta)?
 *
 *   wp_options  → key/value, not queryable per-lead, no status tracking
 *   Custom posts → revision overhead, pollutes post types, no indexes
 *   Dedicated table:
 *       ✓ Proper indexes (email, status, date) for fast queries
 *       ✓ status column: pending → synced lifecycle
 *       ✓ Clean export / GDPR erasure by email
 *       ✓ Cron can query 'pending' rows efficiently
 *       ✓ Admin page can paginate without loading all into memory
 *
 * WHY dbDelta() for table creation?
 *   dbDelta() compares desired schema vs existing table and applies
 *   only the DIFF — safe to call on every theme update.
 *   It's WordPress's official schema migration tool. Never use raw
 *   CREATE TABLE in a plugin/theme that might be updated.
 *
 * @package NuroSparX
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NuroSparX_Lead_DB {

    /** @var string Full table name with WP prefix */
    private string $table;

    /**
     * Current schema version for future migrations.
     * Public so hubspot-cron-setup.php can compare without instantiating the class.
     */
    public const SCHEMA_VERSION = '1.0.0';

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'nurosparx_lead_backup';
    }

    // -------------------------------------------------------------------------
    // Schema
    // -------------------------------------------------------------------------

    /**
     * Create (or update) the backup leads table.
     *
     * Call this on theme activation via after_switch_theme hook.
     * Safe to re-run — dbDelta() handles idempotency.
     *
     * Column notes:
     *   ip_address  VARCHAR(45) → supports IPv6 (max 39 chars + buffer)
     *   status      'pending' | 'synced' | 'failed_permanently'
     *   cf7_entry   Reference to CF7's form submission (if Flamingo is active)
     *   error_reason VARCHAR(500) → truncated, no PII in the error column
     */
    public function create_table(): void {
        global $wpdb;

        $charset = $wpdb->get_charset_collate();

        /**
         * CRITICAL dbDelta() formatting rules (it's very strict):
         *   1. Two spaces between column name and its type/attributes
         *   2. PRIMARY KEY on its own line
         *   3. Index lines start with "KEY" not "INDEX"
         *   4. No trailing comma before closing parenthesis
         *   5. Opening brace on same line as CREATE TABLE
         */
        $sql = "CREATE TABLE {$this->table} (
  id              BIGINT(20) UNSIGNED  NOT NULL AUTO_INCREMENT,
  cf7_form_id     VARCHAR(20)          NOT NULL DEFAULT '',
  full_name       VARCHAR(100)         NOT NULL DEFAULT '',
  email           VARCHAR(150)         NOT NULL DEFAULT '',
  phone           VARCHAR(30)          NOT NULL DEFAULT '',
  company_name    VARCHAR(150)         NOT NULL DEFAULT '',
  message         TEXT                 NOT NULL,
  utm_source      VARCHAR(100)         NOT NULL DEFAULT '',
  utm_medium      VARCHAR(100)         NOT NULL DEFAULT '',
  utm_campaign    VARCHAR(100)         NOT NULL DEFAULT '',
  utm_term        VARCHAR(100)         NOT NULL DEFAULT '',
  utm_content     VARCHAR(100)         NOT NULL DEFAULT '',
  status          VARCHAR(30)          NOT NULL DEFAULT 'pending',
  error_reason    VARCHAR(500)         NOT NULL DEFAULT '',
  ip_address      VARCHAR(45)          NOT NULL DEFAULT '',
  source_url      VARCHAR(500)         NOT NULL DEFAULT '',
  created_at      DATETIME             NOT NULL,
  synced_at       DATETIME             DEFAULT NULL,
  retry_count     TINYINT(3) UNSIGNED  NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY idx_email      (email),
  KEY idx_status     (status),
  KEY idx_created    (created_at),
  KEY idx_cf7_form   (cf7_form_id)
) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );

        update_option( 'nurosparx_db_version', self::SCHEMA_VERSION );
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Save a lead to the backup table.
     *
     * Called when HubSpot API fails. The WP-Cron retry job will pick
     * this up within 30 minutes and attempt to sync again.
     *
     * WHY format specifiers ('%s', '%d') in $wpdb->insert()?
     *   They map to wpdb->prepare() internally, preventing SQL injection.
     *   Always specify formats — relying on wpdb to guess is unreliable.
     *
     * @param  array<string,mixed> $data        Sanitized + transformed lead data
     * @param  string              $cf7_form_id CF7 form post ID (e.g. '82')
     * @param  string              $error       Why HubSpot failed
     * @return int                              Inserted row ID, 0 on failure
     */
    public function save( array $data, string $cf7_form_id, string $error = '' ): int {
        global $wpdb;

        $result = $wpdb->insert(
            $this->table,
            [
                'cf7_form_id'  => $cf7_form_id,
                'full_name'    => $data['full_name']    ?? '',
                'email'        => $data['email']         ?? '',
                'phone'        => $data['phone']         ?? '',
                'company_name' => $data['company_name']  ?? '',
                'message'      => $data['message']       ?? '',
                'utm_source'   => $data['utm_source']    ?? '',
                'utm_medium'   => $data['utm_medium']    ?? '',
                'utm_campaign' => $data['utm_campaign']  ?? '',
                'utm_term'     => $data['utm_term']      ?? '',
                'utm_content'  => $data['utm_content']   ?? '',
                'status'       => 'pending',
                'error_reason' => mb_substr( $error, 0, 500 ),
                'ip_address'   => $data['ip_address']    ?? '',
                'source_url'   => $data['source_url']    ?? '',
                'created_at'   => current_time( 'mysql' ),
                'retry_count'  => 0,
            ],
            [
                '%s', '%s', '%s', '%s', '%s', '%s',
                '%s', '%s', '%s', '%s', '%s',
                '%s', '%s', '%s', '%s',
                '%s', '%d',
            ]
        );

        return $result ? (int) $wpdb->insert_id : 0;
    }

    /**
     * Mark a backup lead as successfully synced.
     *
     * @param int $id Row ID
     */
    public function mark_synced( int $id ): void {
        global $wpdb;

        $wpdb->update(
            $this->table,
            [
                'status'    => 'synced',
                'synced_at' => current_time( 'mysql' ),
            ],
            [ 'id' => $id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
    }

    /**
     * Mark a backup lead as permanently failed (too many retries).
     *
     * @param int    $id     Row ID
     * @param string $reason Why it permanently failed
     */
    public function mark_failed( int $id, string $reason ): void {
        global $wpdb;

        $wpdb->update(
            $this->table,
            [
                'status'       => 'failed_permanently',
                'error_reason' => mb_substr( $reason, 0, 500 ),
            ],
            [ 'id' => $id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Get pending leads for retry.
     *
     * WHY max_retries check?
     * Without it, a lead that fails repeatedly (e.g. invalid email format)
     * would loop forever and spam our logs. After 5 attempts, give up.
     *
     * @param  int $limit  Max leads to process per cron run (prevent timeouts)
     * @return array
     */
    public function get_pending( int $limit = 10 ): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE status = %s
                   AND retry_count < %d
                 ORDER BY created_at ASC
                 LIMIT %d",
                'pending',
                5,      // max retry attempts before marking permanently failed
                $limit
            )
        );
    }

    /**
     * Increment retry_count for a lead.
     * Called before each retry attempt so we can track failures.
     *
     * @param int $id
     */
    public function increment_retry( int $id ): void {
        global $wpdb;

        $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->table} SET retry_count = retry_count + 1 WHERE id = %d",
                $id
            )
        );
    }

    /**
     * Get recent leads for admin display.
     *
     * @param  int    $limit
     * @param  string $status  'all', 'pending', 'synced', 'failed_permanently'
     * @return array
     */
    public function get_recent( int $limit = 50, string $status = 'all' ): array {
        global $wpdb;

        if ( $status === 'all' ) {
            return $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$this->table} ORDER BY created_at DESC LIMIT %d",
                    $limit
                )
            );
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE status = %s ORDER BY created_at DESC LIMIT %d",
                $status,
                $limit
            )
        );
    }

    // -------------------------------------------------------------------------
    // GDPR Purge
    // -------------------------------------------------------------------------

    /**
     * Delete leads older than $days_old.
     *
     * WHY required?
     * GDPR Article 5(1)(e): personal data must not be kept longer than necessary.
     * Backup leads older than 90 days that are still 'pending' were clearly
     * not converted — they must be erased.
     *
     * Attach to wp_scheduled_delete (runs daily) or a dedicated cron.
     *
     * @param int $days_old Purge records older than this many days
     */
    public function purge_old_records( int $days_old = 90 ): int {
        global $wpdb;

        $deleted = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table}
                 WHERE status IN ('pending', 'failed_permanently')
                   AND created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days_old
            )
        );

        return (int) $deleted;
    }

    /**
     * Delete ALL records for a specific email address.
     * Called when processing a GDPR Right to Erasure request.
     *
     * @param  string $email
     * @return int    Number of rows deleted
     */
    public function erase_by_email( string $email ): int {
        global $wpdb;

        $deleted = $wpdb->delete(
            $this->table,
            [ 'email' => strtolower( trim( $email ) ) ],
            [ '%s' ]
        );

        return (int) $deleted;
    }
}
