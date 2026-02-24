/**
 * Settings page script for the test connection button.
 *
 * @package Kntnt\Ad_Attribution_Gads
 * @since   0.4.0
 */
(function () {
	'use strict';

	const button = document.getElementById('kntnt-ad-attr-gads-test-connection');
	const result = document.getElementById('kntnt-ad-attr-gads-test-result');

	if (!button || !result) {
		return;
	}

	button.addEventListener('click', async () => {

		// Disable button and show progress.
		button.disabled = true;
		result.textContent = kntntAdAttrGads.testing || 'Testing\u2026';
		result.style.color = '';

		const body = new URLSearchParams({
			action: kntntAdAttrGads.action,
			nonce: kntntAdAttrGads.nonce,
		});

		try {
			const response = await fetch(kntntAdAttrGads.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body,
			});

			const data = await response.json();

			if (data.success) {
				result.textContent = data.data.message;
				result.style.color = '#00a32a';
			} else {
				result.textContent = data.data?.message || 'Connection failed.';
				result.style.color = '#d63638';
			}
		} catch {
			result.textContent = 'Network error.';
			result.style.color = '#d63638';
		}

		button.disabled = false;
	});
})();
