# Exercise 3: Performance Optimization – NuroSparX Assignment

## Assumptions Made

Since this site could be built with **Elementor / ACF / WooCommerce or any combination**, I've made these assumptions:

1. Theme is a **custom child theme** (not a starter theme like Astra or GeneratePress by itself)
2. Server could be **Apache or Nginx** – I've provided rules for both
3. CDN is **Cloudflare** (free or pro tier)
4. Build tool: No webpack/vite assumed – plain PHP + vanilla JS enqueued via WordPress
5. Caching plugin: **WP Rocket** or **LiteSpeed Cache** recommended (explained below)
6. Images are served from `/wp-content/uploads/` (standard WordPress path)
7. PHP 8.0+, WordPress 6.x

---

## Part A: Prioritized Performance Action Plan

### Current State (Given)
| Metric | Current | Target |
|--------|---------|--------|
| Page Size | 12.4 MB | < 2 MB |
| HTTP Requests | 73 | < 25 |
| LCP | 6.8s | < 2.5s |
| TBT | 1,920ms | < 200ms |
| CLS | 0.42 | < 0.1 |
| Load Time (mobile) | 8.5s | < 3s |

---

### Top 5 Issues in Order of Impact

---

#### 🔴 Issue #1: Unoptimized Images (HIGHEST IMPACT)
**Why it's the problem:**
- 8 images above-fold, avg 3MB each = ~24MB potential just from images
- Browser must download, decode, and paint these before LCP can fire
- No lazy loading = ALL images load on initial page load
- No next-gen formats (WebP/AVIF) = largest file sizes

**Proposed Solution:**
- Convert all images to **WebP** with JPEG/PNG fallback using `<picture>` element
- Set explicit `width` and `height` attributes to eliminate CLS
- Add `loading="lazy"` to all below-fold images
- Add `fetchpriority="high"` to the single LCP image (hero image)
- Preload the LCP image in `<head>` using `<link rel="preload">`
- Use `srcset` for responsive images (multiple sizes)

**Expected Improvement:** LCP: 6.8s → ~2.0s | Page size: 12.4MB → ~2MB

**Implementation Difficulty:** Medium

---

#### 🔴 Issue #2: Render-Blocking JavaScript (15 files, 2.4MB in `<head>`)
**Why it's the problem:**
- Browser must parse and execute ALL JS before rendering any HTML
- TBT of 1,920ms is almost entirely caused by this
- 2.4MB of JS on mobile means ~15s of parse time on mid-range phones

**Proposed Solution:**
- Move all scripts to footer using `wp_enqueue_script()` with `$in_footer = true`
- Add `defer` to non-critical scripts (they load after HTML parse, execute in order)
- Add `async` to fully independent scripts (analytics, tag managers)
- **Load 3rd-party scripts on user interaction** (scroll, click, mousemove) – covered in code
- Bundle/minify JS where possible

**Expected Improvement:** TBT: 1,920ms → ~200ms

**Implementation Difficulty:** Medium

---

#### 🟠 Issue #3: Render-Blocking CSS (15 files)
**Why it's the problem:**
- Browser blocks rendering until all CSS is downloaded and parsed
- Each file = separate HTTP request + parse time
- Most of this CSS is likely NOT needed for above-fold content

**Proposed Solution:**
- Extract and **inline critical CSS** (above-fold styles, ~14KB max)
- Load remaining CSS asynchronously using `media="print"` trick or `preload` + `onload`
- Concatenate all theme CSS into one file
- Remove unused CSS (tools: PurgeCSS or WP Rocket's "Remove Unused CSS" feature)

**Expected Improvement:** Eliminates render-blocking, reduces HTTP requests by ~13

**Implementation Difficulty:** Hard

---

#### 🟡 Issue #4: Google Fonts (23 variants)
**Why it's the problem:**
- Each font variant = separate HTTP request to Google servers
- DNS lookup → TCP handshake → TLS → download for EXTERNAL domain
- 23 variants is extreme – likely only 2-3 actually used on page

**Proposed Solution:**
- Audit which fonts/weights are actually used (browser DevTools → Sources)
- Self-host fonts using `@font-face` (eliminates external DNS lookup)
- Use `font-display: swap` to prevent invisible text during font load
- Maximum: 2 font families, 2-3 weights each
- Use `<link rel="preconnect">` and `<link rel="preload">` for remaining Google Fonts

**Expected Improvement:** Saves 10-20 HTTP requests, 200-800ms

**Implementation Difficulty:** Easy-Medium

---

#### 🟡 Issue #5: Database Queries (147 per page load)
**Why it's the problem:**
- Each query adds server response time (TTFB impact)
- Elementor / ACF / WooCommerce are notorious for N+1 query problems
- 147 queries suggests uncached meta queries and no object caching

**Proposed Solution:**
- Enable **Redis / Memcached** object caching (if host supports it)
- Use WordPress Transients API to cache expensive queries
- Replace `get_post_meta()` loops with a single `get_post_meta($id)` (returns all meta at once)
- Add `'no_found_rows' => true` and `'update_post_meta_cache' => false` to WP_Queries that don't need pagination
- Use **Query Monitor** plugin to identify the worst offenders

**Expected Improvement:** TTFB reduction of 300-800ms

**Implementation Difficulty:** Hard (depends on what's generating queries)

---

## Cloudflare Recommended Configuration

### DNS
✅ Enable Orange Cloud Proxy

This enables:
- CDN edge caching
- DDoS protection
- Faster asset delivery
- Smart routing

---

### Caching Settings

Caching → Configuration

| Setting | Value |
|---|---|
| Caching Level | Standard |
| Browser Cache TTL | 1 Month |

---

### Speed Optimization

Enable:

- Brotli Compression
- Auto Minify (HTML/CSS/JS)
- Early Hints
- HTTP/3
- QUIC

---

### APO (Automatic Platform Optimization)

Recommended for WordPress sites.

Benefits:
- Full HTML edge caching
- Reduced TTFB
- Faster anonymous traffic handling

---

### Cache Warming

After deployment or cache purge:
- Warm homepage cache
- Warm landing pages
- Warm blog archives

This prevents slow first-visit performance caused by cold cache.

---

## Above-the-Fold Optimization Strategy

The above-the-fold section directly impacts:
- LCP
- perceived performance
- first render speed

### Best Practices

- Keep hero section lightweight
- Avoid sliders/carousels above fold
- Avoid heavy animations
- Inline only critical CSS
- Avoid loading unnecessary JS above fold
- Preload only the primary hero image
- Reduce DOM depth in hero section

### Important Note

Even if the overall page is large, users perceive the website as fast when the above-the-fold content renders quickly.

---

## DOM Size & Layout Complexity Optimization

Deep DOM structures increase:
- style recalculation time
- layout computation
- paint complexity
- memory usage

This is especially common in:
- Elementor
- page builders
- nested flex/grid containers

### Recommended Improvements

- Reduce unnecessary wrappers
- Avoid deeply nested Elementor containers
- Use semantic HTML
- Reuse components
- Minimize excessive section nesting

Target:
- DOM size under ~1500 nodes where possible

### Trade-off Discussions

**Q: Client insists on keeping all 8 large images above the fold?**
- Implement **progressive JPEG** encoding (images appear blurry first, then sharpen)
- Use **LQIP** (Low Quality Image Placeholder) — show tiny blurred version first
- Aggressively compress to WebP (80% quality still looks great, 70% file size reduction)
- Use Cloudflare **Image Resizing** (Pro feature) or **Cloudflare Images** to serve optimized versions automatically
- Accept that LCP will not reach "Good" (< 2.5s) but can reach "Needs Improvement" (< 4.0s)

**Q: How to handle 3rd-party scripts (GTM, Analytics, Fonts, Plerdy, Zoho SalesIQ, reCAPTCHA)?**
- **Google Tag Manager**: Load on first user interaction (scroll 1px, mousemove, click)
- **Analytics.js / GA4**: Load via GTM (then it's one request instead of separate)
- **DoubleClick**: Load via GTM with triggers
- **Plerdy**: Load on `DOMContentLoaded` + delay 3s (heatmap tools don't need instant load)
- **Zoho SalesIQ / chat widget**: Load on first scroll or after 5s delay
- **reCAPTCHA / grecaptcha**: Load only on pages with forms, or load on field focus
- **Cloudflare Zaraz**: Load ALL third-party scripts through Cloudflare's server-side proxy (eliminates client-side performance hit entirely) — **HIGHLY RECOMMENDED**

---

### Recommended Plugins

| Plugin | Purpose | Free/Paid |
|--------|---------|-----------|
| **WP Rocket** | Caching, CSS/JS optimization, lazy load | Paid (~$59/yr) |
| **Imagify** or **ShortPixel** | Bulk WebP conversion | Freemium |
| **Query Monitor** | Identify slow database queries | Free |
| **Asset CleanUp Pro** | Disable scripts/styles per page | Freemium |
| **Flying Scripts** | Load scripts on user interaction | Free |
| **Flying Fonts** | Self-host Google Fonts automatically | Free |
| **Redis Object Cache** | PHP object caching via Redis | Free |
| **Perfmatters** | Lightweight performance tweaks | Paid (~$25/yr) |

---

### Before/After Performance Projection

| Metric | Before | After (Projected) |
|--------|--------|-------------------|
| Page Size | 12.4 MB | 1.8–2.2 MB |
| HTTP Requests | 73 | 18–22 |
| LCP | 6.8s | 1.8–2.4s |
| TBT | 1,920ms | 150–250ms |
| CLS | 0.42 | 0.02–0.05 |
| Mobile Load | 8.5s | 2.5–3.5s |
| PageSpeed Score | ~15–25 | ~75–90 |

---

### Tools to Measure Improvements

1. **Google PageSpeed Insights** – Core Web Vitals + field data
2. **WebPageTest.org** – Waterfall chart, filmstrip, real devices
3. **Chrome DevTools → Performance tab** – TBT, LCP, CLS in real time
4. **Query Monitor (WP Plugin)** – Database queries per page
5. **GTmetrix** – Historical tracking, waterfall
6. **Cloudflare Analytics** – After Cloudflare setup (TTFB, cache hit ratio)
7. **Search Console → Core Web Vitals report** – Real user data (28-day window)

---

## Part B: Code Implementations

### Implementation 1: Image Lazy Loading & Optimization
📁 `inc/image-optimization.php`

### Implementation 2: CSS & JS Optimization (incl. 3rd-party script delay)
📁 `inc/asset-optimization.php`

### Server Config
📁 `server-config/nginx.conf` – Nginx caching rules
📁 `server-config/.htaccess` – Apache caching rules (Nginx doesn't use .htaccess)

---

## How to Load in Theme

In your `functions.php`, add:

```php
// Exercise 3 – Performance Optimization
require_once get_template_directory() . '/inc/image-optimization.php';
require_once get_template_directory() . '/inc/asset-optimization.php';
```

 - Also contain theme setup & other code
---

## What I Would Do Differently With More Time

1. Set up **Critical CSS generation** with a Node.js build step (Critical npm package)
2. Implement **Service Worker** for offline caching of static assets
3. Set up **Cloudflare Zaraz** to proxy all 3rd-party scripts server-side
4. Write **automated Lighthouse CI** tests to catch regressions on each deploy
5. Implement **Resource Hints** (`dns-prefetch`, `preconnect`) for all external domains
6. Profile database queries in staging with **slow query log** enabled in MySQL

