# Exercise 1 – WordPress Custom Post Type with Marketing Data Integration

## Folder Structure

```
exercise-1/
└── case-results-plugin/
    ├── case-results-plugin.php          ← Plugin bootstrap & hooks
    ├── includes/
    │   ├── class-post-type.php          ← CPT registration
    │   ├── class-meta-boxes.php         ← Custom fields (no ACF)
    │   ├── class-ajax-handler.php       ← AJAX filter endpoint
    │   ├── class-frontend.php           ← Assets, shortcode, utilities
    │   └── class-schema.php             ← JSON-LD structured data
    ├── templates/
    │   ├── archive-case-result.php      ← Archive page template (copy to theme)
    │   ├── single-case-result.php      ← Sinle page template (copy to theme)
    │   └── partials/
    │       └── case-card.php            ← Reusable card partial
    └── assets/
        ├── js/case-results.js           ← AJAX + GTM tracking
        └── css/case-results.css         ← Responsive grid styles
```

---

## Installation

1. Copy the `case-results-plugin/` folder to `wp-content/plugins/`.
2. Activate the plugin in **WP Admin → Plugins**.
3. Copy `templates/archive-case-result.php` into your **active theme** root  
   (WordPress template hierarchy picks it up automatically).
4. The CPT archive is now live at `/case-results/`.

---

## Architectural Decisions

### 1. Plugin, Not functions.php

All code lives in a self-contained plugin rather than a theme's `functions.php`.

**Why?**  
- Portable — works on any theme without modification.  
- Deactivatable — disable without breaking the theme.  
- Separation of concerns: the theme controls presentation; the plugin owns data and business logic.

---

### 2. Namespaced Classes, Not Procedural Functions

Each concern is a class under the `CaseResults` namespace.

**Why?**  
- No global function name collisions.  
- Autoloadable (PSR-4 compatible structure).  
- Easier unit testing — you can mock or stub individual classes.

---

### 3. No ACF — Native Meta Box API

Custom fields are built with `add_meta_box()` and `update_post_meta()`.

**Why?**  
- The brief explicitly requires it.  
- Zero plugin dependency = no licensing cost, no version conflicts.  
- Senior developers should know the native API; ACF is a productivity tool, not a requirement.

---

### 4. Security Model (Meta Box Save)

Every `save_post` callback follows a four-step security checklist:

| Step | Check | Reason |
|------|-------|--------|
| 1 | `wp_verify_nonce()` | Prevents CSRF — ensures save came from OUR form |
| 2 | `defined('DOING_AUTOSAVE')` | Skips WP auto-save (incomplete data) |
| 3 | `current_user_can('edit_post')` | Authorisation — right capability |
| 4 | `get_post_type() === CPT slug` | Don't fire on other post types |

Each field is sanitised to its **own data type**:
- `absint()` for numbers
- `sanitize_text_field()` for plain text
- Array key whitelist check for the dropdown

---

### 5. WP_Query vs Raw SQL

All database queries use `WP_Query`.

**Why not raw SQL?**  
- `WP_Query` uses prepared statements internally → SQL injection protection.  
- Respects WordPress object cache, filters, and actions.  
- More readable and maintainable.  
- Raw SQL also bypasses `posts_clauses` filters that caching plugins use.

**Avoiding N+1 queries:**  
The `meta_key` + `orderby: meta_value_num` approach fetches posts AND their sort key in one JOIN query, not a separate `get_post_meta()` call per post.

---

### 6. AJAX Architecture

```
Browser → POST /wp-admin/admin-ajax.php
         { action: 'cr_filter_cases', nonce: '...', case_type: '...', paged: 1 }
         ↓
WordPress fires: wp_ajax_nopriv_cr_filter_cases
         ↓
Ajax_Handler::handle()
  → nonce verify
  → whitelist validate
  → WP_Query
  → ob_start() → include case-card.php loop → ob_get_clean()
  → wp_send_json_success({ html, found_posts, max_num_pages })
         ↓
JS replaces #cr-results-grid innerHTML
```

---

### 7. GTM Tracking via Data Attributes

```html
<article class="cr-card"
  data-case-type="car_accident"
  data-settlement="250000"
  data-post-id="42">
```

```js
dataLayer.push({
  event:             'case_result_view',
  case_type:         'car_accident',
  settlement_amount: 250000,
  post_id:           42,
});
```

**Why data attributes instead of inline script?**  
- Clean separation of concerns — HTML carries data, JS reads it.  
- Works after AJAX swaps (no stale closures).  
- Easy for GTM variable configuration (Element Visibility trigger on `.cr-card`).

---

### 8. Schema.org Structured Data

We output `JSON-LD` (not Microdata) using `LegalService` + `ItemList` types.

**Why JSON-LD?**  
- Google's preferred format.  
- Doesn't pollute the HTML structure.  
- Easier to validate via Google's Rich Results Test.

---

## Assumptions Made

1. Theme uses standard `get_header()` / `get_footer()` — no block theme assumed.
2. GTM container is already installed on the site.
3. `WP_CACHE` / object caching is handled at the infrastructure level (no custom caching layer built here, but the queries are cache-friendly).

---

## What I'd Do With More Time

1. **Unit tests** with `WP_UnitTestCase` for the meta box sanitisation logic.
2. **Block editor support** — a Gutenberg block wrapping the shortcode query.
3. **REST API endpoint** (`/wp-json/case-results/v1/cases`) for headless/decoupled use.
4. **CSV export** of case data for the client's marketing team.
5. **Transient caching** on the high-value shortcode query (it's expensive and the data doesn't change often).

---

## How I'd Test in Production

1. **QA the meta box:** Add a case with each field value; check `wp_postmeta` table directly with `SELECT * FROM wp_postmeta WHERE post_id = X`.
2. **Test AJAX filter:** Open DevTools → Network tab → click each filter button → confirm POST to `admin-ajax.php` returns 200 with valid JSON.
3. **Test GTM:** Open GTM Preview mode → click a case card → confirm `case_result_view` fires with correct parameters.
4. **Validate schema:** Paste the archive URL into [Google's Rich Results Test](https://search.google.com/test/rich-results).
5. **Security test:** Submit the AJAX request with an invalid `case_type` value and confirm a 400 error is returned.

---

## Performance Notes

- Assets enqueued **only on CPT pages** (not sitewide).
- JS loaded in the **footer** (non-render-blocking).
- `no_found_rows: true` on the shortcode query (disables COUNT query, faster for non-paginated output).
- `meta_value_num` orderby uses a database index on `meta_value` (WordPress creates this by default).

### 9. Shortcode

    Shortcode: [case_results limit="5" min_amount="100000"]

    - default limit is `5`
    - default min_amount is `0` 
    
