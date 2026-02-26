/**
 * Settings page script for dynamic field states, REST-based operations,
 * and auto-fetching conversion action details.
 *
 * @package Kntnt\Ad_Attribution_Gads
 * @since   1.6.0
 */
(function () {
	'use strict';

	// ─── DOM References ───

	const fields = {
		loginCustomerId:         document.getElementById('login_customer_id'),
		customerId:              document.getElementById('customer_id'),
		developerToken:          document.getElementById('developer_token'),
		clientId:                document.getElementById('client_id'),
		clientSecret:            document.getElementById('client_secret'),
		refreshToken:            document.getElementById('refresh_token'),
		conversionActionId:      document.getElementById('conversion_action_id'),
		conversionActionName:    document.getElementById('conversion_action_name'),
		conversionActionCategory: document.getElementById('conversion_action_category'),
		conversionValue:         document.getElementById('conversion_value'),
		currencyCode:            document.getElementById('currency_code'),
	};

	const testButton   = document.getElementById('kntnt-ad-attr-gads-test-connection');
	const testResult   = document.getElementById('kntnt-ad-attr-gads-test-result');
	const createButton = document.getElementById('kntnt-ad-attr-gads-create-conversion-action');
	const createResult = document.getElementById('kntnt-ad-attr-gads-create-result');

	// ─── Helpers ───

	/**
	 * Collects the 6 API credential values from the form.
	 *
	 * @returns {Object} Credential key-value pairs.
	 */
	const getCredentials = () => ({
		login_customer_id: fields.loginCustomerId?.value ?? '',
		customer_id:       fields.customerId?.value ?? '',
		developer_token:   fields.developerToken?.value ?? '',
		client_id:         fields.clientId?.value ?? '',
		client_secret:     fields.clientSecret?.value ?? '',
		refresh_token:     fields.refreshToken?.value ?? '',
	});

	/**
	 * Checks whether all 5 required auth fields have non-empty values.
	 * login_customer_id is excluded (optional).
	 *
	 * @returns {boolean} True when all required credentials are filled.
	 */
	const hasAuthCredentials = () => {
		const creds = getCredentials();
		return creds.customer_id !== ''
			&& creds.developer_token !== ''
			&& creds.client_id !== ''
			&& creds.client_secret !== ''
			&& creds.refresh_token !== '';
	};

	/**
	 * Sends a POST request to a plugin REST endpoint.
	 *
	 * @param {string} route  Route path (e.g. '/test-connection').
	 * @param {Object} data   Request body.
	 * @returns {Promise<Object>} Parsed JSON response.
	 */
	const restPost = async (route, data) => {
		const response = await fetch(kntntAdAttrGads.restUrl + route, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   kntntAdAttrGads.restNonce,
			},
			credentials: 'same-origin',
			body: JSON.stringify(data),
		});
		return response.json();
	};

	// ─── Dynamic Button/Field States ───

	/**
	 * Updates enabled/disabled states based on current field values.
	 */
	const updateButtonStates = () => {
		const hasAuth = hasAuthCredentials();
		const hasId   = (fields.conversionActionId?.value ?? '') !== '';
		const hasName = (fields.conversionActionName?.value ?? '') !== '';

		// Test Connection: enabled when all required auth fields are filled.
		if (testButton) {
			testButton.disabled = !hasAuth;
		}

		// Create: enabled when auth + name filled + no ID yet.
		if (createButton) {
			createButton.disabled = !(hasAuth && hasName && !hasId);
		}

		// Name and category fields: disabled when an ID exists.
		if (fields.conversionActionName) {
			fields.conversionActionName.disabled = hasId;
		}
		if (fields.conversionActionCategory) {
			fields.conversionActionCategory.disabled = hasId;
		}
	};

	// Listen for input changes on all relevant fields.
	const watchedFields = [
		fields.customerId, fields.developerToken, fields.clientId,
		fields.clientSecret, fields.refreshToken, fields.conversionActionId,
		fields.conversionActionName,
	];

	for (const field of watchedFields) {
		field?.addEventListener('input', updateButtonStates);
	}

	// Set initial states.
	updateButtonStates();

	// ─── Auto-Fetch Conversion Action Details ───

	const autoFetch = async () => {
		const actionId = fields.conversionActionId?.value ?? '';
		if (actionId === '' || !hasAuthCredentials()) {
			return;
		}

		try {
			const data = await restPost('/fetch-conversion-action', {
				...getCredentials(),
				conversion_action_id: actionId,
			});

			if (data.success) {
				if (fields.conversionActionName && data.conversion_action_name) {
					fields.conversionActionName.value = data.conversion_action_name;
				}
				if (fields.conversionActionCategory && data.conversion_action_category) {
					fields.conversionActionCategory.value = data.conversion_action_category;
				}
				updateButtonStates();
			}
		} catch {
			// Silent failure — fields remain as rendered from saved values.
		}
	};

	autoFetch();

	// ─── Test Connection ───

	if (testButton && testResult) {
		testButton.addEventListener('click', async () => {
			testButton.disabled = true;
			testResult.textContent = kntntAdAttrGads.testing || 'Testing\u2026';
			testResult.style.color = '';

			try {
				const data = await restPost('/test-connection', {
					...getCredentials(),
					conversion_action_id: fields.conversionActionId?.value ?? '',
				});

				if (data.success) {
					testResult.textContent = data.message;
					testResult.style.color = '#00a32a';
				} else {
					testResult.textContent = data.message || 'Connection failed.';
					testResult.style.color = '#d63638';
				}
			} catch {
				testResult.textContent = 'Network error.';
				testResult.style.color = '#d63638';
			}

			testButton.disabled = !hasAuthCredentials();
		});
	}

	// ─── Create Conversion Action ───

	if (createButton && createResult) {
		createButton.addEventListener('click', async () => {
			createButton.disabled = true;
			createResult.textContent = 'Creating\u2026';
			createResult.style.color = '';

			try {
				const data = await restPost('/create-conversion-action', {
					...getCredentials(),
					conversion_action_name:     fields.conversionActionName?.value ?? '',
					conversion_action_category: fields.conversionActionCategory?.value ?? 'SUBMIT_LEAD_FORM',
					conversion_value:           fields.conversionValue?.value ?? '0',
					currency_code:              fields.currencyCode?.value ?? 'SEK',
				});

				if (data.success) {
					createResult.textContent = data.message;
					createResult.style.color = '#00a32a';

					// Auto-fill the conversion action ID field.
					if (fields.conversionActionId && data.conversion_action_id) {
						fields.conversionActionId.value = data.conversion_action_id;
					}
				} else {
					createResult.textContent = data.message || 'Creation failed.';
					createResult.style.color = '#d63638';
				}
			} catch {
				createResult.textContent = 'Network error.';
				createResult.style.color = '#d63638';
			}

			updateButtonStates();
		});
	}
})();
