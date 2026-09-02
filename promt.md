# 🌐 Geo Regional Router - Master Developer & AI Engineering Prompt

> **Purpose of this document:**  
> This file acts as the master technical specification and AI engineering prompt for maintaining, developing, extending, or refactoring the **Geo Regional Router (GRR)** WordPress Multisite plugin. Any AI assistant or developer working on this codebase should read and follow this document to ensure architectural consistency, caching compatibility, and code quality.

---

## 1. Project Overview & Context

* **Plugin Name:** Geo Regional Router
* **Current Version:** `1.0.6`
* **Type:** WordPress Multisite Network-Activated Plugin
* **Minimum Requirements:** WordPress 6.0+, PHP 8.3+
* **Primary Target Environment:** WordPress Multisite (subdirectory format, e.g., `/` for Global, `/bd/` for Bangladesh, `/in/` for India) running on **LiteSpeed Web Server**, Hostinger Shared Hosting, Cloudflare, or Nginx.
* **Core Philosophy:** High-performance, 100% Full Page Cache (LSCache) compatible, SEO compliant (Googlebot-friendly), zero-dependency, and production-ready.

---

## 2. Core Architectural Modes

The plugin supports two operational paradigms, configurable under **Network Admin > Settings > Geo Regional Router**:

```
                                [ Visitor Request ]
                                         │
                 ┌───────────────────────┴───────────────────────┐
                 ▼                                               ▼
       [ Mode 1: Client-Side Prompt ]                 [ Mode 2: Immediate Redirect ]
         (Recommended for LSCache)                      (Legacy Server Engine)
                 │                                               │
    ⚡ 100% Full Page Cache Hit                                  │
    (Page served in < 20ms statically)                           │
                 │                                               │
    DOM Ready + Configured Delay                                 │
    (0ms if returning known visitor;                             │
     1.5s delay if first-time visitor)                           │
                 │                                               │
    Asynchronous REST API Call                                   │
    (/wp-json/grr/v1/detect)                                     │
                 │                                               │
    Has visitor previously selected a region?                    │
    ├── YES (grr_user_manual_country set):                       │
    │   └── Instantly auto-redirect to regional URL               │
    │                                                            │
    └── NO (Undecided first-time visitor):                       │
        Does visitor country match current site?                 │
        ├── YES: Do nothing (visitor is in right place).         │
        └── NO:  Display sleek UI Card/Banner                    │
                 with Switch / Stay buttons                      ▼
                 │                                    PHP template_redirect hook
             Visitor Choice:                          detects country & sends
             - Switch → Cookie set & navigate         302 wp_safe_redirect
             - Stay   → Dismissal cookie set          (Bypasses page cache)
             - Auto-Hide (7s) → Assume stay, fade out
```

### Mode 1: Client-Side Geo-Prompt (Default & Recommended)
* **How it works:** Leaves backend `template_redirect` untouched. Every regional page (`/`, `/bd/`, `/in/`) is 100% cached as static HTML by LiteSpeed Cache. After page load, a lightweight Vanilla JS script queries `/wp-json/grr/v1/detect`.
* **Returning Visitors with Saved Choice:** If a visitor previously selected a country (`grr_user_manual_country`), the script executes with **0ms delay** and **automatically redirects** them directly to their preferred regional site (`window.location.replace()`) without showing any prompt.
* **Undecided Visitors:** If the visitor has not chosen yet, it waits for the display delay (default: 1.5s), checks if they should switch, and renders a floating card or banner. If no action is taken within the auto-hide timer (default: 7s), it smoothly slides down and fades out, remembering their choice to stay for the configured retention period (default: 7 days).
* **Why it's essential:** Completely eliminates the LiteSpeed Cache conflict where static caching on `/` would otherwise break PHP backend redirects. Also satisfies Google's SEO guideline: *"Avoid automatic redirects based on IP; use a banner or prompt instead."*

### Mode 2: Immediate 302 Redirect (Legacy Engine)
* **How it works:** Executes in PHP on the `template_redirect` hook (priority 1). Sends `Cache-Control: no-cache` headers and executes `wp_safe_redirect($target_url, 302)`.
* **When to use:** Environments where page caching is disabled on the root URL or handled at the web server/CDN edge via rewrite rules.

---

## 3. Codebase File Structure

```
wp-content/plugins/redirect/
├── geo-regional-router.php        # Main entry point, autoloader, activation & defaults (v1.0.6)
├── uninstall.php                  # Clean database cleanup script on plugin deletion
├── README.md                      # Public documentation & user guide
├── promt.md                       # This Master Developer & AI Engineering Prompt
│
├── includes/
│   ├── class-plugin.php           # Singleton orchestrator, hooks, REST API & frontend assets
│   ├── class-router.php           # Destination calculator, URL normalizer & redirect engine
│   ├── class-country-detector.php # Multi-source GeoIP detector (with WooCommerce auto-discovery) & MMDB reader
│   ├── class-settings.php         # Network Admin tabbed settings UI & options persistence
│   ├── class-diagnostics.php      # Live interactive routing simulator & log manager
│   └── class-logger.php           # Privacy-safe debug logger (automatic IP redaction)
│
└── assets/
    ├── grr-prompt.js              # Vanilla JS frontend controller (< 5KB, zero dependencies)
    ├── grr-prompt.css             # Glassmorphic responsive styles & exit keyframe animations
    ├── admin.js                   # Admin simulator AJAX runner & log cleaner
    ├── admin.css                  # Network admin settings, switcher styling & diagnostic styles
    └── GeoLite2/
        ├── GeoLite2-Country.mmdb  # Bundled MaxMind binary database fallback
        ├── COPYRIGHT.txt
        └── LICENSE.txt
```

---

## 4. Options Schema (`grr_options`)

Stored as a single network option (`get_site_option('grr_options')`):

| Key | Type | Default | Description |
| :--- | :--- | :--- | :--- |
| `enabled` | `int` (0/1) | `0` | Master switch for regional routing. |
| `routing_mode` | `string` | `'prompt'` | `'prompt'` (Client-Side Prompt) or `'immediate'` (Backend 302). |
| `redirect_status` | `int` | `302` | HTTP redirect code: `302`, `307`, `301`, or `308`. |
| `cookie_persistence` | `string` | `'7d'` | Retention period: `'disabled'`, `'session'`, `'24h'`, `'7d'`, or `'30d'`. |
| `prompt_style` | `string` | `'card'` | UI layout: `'card'` (floating card), `'banner'` (top bar), or `'modal'` (center dialog). |
| `prompt_delay` | `float` | `1.5` | Delay in seconds before prompt appears for undecided visitors. |
| `prompt_auto_hide` | `int` | `7` | Seconds before notification automatically fades out (assumes visitor wants to stay). |
| `auto_redirect_countdown` | `int` | `0` | Seconds for countdown timer (`0` = manual click only; >0 auto-redirects with Cancel button). |
| `enable_frontend_switcher` | `int` (0/1) | `1` | Enable Regional Store Switcher (Gutenberg Block, Widgets, & Shortcodes). |
| `site_global` | `int` | `1` | Blog ID of Global / Default site (`/`). |
| `site_bd` | `int` | `0` | Blog ID of Bangladesh site (`/bd/`). |
| `site_in` | `int` | `0` | Blog ID of India site (`/in/`). |
| `skip_logged_in_admins` | `int` (0/1) | `1` | Bypass routing for administrators. |
| `skip_logged_in_users` | `int` (0/1) | `0` | Bypass routing for all logged-in users. |
| `skip_bots` | `int` (0/1) | `1` | Bypass search crawlers (Googlebot, Bingbot, etc.). |
| `skip_rest` | `int` (0/1) | `1` | Bypass `/wp-json/` endpoints. |
| `skip_ajax` | `int` (0/1) | `1` | Bypass `admin-ajax.php`. |
| `skip_cron` | `int` (0/1) | `1` | Bypass `wp-cron.php`. |
| `skip_admin_urls` | `int` (0/1) | `1` | Bypass `/wp-admin/` pages. |
| `skip_xmlrpc` | `int` (0/1) | `1` | Bypass `xmlrpc.php`. |
| `skip_feeds` | `int` (0/1) | `0` | Bypass RSS/Atom feeds. |
| `skip_sitemaps` | `int` (0/1) | `1` | Bypass sitemap XML files. |
| `skip_previews` | `int` (0/1) | `1` | Bypass draft/post previews (`?preview=true`). |
| `country_source_cf` | `int` (0/1) | `1` | Check `HTTP_CF_IPCOUNTRY` header. |
| `country_source_header` | `int` (0/1) | `0` | Check custom proxy header. |
| `country_custom_header_name` | `string` | `'HTTP_X_GEOIP_COUNTRY'` | Header name for reverse proxies. |
| `trusted_proxies` | `string` | `''` | IP or CIDR list for trusted proxies. |
| `maxmind_db_path` | `string` | `''` | Custom path to external `.mmdb` file (auto-detects WooCommerce if empty). |
| `maxmind_license_key` | `string` | `''` | License key for weekly automated updates. |
| `debug_mode` | `int` (0/1) | `0` | Log decisions to `geo-regional-router-debug.log`. |
| `delete_data_on_uninstall` | `int` (0/1) | `0` | Delete network option upon uninstall. |

---

## 5. GeoIP Detection Priority Stack

The `Country_Detector::detect_country()` method checks sources in strict priority order:

1. **Admin Test Override**: `?grr_test_country=BD` (URL parameter) or `grr_admin_test_country` (Cookie) for Network Admins.
2. **Visitor Manual Selection**: `?grr_set_country=BD` (URL parameter) or `grr_user_manual_country` (Cookie).
3. **Saved Visitor Country Cookie**: `grr_visitor_country` (if cookie persistence is enabled).
4. **Cloudflare Header**: `HTTP_CF_IPCOUNTRY` (sanitized and validated against 2-letter ISO).
5. **Configured Custom Header**: E.g., `HTTP_X_GEOIP_COUNTRY` (only trusted if IP is within trusted proxies).
6. **MaxMind GeoLite2 Binary Database**:
   - *Check 1:* User-configured custom path (`$options['maxmind_db_path']`).
   - *Check 2:* **WooCommerce MaxMind Auto-Discovery** via `Country_Detector::get_woocommerce_database_path()` (WooCommerce 3.9+ integration service).
   - *Check 3:* WooCommerce uploads directory (`wp-content/uploads/woocommerce_uploads/*GeoLite2-Country.mmdb`).
   - *Check 4:* Bundled plugin fallback (`assets/GeoLite2/GeoLite2-Country.mmdb`).
7. **Fallback**: Returns `'UNKNOWN'` (prevents redirection loops).

---

## 6. REST API Endpoint Specification

* **Route:** `GET /wp-json/grr/v1/detect`
* **Access:** Public (`permission_callback => '__return_true'`)
* **Headers Sent:** `Cache-Control: no-store, no-cache, must-revalidate, max-age=0` (Never cached).
* **Parameters:**
  * `current_url` *(optional)*: Full URL of the frontend page requesting the evaluation.
* **Success Payload Structure (JSON):**
  ```json
  {
    "success": true,
    "country": "BD",
    "country_name": "Bangladesh",
    "flag": "🇧🇩",
    "should_switch": true,
    "current_url": "https://domain.com/",
    "target_url": "https://domain.com/bd/",
    "target_site_id": 2,
    "target_label": "Bangladesh Store",
    "source": "MaxMind GeoIP Database"
  }
  ```

---

## 7. Client-Side Lifecycle & Event Handling (`grr-prompt.js`)

```
Page Load
  │
  ├── 1. Exclusion Checks:
  │      - ?skipredirect or ?preview=true present? → EXIT.
  │
  ├── 2. Returning Visitor Check:
  │      - Is grr_user_manual_country set?
  │        ├── YES: delay = 0ms → Fetch route immediately.
  │        │        When target_url returned → window.location.replace(target_url).
  │        │
  │        └── NO (Undecided Visitor):
  │            - Has prompt been dismissed (grr_choice_dismissed=1)? → EXIT.
  │            - Already shown in this session (grr_session_shown=1)? → EXIT.
  │            - delay = 1500ms → Fetch route.
  │
  └── 3. Render Prompt:
         - Appends .grr-prompt-wrapper to document.body.
         - Marks sessionStorage.setItem('grr_session_shown', '1') and session cookie.
         - If countdown > 0: Progress bar animates; redirects upon expiry; Cancel dismisses prompt.
         - If countdown == 0: 7s auto-hide timer starts.
         - On expiry / Stay click / ✕ click / Esc:
           - Sets grr_choice_dismissed for configured TTL (e.g. 7 days).
           - Adds .grr-fade-out CSS class.
           - Removes DOM node after 420ms.
```

---

## 8. LiteSpeed Cache (LSCache) Best Practices

When deploying on LiteSpeed Web Server (e.g. Hostinger, cPanel):

1. **Recommended Presets:** Use **Advanced (Recommended)** or **Essentials**. Do **NOT** use **Extreme** or **Aggressive**.
2. **Guest Mode: OFF ❌**: In **LiteSpeed Cache > General**, turn **Guest Mode** and **Guest Optimization** **OFF**. Guest Mode creates an IP vary cache that interferes with dynamic geolocation routing.
3. **JS Optimization Exclude**: If **Load JS Deferred** or **JS Combine** is enabled under **Page Optimization > JS Settings**, add `grr-prompt` to:
   - **JS Excludes**
   - **JS Deferred / Delayed Excludes**
   This ensures `grr-prompt.js` executes immediately on DOM ready without waiting for user scroll/mouse interaction.