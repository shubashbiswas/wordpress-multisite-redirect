=== Geo Regional Router ===
Contributors: Antigravity
Tags: multisite, geoip, redirect, country-routing, location
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Production-ready WordPress Multisite country-based URL routing engine for multi-regional WordPress installations.

== Description ==

Geo Regional Router is a lightweight, standalone, network-activated WordPress Multisite plugin designed for 3-site (or multi-site) regional installations (e.g., Global `/`, Bangladesh `/bd/`, and India `/in/`).

Key Features:
* Dynamic Country Routing: Automatically routes visitors to their country's designated site (`BD` -> `/bd/`, `IN` -> `/in/`, Others -> Global `/`).
* Cross-Regional Correction: When visitors directly access an incorrect regional URL (e.g. an Indian visitor requesting `/bd/about/`), the plugin seamlessly routes them to `/in/about/`.
* Path & Query Preservation: Preserves relative page/post paths (`/about/`, `/products/item-123/`) and query parameters (`utm_source`, `fbclid`, `gclid`, etc.).
* Bypass Parameters: Supports `?skipredirect`, `?skipredirect=1`, and `?skipredirect=true` on all sites.
* Loop & Double-Prefix Prevention: Normalizes URLs to guarantee zero redirect loops or double prefixes (e.g. never produces `/bd/bd/`).
* Multi-Source GeoIP Detection: Prioritizes Cloudflare `CF-IPCountry`, custom HTTP headers with trusted proxy validation, and local MaxMind `.mmdb` database lookups.
* Developer & Diagnostic Tools: Includes a Network Admin diagnostic suite, URL routing simulator, admin test parameter (`?grr_test_country=BD`), and action/filter hooks (`geo_regional_router_should_redirect`, `geo_regional_router_country`, `geo_regional_router_redirect_url`).

== Installation ==

1. Upload the `geo-regional-router` directory (or `multisite-redirect`) to your WordPress installation's `/wp-content/plugins/` directory.
2. Network Activate the plugin in **My Sites > Network Admin > Plugins**.
3. Navigate to **Network Admin > Settings > Geo Regional Router**.
4. In the **General & Site Mapping** tab:
   - Select your **Global / Default Site**.
   - Select your **Bangladesh Site** (e.g. `/bd/`).
   - Select your **India Site** (e.g. `/in/`).
   - Choose your desired Redirect Status Code (Default: `302 Found`).
   - Check **Enable Routing** and click **Save Network Settings**.

== Configuration Instructions ==

For a typical 3-site Multisite setup (`domain.com`, `domain.com/bd/`, `domain.com/in/`):

1. **Multisite Setup Verification**:
   - Ensure WordPress Multisite is installed in Subdirectory mode (`SUBDOMAIN_INSTALL = false` in `wp-config.php`).
   - Main Site: `https://domain.com/` (Blog ID 1)
   - Subsite 1: `https://domain.com/bd/` (Blog ID 2)
   - Subsite 2: `https://domain.com/in/` (Blog ID 3)

2. **Country Detection Setup**:
   - **Cloudflare**: If your site uses Cloudflare, enable "Use Cloudflare CF-IPCountry header" in the **Country Detection** tab. Cloudflare will send the visitor's 2-letter ISO country code automatically in `HTTP_CF_IPCOUNTRY`.
   - **Custom Proxy Header**: If using an Nginx/Varnish reverse proxy or custom CDN module, specify your header name (e.g., `HTTP_X_GEOIP_COUNTRY`).
   - **Trusted Proxies**: Add your trusted CDN or proxy IP ranges (e.g., `10.0.0.0/8`, `172.16.0.0/12`) to prevent IP/header spoofing.

== Cache & CDN Compatibility Guidance ==

Page caching (Cloudflare, LiteSpeed, Nginx FastCGI, Varnish, Redis Page Cache) can interfere with HTTP-level geographic redirects if cache keys do not account for visitor country.

Recommended Cache Configurations:
1. **Cloudflare Cache**:
   - Enable Cloudflare IP Geolocation in Cloudflare Dashboard > Transform Rules / Scrape Shield.
   - If caching full pages at the edge, configure a Cloudflare Custom Cache Key based on `CF-IPCountry` or bypass edge caching for root dynamic URLs.
2. **Nginx FastCGI / Varnish Cache**:
   - Include the detected country header (e.g. `$http_cf_ipcountry`) in your cache hash key so that a cached redirect for `BD` is never served to a `US` visitor.
3. **LiteSpeed Cache**:
   - Use LiteSpeed GeoLite module or configure LiteSpeed Vary Headers: `ls_vary: cookie=grr_visitor_country`.

== Security Considerations ==

- **Proxy Header Protection**: Header values (`CF-IPCountry`, `X-GeoIP-Country`) are only evaluated if the request originates from a configured trusted proxy IP or when trusted proxies are left unconfigured in single-server environments.
- **Open Redirect Prevention**: Redirect destinations are strictly restricted to the home URLs of configured WordPress Multisite blogs. External domains or arbitrary query string parameters cannot trigger off-site redirects.
- **Host Header Sanitization**: URL generation uses standard WordPress Multisite API methods (`get_site_url()`, `get_site()`) and never trusts untrusted HTTP Host headers.

== SEO Considerations ==

- **302 vs 301/308 Redirects**: Default to `302 Found` during staging and initial launch. Permanent redirects (`301`/`308`) are cached aggressively by search engines and browsers; only switch to 301/308 after thorough verification.
- **Hreflang & Canonicals**: Geo Regional Router strictly handles URL routing and does not alter post canonical tags or auto-inject `hreflang` headers unless custom filter hooks are added.

== Testing Checklist ==

Use the following test matrix in the **Diagnostics Tool** tab or via browser testing:

[A] BD visitor -> `https://domain.com/` -> Redirects to `https://domain.com/bd/`
[B] BD visitor -> `https://domain.com/about/` -> Redirects to `https://domain.com/bd/about/`
[C] IN visitor -> `https://domain.com/` -> Redirects to `https://domain.com/in/`
[D] IN visitor -> `https://domain.com/about/` -> Redirects to `https://domain.com/in/about/`
[E] US visitor -> `https://domain.com/` -> No redirect (Remains on Global `/`)
[F] US visitor -> `https://domain.com/about/` -> No redirect (Remains on Global `/about/`)
[G] IN visitor requesting `https://domain.com/bd/about/` -> Redirects to `https://domain.com/in/about/`
[H] BD visitor requesting `https://domain.com/in/about/` -> Redirects to `https://domain.com/bd/about/`
[I] US visitor requesting `https://domain.com/bd/about/` -> Redirects to `https://domain.com/about/`
[J] US visitor requesting `https://domain.com/in/about/` -> Redirects to `https://domain.com/about/`
[K] BD visitor requesting `https://domain.com/bd/about/` -> No redirect (Loop prevention)
[L] IN visitor requesting `https://domain.com/in/about/` -> No redirect (Loop prevention)
[M] US visitor -> `/about/?utm_source=test` -> `/about/?utm_source=test` (Query preserved)
[N] BD visitor -> `/about/?utm_source=test` -> `/bd/about/?utm_source=test` (Query preserved)
[O] IN visitor -> `/bd/about/?skipredirect` -> No redirect (Bypass active)
[P] BD visitor -> `/in/about/?skipredirect` -> No redirect (Bypass active)
[Q] Admin visitor -> Any URL -> No redirect (by default when logged in)
[R] Bot/Crawler (Googlebot) -> Any URL -> No redirect
[S] Unknown country -> Any URL -> No redirect
[T] BD visitor -> `/bd/about/` -> Never produces `/bd/bd/about/`
[U] IN visitor -> `/in/about/` -> Never produces `/in/in/about/`
[V] Repeated requests -> Zero redirect loops.

== Troubleshooting & Rollback ==

If you experience unexpected behavior:
1. Append `?skipredirect` to your URL to bypass routing immediately.
2. Go to **Network Admin > Settings > Geo Regional Router**.
3. Uncheck **Enable Routing** and click **Save Network Settings**.
4. To roll back or deactivate, navigate to **Network Admin > Plugins** and click **Network Deactivate**.

== Changelog ==

= 1.0.0 =
* Initial release of Geo Regional Router.
