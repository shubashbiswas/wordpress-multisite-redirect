<?php
namespace GRR;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Diagnostics
 * Diagnostic panel and URL routing simulation tool.
 */
class Diagnostics {

	/**
	 * Country Detector instance.
	 *
	 * @var Country_Detector
	 */
	private Country_Detector $country_detector;

	/**
	 * Router instance.
	 *
	 * @var Router
	 */
	private Router $router;

	/**
	 * Constructor.
	 *
	 * @param Country_Detector $country_detector Country detector.
	 * @param Router           $router Router engine.
	 */
	public function __construct( Country_Detector $country_detector, Router $router ) {
		$this->country_detector = $country_detector;
		$this->router           = $router;
	}

	/**
	 * Register diagnostic hooks.
	 */
	public function init(): void {
		add_action( 'wp_ajax_grr_run_diagnostic', array( $this, 'ajax_run_diagnostic' ) );
		add_action( 'wp_ajax_grr_clear_log', array( $this, 'ajax_clear_log' ) );
	}

	/**
	 * Render Diagnostic Panel in Network Admin.
	 */
	public function render_diagnostics_panel(): void {
		$options         = get_site_option( 'grr_options', array() );
		$current_blog_id = get_current_blog_id();
		$current_url     = $this->router->get_current_url();
		$country_info    = $this->country_detector->detect_country();
		$detected_code   = $country_info['country'];
		$detection_src   = $country_info['source'];

		$site_global_id = (int) ( $options['site_global'] ?? 0 );
		$site_bd_id     = (int) ( $options['site_bd'] ?? 0 );
		$site_in_id     = (int) ( $options['site_in'] ?? 0 );

		$global_url = $site_global_id ? get_site_url( $site_global_id ) : __( 'Not Configured', 'geo-regional-router' );
		$bd_url     = $site_bd_id ? get_site_url( $site_bd_id ) : __( 'Not Configured', 'geo-regional-router' );
		$in_url     = $site_in_id ? get_site_url( $site_in_id ) : __( 'Not Configured', 'geo-regional-router' );

		// Simulate current request routing
		$route_calc = $this->router->calculate_destination( $detected_code, $options );
		?>
		<div class="grr-diagnostics-container">
			<div class="grr-card">
				<h3><?php esc_html_e( 'Environment & Detection Status', 'geo-regional-router' ); ?></h3>
				<table class="widefat striped">
					<tbody>
						<tr>
							<td><strong><?php esc_html_e( 'Current Site ID', 'geo-regional-router' ); ?>:</strong></td>
							<td><code><?php echo esc_html( $current_blog_id ); ?></code></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Current Site URL', 'geo-regional-router' ); ?>:</strong></td>
							<td><code><?php echo esc_url( get_site_url( $current_blog_id ) ); ?></code></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Configured Global Site (Default)', 'geo-regional-router' ); ?>:</strong></td>
							<td>ID <code><?php echo esc_html( $site_global_id ); ?></code> &mdash; <?php echo esc_html( $global_url ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Configured Bangladesh Site (BD)', 'geo-regional-router' ); ?>:</strong></td>
							<td>ID <code><?php echo esc_html( $site_bd_id ); ?></code> &mdash; <?php echo esc_html( $bd_url ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Configured India Site (IN)', 'geo-regional-router' ); ?>:</strong></td>
							<td>ID <code><?php echo esc_html( $site_in_id ); ?></code> &mdash; <?php echo esc_html( $in_url ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Detected Country Code', 'geo-regional-router' ); ?>:</strong></td>
							<td><span class="grr-badge"><?php echo esc_html( $detected_code ); ?></span> (Source: <em><?php echo esc_html( $detection_src ); ?></em>)</td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Visitor IP Address', 'geo-regional-router' ); ?>:</strong></td>
							<td><code><?php echo esc_html( $this->country_detector->get_client_ip() ); ?></code></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Current Request URL', 'geo-regional-router' ); ?>:</strong></td>
							<td><code><?php echo esc_url( $current_url ); ?></code></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Routing Evaluation', 'geo-regional-router' ); ?>:</strong></td>
							<td>
								<?php if ( $route_calc['should_redirect'] ) : ?>
									<span class="grr-status-badge grr-status-redirect"><?php esc_html_e( 'WOULD REDIRECT', 'geo-regional-router' ); ?></span>
									&rarr; <code><?php echo esc_url( $route_calc['target_url'] ); ?></code>
								<?php else : ?>
									<span class="grr-status-badge grr-status-noredirect"><?php esc_html_e( 'NO REDIRECT', 'geo-regional-router' ); ?></span>
									(Reason: <em><?php echo esc_html( $route_calc['reason'] ); ?></em>)
								<?php endif; ?>
							</td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="grr-card">
				<h3><?php esc_html_e( 'Interactive URL Routing Simulator', 'geo-regional-router' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Simulate how any country visitor and URL path will be evaluated by the Geo Regional Router engine.', 'geo-regional-router' ); ?></p>

				<form id="grr-simulator-form" style="margin-top: 15px;">
					<table class="form-table">
						<tr>
							<th scope="row"><label for="grr_sim_country"><?php esc_html_e( 'Test Country Code', 'geo-regional-router' ); ?></label></th>
							<td>
								<select id="grr_sim_country" name="sim_country">
									<option value="BD">Bangladesh (BD)</option>
									<option value="IN">India (IN)</option>
									<option value="US" selected>United States (US - Global)</option>
									<option value="GB">United Kingdom (GB - Global)</option>
									<option value="CA">Canada (CA - Global)</option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="grr_sim_path"><?php esc_html_e( 'Test Request Path / Query', 'geo-regional-router' ); ?></label></th>
							<td>
								<input type="text" id="grr_sim_path" name="sim_path" value="/about/?utm_source=test" class="large-text" placeholder="/about/ or /bd/contact/" />
							</td>
						</tr>
					</table>
					<button type="button" id="grr-run-sim-btn" class="button button-primary"><?php esc_html_e( 'Run Routing Simulation', 'geo-regional-router' ); ?></button>
				</form>

				<div id="grr-sim-results" style="margin-top: 20px; display: none;"></div>
			</div>

			<div class="grr-card">
				<h3><?php esc_html_e( 'Debug Log Manager', 'geo-regional-router' ); ?></h3>
				<?php
				$logger   = Plugin::get_instance()->get_logger();
				$log_file = $logger->get_log_filepath();
				$exists   = file_exists( $log_file );
				$size     = $exists ? size_format( filesize( $log_file ) ) : '0 B';
				?>
				<p>
					<strong><?php esc_html_e( 'Log File Path:', 'geo-regional-router' ); ?></strong> <code><?php echo esc_html( $log_file ); ?></code><br />
					<strong><?php esc_html_e( 'Log File Size:', 'geo-regional-router' ); ?></strong> <?php echo esc_html( $size ); ?>
				</p>
				<button type="button" id="grr-clear-log-btn" class="button button-secondary" <?php disabled( ! $exists ); ?>>
					<?php esc_html_e( 'Clear Debug Log File', 'geo-regional-router' ); ?>
				</button>
				<span id="grr-log-status" style="margin-left: 10px;"></span>
			</div>
		</div>
		<?php
	}

	/**
	 * AJAX endpoint for URL simulator.
	 */
	public function ajax_run_diagnostic(): void {
		check_ajax_referer( 'grr_diagnostics_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'geo-regional-router' ) ) );
		}

		$test_country = strtoupper( sanitize_text_field( wp_unslash( $_POST['sim_country'] ?? 'US' ) ) );
		$test_path    = sanitize_text_field( wp_unslash( $_POST['sim_path'] ?? '/' ) );

		$options         = get_site_option( 'grr_options', array() );
		$site_global_id = (int) ( $options['site_global'] ?? 0 );
		$site_bd_id     = (int) ( $options['site_bd'] ?? 0 );
		$site_in_id     = (int) ( $options['site_in'] ?? 0 );

		if ( ! $site_global_id || ! $site_bd_id || ! $site_in_id ) {
			wp_send_json_error( array( 'message' => __( 'Site mappings are not fully configured in plugin options.', 'geo-regional-router' ) ) );
		}

		// Determine target site ID
		switch ( $test_country ) {
			case 'BD':
				$target_site_id = $site_bd_id;
				break;
			case 'IN':
				$target_site_id = $site_in_id;
				break;
			default:
				$target_site_id = $site_global_id;
				break;
		}

		$global_site_url = get_site_url( $site_global_id );
		$bd_site_url     = get_site_url( $site_bd_id );
		$in_site_url     = get_site_url( $site_in_id );
		$target_site_url = get_site_url( $target_site_id );

		$parsed_uri = wp_parse_url( $test_path );
		$path       = $parsed_uri['path'] ?? '/';
		$query      = isset( $parsed_uri['query'] ) ? '?' . $parsed_uri['query'] : '';

		$clean_path = $this->router->extract_clean_path( $path, array( $global_site_url, $bd_site_url, $in_site_url ) );
		$target_url = rtrim( $target_site_url, '/' ) . '/' . ltrim( $clean_path, '/' ) . $query;

		// Calculate current requested full URL simulation (assuming requested on global or detected site)
		$current_sim_url = rtrim( $global_site_url, '/' ) . '/' . ltrim( $path, '/' ) . $query;

		$should_redirect = ( rtrim( strtolower( $current_sim_url ), '/' ) !== rtrim( strtolower( $target_url ), '/' ) );

		wp_send_json_success(
			array(
				'country'         => $test_country,
				'input_path'      => $test_path,
				'clean_path'      => $clean_path,
				'target_site_id'  => $target_site_id,
				'target_url'      => $target_url,
				'current_url'     => $current_sim_url,
				'should_redirect' => $should_redirect,
				'reason'          => $should_redirect ? sprintf( 'Country [%s] maps to Site ID %d.', $test_country, $target_site_id ) : 'Destination URL matches requested URL (No redirect needed).',
			)
		);
	}

	/**
	 * AJAX endpoint to clear debug log file.
	 */
	public function ajax_clear_log(): void {
		check_ajax_referer( 'grr_diagnostics_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'geo-regional-router' ) ) );
		}

		$logger  = Plugin::get_instance()->get_logger();
		$cleared = $logger->clear_log();

		if ( $cleared ) {
			wp_send_json_success( array( 'message' => __( 'Log file cleared successfully.', 'geo-regional-router' ) ) );
		} else {
			wp_send_json_error( array( 'message' => __( 'Failed to clear log file or log file does not exist.', 'geo-regional-router' ) ) );
		}
	}
}
