# 🌐 Geo Regional Router - Master Developer & AI Engineering Prompt

> **Purpose of this document:**  
> This file acts as the master technical specification and AI engineering prompt for maintaining, developing, extending, or refactoring the **Geo Regional Router (GRR)** WordPress Multisite plugin. Any AI assistant or developer working on this codebase should read and follow this document to ensure architectural consistency, caching compatibility, and code quality.

---

## 1. Project Overview & Context

* **Plugin Name:** Geo Regional Router
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
    DOM Ready + 1.5s Delay                                       │
                 │                                               │
    Asynchronous REST API Call                                   │
    (/wp-json/grr/v1/detect)                                     │
                 │                                               │
    Does visitor country match site?                             │
    ├── YES: Do nothing.                                         │
    └── NO:  Display sleek UI Card/Banner                        │
             with Switch / Stay buttons                          ▼
                 │                                    PHP template_redirect hook
             User Choice:                             detects country & sends
             - Switch → Cookie set & navigate         302 wp_safe_redirect
             - Stay   → Dismissal cookie set          (Bypasses page cache)
```

### Mode 1: Client-Side Geo-Prompt (Default & Recommended)
* **How it works:** Leaves backend `template_redirect` untouched. Every regional page (`/`, `/bd/`, `/in/`) is 100% cached as static HTML by LiteSpeed Cache. After page load, a lightweight Vanilla JS script queries `/wp-json/grr/v1/detect`. If the visitor is in Bangladesh but viewing the Global site, an elegant floating card or banner asks them to switch or auto-redirects after a countdown.
* **Why it's essential:** Completely eliminates the LiteSpeed Cache conflict where static caching on `/` would otherwise break PHP backend redirects. Also satisfies Google's SEO guideline: *"Avoid automatic redirects based on IP; use a banner or prompt instead."*

### Mode 2: Immediate 302 Redirect (Legacy Engine)
* **How it works:** Executes in PHP on the `template_redirect` hook (priority 1). Sends `Cache-Control: no-cache` headers and executes `wp_safe_redirect($target_url, 302)`.
* **When to use:** Environments where page caching is disabled on the root URL or handled at the web server/CDN edge via rewrite rules.

---

## 3. Codebase File Structure

```
wp-content/plugins/redirect/
├── geo-regional-router.php        # Main entry point, autoloader, activation & defaults
├── uninstall.php                  # Clean database cleanup script on plugin deletion
├── README.md                      # Public documentation & user guide
├── promt.md                       # This Master Developer & AI Engineering Prompt
│
├── includes/
│   ├── class-plugin.php           # Singleton orchestrator, hooks, REST API & frontend assets
│   ├── class-router.php           # Destination calculator, URL normalizer & redirect engine
│   ├── class-country-detector.php # Multi-source GeoIP detector & pure-PHP MMDB reader
│   ├── class-settings.php         # Network Admin tabbed settings UI & options persistence
│   ├── class-diagnostics.php      # Live interactive routing simulator & log manager
│   └── class-logger.php           # Privacy-safe debug logger (automatic IP redaction)
│
└── assets/
    ├── grr-prompt.js              # Vanilla JS frontend controller (< 4KB, zero dependencies)
    ├── grr-prompt.css             # Glassmorphic responsive styles for Prompt Card, Banner & Modal
    ├── admin.js                   # Admin simulator AJAX runner & log cleaner
    ├── admin.css                  # Network admin settings & diagnostic styles
    └── GeoLite2/
        ├── GeoLite2-Country.mmdb  # Bundled MaxMind binary database
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
| `prompt_style` | `string` | `'card'` | UI layout: `'card'` (floating), `'banner'` (top bar), or `'modal'`. |
| `prompt_delay` | `float` | `1.5` | Delay in seconds before prompt appears. |
| `prompt_auto_hide` | `int` | `0` | Seconds to auto-dismiss prompt without redirect (`0` = disabled). |
| `auto_redirect_countdown` | `int` | `0` | Seconds for countdown timer (`0` = manual click only). |
| `enable_footer_switcher` | `int` (0/1) | `0` | Automatically render country switcher in website footer (`wp_footer`). |
| `footer_switcher_style` | `string` | `'inline'` | `'inline'` (flags & text), `'buttons'`, or `'compact'` (select). |
| `footer_switcher_position` | `string` | `'center'` | Alignment: `'center'`, `'left'`, or `'right'`. |
| `site_global` | `int` | `1` | Blog ID of Global / Default site (`/`). |
| `site_bd` | `int` | `0` | Blog ID of Bangladesh site (`/bd/`). |
| `site_in` | `int` | `0` | Blog ID of India site (`/in/`). |
| `cookie_persistence` | `string` | `'disabled'` | `'disabled'`, `'session'`, `'24h'`, or `'7d'`. |
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
| `maxmind_db_path` | `string` | `''` | Custom path to external `.mmdb` file. |
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
6. **MaxMind GeoLite2 Binary Database**: High-speed pure-PHP binary reader located in `assets/GeoLite2/GeoLite2-Country.mmdb`.
7. **Fallback**: Returns `'UNKNOWN'` (prevents redirection loops).

---

## 6. REST API Endpoint Specification

* **Route:** `GET /wp-json/grr/v1/detect`
* **Access:** Public (`permission_callback => '__return_true'`)
* **Headers Sent:** `Cache-Control: no-store, no-cache, must-revalidate, max-age=0` (Never cached).
* **Parameters:**
  * `current_url` *(optional)*: Full URL of the frontend page requesting the evaluation.
* **Sample JSON Response:**
  ```json
  {
    "success": true,
    "country": "BD",
    "country_name": "Bangladesh",
    "flag": "🇧🇩",
    "should_switch": true,
    "current_url": "https://domain.com/about/",
    "target_url": "https://domain.com/bd/about/",
    "target_site_id": 2,
    "target_label": "Bangladesh Store",
    "source": "MaxMind GeoIP Database"
  }
  ```

---

## 7. Developer Hooks & Filters

Extend or modify plugin behavior programmatically without editing core files:

```php
// 1. Filter detected country code
add_filter( 'geo_regional_router_country', function( string $country, string $current_url ): string {
    if ( strpos( $current_url, '/partner-bd/' ) !== false ) {
        return 'BD';
    }
    return $country;
}, 10, 2 );

// 2. Prevent redirection on specific pages
add_filter( 'geo_regional_router_should_redirect', function( bool $should, string $current, string $target, string $country ): bool {
    if ( is_page( 'special-landing' ) ) {
        return false;
    }
    return $should;
}, 10, 4 );

// 3. Customize target redirect URL
add_filter( 'geo_regional_router_redirect_url', function( string $target_url, string $current_url, string $country ): string {
    return add_query_arg( 'ref', 'geo', $target_url );
}, 10, 3 );
```

---

## 8. Coding & Engineering Rules for Future Development

When extending or modifying this plugin, adhere strictly to these rules:

1. **Never break Full Page Cache (LSCache)**:
   * Do not add blocking logic into PHP `init` or `template_redirect` when `routing_mode === 'prompt'`.
   * Keep the frontend check strictly asynchronous via the `/wp-json/grr/v1/detect` REST endpoint.
2. **Zero Dependencies on Frontend**:
   * Keep `grr-prompt.js` in pure **Vanilla JavaScript** (no jQuery, no external frameworks, < 5KB).
   * Ensure mobile responsiveness and accessible keyboard controls (`Escape` key closes prompt).
3. **URL Normalization & Double-Prefix Guard**:
   * Always route paths through `Router::extract_clean_path()` to guarantee that paths like `/bd/about/` never turn into `/bd/bd/about/`.
   * Preserve all query strings (`utm_*`, `gclid`, `fbclid`, filters).
4. **Privacy & Security**:
   * Never write raw visitor IP addresses to `geo-regional-router-debug.log`. Always sanitize with `Logger::sanitize_log_message()`.
   * Validate all redirect hosts against configured Multisite blog domains to prevent open redirect vulnerabilities.
5. **Multi-Region Scalability**:
   * To add support for additional regions (e.g., US, UK, EU, UAE), register the new site in the settings options array (`grr_options`) and extend the mapping switch inside `Router::calculate_destination()`.