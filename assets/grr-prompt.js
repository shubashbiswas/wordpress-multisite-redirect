/**
 * Geo Regional Router - Client-Side Geo-Prompt Controller
 * Asynchronous country check compatible with LiteSpeed Full Page Cache.
 */
(function () {
	'use strict';

	console.log('[GRR] Geo Regional Router prompt script loaded (v1.0.6).');

	if (typeof window === 'undefined') {
		return;
	}

	if (typeof grrPromptConfig === 'undefined') {
		console.warn('[GRR] grrPromptConfig is not defined on this page.');
		return;
	}

	console.log('[GRR] Active config:', grrPromptConfig);

	/**
	 * Cookie helper functions.
	 */
	function getCookie(name) {
		const match = document.cookie.match(new RegExp('(^|;\\s*)(' + name + ')=([^;]*)'));
		return match ? decodeURIComponent(match[3]) : null;
	}

	function setCookie(name, value, days) {
		let expires = '';
		if (days && days > 0) {
			const date = new Date();
			date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
			expires = '; expires=' + date.toUTCString();
		}
		const isSecure = window.location.protocol === 'https:' ? '; Secure' : '';
		document.cookie = name + '=' + encodeURIComponent(value) + expires + '; path=/; SameSite=Lax' + isSecure;
	}

	// 1. Initial Exclusion Checks
	if (window.location.search.indexOf('skipredirect') !== -1 || window.location.search.indexOf('preview=true') !== -1) {
		console.log('[GRR] Prompt skipped: skipredirect/preview parameter detected.');
		return;
	}

	const manualCountry = getCookie('grr_user_manual_country');
	const isTestMode = window.location.search.indexOf('grr_test_country') !== -1;
	if (isTestMode) {
		console.log('[GRR] Test mode active via query parameter.');
	}

	// If visitor already explicitly chose to stay on this website (and hasn't chosen another region), don't nag them
	if (!manualCountry && !isTestMode && getCookie('grr_choice_dismissed') === '1') {
		console.log('[GRR] Prompt skipped: visitor previously chose to stay on this website (grr_choice_dismissed=1).');
		return;
	}

	// Show strictly ONCE per browser session across all pages for undecided visitors
	if (!manualCountry && !isTestMode) {
		try {
			if (sessionStorage.getItem('grr_session_shown') === '1') {
				console.log('[GRR] Prompt skipped: already shown once in this session (sessionStorage).');
				return;
			}
		} catch (e) {}

		if (getCookie('grr_session_shown') === '1') {
			console.log('[GRR] Prompt skipped: already shown once in this session (session cookie).');
			return;
		}
	}

	/**
	 * Initialize async check after DOM is ready and configured delay has elapsed.
	 */
	function init() {
		// If visitor has an established manual regional preference, route immediately without delay
		const hasManualChoice = !!getCookie('grr_user_manual_country');
		const delay = hasManualChoice ? 0 : Math.max(0, parseInt(grrPromptConfig.delay, 10) || 1500);

		if (hasManualChoice) {
			console.log('[GRR] Visitor has saved regional preference. Checking route immediately...');
		} else {
			console.log('[GRR] Scheduling geo detection check in ' + delay + 'ms...');
		}

		setTimeout(function () {
			fetchGeoData();
		}, delay);
	}

	/**
	 * Fetch country detection payload from dynamic REST API endpoint.
	 */
	function fetchGeoData() {
		const currentUrl = window.location.href;
		const endpoint = grrPromptConfig.restUrl + (grrPromptConfig.restUrl.indexOf('?') === -1 ? '?' : '&') +
			'current_url=' + encodeURIComponent(currentUrl);

		console.log('[GRR] Fetching geo payload:', endpoint);

		fetch(endpoint, {
			method: 'GET',
			headers: {
				'Accept': 'application/json'
			},
			cache: 'no-store'
		})
		.then(function (response) {
			if (!response.ok) {
				throw new Error('Network response was not ok (' + response.status + ')');
			}
			return response.json();
		})
		.then(function (data) {
			console.log('[GRR] Geo detection response:', data);

			if (!data || !data.success || !data.should_switch || !data.target_url) {
				console.log('[GRR] No switch needed (visitor is already on matching regional site).');
				return;
			}

			// If real visitor ALREADY explicitly chose this regional site previously, automatically redirect them!
			const savedManualCountry = getCookie('grr_user_manual_country');
			if (!isTestMode && savedManualCountry && savedManualCountry === data.country) {
				console.log('[GRR] Visitor previously selected ' + savedManualCountry + '. Automatically redirecting to: ' + data.target_url);
				window.location.replace(data.target_url);
				return;
			}

			renderPrompt(data);
		})
		.catch(function (error) {
			console.warn('[GRR] Dynamic Geo check skipped:', error.message);
		});
	}

	/**
	 * Render the prompt UI.
	 */
	function renderPrompt(data) {
		const style = grrPromptConfig.style || 'card';
		const i18n = grrPromptConfig.i18n || {};

		// Create backdrop if modal
		let backdrop = null;
		if (style === 'modal') {
			backdrop = document.createElement('div');
			backdrop.className = 'grr-modal-backdrop';
			document.body.appendChild(backdrop);
		}

		// Create wrapper
		const wrapper = document.createElement('div');
		wrapper.className = 'grr-prompt-wrapper grr-style-' + style;
		wrapper.setAttribute('role', 'dialog');
		wrapper.setAttribute('aria-modal', 'true');
		wrapper.setAttribute('aria-label', 'Regional Website Selection');

		const visitingFrom = (i18n.visitingFrom || 'Visiting from %s?').replace('%s', data.country_name);
		const message = (i18n.message || 'We noticed you are visiting from %1$s. Would you like to switch to our %2$s site?')
			.replace('%1$s', data.country_name)
			.replace('%2$s', data.target_label || data.country_name);
		const switchText = (i18n.switchBtn || 'Switch to %s').replace('%s', data.target_label || data.country_name);
		const stayText = i18n.stayBtn || 'Stay on this site';

		let countdownHtml = '';
		const countdownSeconds = parseInt(grrPromptConfig.countdown, 10) || 0;
		if (countdownSeconds > 0) {
			countdownHtml = `
				<div class="grr-countdown-bar-wrap">
					<div class="grr-countdown-bar" id="grrCountdownBar" style="width: 100%;"></div>
				</div>
				<div class="grr-countdown-text">
					<span id="grrCountdownLabel">${(i18n.redirecting || 'Redirecting in %d seconds…').replace('%d', countdownSeconds)}</span>
					<button type="button" class="grr-countdown-cancel" id="grrCountdownCancel">${i18n.cancel || 'Cancel'}</button>
				</div>
			`;
		}

		wrapper.innerHTML = `
			<div class="grr-prompt-inner">
				<div class="grr-prompt-header">
					<div class="grr-prompt-badge">
						<span class="grr-prompt-flag">${data.flag || '🌐'}</span>
						<span>${visitingFrom}</span>
					</div>
					<button type="button" class="grr-prompt-close" id="grrPromptClose" aria-label="Close">✕</button>
				</div>
				<div class="grr-prompt-body">
					${message}
					${countdownHtml}
				</div>
				<div class="grr-prompt-actions">
					<a href="${data.target_url}" class="grr-btn-switch" id="grrBtnSwitch">
						${switchText} →
					</a>
					<button type="button" class="grr-btn-stay" id="grrBtnStay">
						${stayText}
					</button>
				</div>
			</div>
		`;

		document.body.appendChild(wrapper);

		// Immediately mark session as shown so subsequent page visits won't show it again
		try {
			sessionStorage.setItem('grr_session_shown', '1');
		} catch (e) {}
		setCookie('grr_session_shown', '1', 0);

		const cookieTtlDays = parseInt(grrPromptConfig.cookieTtl, 10) || 7;

		// Cleanup handler: If visitor does not choose an option, assume they want to stay on this website
		function dismissPrompt(rememberDismissal) {
			console.log('[GRR] Dismissing prompt (fade out)...');
			try {
				sessionStorage.setItem('grr_session_shown', '1');
			} catch (e) {}
			setCookie('grr_session_shown', '1', 0);

			// Always persist choice to stay on this website for configured TTL (default 7 days)
			setCookie('grr_choice_dismissed', '1', cookieTtlDays);

			wrapper.classList.add('grr-fade-out');
			if (backdrop) {
				backdrop.classList.add('grr-fade-out');
			}

			setTimeout(function () {
				if (wrapper.parentNode) {
					wrapper.parentNode.removeChild(wrapper);
				}
				if (backdrop && backdrop.parentNode) {
					backdrop.parentNode.removeChild(backdrop);
				}
			}, 420);
		}

		// Auto-redirect Countdown vs Auto-Hide handling
		if (countdownSeconds > 0) {
			// Mode A: Auto-redirect countdown is active
			let remaining = countdownSeconds;
			const bar = wrapper.querySelector('#grrCountdownBar');
			const label = wrapper.querySelector('#grrCountdownLabel');
			const cancelBtn = wrapper.querySelector('#grrCountdownCancel');

			if (bar) {
				bar.style.transition = 'width ' + countdownSeconds + 's linear';
				setTimeout(function () {
					bar.style.width = '0%';
				}, 50);
			}

			const interval = setInterval(function () {
				remaining -= 1;
				if (label) {
					label.textContent = (i18n.redirecting || 'Redirecting in %d seconds…').replace('%d', remaining);
				}

				if (remaining <= 0) {
					clearInterval(interval);
					setCookie('grr_user_manual_country', data.country, cookieTtlDays);
					setCookie('grr_visitor_country', data.country, cookieTtlDays);
					window.location.href = data.target_url;
				}
			}, 1000);

			if (cancelBtn) {
				cancelBtn.addEventListener('click', function () {
					clearInterval(interval);
					// Visitor explicitly cancelled auto-redirect -> assume they want to stay
					dismissPrompt(true);
				});
			}
		} else {
			// Mode B: Normal prompt -> Auto-Hide if visitor ignores option (assume stay)
			let rawAutoHide = parseInt(grrPromptConfig.autoHide, 10);
			let autoHideMs = 7000;
			if (rawAutoHide > 100) {
				autoHideMs = rawAutoHide; // Already in milliseconds (e.g. 7000)
			} else if (rawAutoHide > 0) {
				autoHideMs = rawAutoHide * 1000; // In seconds (e.g. 7)
			}

			console.log('[GRR] Prompt displayed. Auto-hide timer set for ' + (autoHideMs / 1000) + 's.');

			setTimeout(function () {
				console.log('[GRR] Auto-hide timer expired (' + (autoHideMs / 1000) + 's). Visitor chose to stay on this website.');
				dismissPrompt(true);
			}, autoHideMs);
		}

		// Action: Switch button click
		const switchBtn = wrapper.querySelector('#grrBtnSwitch');
		if (switchBtn) {
			switchBtn.addEventListener('click', function (e) {
				e.preventDefault();
				setCookie('grr_user_manual_country', data.country, cookieTtlDays);
				setCookie('grr_visitor_country', data.country, cookieTtlDays);
				window.location.href = data.target_url;
			});
		}

		// Action: Stay button click
		const stayBtn = wrapper.querySelector('#grrBtnStay');
		if (stayBtn) {
			stayBtn.addEventListener('click', function () {
				dismissPrompt(true);
			});
		}

		// Action: Close "X" button click
		const closeBtn = wrapper.querySelector('#grrPromptClose');
		if (closeBtn) {
			closeBtn.addEventListener('click', function () {
				dismissPrompt(true);
			});
		}

		// Escape key dismissal for accessibility
		document.addEventListener('keydown', function escHandler(e) {
			if (e.key === 'Escape' || e.keyCode === 27) {
				dismissPrompt(true);
				document.removeEventListener('keydown', escHandler);
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
