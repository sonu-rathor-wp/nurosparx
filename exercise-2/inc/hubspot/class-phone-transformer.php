<?php
/**
 * NuroSparX — Phone Number Transformer
 *
 * Converts raw phone input to E.164 international format.
 *
 * WHY E.164?
 *   E.164 is the ITU-T standard: +[country_code][subscriber_number]
 *   Example: +12125551234 for a New York number.
 *
 *   HubSpot stores phones in E.164 for:
 *   → Dialler integrations (HubSpot Calling, Aircall, Twilio)
 *   → Contact deduplication  "(212) 555-1234" and "+12125551234" = same person
 *   → SMS/WhatsApp workflows
 *   → International display formatting per locale
 *
 * PRODUCTION NOTE:
 *   This class handles US numbers correctly and makes a best-effort for
 *   international numbers. For production with heavy international traffic,
 *   replace to_e164() internals with:
 *       composer require giggsey/libphonenumber-for-php
 *   libphonenumber is Google's open-source phone parsing library — the
 *   same one Android uses. It handles 250+ country codes perfectly.
 *
 * WHY a separate class?
 *   Phone transformation is a self-contained algorithm with its own
 *   edge cases and tests. Keeping it isolated means:
 *   → Unit test every case without booting WordPress
 *   → Swap the implementation (e.g. add libphonenumber) in one place
 *   → Reuse in other integrations (SMS plugin, CRM sync, etc.)
 *
 * @package NuroSparX
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class NuroSparX_Phone_Transformer {

    /**
     * Convert a phone string to E.164 format.
     *
     * Decision tree:
     *   1. Already has + prefix         → strip non-digits after +, return as-is
     *   2. 10 digits (US without code)  → prepend +1
     *   3. 11 digits starting with 1    → prepend +
     *   4. 7–15 digits (international)  → prepend + (best-effort)
     *   5. Anything else                → return original (non-fatal)
     *
     * @param  string $phone  Raw phone input from form
     * @return string         E.164 formatted phone or original on failure
     */
    public function to_e164( string $phone ): string {
        $phone = trim( $phone );
        error_log(
            'raw phone input: ' . $phone
        );
        if ( empty( $phone ) ) {
            return '';
        }

        // Case 1: Already E.164 — starts with +
        if ( str_starts_with( $phone, '+' ) ) {
            // Clean: keep + and digits only
            $digits = preg_replace( '/[^\d]/', '', substr( $phone, 1 ) );
            return '+' . $digits;
        }

        // Strip all non-digit characters from raw input
        $digits      = preg_replace( '/\D/', '', $phone );
        $digit_count = strlen( $digits );

        // Case 2: 10 digits → US number without country code
        //   (212) 555-1234 → +12125551234
        if ( $digit_count === 10 ) {
            return '+1' . $digits;
        }

        // Case 3: 11 digits starting with 1 → US number with country code
        //   12125551234 → +12125551234
        if ( $digit_count === 11 && $digits[0] === '1' ) {
            return '+' . $digits;
        }

        // Case 4: 7–15 digits → international, best-effort
        //   Prepend + and trust the user entered their country code
        if ( $digit_count >= 7 && $digit_count <= 15 ) {
            return '+' . $digits;
        }

        // Case 5: Can't determine — return original (non-fatal, HubSpot will store as-is)
        return $phone;
    }

    /**
     * Validate whether a string looks like a parseable phone number.
     * Used for soft validation — we never hard-reject international numbers
     * we don't understand.
     *
     * @param  string $phone
     * @return bool
     */
    public function is_valid_format( string $phone ): bool {
        $digits = preg_replace( '/\D/', '', $phone );
        return strlen( $digits ) >= 7 && strlen( $digits ) <= 15;
    }
}
