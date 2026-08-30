You are an expert WordPress plugin developer with deep knowledge of WordPress Multisite, PHP, networking, security, caching, HTTP redirects, and GeoIP.

Build a COMPLETE, production-ready, ready-to-install WordPress Multisite plugin that implements country-based URL routing for a 3-site WordPress Multisite installation.

==================================================
## PROJECT REQUIREMENTS
==================================================

I have one WordPress Multisite installation with these sites:

1. Global:
   https://domain.com/

2. Bangladesh:
   https://domain.com/bd/

3. India:
   https://domain.com/in/

The actual domain must NOT be hardcoded throughout the code. Make the site URLs configurable from the plugin settings.

The plugin must work as a NETWORK-ACTIVATED Multisite plugin.

The primary purpose is geographic routing based on visitor country.

==================================================
##  DESIRED BEHAVIOR
==================================================

The visitor should always be routed to the site corresponding to their country.

Country mapping:

BD → Bangladesh site → /bd/
IN → India site → /in/
ALL OTHER COUNTRIES → Global site → /

Examples:

Bangladesh visitor:
https://domain.com/
→ https://domain.com/bd/

Bangladesh visitor:
https://domain.com/about/
→ https://domain.com/bd/about/

Bangladesh visitor:
https://domain.com/products/example/
→ https://domain.com/bd/products/example/

India visitor:
https://domain.com/
→ https://domain.com/in/

India visitor:
https://domain.com/about/
→ https://domain.com/in/about/

India visitor:
https://domain.com/products/example/
→ https://domain.com/in/products/example/

US visitor:
https://domain.com/
→ https://domain.com/

US visitor:
https://domain.com/about/
→ https://domain.com/about/

==================================================
CROSS-REGIONAL ACCESS
==================================================

The routing MUST also work when somebody directly enters the wrong regional URL.

Examples:

Indian visitor requests:

https://domain.com/bd/about/

Result:

https://domain.com/in/about/

Bangladesh visitor requests:

https://domain.com/in/about/

Result:

https://domain.com/bd/about/

US visitor requests:

https://domain.com/bd/about/

Result:

https://domain.com/about/

US visitor requests:

https://domain.com/in/about/

Result:

https://domain.com/about/

This means the plugin must determine the visitor's country FIRST and then determine the correct site.

Do NOT depend on "redirect if not country" functionality from another plugin.

==================================================
PATH PRESERVATION
==================================================

The requested path must be preserved when moving between sites.

Examples:

/about/
/contact/
/services/
/products/abc/
/blog/my-post/
/category/news/
/some/deep/path/

must become:

/bd/about/
/bd/contact/
/bd/services/
/bd/products/abc/
/bd/blog/my-post/
/bd/category/news/
/bd/some/deep/path/

for Bangladesh.

Likewise:

/about/
/products/abc/

must become:

/in/about/
/in/products/abc/

for India.

For Global, remove the /bd/ or /in/ prefix when routing back to Global.

Examples:

/bd/about/ → /about/
/in/products/abc/ → /products/abc/

Preserve trailing slash behavior where possible.

==================================================
QUERY PARAMETERS
==================================================

Always preserve query parameters.

Example:

https://domain.com/about/?utm_source=google&utm_campaign=test

Bangladesh:

https://domain.com/bd/about/?utm_source=google&utm_campaign=test

India:

https://domain.com/in/about/?utm_source=google&utm_campaign=test

Do not lose:

utm_source
utm_medium
utm_campaign
utm_term
utm_content
fbclid
gclid
custom query parameters
etc.

==================================================
BYPASS PARAMETER
==================================================

Implement:

?skipredirect

If the URL contains:

https://domain.com/about/?skipredirect

then the plugin must NOT perform geographic redirection for that request.

It should allow the visitor to view the URL they explicitly requested.

The bypass should work on all three sites.

The plugin must not remove the skipredirect parameter unless this is explicitly configurable.

Also support:

?skipredirect=1

?skipredirect=true

==================================================
COOKIE / SESSION BEHAVIOR
==================================================

Do NOT permanently lock users to a country.

The visitor's country should be evaluated on each request unless a configurable optimization is enabled.

Provide an optional setting:

"Remember routing decision"

Options:

- Disabled
- Session
- 24 hours
- 7 days

Default:

Disabled

If enabled, use a secure, appropriately named cookie.

However, the ?skipredirect parameter must always override the cookie.

==================================================
COUNTRY DETECTION
==================================================

The plugin must have a reliable country detection abstraction.

Implement support for:

1. Cloudflare:
   CF-IPCountry

2. A configurable HTTP header:
   Admin can specify a country header name.

3. MaxMind GeoIP2 / GeoLite2:
   Provide an optional local database configuration.

Do NOT blindly trust arbitrary visitor-controlled headers.

For security:

- Only trust configurable proxy/CDN headers when the request comes through a trusted proxy/CDN.
- Provide a setting for trusted proxy IP ranges or trusted proxy mode.
- Document the security implications.

The country detector should return ISO 3166-1 alpha-2 country codes.

Examples:

BD
IN
US
GB
CA

If country detection fails:

Default to GLOBAL.

Do NOT redirect when the country cannot be determined.

==================================================
ADMIN SETTINGS
==================================================

Create a Network Admin settings page:

Network Admin
→ Settings
→ Geo Regional Router

Use the WordPress Settings API where appropriate.

Settings must include:

GENERAL
-------

Enable routing:
[ON/OFF]

Redirect status:
- 302 Temporary
- 307 Temporary
- 301 Permanent
- 308 Permanent

Default:
302

Country/site mapping:

Bangladesh:
Country code: BD
Site ID: [configurable]

India:
Country code: IN
Site ID: [configurable]

Global/default site:
Site ID: [configurable]

Automatically display available Multisite sites in dropdowns.

Do not require administrators to manually type Blog IDs if they can be selected from existing sites.

==================================================
SITE CONFIGURATION
==================================================

Display the current Multisite sites:

Site ID | Site URL | Site Name | Country

Allow the administrator to select:

Global site
Bangladesh site
India site

Validate that the selected sites actually exist.

Prevent selecting the same site for multiple roles.

==================================================
REDIRECT SETTINGS
==================================================

Options:

[ ] Disable redirects for logged-in administrators
Default: ON

[ ] Disable redirects for all logged-in users
Default: OFF

[ ] Skip bots/crawlers
Default: ON

[ ] Skip REST API
Default: ON

[ ] Skip AJAX
Default: ON

[ ] Skip WP Cron
Default: ON

[ ] Skip admin URLs
Default: ON

[ ] Skip XML-RPC
Default: ON

[ ] Skip feed requests
Default: OFF

[ ] Skip sitemap requests
Default: ON

[ ] Skip WordPress preview requests
Default: ON

==================================================
BOT DETECTION
==================================================

Implement reasonable bot/crawler detection.

At minimum recognize common crawlers such as:

Googlebot
bingbot
Baiduspider
YandexBot
DuckDuckBot
Slurp
facebookexternalhit
Twitterbot
LinkedInBot

Do not rely solely on user-agent for security-sensitive decisions.

Make bot skipping configurable.

==================================================
REDIRECT LOOP PREVENTION
==================================================

This is extremely important.

The plugin MUST NEVER create redirect loops.

Examples:

BD visitor:

/bd/about/
→ must NOT redirect to /bd/about/

IN visitor:

/in/about/
→ must NOT redirect to /in/about/

Global visitor:

/about/
→ must NOT redirect to /about/

Normalize URLs before comparing.

Avoid unnecessary redirects when the destination is already the correct URL.

==================================================
REGIONAL PREFIX HANDLING
==================================================

The plugin must understand:

/
/bd/
/in/

These prefixes represent the three configured sites.

When the visitor is on the wrong site:

BD visitor:
/in/about/
→ /bd/about/

IN visitor:
/bd/about/
→ /in/about/

Other visitor:
/bd/about/
→ /about/

Other visitor:
/in/about/
→ /about/

Do NOT accidentally create:

/bd/bd/about/
/in/in/about/

==================================================
IMPORTANT PATH EDGE CASE
==================================================

If the Global site receives:

/bd/about/

and the visitor is from Bangladesh, this URL is already the Bangladesh site's URL.

Do NOT produce:

/bd/bd/about/

Similarly:

/in/about/

must never become:

/in/in/about/

==================================================
SITE AVAILABILITY
==================================================

This plugin does NOT need to perform cross-site content matching.

IMPORTANT:

Do NOT check whether /bd/about/ exists before redirecting.

Do NOT query the Bangladesh site's database to determine whether the requested page exists.

Do NOT use get_page_by_path() for this purpose.

The routing is purely URL-based.

If the Global site has /about/ but the Bangladesh site does not, Bangladesh visitors should still be routed to:

/bd/about/

The Bangladesh site itself can return its normal WordPress 404 if that URL doesn't exist.

Respect caching mechanisms and avoid unnecessary redirects when the destination is already the correct URL.

==================================================
WORDPRESS MULTISITE
==================================================

The plugin must be specifically designed for WordPress Multisite.

It should work with subdirectory Multisite.

Do NOT assume that only one site exists.

Use WordPress Multisite APIs correctly.

Do not unnecessarily call switch_to_blog() for every request.

The routing should be based on the current site's URL/site ID.

==================================================
WHERE THE CODE RUNS
==================================================

The plugin should preferably run early enough to redirect before WordPress performs unnecessary page/database processing, but it must not break:

- WordPress admin
- REST API
- AJAX
- cron
- login
- previews
- feeds
- sitemaps

Use appropriate WordPress hooks.

Explain why the selected hook is appropriate.

Avoid redirecting after output has already started.

Check headers_sent() before redirecting.

==================================================
HTTPS / URL GENERATION
==================================================

Never manually concatenate the domain in a way that can produce malformed URLs.

Use WordPress functions such as:

home_url()
get_home_url()
get_site_url()
get_current_blog_id()
get_current_network_id()
get_blog_details()

Build destination URLs safely.

Support HTTPS.

Never downgrade HTTPS to HTTP.

==================================================
PATH SECURITY
==================================================

Do not trust arbitrary Host headers.

Use the configured WordPress site URLs.

Prevent open redirects.

Never allow a query parameter to specify an arbitrary redirect destination.

The only valid redirect destinations must be the configured Global, Bangladesh, or India site URLs.

==================================================
REDIRECT METHOD
==================================================

Use WordPress's safe redirect functionality where appropriate.

Default:

302

Provide a filter/action to customize behavior.

For example:

geo_regional_router_redirect_url
geo_regional_router_country
geo_regional_router_should_redirect

Use sensible namespacing/prefixing to avoid conflicts.

==================================================
CACHE / CDN COMPATIBILITY
==================================================

This is extremely important.

The documentation must explain that geographic redirects can be affected by:

- page caching
- full-page cache
- Cloudflare cache
- reverse proxies
- LiteSpeed Cache
- Nginx FastCGI cache
- Varnish

A cached redirect must not be accidentally served to visitors from another country.

Provide documentation explaining recommended cache configuration.

If Cloudflare is used, explain how CF-IPCountry should be handled.

The plugin should not attempt to cache country decisions unless the optional cookie/session feature is enabled.

==================================================
LOGGING / DEBUG MODE
==================================================

Provide:

Debug mode:
OFF by default.

When enabled, log:

timestamp
country
current site ID
current URL
detected site
target site
redirect decision
reason for skipping

Do NOT log:

IP addresses
full personal data
authentication credentials
cookies
query parameters containing sensitive values

Use:

error_log()

or a safe WordPress-compatible logging abstraction.

Provide a "Clear Debug Log" button if a plugin-managed log file is used.

==================================================
ADMIN TEST / DIAGNOSTIC TOOL
==================================================

Create a diagnostics section showing:

Current site ID
Current site URL
Configured Global site
Configured Bangladesh site
Configured India site
Detected country
Country source
Current request path
Calculated destination
Whether redirect would occur
Reason if no redirect

Provide a test input:

Test country code:
[ BD ]
[ IN ]
[ US ]

and test path:

[ /about/ ]

Then show:

Current:
https://domain.com/about/

Would redirect to:
https://domain.com/bd/about/

Do not actually redirect when using the diagnostic tool.

==================================================
REST / AJAX SAFETY
==================================================

Do not redirect REST API requests.

Do not redirect AJAX requests.

Do not redirect WP Cron.

Do not redirect wp-login.php unless explicitly configured.

Do not break WordPress authentication.

==================================================
SEO
==================================================

Provide an admin warning that 301/308 redirects should not be enabled until the configuration is thoroughly tested.

Default to 302.

Explain SEO considerations for geographic redirects.

Do not add hreflang automatically unless explicitly requested.

Do not modify canonical URLs automatically.

==================================================
ADMIN UI
==================================================

The settings UI should be clean and native WordPress style.

Use:

settings sections
settings fields
WordPress notices
sanitization
nonces
capability checks

Only Network Administrators / super admins should be able to change settings.

Use:

manage_network_options

or an appropriate Multisite capability.

==================================================
PLUGIN ARCHITECTURE
==================================================

Do NOT create one giant PHP file if a clean modular architecture is more appropriate.

However, the final answer must provide a complete installable plugin.

A suitable structure would be:

geo-regional-router/
├── geo-regional-router.php
├── includes/
│   ├── class-plugin.php
│   ├── class-router.php
│   ├── class-country-detector.php
│   ├── class-settings.php
│   ├── class-diagnostics.php
│   └── class-logger.php
├── assets/
│   ├── admin.css
│   └── admin.js
├── readme.txt
└── uninstall.php

Use a unique PHP namespace or strong prefix such as:

GRR\

or:

geo_regional_router_

Avoid generic function/class names.

==================================================
UNINSTALL
==================================================

On uninstall:

Do NOT delete WordPress sites.

Do NOT delete posts/pages.

Do NOT modify Multisite configuration.

Only remove plugin-specific options if the administrator explicitly chooses:

"Delete plugin data on uninstall"

Default:
OFF

==================================================
ACTIVATION
==================================================

On activation:

Check that:

- WordPress Multisite is enabled.
- Required PHP version 8.3+ is available.
- Selected sites exist.

Do not break activation if settings have not yet been configured.

Provide an admin notice explaining that configuration is required.

==================================================
ERROR HANDLING
==================================================

If:

- GeoIP cannot determine country
- configured site doesn't exist
- current site is invalid
- destination URL cannot be generated
- headers have already been sent
- plugin configuration is incomplete

then:

DO NOT REDIRECT.

Allow the current page to load normally.

Never produce a fatal error.

==================================================
PERFORMANCE
==================================================

The plugin should be lightweight.

Avoid expensive database queries on every request.

Cache site configuration using WordPress options/object cache where appropriate.

Do not call switch_to_blog().

Do not query page/post existence.

Do not make external HTTP requests for every visitor.

Country detection should be fast.

==================================================
COUNTRY DETECTION PRIORITY
==================================================

Implement a clear priority:

1. Trusted Cloudflare CF-IPCountry
2. Configured trusted country header
3. MaxMind local database if configured
4. Otherwise unknown

If multiple sources are configured, document which source wins.

==================================================
MANUAL COUNTRY OVERRIDE FOR TESTING
==================================================

Provide a development-only test mechanism.

For example:

?grr_test_country=BD

BUT:

- Only allow it for logged-in Network Administrators.
- Never honor it for normal visitors.
- Never allow it in production for unauthenticated users.
- Clearly show when test mode is active.

Also retain:

?skipredirect

for legitimate bypass testing.

==================================================
CRITICAL REQUIREMENT: NO DEPENDENCY
==================================================

The plugin must NOT require:

Advanced GeoIP Redirect
GeoTargetingWP
WPML
Polylang
GeoIP Redirect plugins
or any other WordPress plugin.

It should be standalone.

For GeoIP:

Prefer Cloudflare CF-IPCountry when available.

Provide optional MaxMind support if practical.

If MaxMind requires a Composer dependency, provide exact installation instructions and/or vendor packaging requirements.

==================================================
FINAL DELIVERABLE
==================================================

I want a COMPLETE ready-to-install plugin.

Provide:

1. Full directory structure.

2. FULL CONTENTS of EVERY file.

3. No pseudocode.

4. No placeholders such as:
   "implement this here"
   "add your code"
   "etc."

5. Every PHP file must be syntactically complete.

6. Include plugin header.

7. Include readme.txt.

8. Include installation instructions.

9. Include configuration instructions.

10. Include testing instructions.

11. Include troubleshooting instructions.

12. Include security considerations.

13. Include cache/CDN configuration guidance.

14. Include rollback instructions.

==================================================
TEST CASES
==================================================

Before presenting the final code, mentally test all of these:

A.

BD visitor:
Global /
→ /bd/

B.

BD visitor:
Global /about/
→ /bd/about/

C.

IN visitor:
Global /
→ /in/

D.

IN visitor:
Global /about/
→ /in/about/

E.

US visitor:
Global /
→ /

F.

US visitor:
Global /about/
→ /about/

G.

IN visitor:
BD /about/
→ /in/about/

H.

BD visitor:
IN /about/
→ /bd/about/

I.

US visitor:
BD /about/
→ /about/

J.

US visitor:
IN /about/
→ /about/

K.

BD visitor:
BD /about/
→ no redirect

L.

IN visitor:
IN /about/
→ no redirect

M.

US visitor:
Global /about/?utm_source=test
→ /about/?utm_source=test

N.

BD visitor:
Global /about/?utm_source=test
→ /bd/about/?utm_source=test

O.

IN visitor:
BD /about/?skipredirect
→ no redirect

P.

BD visitor:
IN /about/?skipredirect
→ no redirect

Q.

Admin visitor:
Any URL
→ no redirect by default

R.

Bot:
Any URL
→ no redirect by default

S.

Unknown country:
Any URL
→ no redirect

T.

BD visitor:
Global /bd/about/
→ /bd/about/ or no redirect, but NEVER /bd/bd/about/

U.

IN visitor:
Global /in/about/
→ /in/about/ or no redirect, but NEVER /in/in/about/

V.

Repeated requests:
Must not create redirect loops.

==================================================
IMPORTANT DESIGN DECISION
==================================================

The plugin is NOT doing content matching.

It must NOT ask:

"Does /bd/about/ exist?"

It only determines:

"What regional site should this visitor be on?"

Then it preserves the requested path.

WordPress on that regional site is responsible for returning the appropriate page or 404.

==================================================
OUTPUT FORMAT
==================================================

First provide a short architecture explanation.

Then provide the complete file tree.

Then provide every file in separate code blocks with the exact file path as the heading.

Then provide installation instructions.

Then provide configuration instructions specifically for:

domain.com
domain.com/bd/
domain.com/in/

Then provide a testing checklist using the test cases above.

Then provide production deployment recommendations.

Do not omit any required file or code.
Do not give pseudocode.
Do not simplify the implementation.
Produce code that can actually be zipped and installed as a WordPress Multisite plugin.