# Exercise 2 — CF7 + HubSpot Integration Layer

## Strategic Decision — Why Contact Form 7?

I intentionally chose Contact Form 7 (CF7) over building a form from scratch.

**CF7 already solves:**
- Nonce / CSRF protection (generated per page load, verified on submit)
- Honeypot spam detection (via CF7 Flamingo companion plugin)
- Field validation (required, email format, tel pattern)
- AJAX submission (no page reload, built-in response messages)
- Admin UI (form builder, email templates, entry management)
- Accessibility (ARIA, screen reader compatible markup)

**My custom code solves everything business-critical:**
- IP-based rate limiting
- Defence-in-depth re-sanitization
- Phone → E.164 transformation
- HubSpot Forms API v3 submission
- WordPress DB fallback when HubSpot fails
- WP-Cron retry queue (every 30 minutes, max 5 attempts)
- GTM dataLayer event on successful submission
- Safe error logging (zero PII in logs)
- GDPR data purge and WordPress privacy tools integration
- Admin page for ops team visibility

This reflects real agency workflow. Most enterprise WordPress sites
use CF7, WPForms, or Gravity Forms while custom-coding their CRM integrations.

---

## File Structure

```
exercise-2/
├── inc/
│   └── hubspot/
│       ├── assets/
│       │   └── utm-capture.js            ← UTM capture, CF7 field population, GTM event
│       ├── class-rate-limiter.php         ← IP rate limiting via WP Transients
│       ├── class-phone-transformer.php    ← Phone string → E.164 format
│       ├── class-lead-db.php              ← Backup table CRUD, cron retry, GDPR erasure
│       ├── class-hubspot-integration.php  ← CF7 hooks + HubSpot API + fallback logic
│       ├── hubspot-cron-setup.php         ← Activation hooks, cron scheduling, GDPR purge
│       ├── hubspot-admin-page.php         ← WP-admin lead management screen
│       └── hubspot-bootstrap.php          ← Entry point (one require in functions.php)
└── README.md                              ← This file
```

---

## Setup (Step by Step)

### Step 1 — Add credentials to wp-config.php

```php
// ABOVE "That's all, stop editing!" line:
define( 'HUBSPOT_ACCESS_TOKEN', 'pat-na1-xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx' );
define( 'HUBSPOT_PORTAL_ID',    '12345678' );
define( 'HUBSPOT_FORM_GUID',    'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx' );
```

### Step 2 — Add to functions.php

For parent/custom theme
```php
require_once get_template_directory() . '/inc/hubspot/hubspot-bootstrap.php';
```
For child theme
```php
require_once get_stylesheet_directory() . '/inc/hubspot/hubspot-bootstrap.php';
```

### Step 3 — Activate the theme (creates DB table + schedules cron)

Go to Appearance → Themes → Activate your theme.

### Step 4 — Install Contact Form 7

`wordpress.org/plugins/contact-form-7/` — free, no licence needed.

### Step 5 — Create the form

Use this exact form body in CF7:

```
[text* your-name     autocomplete:name  placeholder "Full Name"]
[email* your-email   autocomplete:email placeholder "Email Address"]
[tel*  your-phone    autocomplete:tel   placeholder "Phone Number"]
[text  your-company                     placeholder "Company Name"]
[textarea your-message                  placeholder "Message"]

[hidden utm_source   id:utm_source]
[hidden utm_medium   id:utm_medium]
[hidden utm_campaign id:utm_campaign]

<div style="display:none;">[text company_website_honeypot]</div>

[submit "Send Message"]
```

### Step 6 — Update the form ID

Copy the post ID from the CF7 shortcode:
```
[contact-form-7 id="82" title="Contact form"]
                 ^^
```
Update `CF7_FORM_ID` constant in `class-hubspot-integration.php`:
```php
private const CF7_FORM_ID = '82'; // ← your post id here
```

### Step 7 — Create HubSpot custom contact properties

HubSpot → Settings → Properties → Contact → Create property (Single-line text):
- `utm_source`
- `utm_medium`
- `utm_campaign`

---

## Full Request Lifecycle

```
User submits CF7 form
        │
        ▼
CF7: nonce verification                        [CF7 built-in]
CF7: honeypot check (CSS hidden field)         [CF7 built-in]
CF7: field validation (required, email format) [CF7 built-in]
        │
        ▼
wpcf7_spam filter
  └── NuroSparX: IP rate limit check
      ├── Exceeded → return true (CF7 marks as spam, shows spam message)
      └── OK → return false (continue)
        │
        ▼
wpcf7_before_send_mail action
  └── NuroSparX: handle_submission()
      ├── Guard: only our form ID
      ├── Custom honeypot check (hidden div field)
      ├── Re-sanitize: sanitize_text_field(), sanitize_email(), sanitize_textarea_field()
      ├── Transform: lowercase email, E.164 phone, split name → firstname/lastname
      ├── Enrich: add IP, source URL
      ├── HubSpot Forms API v3 POST
      │     ├── 200 OK → increment rate limit → ✅ done
      │     └── Error  → save to DB backup → increment rate limit → log error
      └── CF7 continues → sends notification email → shows confirmation to user
        │
        ▼
JS: wpcf7mailsent DOM event fires
  └── Push GTM dataLayer event: cf7_form_submitted + UTM params
        │
        ▼
WP-Cron (every 30 min, if there are pending DB backups)
  └── process_retry_queue()
      ├── Fetch up to 10 pending leads
      ├── increment retry_count
      ├── Re-attempt HubSpot API
      ├── Success → mark_synced()
      └── 5th failure → mark_failed() permanently
```

---

## API Request / Response Examples

### Successful Submission — HubSpot Payload

```json
POST https://api.hsforms.com/submissions/v3/integration/submit/{portalId}/{formGuid}
Authorization: Bearer pat-na1-xxxx
Content-Type: application/json

{
  "fields": [
    { "name": "firstname",    "value": "John" },
    { "name": "lastname",     "value": "Smith" },
    { "name": "email",        "value": "john@acme.com" },
    { "name": "phone",        "value": "+12125551234" },
    { "name": "company",      "value": "Acme Corp" },
    { "name": "message",      "value": "Hello, I'd like to learn more." },
    { "name": "utm_source",   "value": "google" },
    { "name": "utm_medium",   "value": "cpc" },
    { "name": "utm_campaign", "value": "brand-2024" },
    { "name": "form_source",  "value": "contact_form_7" }
  ],
  "context": {
    "pageUri": "https://example.com/contact",
    "pageName": "NuroSparX — Contact",
    "hutk": "abc123hubspotcookievalue"
  }
}
```

### HubSpot Success Response
```
HTTP 200 OK
{ "inlineMessage": "Thanks for submitting the form." }
```

### HubSpot Error Responses
```
HTTP 400  Bad Request    → Wrong field name / malformed payload
HTTP 401  Unauthorized   → Invalid access token
HTTP 403  Forbidden      → Token missing forms scope
HTTP 404  Not Found      → Wrong portal ID or form GUID
HTTP 429  Too Many Req.  → HubSpot's own rate limit
```

---

## Database Schema

```sql
CREATE TABLE wp_nurosparx_lead_backup (
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
  KEY idx_email    (email),
  KEY idx_status   (status),
  KEY idx_created  (created_at),
  KEY idx_cf7_form (cf7_form_id)
);
```

---

## Security Checklist

- [x] CF7 nonce (CSRF protection, generated per page load)
- [x] CF7 built-in honeypot (CSS hidden field)
- [x] Custom honeypot (hidden div field — catches different bot types)
- [x] IP rate limiting (3 per hour, WP Transients)
- [x] Defence-in-depth re-sanitization (sanitize_* on all fields)
- [x] `$wpdb->insert()` with format specifiers (SQL injection prevention)
- [x] Credentials in wp-config.php (not in DB, not in code)
- [x] `is_wp_error()` check on HTTP response (transport safety)
- [x] IP validation excludes private ranges (proxy spoofing prevention)
- [x] Error log never contains PII (GDPR Art. 5(1)(e))
- [x] GDPR 90-day auto-purge via daily cron
- [x] WordPress privacy eraser integration (right-to-erasure)
- [x] `current_user_can('manage_options')` on all admin actions
- [x] Nonce verification on all admin action URLs

---

## What I'd Do With More Time

1. **WP_List_Table** for admin page — bulk actions, column sorting, CSV export
2. **libphonenumber-for-php** — international E.164 beyond US numbers
3. **PHPUnit tests** — unit test each class method independently  
4. **Redis atomic rate limiting** — replace transient read+increment with INCR
5. **Sentry integration** — structured error alerting instead of error_log()
6. **Webhook receipt confirmation** — HubSpot pings us when contact is created
7. **Double opt-in flow** — confirmation email before final HubSpot sync (GDPR best practice)
8. **WP-CLI command** — `wp nurosparx retry-leads` for manual bulk retry

## How I'd Test in Production

1. **Staging with real credentials** — submit real lead, verify in HubSpot contact timeline
2. **Simulate HubSpot failure** — wrong FORM_GUID → verify DB backup row + correct error_reason
3. **Cron retry test** — confirm pending lead syncs within 30 minutes automatically
4. **Rate limit test** — submit 4 times from same IP — 4th blocked by CF7 spam message
5. **Honeypot test** — POST with `company_website_honeypot` filled — silently ignored
6. **Phone formatting** — test raw 10-digit, 11-digit with leading 1, UK format (+44)
7. **GTM preview mode** — confirm `cf7_form_submitted` event fires with correct UTM params
8. **GDPR eraser** — Tools → Erase Personal Data → confirm row removed from backup table
