/**
 * NuroSparX — UTM Capture & CF7 Field Population
 *
 * Responsibilities:
 *   1. On every page load → read UTM params from URL → store in sessionStorage
 *   2. On the contact form page → populate CF7 hidden fields from sessionStorage
 *   3. On successful CF7 submit → push GTM dataLayer event
 *
 * WHY sessionStorage (not localStorage)?
 *   localStorage persists FOREVER across sessions.
 *   sessionStorage persists only within the current browser tab/session.
 *
 *   Marketing attribution rule: UTMs should reflect the session that led
 *   to the conversion — not a campaign from 3 months ago.
 *   sessionStorage gives us within-session first-touch attribution.
 *   If you want cross-session attribution (e.g. last-touch over 30 days),
 *   use localStorage with an expiry timestamp check.
 *
 * WHY populate hidden fields and not send UTMs separately?
 *   CF7 submits all form fields together in one AJAX request.
 *   Our PHP hook (wpcf7_before_send_mail) reads $_POST['utm_source'] etc.
 *   Populating the hidden fields means everything travels in the same payload —
 *   no race conditions, no separate AJAX calls, no session state on the server.
 *
 * WHY IIFE + 'use strict'?
 *   IIFE = Immediately Invoked Function Expression.
 *   Keeps all variables in a private scope — zero global pollution.
 *   'use strict' catches undeclared variables, silent assignment errors, etc.
 *
 * ns_cf7_config is injected by wp_localize_script() in PHP:
 *   { form_id: 'ae21fd9' }
 *
 * @package NuroSparX
 * @since   1.0.0
 */

( function () {
    'use strict';

    // ── Config ────────────────────────────────────────────────────────────────

    /**
     * UTM parameter keys we track.
     * These match the CF7 hidden field names in the shortcode:
     *   [hidden utm_source id:utm_source]  → input ID becomes "utm_source-HASH"
     * and the PHP FIELD_MAP in class-hubspot-integration.php.
     */
    var UTM_KEYS = [
        'utm_source',
        'utm_medium',
        'utm_campaign',
        'utm_term',
        'utm_content'
    ];

    /**
     * CF7 form post ID from wp_localize_script().
     * CF7 generates hidden field IDs as: fieldname-HASH
     * e.g. utm_source-ae21fd9
     *
     * WHY use the post ID and not a generic selector?
     * A page could theoretically have multiple CF7 forms.
     * Targeting by form post ID ensures we populate the RIGHT form's fields.
     */
    var FORM_ID = ( typeof ns_cf7_config !== 'undefined' && ns_cf7_config.form_id )
        ? ns_cf7_config.form_id
        : '';

    // ── Step 1: Capture UTMs from current URL ─────────────────────────────────

    /**
     * Read UTM params from the URL and persist to sessionStorage.
     *
     * WHY only overwrite if the URL has a value?
     * Preserves first-touch attribution within the session.
     * Example flow:
     *   User clicks Google Ad → /?utm_source=google&utm_campaign=brand
     *   Navigates to /about/   (no UTMs in URL)
     *   Navigates to /contact/ (no UTMs in URL)
     *   → sessionStorage still has utm_source=google
     *   Submits form → correct attribution
     *
     * If we overwrote on every page, the /contact/ page load would clear them.
     */
    function captureUTMsFromURL() {
        var params = new URLSearchParams( window.location.search );

        UTM_KEYS.forEach( function ( key ) {
            var value = params.get( key );
            if ( value && value.trim() !== '' ) {
                sessionStorage.setItem( 'ns_' + key, value.trim() );
            }
        } );
    }

    // ── Step 2: Populate CF7 hidden fields ────────────────────────────────────

    /**
     * Find CF7 hidden input fields and fill them from sessionStorage.
     *
     * CF7 hidden field ID format: {field-name}-{form-hash}
     * From shortcode: [hidden utm_source id:utm_source]
     * CF7 generates: <input type="hidden" id="utm_source" name="utm_source" ...>
     *
     * NOTE: CF7 uses the id: tag to set the actual HTML id attribute.
     * So [hidden utm_source id:utm_source] → id="utm_source"
     * We can target by ID directly.
     */
    function populateCF7HiddenFields() {
        UTM_KEYS.forEach( function ( key ) {
            var stored = sessionStorage.getItem( 'ns_' + key );
            if ( ! stored ) return;

            // Target the hidden field by its id attribute (set via id: in CF7 shortcode)
            var field = document.getElementById( key );
            if ( field ) {
                field.value = stored;
            }
        } );
    }

    // ── Step 3: GTM Event on Successful CF7 Submit ────────────────────────────

    /**
     * Listen for CF7's custom DOM event on successful submission.
     *
     * CF7 fires 'wpcf7mailsent' on the form element after the server
     * responds with success. This is CF7's official event — reliable,
     * no polling needed.
     *
     * WHY dataLayer.push() and not gtag()?
     * dataLayer is the raw GTM interface. Works regardless of GTM version.
     * gtag() requires the GA4 gtag.js snippet — not all sites use it.
     *
     * Event properties we send:
     *   event         → GTM trigger matches on this
     *   form_id       → CF7 form post ID (useful if multiple forms on site)
     *   utm_source    → from sessionStorage
     *   utm_medium    → from sessionStorage
     *   utm_campaign  → from sessionStorage
     *
     * These UTM values allow GTM to fire GA4/Google Ads conversion tags
     * with proper attribution context attached.
     */
    function setupCF7SuccessTracking() {
        document.addEventListener( 'wpcf7mailsent', function ( event ) {
            // Safety check: only track our specific form
            if ( FORM_ID && event.detail.contactFormId !== FORM_ID ) {
                return;
            }

            if ( typeof window.dataLayer === 'undefined' ) {
                window.dataLayer = [];
            }

            window.dataLayer.push( {
                event:        'cf7_form_submitted',
                form_id:      FORM_ID,
                utm_source:   sessionStorage.getItem( 'ns_utm_source' )   || '',
                utm_medium:   sessionStorage.getItem( 'ns_utm_medium' )   || '',
                utm_campaign: sessionStorage.getItem( 'ns_utm_campaign' ) || '',
                utm_term:     sessionStorage.getItem( 'ns_utm_term' )     || '',
                utm_content:  sessionStorage.getItem( 'ns_utm_content' )  || '',
            } );
            console.log( 'CF7 submission tracked in GTM dataLayer.' );
            console.log( 'Event data:', {
                form_id:      FORM_ID,
                utm_source:   sessionStorage.getItem( 'ns_utm_source' )   || '',
                utm_medium:   sessionStorage.getItem( 'ns_utm_medium' )   || '',
                utm_campaign: sessionStorage.getItem( 'ns_utm_campaign' ) || '',
                utm_term:     sessionStorage.getItem( 'ns_utm_term' )     || '',
                utm_content:  sessionStorage.getItem( 'ns_utm_content' )  || '',
            } );
        }, false );
    }

    /**
     * CF7 also fires these events — useful for tracking:
     *   wpcf7invalid    → validation failed (could track form abandonment)
     *   wpcf7spam       → submission blocked as spam
     *   wpcf7mailfailed → server error during mail send
     *
     * Example — track spam blocks:
     *
     * document.addEventListener( 'wpcf7spam', function ( event ) {
     *     window.dataLayer = window.dataLayer || [];
     *     window.dataLayer.push( { event: 'cf7_spam_blocked', form_id: FORM_ID } );
     * } );
     */

    // ── Step 4: Re-populate after CF7 AJAX resets the form ───────────────────

    /**
     * CF7 re-renders the form HTML after each AJAX submission (success or fail).
     * This resets all input values — including our hidden UTM fields.
     *
     * We listen for CF7's 'wpcf7reset' event and re-populate.
     * This covers the edge case where CF7 is configured to keep the form
     * visible after submission (no redirect).
     */
    document.addEventListener( 'wpcf7reset', function () {
        // Small delay to ensure CF7 has finished re-rendering the DOM
        setTimeout( populateCF7HiddenFields, 100 );
    }, false );

    // ── Init ──────────────────────────────────────────────────────────────────

    /**
     * DOMContentLoaded fires when HTML is parsed — before images, stylesheets.
     * Ideal for form field manipulation: DOM is ready, no need to wait for assets.
     */
    document.addEventListener( 'DOMContentLoaded', function () {
        captureUTMsFromURL();     // Always run — captures UTMs on any page
        populateCF7HiddenFields(); // Only does work if CF7 fields exist on page
        setupCF7SuccessTracking(); // Attach GTM event listener
    } );

} )();
