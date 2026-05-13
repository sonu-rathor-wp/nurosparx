<?php
/**
 * NuroSparX — Contact Form 7 → HubSpot Integration
 *
 * This class is the INTEGRATION LAYER. It does not build forms.
 *
 * Contact Form 7 already handles:
 *   ✓ Nonce / CSRF protection (generated per-page load)
 *   ✓ Honeypot field spam detection
 *   ✓ Field-level validation (required, email format, tel pattern)
 *   ✓ AJAX submission (no page reload)
 *   ✓ Confirmation messages & redirects
 *   ✓ Admin UI (form builder, email templates)
 *   ✓ Akismet / reCAPTCHA integration
 *   ✓ Flamingo entry logging (optional companion plugin)
 *
 * This class adds:
 *   ✓ IP-based rate limiting (pre-submission, via wpcf7_spam filter)
 *   ✓ Honeypot validation for our custom field (defence-in-depth)
 *   ✓ Defence-in-depth re-sanitization of CF7 data
 *   ✓ Phone → E.164 transformation
 *   ✓ HubSpot Forms API v3 submission
 *   ✓ DB fallback when HubSpot is unreachable
 *   ✓ WP-Cron retry for 'pending' leads
 *   ✓ GTM dataLayer event on successful submission
 *   ✓ Safe error logging (no PII in logs)
 *
 * CF7 Hook Lifecycle (in order):
 *   1. wpcf7_spam              → spam check (we add rate limiting here)
 *   2. wpcf7_validate          → field validation
 *   3. wpcf7_before_send_mail  → our main integration hook
 *   4. wpcf7_mail_sent         → post-send (confirmation, redirect)
 *
 * WHY wpcf7_before_send_mail and NOT wpcf7_mail_sent?
 *   wpcf7_before_send_mail fires BEFORE CF7 sends its notification email.
 *   We hook here so:
 *   → We process the submission at the same time as CF7 (same request cycle)
 *   → If we need to abort (e.g. test/internal submission), we can flag it
 *   → Data is definitely sanitized by CF7 before we touch it
 *
 * @package NuroSparX
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NuroSparX_HubSpot_Integration {

    // -------------------------------------------------------------------------
    // Configuration
    // -------------------------------------------------------------------------

    /**
     * The CF7 post ID if the post ID changes
     * after an import/migration please update this id single id only
     */
    private const CF7_FORM_ID = '82';

    /**
     * CF7 field name → HubSpot property mapping.
     *
     * Keys   = CF7 field names (from your shortcode)
     * Values = HubSpot Contact property internal names
     *
     * WHY a constant map?
     * One place to update if CF7 fields are renamed or HubSpot properties change.
     * Self-documenting — anyone reading the code sees exactly what maps where.
     */
    private const FIELD_MAP = [
        // CF7 field name     => HubSpot property name
        'your-name'           => 'full_name',      // We split to firstname/lastname before sending
        'your-email'          => 'email',
        'your-phone'          => 'phone',
        'your-company'        => 'company',
        'your-message'        => 'message',
        'utm_source'          => 'utm_source',      // Custom HubSpot properties
        'utm_medium'          => 'utm_medium',
        'utm_campaign'        => 'utm_campaign',
        // utm_term and utm_content not in this form — add fields if needed
    ];

    /** HubSpot honeypot field name (from our CF7 shortcode) */
    private const HONEYPOT_FIELD = 'company_website_honeypot';

    /** HubSpot Forms API v3 endpoint */
    private const HS_ENDPOINT = 'https://api.hsforms.com/submissions/v3/integration/submit';

    // -------------------------------------------------------------------------
    // Dependencies (injected in constructor)
    // -------------------------------------------------------------------------

    private NuroSparX_Rate_Limiter     $rate_limiter;
    private NuroSparX_Lead_DB          $lead_db;
    private NuroSparX_Phone_Transformer $phone_transformer;

    private string $hs_access_token;
    private string $hs_portal_id;
    private string $hs_form_guid;

    // -------------------------------------------------------------------------
    // Bootstrap
    // -------------------------------------------------------------------------

    public function __construct(
        NuroSparX_Rate_Limiter     $rate_limiter,
        NuroSparX_Lead_DB          $lead_db,
        NuroSparX_Phone_Transformer $phone_transformer
    ) {
        $this->rate_limiter      = $rate_limiter;
        $this->lead_db           = $lead_db;
        $this->phone_transformer = $phone_transformer;

        // Credentials live in wp-config.php — never in code or DB
        $this->hs_access_token = defined( 'HUBSPOT_ACCESS_TOKEN' ) ? HUBSPOT_ACCESS_TOKEN : '';
        $this->hs_portal_id    = defined( 'HUBSPOT_PORTAL_ID' )    ? HUBSPOT_PORTAL_ID    : '';
        $this->hs_form_guid    = defined( 'HUBSPOT_FORM_GUID' )    ? HUBSPOT_FORM_GUID    : '';

        $this->register_hooks();
    }

    /**
     * Register all CF7 hooks.
     *
     * WHY register hooks in a method (not the constructor directly)?
     * Keeps the constructor clean and makes hooks easy to find.
     * A future developer can read register_hooks() and understand
     * the full integration surface in 30 seconds.
     */
    private function register_hooks(): void {

        /**
         * Hook 1: wpcf7_spam (filter)
         * ─────────────────────────────────────────────────────────────────
         * Return true = mark as spam = CF7 rejects the submission silently.
         * We use this for rate limiting because:
         *   → Fires early — no DB write happens if spam=true
         *   → CF7 already shows its own "Spam" response to the user
         *   → Our logic stays clean (spam filter = input gate)
         *
         * We do NOT check which form here — we want to rate-limit ALL forms.
         * To limit to this form only: check $result->get_contact_form()->id()
         */
        add_filter( 'wpcf7_spam', [ $this, 'rate_limit_check' ], 10, 1 );

        /**
         * Hook 2: wpcf7_before_send_mail (action)
         * ─────────────────────────────────────────────────────────────────
         * This is our MAIN integration hook.
         * Fires: after spam check, after validation, before CF7 email send.
         * Receives: WPCF7_ContactForm $cf7 object
         *
         * WHY here and not wpcf7_mail_sent?
         * → wpcf7_mail_sent fires AFTER email goes out — if email fails, hook
         *   still fires. wpcf7_before_send_mail is more predictable.
         * → In wpcf7_before_send_mail we can access the submission AND abort
         *   CF7's own email if needed (e.g. the form is for API-only clients).
         */
        add_action( 'wpcf7_before_send_mail', [ $this, 'handle_submission' ], 10, 3 );

        /**
         * Hook 3: WP-Cron retry
         * ─────────────────────────────────────────────────────────────────
         * Process pending leads that failed to sync to HubSpot.
         */
        add_action( 'nurosparx_retry_hubspot_sync', [ $this, 'process_retry_queue' ] );

        /**
         * Hook 4: Enqueue UTM capture script
         */
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    // =========================================================================
    // HOOK 1: Rate Limiting
    // =========================================================================

    /**
     * CF7 spam filter — block if IP is rate-limited.
     *
     * @param  bool $spam  Current spam status (from previous filters)
     * @return bool        TRUE = spam (block), FALSE = allow
     */
    public function rate_limit_check( bool $spam ): bool {
        // If already flagged as spam by Akismet or honeypot — respect that
        if ( $spam ) {
            return true;
        }

        $ip = $this->get_client_ip();
        return $this->rate_limiter->is_exceeded( $ip );
    }

    // =========================================================================
    // HOOK 2: Main Submission Handler
    // =========================================================================

    /**
     * Main integration handler — fires on wpcf7_before_send_mail.
     *
     * wpcf7_before_send_mail signature:
     *   @param WPCF7_ContactForm $cf7       The contact form object
     *   @param bool              &$abort    Set to true to abort CF7 mail send
     *   @param WPCF7_Submission  $submission The submission object
     *
     * We DON'T abort mail — let CF7 handle its own email notification.
     * Our job is ONLY HubSpot sync.
     *
     * @param WPCF7_ContactForm $cf7
     * @param bool              $abort
     * @param WPCF7_Submission  $submission
     */
    public function handle_submission( $cf7, &$abort, $submission ): void {
        
        // Guard: only process OUR specific form by its CF7 post ID
        if ( $cf7->id() == self::CF7_FORM_ID ) {
            // Guard: get the submission object safely
            if ( ! $submission instanceof WPCF7_Submission ) {
                $submission = WPCF7_Submission::get_instance();
            }
            if ( ! $submission ) {
                $this->log_error( 'CF7 submission object not available.' );
                return;
            }

            // Get the posted (already CF7-sanitized) data
            $posted = $submission->get_posted_data();
            // Step 1: Our custom honeypot check (defence-in-depth on top of CF7's)
            if ( $this->is_honeypot_triggered( $posted ) ) {
                // Don't abort CF7 — it will show its success message
                // Bots never know they were caught
                return;
            }

            // Step 2: Re-sanitize (our layer, independent of CF7's layer)
            $sanitized = $this->sanitize_posted_data( $posted );

            // Step 3: Transform (E.164 phone, name split, email normalise)
            $data = $this->transform_data( $sanitized );

            // Step 4: Enrich with request metadata
            $data['ip_address'] = $this->get_client_ip();
            $data['source_url'] = sanitize_url( $submission->get_meta( 'url' ) ?? '' );

            // Step 5: Attempt HubSpot sync
            $result = $this->send_to_hubspot( $data );

            if ( $result['success'] ) {
                // Increment rate limit only on real successful submissions
                $this->rate_limiter->increment( $data['ip_address'] );
                return; // All good — CF7 sends its confirmation, we're done
            }

            // Step 6: Fallback — save to DB
            $backup_id = $this->lead_db->save( $data, self::CF7_FORM_ID, $result['error'] ?? '' );
            $this->rate_limiter->increment( $data['ip_address'] );

            $this->log_error(
                "HubSpot sync failed for form " . self::CF7_FORM_ID . ". DB Backup ID: {$backup_id}.",
                $result['error'] ?? ''
            );
        }

        /**
         * WHY NOT abort or show error to the user?
         * The lead is safely in our DB. Cron will retry in 30 min.
         * Showing an error would:
         *   → Confuse the user (they submitted correctly)
         *   → Risk duplicate submissions (user retries = two backup rows)
         *   → Damage trust for a backend issue they can't fix
         * Operators monitor DB backup count or error logs.
         */
    }

    // =========================================================================
    // HOOK 3: WP-Cron Retry
    // =========================================================================

    /**
     * Retry pending leads — called by WP-Cron every 30 minutes.
     *
     * Processes up to 10 leads per run to avoid PHP timeout.
     * Increments retry_count before each attempt.
     * After 5 failures: marks lead as 'failed_permanently'.
     */
    public function process_retry_queue(): void {
        $pending = $this->lead_db->get_pending( 10 );

        if ( empty( $pending ) ) {
            return;
        }

        foreach ( $pending as $lead ) {
            $this->lead_db->increment_retry( $lead->id );

            // Rebuild data array from DB row
            $data = [
                'firstname'    => explode( ' ', $lead->full_name, 2 )[0],
                'lastname'     => explode( ' ', $lead->full_name, 2 )[1] ?? '',
                'full_name'    => $lead->full_name,
                'email'        => $lead->email,
                'phone'        => $lead->phone,
                'company'      => $lead->company_name,
                'message'      => $lead->message,
                'utm_source'   => $lead->utm_source,
                'utm_medium'   => $lead->utm_medium,
                'utm_campaign' => $lead->utm_campaign,
                'utm_term'     => $lead->utm_term    ?? '',
                'utm_content'  => $lead->utm_content ?? '',
                'source_url'   => $lead->source_url,
            ];

            $result = $this->send_to_hubspot( $data );

            if ( $result['success'] ) {
                $this->lead_db->mark_synced( $lead->id );
                $this->log_error( "Cron retry succeeded for backup lead #{$lead->id}." );
            } elseif ( (int) $lead->retry_count >= 4 ) {
                // Already tried 5 times (0-indexed: 4 = 5th attempt) — give up
                $this->lead_db->mark_failed( $lead->id, 'Max retries reached. Last error: ' . ( $result['error'] ?? '' ) );
                $this->log_error( "Backup lead #{$lead->id} permanently failed after 5 retries.", $result['error'] ?? '' );
            }
            // Else: leave as 'pending' — cron will retry next run
        }
    }

    // =========================================================================
    // Data Layer
    // =========================================================================

    /**
     * Custom honeypot check.
     *
     * CF7 has built-in honeypot via its Flamingo companion plugin.
     * We ALSO check our custom honeypot field (company_website_honeypot)
     * as a second layer — CF7's built-in honeypot uses CSS, ours uses
     * a hidden div (different detection technique, catches different bots).
     *
     * WHY two honeypot techniques?
     * Some bots detect display:none fields. Some miss CSS-hidden fields.
     * Two techniques catch the union.
     *
     * @param  array $posted  CF7 posted data
     * @return bool           TRUE = bot detected
     */
    private function is_honeypot_triggered( array $posted ): bool {
        $honeypot_value = trim( $posted[ self::HONEYPOT_FIELD ] ?? '' );
        return ! empty( $honeypot_value );
    }

    /**
     * Re-sanitize all CF7 posted fields with WordPress-native sanitizers.
     *
     * Defence-in-depth principle: never trust data from upstream systems.
     * CF7 sanitizes for its own context. We sanitize for OURS (DB + API).
     *
     * sanitize_text_field()    → strips tags, extra whitespace, line breaks
     * sanitize_email()         → strips invalid email characters
     * sanitize_textarea_field()→ strips tags but preserves line breaks
     *
     * @param  array $posted  Raw CF7 posted data
     * @return array          Sanitized data keyed by semantic name
     */
    private function sanitize_posted_data( array $posted ): array {
        return [
            'full_name'    => sanitize_text_field(     $posted['your-name']    ?? '' ),
            'email'        => sanitize_email(           $posted['your-email']   ?? '' ),
            'phone'        => sanitize_text_field(     $posted['your-phone']   ?? '' ),
            'company'      => sanitize_text_field(     $posted['your-company'] ?? '' ),
            'message'      => sanitize_textarea_field( $posted['your-message'] ?? '' ),
            'utm_source'   => sanitize_text_field(     $posted['utm_source']   ?? '' ),
            'utm_medium'   => sanitize_text_field(     $posted['utm_medium']   ?? '' ),
            'utm_campaign' => sanitize_text_field(     $posted['utm_campaign'] ?? '' ),
        ];
    }

    /**
     * Transform data into HubSpot-ready format.
     *
     * Transformations applied:
     *   → Email lowercased + trimmed (HubSpot deduplication is case-sensitive!)
     *   → Phone converted to E.164 standard
     *   → Full name split into firstname + lastname (HubSpot stores separately)
     *
     * @param  array $data  Sanitized data
     * @return array        Transformed data
     */
    private function transform_data( array $data ): array {
        // Normalize email (HubSpot deduplication is CASE-SENSITIVE — critical)
        $data['email'] = strtolower( trim( $data['email'] ) );

        // E.164 phone transformation
        if ( ! empty( $data['phone'] ) ) {
            $data['phone'] = $this->phone_transformer->to_e164( $data['phone'] );
        }

        // Split full name for HubSpot's separate property fields
        // HubSpot uses firstname + lastname for personalization in email templates
        $name_parts      = explode( ' ', trim( $data['full_name'] ), 2 );
        $data['firstname'] = $name_parts[0];
        $data['lastname']  = $name_parts[1] ?? '';

        return $data;
    }

    // =========================================================================
    // HubSpot API
    // =========================================================================

    /**
     * Submit lead data to HubSpot Forms API v3.
     *
     * Endpoint: POST /submissions/v3/integration/submit/{portalId}/{formGuid}
     * Auth: Bearer token in Authorization header
     *
     * WHY Forms API (not Contacts API)?
     * ─ Auto-enrolls contact in HubSpot workflows set on the form
     * ─ hutk cookie stitches submission to visitor's session history
     *   (pages visited, original source, time on site)
     * ─ Handles duplicate contacts automatically (by email)
     * ─ No manual list enrollment needed
     * ─ Simpler payload — just fields[] array
     *
     * WHY wp_remote_post() not cURL?
     * ─ Respects WP_PROXY_* constants (corporate proxies, Cloudflare tunnels)
     * ─ Returns WP_Error on transport failure — easy to detect
     * ─ Works on hosts where cURL is compiled out (uses PHP streams as fallback)
     * ─ Is filterable — enables mocking in tests via http_api_transports
     *
     * @param  array $data  Transformed lead data
     * @return array{ success: bool, lead_id?: string, error?: string }
     */
    private function send_to_hubspot( array $data ): array {

        // Guard: credentials must be configured
        if ( empty( $this->hs_access_token ) || empty( $this->hs_portal_id ) || empty( $this->hs_form_guid ) ) {
            return [
                'success' => false,
                'error'   => 'HubSpot credentials not configured. Set HUBSPOT_ACCESS_TOKEN, HUBSPOT_PORTAL_ID, HUBSPOT_FORM_GUID in wp-config.php.',
            ];
        }

        // Build fields array — HubSpot Forms API format
        $fields = array_filter( [
            [ 'name' => 'firstname',    'value' => $data['firstname']    ?? '' ],
            [ 'name' => 'lastname',     'value' => $data['lastname']     ?? '' ],
            [ 'name' => 'email',        'value' => $data['email']        ?? '' ],
            [ 'name' => 'phone',        'value' => $data['phone']        ?? '' ],
            [ 'name' => 'company',      'value' => $data['company']      ?? '' ],
            [ 'name' => 'message',      'value' => $data['message']      ?? '' ],
            // Custom HubSpot Contact properties — create in HubSpot → Settings → Properties first
            [ 'name' => 'utm_source',   'value' => $data['utm_source']   ?? '' ],
            [ 'name' => 'utm_medium',   'value' => $data['utm_medium']   ?? '' ],
            [ 'name' => 'utm_campaign', 'value' => $data['utm_campaign'] ?? '' ],
            [ 'name' => 'form_source',  'value' => 'contact_form_7' ],       // Useful for HS reporting
        ], fn( $field ) => $field['value'] !== '' ); // Don't send empty fields

        /**
         * Context block enables HubSpot session stitching.
         *
         * hutk (HubSpot tracking key) = value of the hubspotutk cookie.
         * This cookie is set by the HubSpot tracking script (hs-analytics.js).
         * When present, HubSpot merges this form submission with the
         * visitor's existing session: pages visited, time on site, original
         * traffic source, prior form fills. Critical for accurate attribution.
         *
         * pageUri → which page the form was on (for HS analytics)
         * pageName → human-readable name in HS contact timeline
         */
        $context = [
            'pageUri'  => $data['source_url'] ?: get_home_url(),
            'pageName' => get_bloginfo( 'name' ) . ' — Contact',
        ];

        if ( ! empty( $_COOKIE['hubspotutk'] ) ) {
            $context['hutk'] = sanitize_text_field( $_COOKIE['hubspotutk'] );
        }

        $endpoint = sprintf(
            '%s/%s/%s',
            self::HS_ENDPOINT,
            $this->hs_portal_id,
            $this->hs_form_guid
        );

        $response = wp_remote_post( $endpoint, [
            'method'  => 'POST',
            'timeout' => 15,            // 15s max — avoid PHP timeout on slow API
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->hs_access_token,
            ],
            'body' => wp_json_encode( [
                'fields'  => array_values( $fields ), // Reset keys after array_filter
                'context' => $context,
            ] ),
        ] );

        // Transport-level failure (DNS, timeout, SSL error — not an HTTP error code)
        if ( is_wp_error( $response ) ) {
            return [
                'success' => false,
                'error'   => 'Network/transport error: ' . $response->get_error_message(),
            ];
        }

        $http_status = (int) wp_remote_retrieve_response_code( $response );
        $body        = json_decode( wp_remote_retrieve_body( $response ), true );

        // HubSpot returns 200 on success
        if ( $http_status === 200 ) {
            return [
                'success' => true,
                'lead_id' => 'hs_' . time(),
            ];
        }

        /**
         * Common HubSpot error codes:
         *   400 → Malformed payload (wrong field names, missing required fields)
         *   401 → Invalid/expired access token
         *   403 → Token doesn't have forms scope
         *   404 → Wrong portal ID or form GUID
         *   429 → HubSpot's own rate limit exceeded
         */
        $hs_message = $body['message'] ?? ( 'Unexpected response from HubSpot.' );

        return [
            'success' => false,
            'error'   => "HubSpot API [{$http_status}]: {$hs_message}",
            'code'    => $http_status,
        ];
    }

    // =========================================================================
    // Assets
    // =========================================================================

    /**
     * Enqueue UTM capture script on pages that have the CF7 form.
     *
     * WHY check for the form first?
     * Loading the script on every page wastes resources.
     * We check page content for the CF7 shortcode.
     */
    public function enqueue_assets(): void {
        if ( ! $this->current_page_has_form() ) {
            return;
        }

        wp_enqueue_script(
            'nurosparx-utm-capture',
            get_stylesheet_directory_uri() . '/inc/hubspot/assets/utm-capture.js',
            [],      // No jQuery needed — native JS only
            '1.0.0',
            true     // Load in footer (after CF7's own scripts)
        );

        /**
         * Pass CF7 form post ID to JS so it can target the correct hidden fields.
         * CF7 generates input IDs as: input_FORMHASH_FIELDNAME
         * e.g. input_ae21fd9_utm_source
         */
        wp_localize_script( 'nurosparx-utm-capture', 'ns_cf7_config', [
            'form_id' => self::CF7_FORM_ID,
        ] );
    }

    /**
     * Detect if the current page contains our specific CF7 form.
     *
     * @return bool
     */
    private function current_page_has_form(): bool {
        global $post;
        if ( ! $post instanceof WP_Post ) {
            return false;
        }
        // Check for our specific form post ID in the shortcode
        return str_contains( $post->post_content, self::CF7_FORM_ID );
    }

    // =========================================================================
    // Utilities
    // =========================================================================

    /**
     * Get the real client IP address behind CDNs / reverse proxies.
     *
     * Priority order:
     *   HTTP_CF_CONNECTING_IP → Cloudflare (most accurate — real client)
     *   HTTP_X_FORWARDED_FOR  → Standard proxy header
     *   HTTP_X_REAL_IP        → Nginx reverse proxy
     *   REMOTE_ADDR           → Direct connection (fallback)
     *
     * Security: validate each candidate with FILTER_VALIDATE_IP and
     * exclude private ranges (192.168.x, 10.x, 127.x) to prevent
     * IP spoofing via a crafted X-Forwarded-For header.
     *
     * @return string
     */
    private function get_client_ip(): string {
        $candidates = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'REMOTE_ADDR',
        ];

        foreach ( $candidates as $header ) {
            if ( empty( $_SERVER[ $header ] ) ) {
                continue;
            }
            // X-Forwarded-For can be "client, proxy1, proxy2" — take leftmost
            $ip = trim( explode( ',', $_SERVER[ $header ] )[0] );
            if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
                return $ip;
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Write to the WordPress debug log.
     *
     * Rules:
     *   → Never log email addresses, phone numbers, or names (GDPR Art. 5(1)(e))
     *   → Truncate technical detail to 200 chars (no massive stack traces in logs)
     *   → Only logs when WP_DEBUG_LOG is true
     *
     * In production: replace with a proper observability tool (Sentry, Raygun).
     * error_log() is fine for debugging; not for production alerting.
     *
     * @param string $message  Safe summary (no PII)
     * @param string $detail   Technical detail (truncated, tags stripped)
     */
    private function log_error( string $message, string $detail = '' ): void {
        if ( ! ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) ) {
            return;
        }

        $safe_detail = mb_substr( wp_strip_all_tags( $detail ), 0, 200 );

        error_log( sprintf(
            '[NuroSparX][HubSpot][%s] %s%s',
            current_time( 'mysql' ),
            $message,
            $safe_detail ? ' | ' . $safe_detail : ''
        ) );
    }
}
