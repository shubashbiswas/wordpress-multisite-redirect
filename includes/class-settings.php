<?php
namespace GRR;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Settings
 * Network Admin Settings interface and options management.
 */
class Settings {

	/**
	 * Initialize settings hooks.
	 */
	public function init(): void {
		add_action( 'network_admin_menu', array( $this, 'add_network_menu' ) );
		add_action( 'network_admin_edit_grr_save_settings', array( $this, 'save_network_settings' ) );
	}

	/**
	 * Register Network Admin menu entry.
	 */
	public function add_network_menu(): void {
		add_submenu_page(
			'settings.php',
			esc_html__( 'Geo Regional Router Settings', 'geo-regional-router' ),
			esc_html__( 'Geo Regional Router', 'geo-regional-router' ),
			'manage_network_options',
			'geo-regional-router',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Render Network Settings Admin Page.
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'geo-regional-router' ) );
		}

		$options = get_site_option( 'grr_options', array() );
		$sites   = get_sites( array( 'number' => 500 ) );

		// Active tab handling
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';

		// Display notices
		if ( isset( $_GET['updated'] ) && '1' === $_GET['updated'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved successfully.', 'geo-regional-router' ) . '</p></div>';
		}

		if ( isset( $_GET['error'] ) && 'duplicate_sites' === $_GET['error'] ) {
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Error: Cannot assign the same site to multiple regional roles.', 'geo-regional-router' ) . '</p></div>';
		}
		?>
		<div class="wrap grr-settings-wrap">
			<h1><?php esc_html_e( 'Geo Regional Router Settings', 'geo-regional-router' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Configure country-based automatic URL routing, edge caching, SEO hreflang, and regional switchers across your WordPress Multisite network.', 'geo-regional-router' ); ?>
			</p>

			<h2 class="nav-tab-wrapper">
				<a href="?page=geo-regional-router&tab=general" class="nav-tab <?php echo 'general' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'General & Site Mapping', 'geo-regional-router' ); ?>
				</a>
				<a href="?page=geo-regional-router&tab=exclusions" class="nav-tab <?php echo 'exclusions' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Exclusions & Bypasses', 'geo-regional-router' ); ?>
				</a>
				<a href="?page=geo-regional-router&tab=detection" class="nav-tab <?php echo 'detection' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Country Detection', 'geo-regional-router' ); ?>
				</a>
				<a href="?page=geo-regional-router&tab=features" class="nav-tab <?php echo 'features' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'SEO & Edge Cache & UI', 'geo-regional-router' ); ?>
				</a>
				<a href="?page=geo-regional-router&tab=diagnostics" class="nav-tab <?php echo 'diagnostics' === $active_tab ? 'nav-tab-active' : ''; ?>">
					<?php esc_html_e( 'Diagnostics Tool', 'geo-regional-router' ); ?>
				</a>
			</h2>

			<?php if ( 'diagnostics' === $active_tab ) : ?>
				<?php Plugin::get_instance()->get_diagnostics()->render_diagnostics_panel(); ?>
			<?php else : ?>
				<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=grr_save_settings' ) ); ?>">
					<?php wp_nonce_field( 'grr_save_settings_nonce', 'grr_nonce' ); ?>
					<input type="hidden" name="tab" value="<?php echo esc_attr( $active_tab ); ?>" />

					<?php if ( 'general' === $active_tab ) : ?>
						<div class="grr-card">
							<h3><?php esc_html_e( 'General Router Configuration', 'geo-regional-router' ); ?></h3>
							<table class="form-table">
								<tr>
									<th scope="row"><?php esc_html_e( 'Enable Routing', 'geo-regional-router' ); ?></th>
									<td>
										<label>
											<input type="checkbox" name="enabled" value="1" <?php checked( 1, $options['enabled'] ?? 0 ); ?> />
											<?php esc_html_e( 'Enable geographic URL routing for visitors', 'geo-regional-router' ); ?>
										</label>
									</td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Routing Architecture Mode', 'geo-regional-router' ); ?></th>
									<td>
										<select name="routing_mode">
											<option value="prompt" <?php selected( 'prompt', $options['routing_mode'] ?? 'prompt' ); ?>>
												<?php esc_html_e( 'Client-Side Geo-Prompt / Modal (Recommended for LiteSpeed Full Page Cache & SEO)', 'geo-regional-router' ); ?>
											</option>
											<option value="immediate" <?php selected( 'immediate', $options['routing_mode'] ?? 'prompt' ); ?>>
												<?php esc_html_e( 'Immediate 302 Redirect (PHP Backend Engine)', 'geo-regional-router' ); ?>
											</option>
										</select>
										<p class="description">
											<strong><?php esc_html_e( 'Why Prompt Mode is Recommended:', 'geo-regional-router' ); ?></strong>
											<?php esc_html_e( 'Allows LiteSpeed Cache to 100% full-page cache every URL (/, /bd/, /in/). A non-intrusive prompt or countdown asks visitors to switch regions after page load, eliminating cache conflicts and Googlebot indexing penalties.', 'geo-regional-router' ); ?>
										</p>
									</td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Redirect Status Code', 'geo-regional-router' ); ?></th>
									<td>
										<select name="redirect_status">
											<option value="302" <?php selected( 302, $options['redirect_status'] ?? 302 ); ?>>302 Found (Temporary - Recommended for Live & GeoIP)</option>
											<option value="307" <?php selected( 307, $options['redirect_status'] ?? 302 ); ?>>307 Temporary Redirect</option>
											<option value="301" <?php selected( 301, $options['redirect_status'] ?? 302 ); ?>>301 Moved Permanently (Not Recommended for GeoIP)</option>
											<option value="308" <?php selected( 308, $options['redirect_status'] ?? 302 ); ?>>308 Permanent Redirect</option>
										</select>
										<p class="description">
											<strong><?php esc_html_e( 'SEO & GeoIP Best Practice:', 'geo-regional-router' ); ?></strong>
											<?php esc_html_e( 'Keep status set to 302 or 307. 301 permanent redirects cause browsers and Googlebot to cache regional URLs permanently, breaking location switching and search engine indexing.', 'geo-regional-router' ); ?>
										</p>
									</td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Remember Routing Decision', 'geo-regional-router' ); ?></th>
									<td>
										<select name="cookie_persistence">
											<option value="disabled" <?php selected( 'disabled', $options['cookie_persistence'] ?? 'disabled' ); ?>><?php esc_html_e( 'Disabled (Re-evaluate on every request)', 'geo-regional-router' ); ?></option>
											<option value="session" <?php selected( 'session', $options['cookie_persistence'] ?? 'disabled' ); ?>><?php esc_html_e( 'Session Only (Until browser closes)', 'geo-regional-router' ); ?></option>
											<option value="24h" <?php selected( '24h', $options['cookie_persistence'] ?? 'disabled' ); ?>><?php esc_html_e( '24 Hours', 'geo-regional-router' ); ?></option>
											<option value="7d" <?php selected( '7d', $options['cookie_persistence'] ?? 'disabled' ); ?>><?php esc_html_e( '7 Days', 'geo-regional-router' ); ?></option>
										</select>
										<p class="description">
											<?php esc_html_e( 'Controls visitor country cookie caching. Note: The ?skipredirect parameter always overrides cookie persistence.', 'geo-regional-router' ); ?>
										</p>
									</td>
								</tr>
							</table>
						</div>

						<div class="grr-card">
							<h3><?php esc_html_e( 'Multisite Regional Mappings', 'geo-regional-router' ); ?></h3>
							<table class="widefat striped">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Regional Role', 'geo-regional-router' ); ?></th>
										<th><?php esc_html_e( 'Country Code', 'geo-regional-router' ); ?></th>
										<th><?php esc_html_e( 'Assigned Site', 'geo-regional-router' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<tr>
										<td><strong><?php esc_html_e( 'Global / Default Site', 'geo-regional-router' ); ?></strong></td>
										<td><code>ALL OTHER COUNTRIES</code></td>
										<td>
											<select name="site_global">
												<option value="0"><?php esc_html_e( '-- Select Site --', 'geo-regional-router' ); ?></option>
												<?php foreach ( $sites as $site ) : ?>
													<option value="<?php echo esc_attr( $site->blog_id ); ?>" <?php selected( $site->blog_id, $options['site_global'] ?? 0 ); ?>>
														ID <?php echo esc_html( $site->blog_id ); ?> - <?php echo esc_html( $site->domain . $site->path ); ?>
													</option>
												<?php endforeach; ?>
											</select>
										</td>
									</tr>
									<tr>
										<td><strong><?php esc_html_e( 'Bangladesh Site', 'geo-regional-router' ); ?></strong></td>
										<td><code>BD</code></td>
										<td>
											<select name="site_bd">
												<option value="0"><?php esc_html_e( '-- Select Site --', 'geo-regional-router' ); ?></option>
												<?php foreach ( $sites as $site ) : ?>
													<option value="<?php echo esc_attr( $site->blog_id ); ?>" <?php selected( $site->blog_id, $options['site_bd'] ?? 0 ); ?>>
														ID <?php echo esc_html( $site->blog_id ); ?> - <?php echo esc_html( $site->domain . $site->path ); ?>
													</option>
												<?php endforeach; ?>
											</select>
										</td>
									</tr>
									<tr>
										<td><strong><?php esc_html_e( 'India Site', 'geo-regional-router' ); ?></strong></td>
										<td><code>IN</code></td>
										<td>
											<select name="site_in">
												<option value="0"><?php esc_html_e( '-- Select Site --', 'geo-regional-router' ); ?></option>
												<?php foreach ( $sites as $site ) : ?>
													<option value="<?php echo esc_attr( $site->blog_id ); ?>" <?php selected( $site->blog_id, $options['site_in'] ?? 0 ); ?>>
														ID <?php echo esc_html( $site->blog_id ); ?> - <?php echo esc_html( $site->domain . $site->path ); ?>
													</option>
												<?php endforeach; ?>
											</select>
										</td>
									</tr>
								</tbody>
							</table>
						</div>

					<?php elseif ( 'exclusions' === $active_tab ) : ?>
						<div class="grr-card">
							<h3><?php esc_html_e( 'Routing Exclusion Rules', 'geo-regional-router' ); ?></h3>
							<table class="form-table">
								<tr>
									<th scope="row"><?php esc_html_e( 'User Exclusions', 'geo-regional-router' ); ?></th>
									<td>
										<fieldset>
											<label>
												<input type="checkbox" name="skip_logged_in_admins" value="1" <?php checked( 1, $options['skip_logged_in_admins'] ?? 1 ); ?> />
												<?php esc_html_e( 'Disable redirects for logged-in Administrators (Default: ON)', 'geo-regional-router' ); ?>
											</label><br />
											<label>
												<input type="checkbox" name="skip_logged_in_users" value="1" <?php checked( 1, $options['skip_logged_in_users'] ?? 0 ); ?> />
												<?php esc_html_e( 'Disable redirects for all logged-in users (Default: OFF)', 'geo-regional-router' ); ?>
											</label>
										</fieldset>
									</td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Request & Bot Exclusions', 'geo-regional-router' ); ?></th>
									<td>
										<fieldset>
											<label><input type="checkbox" name="skip_bots" value="1" <?php checked( 1, $options['skip_bots'] ?? 1 ); ?> /> <?php esc_html_e( 'Skip Search Engine Crawlers & Bots (Googlebot, Bingbot, YandexBot, etc.)', 'geo-regional-router' ); ?></label><br />
											<label><input type="checkbox" name="skip_rest" value="1" <?php checked( 1, $options['skip_rest'] ?? 1 ); ?> /> <?php esc_html_e( 'Skip REST API Requests (/wp-json/)', 'geo-regional-router' ); ?></label><br />
											<label><input type="checkbox" name="skip_ajax" value="1" <?php checked( 1, $options['skip_ajax'] ?? 1 ); ?> /> <?php esc_html_e( 'Skip AJAX Requests (admin-ajax.php)', 'geo-regional-router' ); ?></label><br />
											<label><input type="checkbox" name="skip_cron" value="1" <?php checked( 1, $options['skip_cron'] ?? 1 ); ?> /> <?php esc_html_e( 'Skip WP Cron (wp-cron.php)', 'geo-regional-router' ); ?></label><br />
											<label><input type="checkbox" name="skip_admin_urls" value="1" <?php checked( 1, $options['skip_admin_urls'] ?? 1 ); ?> /> <?php esc_html_e( 'Skip Admin URLs (/wp-admin/)', 'geo-regional-router' ); ?></label><br />
											<label><input type="checkbox" name="skip_xmlrpc" value="1" <?php checked( 1, $options['skip_xmlrpc'] ?? 1 ); ?> /> <?php esc_html_e( 'Skip XML-RPC (xmlrpc.php)', 'geo-regional-router' ); ?></label><br />
											<label><input type="checkbox" name="skip_feeds" value="1" <?php checked( 1, $options['skip_feeds'] ?? 0 ); ?> /> <?php esc_html_e( 'Skip RSS/Atom Feeds', 'geo-regional-router' ); ?></label><br />
											<label><input type="checkbox" name="skip_sitemaps" value="1" <?php checked( 1, $options['skip_sitemaps'] ?? 1 ); ?> /> <?php esc_html_e( 'Skip XML Sitemaps', 'geo-regional-router' ); ?></label><br />
											<label><input type="checkbox" name="skip_previews" value="1" <?php checked( 1, $options['skip_previews'] ?? 1 ); ?> /> <?php esc_html_e( 'Skip Post/Page Preview requests', 'geo-regional-router' ); ?></label>
										</fieldset>
									</td>
								</tr>
							</table>
						</div>

					<?php elseif ( 'detection' === $active_tab ) : ?>
						<div class="grr-card">
							<h3><?php esc_html_e( 'Country Detection Sources & Priority', 'geo-regional-router' ); ?></h3>
							<table class="form-table">
								<tr>
									<th scope="row"><?php esc_html_e( 'Cloudflare GeoIP', 'geo-regional-router' ); ?></th>
									<td>
										<label>
											<input type="checkbox" name="country_source_cf" value="1" <?php checked( 1, $options['country_source_cf'] ?? 1 ); ?> />
											<?php esc_html_e( 'Use Cloudflare CF-IPCountry header when present (Priority 1)', 'geo-regional-router' ); ?>
										</label>
									</td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Custom HTTP Header', 'geo-regional-router' ); ?></th>
									<td>
										<label>
											<input type="checkbox" name="country_source_header" value="1" <?php checked( 1, $options['country_source_header'] ?? 0 ); ?> />
											<?php esc_html_e( 'Enable custom HTTP header country detection (Priority 2)', 'geo-regional-router' ); ?>
										</label><br /><br />
										<input type="text" name="country_custom_header_name" value="<?php echo esc_attr( $options['country_custom_header_name'] ?? 'HTTP_X_GEOIP_COUNTRY' ); ?>" class="regular-text" placeholder="HTTP_X_GEOIP_COUNTRY" />
										<p class="description"><?php esc_html_e( 'Header key as received in $_SERVER (e.g. HTTP_X_GEOIP_COUNTRY).', 'geo-regional-router' ); ?></p>
									</td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Trusted Proxy IPs', 'geo-regional-router' ); ?></th>
									<td>
										<textarea name="trusted_proxies" rows="4" class="large-text code" placeholder="10.0.0.0/8&#10;172.16.0.0/12&#10;192.168.0.0/16"><?php echo esc_textarea( $options['trusted_proxies'] ?? '' ); ?></textarea>
										<p class="description">
											<?php esc_html_e( 'Security Note: Enter trusted proxy/CDN IP addresses or CIDR blocks (one per line). If left blank, proxy headers will be evaluated directly.', 'geo-regional-router' ); ?>
										</p>
									</td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'MaxMind Database Path & License', 'geo-regional-router' ); ?></th>
									<td>
										<input type="text" name="maxmind_db_path" value="<?php echo esc_attr( $options['maxmind_db_path'] ?? '' ); ?>" class="large-text" placeholder="/path/to/GeoLite2-Country.mmdb" />
										<p class="description"><?php esc_html_e( 'Absolute path to local MaxMind GeoLite2-Country.mmdb binary database file.', 'geo-regional-router' ); ?></p>
										<br />
										<label><strong><?php esc_html_e( 'MaxMind License Key (For Auto-Updates):', 'geo-regional-router' ); ?></strong></label><br />
										<input type="password" name="maxmind_license_key" value="<?php echo esc_attr( $options['maxmind_license_key'] ?? '' ); ?>" class="regular-text" placeholder="License key for weekly updates" />
									</td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Debug & Uninstall', 'geo-regional-router' ); ?></th>
									<td>
										<fieldset>
											<label>
												<input type="checkbox" name="debug_mode" value="1" <?php checked( 1, $options['debug_mode'] ?? 0 ); ?> />
												<?php esc_html_e( 'Enable Debug Logging (Logs decisions to wp-content/uploads/geo-regional-router-debug.log)', 'geo-regional-router' ); ?>
											</label><br />
											<label>
												<input type="checkbox" name="delete_data_on_uninstall" value="1" <?php checked( 1, $options['delete_data_on_uninstall'] ?? 0 ); ?> />
												<?php esc_html_e( 'Delete plugin settings option on uninstall (Default: OFF)', 'geo-regional-router' ); ?>
											</label>
										</fieldset>
									</td>
								</tr>
							</table>
						</div>

					<?php elseif ( 'features' === $active_tab ) : ?>
						<div class="grr-card">
							<h3><?php esc_html_e( 'SEO & Edge Cache & Admin UI Features', 'geo-regional-router' ); ?></h3>
							<table class="form-table">
								<tr>
									<th scope="row"><?php esc_html_e( 'SEO hreflang Meta Tags', 'geo-regional-router' ); ?></th>
									<td>
										<label>
											<input type="checkbox" name="enable_hreflang" value="1" <?php checked( 1, $options['enable_hreflang'] ?? 1 ); ?> />
											<?php esc_html_e( 'Auto-inject hreflang alternate links (bn-BD, hi-IN, x-default) into page <head>', 'geo-regional-router' ); ?>
										</label>
									</td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Edge Cache Response Headers', 'geo-regional-router' ); ?></th>
									<td>
										<label>
											<input type="checkbox" name="enable_edge_headers" value="1" <?php checked( 1, $options['enable_edge_headers'] ?? 1 ); ?> />
											<?php esc_html_e( 'Send "Vary: CF-IPCountry, Accept-Language" header to prevent reverse proxies from caching wrong regional redirects', 'geo-regional-router' ); ?>
										</label>
									</td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Admin Bar Quick Switcher', 'geo-regional-router' ); ?></th>
									<td>
										<label>
											<input type="checkbox" name="enable_admin_bar" value="1" <?php checked( 1, $options['enable_admin_bar'] ?? 1 ); ?> />
											<?php esc_html_e( 'Display Quick Geo Country Switcher in top WordPress Admin Bar for Network Admins', 'geo-regional-router' ); ?>
										</label>
									</td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Frontend Regional Switcher', 'geo-regional-router' ); ?></th>
									<td>
										<label>
											<input type="checkbox" name="enable_frontend_switcher" value="1" <?php checked( 1, $options['enable_frontend_switcher'] ?? 1 ); ?> />
											<?php esc_html_e( 'Enable [geo_regional_switcher] shortcode for visitors', 'geo-regional-router' ); ?>
										</label><br />
										<label>
											<input type="checkbox" name="enable_floating_widget" value="1" <?php checked( 1, $options['enable_floating_widget'] ?? 0 ); ?> />
											<?php esc_html_e( 'Automatically render floating regional switcher in bottom-right corner of frontend', 'geo-regional-router' ); ?>
										</label>
										<hr style="margin: 15px 0; border: 0; border-top: 1px solid #e0e0e0;" />
										<p>
											<label>
												<input type="checkbox" name="enable_footer_switcher" value="1" <?php checked( 1, $options['enable_footer_switcher'] ?? 0 ); ?> />
												<strong><?php esc_html_e( 'Display Country Switcher in website footer (wp_footer)', 'geo-regional-router' ); ?></strong>
											</label>
										</p>
										<p>
											<label><?php esc_html_e( 'Footer Switcher Style:', 'geo-regional-router' ); ?></label><br />
											<select name="footer_switcher_style">
												<option value="inline" <?php selected( 'inline', $options['footer_switcher_style'] ?? 'inline' ); ?>><?php esc_html_e( 'Inline Links with Flags (GLOBAL | 🇧🇩 BD | 🇮🇳 IN)', 'geo-regional-router' ); ?></option>
												<option value="buttons" <?php selected( 'buttons', $options['footer_switcher_style'] ?? 'inline' ); ?>><?php esc_html_e( 'Pill Buttons Style', 'geo-regional-router' ); ?></option>
												<option value="compact" <?php selected( 'compact', $options['footer_switcher_style'] ?? 'inline' ); ?>><?php esc_html_e( 'Micro Dropdown Select', 'geo-regional-router' ); ?></option>
											</select>
										</p>
										<p>
											<label><?php esc_html_e( 'Footer Switcher Alignment:', 'geo-regional-router' ); ?></label><br />
											<select name="footer_switcher_position">
												<option value="center" <?php selected( 'center', $options['footer_switcher_position'] ?? 'center' ); ?>><?php esc_html_e( 'Centered', 'geo-regional-router' ); ?></option>
												<option value="left" <?php selected( 'left', $options['footer_switcher_position'] ?? 'center' ); ?>><?php esc_html_e( 'Left-Aligned', 'geo-regional-router' ); ?></option>
												<option value="right" <?php selected( 'right', $options['footer_switcher_position'] ?? 'center' ); ?>><?php esc_html_e( 'Right-Aligned', 'geo-regional-router' ); ?></option>
											</select>
										</p>
										<p class="description">
											<?php esc_html_e( 'Shortcode examples: [geo_regional_switcher], [geo_regional_switcher style="buttons"], [geo_regional_switcher style="inline"]', 'geo-regional-router' ); ?>
										</p>
									</td>
								</tr>
								<tr>
									<th scope="row"><?php esc_html_e( 'Geo-Prompt Modal / Banner UI', 'geo-regional-router' ); ?></th>
									<td>
										<fieldset>
											<p>
												<label><strong><?php esc_html_e( 'Display Layout Style:', 'geo-regional-router' ); ?></strong></label><br />
												<select name="prompt_style">
													<option value="card" <?php selected( 'card', $options['prompt_style'] ?? 'card' ); ?>><?php esc_html_e( 'Floating Card (Bottom-Right Corner)', 'geo-regional-router' ); ?></option>
													<option value="banner" <?php selected( 'banner', $options['prompt_style'] ?? 'card' ); ?>><?php esc_html_e( 'Top Notification Bar', 'geo-regional-router' ); ?></option>
													<option value="modal" <?php selected( 'modal', $options['prompt_style'] ?? 'card' ); ?>><?php esc_html_e( 'Center Modal Dialog (Backdrop Overlay)', 'geo-regional-router' ); ?></option>
												</select>
											</p>
											<p>
												<label><strong><?php esc_html_e( 'Display Delay (Seconds):', 'geo-regional-router' ); ?></strong></label><br />
												<input type="number" step="0.5" min="0" max="10" name="prompt_delay" value="<?php echo esc_attr( $options['prompt_delay'] ?? 1.5 ); ?>" class="small-text" />
												<span class="description"><?php esc_html_e( 'Seconds to wait after page load before showing the prompt (e.g. 1.5).', 'geo-regional-router' ); ?></span>
											</p>
											<p>
												<label><strong><?php esc_html_e( 'Auto-Redirect Countdown (Seconds):', 'geo-regional-router' ); ?></strong></label><br />
												<input type="number" step="1" min="0" max="30" name="auto_redirect_countdown" value="<?php echo esc_attr( $options['auto_redirect_countdown'] ?? 0 ); ?>" class="small-text" />
												<span class="description"><?php esc_html_e( 'Set to 0 to disable countdown (visitor must click manually). Set to e.g. 5 to auto-redirect after 5 seconds with a Cancel button.', 'geo-regional-router' ); ?></span>
											</p>
											<p>
												<label><strong><?php esc_html_e( 'Auto-Hide Notification (Seconds):', 'geo-regional-router' ); ?></strong></label><br />
												<input type="number" step="1" min="1" max="60" name="prompt_auto_hide" value="<?php echo esc_attr( ! empty( $options['prompt_auto_hide'] ) ? (int) $options['prompt_auto_hide'] : 7 ); ?>" class="small-text" />
												<span class="description"><?php esc_html_e( 'Seconds before the notification banner automatically fades out. If visitors do not choose any option, they are assumed to want to stay on this website, and the prompt will not be shown again (Default: 7s).', 'geo-regional-router' ); ?></span>
											</p>
										</fieldset>
									</td>
								</tr>
							</table>
						</div>
					<?php endif; ?>

					<?php submit_button( esc_html__( 'Save Network Settings', 'geo-regional-router' ) ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Save Network Settings POST handler.
	 */
	public function save_network_settings(): void {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'Unauthorized access.', 'geo-regional-router' ) );
		}

		check_admin_referer( 'grr_save_settings_nonce', 'grr_nonce' );

		$existing = get_site_option( 'grr_options', array() );
		$tab      = isset( $_POST['tab'] ) ? sanitize_key( $_POST['tab'] ) : 'general';

		if ( 'general' === $tab ) {
			$site_global = isset( $_POST['site_global'] ) ? (int) $_POST['site_global'] : 0;
			$site_bd     = isset( $_POST['site_bd'] ) ? (int) $_POST['site_bd'] : 0;
			$site_in     = isset( $_POST['site_in'] ) ? (int) $_POST['site_in'] : 0;

			if ( $site_global > 0 && ( $site_global === $site_bd || $site_global === $site_in || ( $site_bd > 0 && $site_bd === $site_in ) ) ) {
				wp_safe_redirect( network_admin_url( 'settings.php?page=geo-regional-router&tab=general&error=duplicate_sites' ) );
				exit;
			}

			$existing['enabled']            = isset( $_POST['enabled'] ) ? 1 : 0;
			$existing['routing_mode']       = isset( $_POST['routing_mode'] ) ? sanitize_key( $_POST['routing_mode'] ) : 'prompt';
			$existing['redirect_status']    = isset( $_POST['redirect_status'] ) ? (int) $_POST['redirect_status'] : 302;
			$existing['cookie_persistence'] = isset( $_POST['cookie_persistence'] ) ? sanitize_key( $_POST['cookie_persistence'] ) : 'disabled';
			$existing['site_global']        = $site_global;
			$existing['site_bd']            = $site_bd;
			$existing['site_in']            = $site_in;

		} elseif ( 'exclusions' === $tab ) {
			$existing['skip_logged_in_admins'] = isset( $_POST['skip_logged_in_admins'] ) ? 1 : 0;
			$existing['skip_logged_in_users']  = isset( $_POST['skip_logged_in_users'] ) ? 1 : 0;
			$existing['skip_bots']             = isset( $_POST['skip_bots'] ) ? 1 : 0;
			$existing['skip_rest']             = isset( $_POST['skip_rest'] ) ? 1 : 0;
			$existing['skip_ajax']             = isset( $_POST['skip_ajax'] ) ? 1 : 0;
			$existing['skip_cron']             = isset( $_POST['skip_cron'] ) ? 1 : 0;
			$existing['skip_admin_urls']       = isset( $_POST['skip_admin_urls'] ) ? 1 : 0;
			$existing['skip_xmlrpc']           = isset( $_POST['skip_xmlrpc'] ) ? 1 : 0;
			$existing['skip_feeds']            = isset( $_POST['skip_feeds'] ) ? 1 : 0;
			$existing['skip_sitemaps']         = isset( $_POST['skip_sitemaps'] ) ? 1 : 0;
			$existing['skip_previews']         = isset( $_POST['skip_previews'] ) ? 1 : 0;

		} elseif ( 'detection' === $tab ) {
			$existing['country_source_cf']          = isset( $_POST['country_source_cf'] ) ? 1 : 0;
			$existing['country_source_header']      = isset( $_POST['country_source_header'] ) ? 1 : 0;
			$existing['country_custom_header_name'] = isset( $_POST['country_custom_header_name'] ) ? sanitize_text_field( wp_unslash( $_POST['country_custom_header_name'] ) ) : 'HTTP_X_GEOIP_COUNTRY';
			$existing['trusted_proxies']            = isset( $_POST['trusted_proxies'] ) ? sanitize_textarea_field( wp_unslash( $_POST['trusted_proxies'] ) ) : '';
			$existing['maxmind_db_path']            = isset( $_POST['maxmind_db_path'] ) ? sanitize_text_field( wp_unslash( $_POST['maxmind_db_path'] ) ) : '';
			$existing['maxmind_license_key']        = isset( $_POST['maxmind_license_key'] ) ? sanitize_text_field( wp_unslash( $_POST['maxmind_license_key'] ) ) : '';
			$existing['debug_mode']                 = isset( $_POST['debug_mode'] ) ? 1 : 0;
			$existing['delete_data_on_uninstall']   = isset( $_POST['delete_data_on_uninstall'] ) ? 1 : 0;

		} elseif ( 'features' === $tab ) {
			$existing['enable_hreflang']            = isset( $_POST['enable_hreflang'] ) ? 1 : 0;
			$existing['enable_edge_headers']        = isset( $_POST['enable_edge_headers'] ) ? 1 : 0;
			$existing['enable_admin_bar']           = isset( $_POST['enable_admin_bar'] ) ? 1 : 0;
			$existing['enable_frontend_switcher']   = isset( $_POST['enable_frontend_switcher'] ) ? 1 : 0;
			$existing['enable_floating_widget']     = isset( $_POST['enable_floating_widget'] ) ? 1 : 0;
			$existing['enable_footer_switcher']     = isset( $_POST['enable_footer_switcher'] ) ? 1 : 0;
			$existing['footer_switcher_style']      = isset( $_POST['footer_switcher_style'] ) ? sanitize_key( $_POST['footer_switcher_style'] ) : 'inline';
			$existing['footer_switcher_position']   = isset( $_POST['footer_switcher_position'] ) ? sanitize_key( $_POST['footer_switcher_position'] ) : 'center';
			$existing['prompt_style']               = isset( $_POST['prompt_style'] ) ? sanitize_key( $_POST['prompt_style'] ) : 'card';
			$existing['prompt_delay']               = isset( $_POST['prompt_delay'] ) ? max( 0, (float) $_POST['prompt_delay'] ) : 1.5;
			$existing['prompt_auto_hide']           = isset( $_POST['prompt_auto_hide'] ) ? max( 0, (int) $_POST['prompt_auto_hide'] ) : 0;
			$existing['auto_redirect_countdown']    = isset( $_POST['auto_redirect_countdown'] ) ? max( 0, (int) $_POST['auto_redirect_countdown'] ) : 0;
		}

		update_site_option( 'grr_options', $existing );

		wp_safe_redirect( network_admin_url( 'settings.php?page=geo-regional-router&tab=' . $tab . '&updated=1' ) );
		exit;
	}
}
