/**
 * Geo Regional Router Admin JavaScript
 */
(function ($) {
	'use strict';

	$(document).ready(function () {
		// Run routing simulator
		$('#grr-run-sim-btn').on('click', function (e) {
			e.preventDefault();

			var $btn = $(this);
			var $results = $('#grr-sim-results');
			var country = $('#grr_sim_country').val();
			var path = $('#grr_sim_path').val();

			$btn.prop('disabled', true).text('Evaluating...');
			$results.hide();

			$.ajax({
				url: window.grrAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'grr_run_diagnostic',
					nonce: window.grrAdmin.nonce,
					sim_country: country,
					sim_path: path
				},
				success: function (response) {
					$btn.prop('disabled', false).text('Run Routing Simulation');

					if (response.success) {
						var data = response.data;
						var boxClass = data.should_redirect ? 'is-redirect' : 'is-noredirect';
						var statusBadge = data.should_redirect 
							? '<span class="grr-status-badge grr-status-redirect">WOULD REDIRECT</span>' 
							: '<span class="grr-status-badge grr-status-noredirect">NO REDIRECT</span>';

						var html = '<div class="grr-sim-result-box ' + boxClass + '">';
						html += '<h4>Simulation Results for ' + escapeHtml(data.country) + ' Visitor</h4>';
						html += '<table class="grr-sim-table">';
						html += '<tr><td>Evaluation Status:</td><td>' + statusBadge + '</td></tr>';
						html += '<tr><td>Requested Path:</td><td><code>' + escapeHtml(data.input_path) + '</code></td></tr>';
						html += '<tr><td>Clean Inner Path:</td><td><code>' + escapeHtml(data.clean_path) + '</code></td></tr>';
						html += '<tr><td>Simulated Request URL:</td><td><code>' + escapeHtml(data.current_url) + '</code></td></tr>';
						html += '<tr><td>Calculated Target URL:</td><td><code>' + escapeHtml(data.target_url) + '</code></td></tr>';
						html += '<tr><td>Decision Reason:</td><td><em>' + escapeHtml(data.reason) + '</em></td></tr>';
						html += '</table></div>';

						$results.html(html).slideDown();
					} else {
						$results.html('<div class="notice notice-error"><p>' + escapeHtml(response.data.message) + '</p></div>').slideDown();
					}
				},
				error: function () {
					$btn.prop('disabled', false).text('Run Routing Simulation');
					$results.html('<div class="notice notice-error"><p>An error occurred while contacting the server.</p></div>').slideDown();
				}
			});
		});

		// Clear log file
		$('#grr-clear-log-btn').on('click', function (e) {
			e.preventDefault();
			if (!confirm('Are you sure you want to clear the debug log file?')) {
				return;
			}

			var $btn = $(this);
			var $status = $('#grr-log-status');
			$btn.prop('disabled', true);
			$status.text('Clearing...');

			$.ajax({
				url: window.grrAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'grr_clear_log',
					nonce: window.grrAdmin.nonce
				},
				success: function (response) {
					if (response.success) {
						$status.text(response.data.message).css('color', '#00a32a');
					} else {
						$status.text(response.data.message).css('color', '#d63638');
						$btn.prop('disabled', false);
					}
				},
				error: function () {
					$status.text('Server connection error.').css('color', '#d63638');
					$btn.prop('disabled', false);
				}
			});
		});

		function escapeHtml(text) {
			if (!text) return '';
			return String(text)
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;')
				.replace(/'/g, '&#039;');
		}
	});
})(jQuery);
