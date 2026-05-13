<?php
/**
 * NuroSparX — Rate Limiter
 *
 * IP-based rate limiting using WordPress Transients API.
 *
 * WHY a dedicated class?
 *   Rate limiting logic is independent of HubSpot. Tomorrow we might add
 *   a second form (e.g. newsletter). Same class, new instance — no copy-paste.
 *   Separation of Concerns: this class knows ONLY about counting. It does
 *   not know about HubSpot, CF7, or the DB.
 *
 * WHY Transients?
 *   → Auto-expires after the window. No cron cleanup needed.
 *   → Uses Redis/Memcached when available (sub-millisecond).
 *   → Falls back to WP options table (works on all shared hosting).
 *   → Per-IP key means one abuser doesn't affect others.
 *
 * KNOWN LIMITATION — race condition:
 *   Two concurrent requests can both read count=2, both write count=3,
 *   and both slip through when only one should.
 *   For a CONTACT FORM: acceptable. 1 extra submit in a race won't hurt.
 *   For PAYMENTS / AUTH: use Redis INCR (atomic read+increment in one op).
 *
 * @package NuroSparX
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NuroSparX_Rate_Limiter {

    /**
     * Max allowed submissions per IP within the time window.
     * @var int
     */
    private int $max_attempts;

    /**
     * Time window in seconds.
     * Default: 3600 = 1 hour.
     * @var int
     */
    private int $window_seconds;

    /**
     * Transient key prefix.
     * md5(IP) keeps the key alphanumeric and under 172-char WP limit.
     * @var string
     */
    private string $key_prefix;

    /**
     * @param int    $max_attempts    Max submissions allowed in the window
     * @param int    $window_seconds  Window duration in seconds
     * @param string $key_prefix      Unique prefix (allows multiple rate limiters)
     */
    public function __construct(
        int    $max_attempts    = 3,
        int    $window_seconds  = 3600,
        string $key_prefix      = 'ns_rl_'
    ) {
        $this->max_attempts   = $max_attempts;
        $this->window_seconds = $window_seconds;
        $this->key_prefix     = $key_prefix;
    }

    /**
     * Check if the given IP has exceeded the limit.
     *
     * @param  string $ip
     * @return bool   TRUE = blocked, FALSE = allowed
     */
    public function is_exceeded( string $ip ): bool {
        $count = get_transient( $this->make_key( $ip ) );
        return ( false !== $count && (int) $count >= $this->max_attempts );
    }

    /**
     * Increment the counter for this IP.
     *
     * Called AFTER a successful (or fallback-saved) submission.
     * WHY not before?  We don't count rejected submissions — only real ones.
     * A validation error shouldn't eat into the user's 3 attempts.
     *
     * @param string $ip
     */
    public function increment( string $ip ): void {
        $key   = $this->make_key( $ip );
        $count = get_transient( $key );

        if ( false === $count ) {
            // First submission — start counter with full TTL
            set_transient( $key, 1, $this->window_seconds );
        } else {
            // Subsequent — increment, keep remaining TTL
            // NOTE: set_transient resets TTL. For a precise sliding window
            // you'd store the first-submission timestamp and compute remaining
            // TTL manually. For a contact form, resetting TTL is fine.
            set_transient( $key, (int) $count + 1, $this->window_seconds );
        }
    }

    /**
     * Get remaining attempts for this IP (useful for debugging / admin UI).
     *
     * @param  string $ip
     * @return int
     */
    public function remaining( string $ip ): int {
        $count = get_transient( $this->make_key( $ip ) );
        if ( false === $count ) {
            return $this->max_attempts;
        }
        return max( 0, $this->max_attempts - (int) $count );
    }

    /**
     * Build the transient key for an IP address.
     *
     * WHY md5?
     *   → Ensures the key is always alphanumeric (transient keys must be)
     *   → IPv6 addresses are long; md5 keeps us well under the 172-char limit
     *   → Not used for security — just namespacing, so md5 is sufficient
     *
     * @param  string $ip
     * @return string
     */
    private function make_key( string $ip ): string {
        return $this->key_prefix . md5( $ip );
    }
}
