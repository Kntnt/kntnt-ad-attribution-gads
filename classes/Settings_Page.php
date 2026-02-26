<?php
/**
 * WordPress settings page for Google Ads API configuration.
 *
 * Registers an options page under Settings > Google Ads Attribution with
 * four sections: API Credentials, Conversion Action, Conversion Defaults,
 * and Diagnostic Log. Includes REST endpoints for test connection, create
 * conversion action, and fetch conversion action details.
 *
 * @package Kntnt\Ad_Attribution_Gads
 * @since   0.2.0
 */

declare( strict_types = 1 );

namespace Kntnt\Ad_Attribution_Gads;

/**
 * Admin settings page for Google Ads API credentials and conversion defaults.
 *
 * Uses the WordPress Settings API for registration, rendering, and
 * sanitization. The page is added under the Settings menu via
 * `add_options_page()` and requires the `manage_options` capability.
 * REST endpoints handle test connection, create conversion action, and
 * fetch conversion action details using current form values.
 *
 * @since 0.2.0
 */
final class Settings_Page {

	/**
	 * Settings page slug.
	 *
	 * @var string
	 * @since 0.2.0
	 */
	private const PAGE_SLUG = 'kntnt-ad-attr-gads';

	/**
	 * Settings group name for the Settings API.
	 *
	 * @var string
	 * @since 0.2.0
	 */
	private const SETTINGS_GROUP = 'kntnt_ad_attr_gads_group';

	/**
	 * Section ID for API credentials fields.
	 *
	 * @var string
	 * @since 1.6.0
	 */
	private const SECTION_API_CREDENTIALS = 'kntnt_ad_attr_gads_api_credentials';

	/**
	 * Section ID for conversion action fields.
	 *
	 * @var string
	 * @since 1.6.0
	 */
	private const SECTION_CONVERSION_ACTION = 'kntnt_ad_attr_gads_conversion_action';

	/**
	 * Section ID for conversion defaults fields.
	 *
	 * @var string
	 * @since 1.6.0
	 */
	private const SECTION_CONVERSION_DEFAULTS = 'kntnt_ad_attr_gads_conversion_defaults';

	/**
	 * REST API namespace for plugin endpoints.
	 *
	 * @var string
	 * @since 1.6.0
	 */
	private const REST_NAMESPACE = 'kntnt-ad-attr-gads/v1';

	/**
	 * Google Ads ConversionActionCategory enum values.
	 *
	 * @var array<string,string>
	 * @since 1.5.0
	 */
	private const CONVERSION_ACTION_CATEGORIES = [
		'SUBMIT_LEAD_FORM' => 'Submit lead form',
		'IMPORTED_LEAD'    => 'Imported lead',
		'QUALIFIED_LEAD'   => 'Qualified lead',
		'CONVERTED_LEAD'   => 'Converted lead',
		'PHONE_CALL_LEAD'  => 'Phone call lead',
		'DEFAULT'          => 'Default',
		'PURCHASE'         => 'Purchase',
		'SIGNUP'           => 'Sign-up',
		'PAGE_VIEW'        => 'Page view',
		'DOWNLOAD'         => 'Download',
		'ADD_TO_CART'      => 'Add to cart',
		'BEGIN_CHECKOUT'   => 'Begin checkout',
		'SUBSCRIBE_PAID'   => 'Subscribe (paid)',
		'BOOK_APPOINTMENT' => 'Book appointment',
		'REQUEST_QUOTE'    => 'Request quote',
		'GET_DIRECTIONS'   => 'Get directions',
		'OUTBOUND_CLICK'   => 'Outbound click',
		'CONTACT'          => 'Contact',
		'ENGAGEMENT'       => 'Engagement',
		'STORE_VISIT'      => 'Store visit',
		'STORE_SALE'       => 'Store sale',
	];

	/**
	 * ISO 4217 currency codes supported by Google Ads.
	 *
	 * @var string[]
	 * @since 0.2.0
	 */
	private const CURRENCY_CODES = [
		'AED', 'ARS', 'AUD', 'BGN', 'BHD', 'BND', 'BOB', 'BRL', 'CAD',
		'CHF', 'CLP', 'CNY', 'COP', 'CZK', 'DKK', 'EGP', 'EUR', 'GBP',
		'HKD', 'HRK', 'HUF', 'IDR', 'ILS', 'INR', 'ISK', 'JPY', 'KRW',
		'KWD', 'LKR', 'MAD', 'MXN', 'MYR', 'NGN', 'NOK', 'NZD', 'PEN',
		'PHP', 'PKR', 'PLN', 'QAR', 'RON', 'RUB', 'SAR', 'SEK', 'SGD',
		'THB', 'TRY', 'TWD', 'UAH', 'USD', 'VND', 'ZAR',
	];

	/**
	 * Settings instance for reading/writing settings.
	 *
	 * @var Settings
	 * @since 0.2.0
	 */
	private readonly Settings $settings;

	/**
	 * Hook suffix returned by add_options_page(), used for script enqueuing.
	 *
	 * @var string|null
	 * @since 0.4.0
	 */
	private ?string $page_hook = null;

	/**
	 * Constructs the settings page and registers admin hooks.
	 *
	 * @param Settings $settings Settings instance for data access.
	 *
	 * @since 0.2.0
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;

		// Register hooks only in admin context.
		if ( is_admin() ) {
			add_action( 'admin_menu', [ $this, 'add_page' ] );
			add_action( 'admin_init', [ $this, 'register_settings' ] );
			add_action( 'admin_notices', [ $this, 'display_credential_notice' ] );
			add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_scripts' ] );
		}

		// REST routes are registered outside is_admin() — REST API runs outside admin context.
		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );
	}

	/**
	 * Registers REST API routes for settings page operations.
	 *
	 * All routes use current form values (POST params) instead of saved
	 * settings so users can test before saving.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	public function register_rest_routes(): void {
		$permission = fn() => current_user_can( 'manage_options' );

		register_rest_route( self::REST_NAMESPACE, '/test-connection', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_test_connection' ],
			'permission_callback' => $permission,
		] );

		register_rest_route( self::REST_NAMESPACE, '/create-conversion-action', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_create_conversion_action' ],
			'permission_callback' => $permission,
		] );

		register_rest_route( self::REST_NAMESPACE, '/fetch-conversion-action', [
			'methods'             => 'POST',
			'callback'            => [ $this, 'handle_fetch_conversion_action' ],
			'permission_callback' => $permission,
		] );
	}

	/**
	 * Displays an admin notice when a credential error has been flagged.
	 *
	 * Shows a persistent error notice on all admin pages with a link
	 * to the settings page. Only visible to users with `manage_options`.
	 *
	 * @return void
	 * @since 0.5.0
	 */
	public function display_credential_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! get_transient( Conversion_Reporter::CREDENTIAL_ERROR_TRANSIENT ) ) {
			return;
		}

		$settings_url = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );

		printf(
			'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
			wp_kses(
				sprintf(
					/* translators: %s: URL to the Google Ads Attribution settings page */
					__( 'Google Ads conversion uploads are failing due to invalid or missing credentials. Please check your <a href="%s">Google Ads Attribution settings</a>.', 'kntnt-ad-attr-gads' ),
					esc_url( $settings_url ),
				),
				[ 'a' => [ 'href' => [] ] ],
			),
		);
	}

	/**
	 * Adds the settings page under the WordPress Settings menu.
	 *
	 * @return void
	 * @since 0.2.0
	 */
	public function add_page(): void {
		$this->page_hook = add_options_page(
			__( 'Google Ads Attribution', 'kntnt-ad-attr-gads' ),
			__( 'Google Ads Attribution', 'kntnt-ad-attr-gads' ),
			'manage_options',
			self::PAGE_SLUG,
			[ $this, 'render_page' ],
		);
	}

	/**
	 * Registers settings, sections, and fields with the Settings API.
	 *
	 * @return void
	 * @since 0.2.0
	 */
	public function register_settings(): void {

		// Register the single option with sanitization callback.
		register_setting(
			self::SETTINGS_GROUP,
			Settings::OPTION_KEY,
			[
				'type'              => 'array',
				'sanitize_callback' => [ $this, 'sanitize_settings' ],
			],
		);

		// API Credentials section.
		add_settings_section(
			self::SECTION_API_CREDENTIALS,
			__( 'API Credentials', 'kntnt-ad-attr-gads' ),
			fn() => printf(
				'<p>%s</p>',
				wp_kses(
					sprintf(
						/* translators: %s: URL to the configuration guide on GitHub */
						__( 'Enter your Google Ads API credentials. All fields are required. See the <a href="%s" target="_blank" rel="noopener noreferrer">configuration guide</a> for step-by-step instructions.', 'kntnt-ad-attr-gads' ),
						'https://github.com/Kntnt/kntnt-ad-attribution-gads#configuration-guide',
					),
					[ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ],
				),
			),
			self::PAGE_SLUG,
		);

		// Conversion Action section.
		add_settings_section(
			self::SECTION_CONVERSION_ACTION,
			__( 'Conversion Action', 'kntnt-ad-attr-gads' ),
			fn() => printf(
				'<p>%s</p>',
				esc_html__( 'Configure or create the conversion action used for offline conversion uploads.', 'kntnt-ad-attr-gads' ),
			),
			self::PAGE_SLUG,
		);

		// Conversion Defaults section.
		add_settings_section(
			self::SECTION_CONVERSION_DEFAULTS,
			__( 'Conversion Defaults', 'kntnt-ad-attr-gads' ),
			fn() => printf(
				'<p>%s</p>',
				esc_html__( 'Default values for offline conversion uploads.', 'kntnt-ad-attr-gads' ),
			),
			self::PAGE_SLUG,
		);

		// Register individual fields.
		$this->add_api_credential_fields();
		$this->add_conversion_action_fields();
		$this->add_conversion_default_fields();
	}

	/**
	 * Sanitizes settings before they are saved.
	 *
	 * Disabled fields (conversion_action_name/category) are not submitted by
	 * the browser, so their previous values are preserved from the database.
	 *
	 * @param mixed $input Raw form input.
	 *
	 * @return array<string,string> Sanitized settings.
	 * @since 0.2.0
	 */
	public function sanitize_settings( mixed $input ): array {
		$input = is_array( $input ) ? $input : [];
		$clean = [];

		// Trim all string values.
		foreach ( $input as $key => $value ) {
			$clean[ $key ] = is_string( $value ) ? trim( $value ) : (string) $value;
		}

		// Strip dashes from customer IDs (users often paste "123-456-7890").
		if ( isset( $clean['customer_id'] ) ) {
			$clean['customer_id'] = str_replace( '-', '', $clean['customer_id'] );
		}
		if ( isset( $clean['login_customer_id'] ) ) {
			$clean['login_customer_id'] = str_replace( '-', '', $clean['login_customer_id'] );
		}

		// Validate conversion_value as non-negative float.
		if ( isset( $clean['conversion_value'] ) ) {
			$value = filter_var( $clean['conversion_value'], FILTER_VALIDATE_FLOAT );
			$clean['conversion_value'] = ( $value !== false && $value >= 0 ) ? (string) $value : '0';
		}

		// Preserve disabled fields from the database (browser doesn't submit them).
		if ( ! empty( $clean['conversion_action_id'] ) ) {
			$existing = $this->settings->get_all();
			$clean['conversion_action_name']     ??= $existing['conversion_action_name'];
			$clean['conversion_action_category'] ??= $existing['conversion_action_category'];
		}

		return $clean;
	}

	/**
	 * Renders the settings page HTML.
	 *
	 * Uses per-section rendering to control the layout. A single Save
	 * button at the end saves all sections. The Test Connection button
	 * is rendered after the Save button, disabled by default.
	 *
	 * @return void
	 * @since 0.2.0
	 */
	public function render_page(): void {
		?>
		<style>
			.kntnt-ad-attr-gads-hint { font-size: 12px; color: #999; }
			.kntnt-ad-attr-gads-hint:hover { color: #646970; }
		</style>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::SETTINGS_GROUP );
				$this->render_section( self::SECTION_API_CREDENTIALS );
				$this->render_section( self::SECTION_CONVERSION_ACTION );
				$this->render_section( self::SECTION_CONVERSION_DEFAULTS );
				$this->render_test_connection_button();
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/**
	 * Enqueues the settings page JavaScript on our admin page only.
	 *
	 * @param string $hook The current admin page hook suffix.
	 *
	 * @return void
	 * @since 0.4.0
	 */
	public function enqueue_scripts( string $hook ): void {

		// Only load on our settings page.
		if ( $hook !== $this->page_hook ) {
			return;
		}

		wp_enqueue_script(
			'kntnt-ad-attr-gads-settings',
			plugins_url( 'js/settings-page.js', dirname( __FILE__ ) ),
			[],
			Plugin::get_version(),
			true,
		);

		wp_localize_script( 'kntnt-ad-attr-gads-settings', 'kntntAdAttrGads', [
			'restUrl'   => rest_url( self::REST_NAMESPACE ),
			'restNonce' => wp_create_nonce( 'wp_rest' ),
			'testing'   => __( 'Testing…', 'kntnt-ad-attr-gads' ),
		] );
	}

	/**
	 * Handles the REST test connection request.
	 *
	 * Reads credentials from the request body (current form values) and
	 * verifies them against the Google Ads API.
	 *
	 * @param \WP_REST_Request $request REST request with form values.
	 *
	 * @return \WP_REST_Response Test result.
	 * @since 1.6.0
	 */
	public function handle_test_connection( \WP_REST_Request $request ): \WP_REST_Response {
		$credentials = $this->extract_credentials( $request->get_params() );

		// Validate required credentials.
		$required = [ 'customer_id', 'developer_token', 'client_id', 'client_secret', 'refresh_token' ];
		foreach ( $required as $field ) {
			if ( empty( $credentials[ $field ] ) ) {
				return new \WP_REST_Response( [
					'success' => false,
					'message' => __( 'Please fill in all required API credentials first.', 'kntnt-ad-attr-gads' ),
				], 400 );
			}
		}

		$conversion_action_id = sanitize_text_field( wp_unslash( $request->get_param( 'conversion_action_id' ) ?? '' ) );

		$client = new Google_Ads_Client(
			customer_id: $credentials['customer_id'],
			developer_token: $credentials['developer_token'],
			client_id: $credentials['client_id'],
			client_secret: $credentials['client_secret'],
			refresh_token: $credentials['refresh_token'],
			login_customer_id: $credentials['login_customer_id'],
			conversion_action_id: $conversion_action_id,
			logger: \Kntnt\Ad_Attribution\Plugin::get_instance()->logger,
		);

		$result = $client->test_connection();

		if ( $result['success'] ) {
			$message = ( $result['conversion_action_name'] ?? '' ) !== ''
				? sprintf(
					/* translators: %s: Name of the verified conversion action in Google Ads */
					__( 'Connection successful! All credentials verified. Conversion action: %s', 'kntnt-ad-attr-gads' ),
					$result['conversion_action_name'],
				)
				: __( 'Connection successful! OAuth2 token refresh succeeded.', 'kntnt-ad-attr-gads' );

			return new \WP_REST_Response( [ 'success' => true, 'message' => $message ] );
		}

		// Include masked diagnostics to aid troubleshooting.
		$diagnostics = sprintf(
			"\n\nDiagnostics: client_id=%s | client_secret=%s | refresh_token=%s | customer_id=%s | developer_token=%s | login_customer_id=%s | conversion_action_id=%s\n\nGoogle response: %s",
			$credentials['client_id'],
			self::mask( $credentials['client_secret'] ),
			self::mask( $credentials['refresh_token'] ),
			$credentials['customer_id'],
			self::mask( $credentials['developer_token'] ),
			$credentials['login_customer_id'] ?: '(empty)',
			$conversion_action_id,
			$result['debug'] ?? 'N/A',
		);

		return new \WP_REST_Response( [
			'success' => false,
			'message' => $result['error'] . $diagnostics,
		], 200 );
	}

	/**
	 * Handles the REST create conversion action request.
	 *
	 * Reads credentials and conversion action details from the request
	 * body and creates the action in Google Ads. Saves the resulting ID.
	 *
	 * @param \WP_REST_Request $request REST request with form values.
	 *
	 * @return \WP_REST_Response Create result.
	 * @since 1.6.0
	 */
	public function handle_create_conversion_action( \WP_REST_Request $request ): \WP_REST_Response {
		$credentials = $this->extract_credentials( $request->get_params() );

		// Validate required credentials.
		$required = [ 'customer_id', 'developer_token', 'client_id', 'client_secret', 'refresh_token' ];
		foreach ( $required as $field ) {
			if ( empty( $credentials[ $field ] ) ) {
				return new \WP_REST_Response( [
					'success' => false,
					'message' => __( 'Please fill in all required API credentials first.', 'kntnt-ad-attr-gads' ),
				], 400 );
			}
		}

		// Read conversion action details from request.
		$name     = sanitize_text_field( wp_unslash( $request->get_param( 'conversion_action_name' ) ?? '' ) );
		$category = sanitize_text_field( wp_unslash( $request->get_param( 'conversion_action_category' ) ?? 'SUBMIT_LEAD_FORM' ) );
		$value    = (float) ( $request->get_param( 'conversion_value' ) ?? 0 );
		$currency = sanitize_text_field( wp_unslash( $request->get_param( 'currency_code' ) ?? 'SEK' ) );

		if ( $name === '' ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => __( 'Please enter a Conversion Action Name.', 'kntnt-ad-attr-gads' ),
			], 400 );
		}

		$client = new Google_Ads_Client(
			customer_id: $credentials['customer_id'],
			developer_token: $credentials['developer_token'],
			client_id: $credentials['client_id'],
			client_secret: $credentials['client_secret'],
			refresh_token: $credentials['refresh_token'],
			login_customer_id: $credentials['login_customer_id'],
			logger: \Kntnt\Ad_Attribution\Plugin::get_instance()->logger,
		);

		$result = $client->create_conversion_action(
			name: $name,
			default_value: $value,
			currency_code: $currency,
			category: $category,
		);

		if ( $result['success'] ) {

			// Save the new conversion action ID to settings.
			$this->settings->update( [
				'conversion_action_id'       => $result['conversion_action_id'],
				'conversion_action_name'     => $name,
				'conversion_action_category' => $category,
			] );

			return new \WP_REST_Response( [
				'success'              => true,
				'message'              => sprintf(
					/* translators: 1: Conversion action name, 2: Conversion action ID */
					__( 'Conversion action "%1$s" created successfully (ID: %2$s).', 'kntnt-ad-attr-gads' ),
					$name,
					$result['conversion_action_id'],
				),
				'conversion_action_id' => $result['conversion_action_id'],
			] );
		}

		return new \WP_REST_Response( [ 'success' => false, 'message' => $result['error'] ], 200 );
	}

	/**
	 * Handles the REST fetch conversion action details request.
	 *
	 * Reads credentials and conversion action ID from the request body
	 * and fetches name and category from the Google Ads API.
	 *
	 * @param \WP_REST_Request $request REST request with form values.
	 *
	 * @return \WP_REST_Response Fetch result with name and category.
	 * @since 1.6.0
	 */
	public function handle_fetch_conversion_action( \WP_REST_Request $request ): \WP_REST_Response {
		$credentials = $this->extract_credentials( $request->get_params() );

		// Validate required credentials.
		$required = [ 'customer_id', 'developer_token', 'client_id', 'client_secret', 'refresh_token' ];
		foreach ( $required as $field ) {
			if ( empty( $credentials[ $field ] ) ) {
				return new \WP_REST_Response( [
					'success' => false,
					'message' => __( 'Please fill in all required API credentials first.', 'kntnt-ad-attr-gads' ),
				], 400 );
			}
		}

		$conversion_action_id = sanitize_text_field( wp_unslash( $request->get_param( 'conversion_action_id' ) ?? '' ) );
		if ( $conversion_action_id === '' ) {
			return new \WP_REST_Response( [
				'success' => false,
				'message' => __( 'Conversion Action ID is required.', 'kntnt-ad-attr-gads' ),
			], 400 );
		}

		$client = new Google_Ads_Client(
			customer_id: $credentials['customer_id'],
			developer_token: $credentials['developer_token'],
			client_id: $credentials['client_id'],
			client_secret: $credentials['client_secret'],
			refresh_token: $credentials['refresh_token'],
			login_customer_id: $credentials['login_customer_id'],
			logger: \Kntnt\Ad_Attribution\Plugin::get_instance()->logger,
		);

		$result = $client->fetch_conversion_action_details( $conversion_action_id );

		if ( $result['success'] ) {
			return new \WP_REST_Response( [
				'success'                    => true,
				'conversion_action_name'     => $result['conversion_action_name'],
				'conversion_action_category' => $result['conversion_action_category'],
			] );
		}

		return new \WP_REST_Response( [ 'success' => false, 'message' => $result['error'] ], 200 );
	}

	/**
	 * Registers API credential fields.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	private function add_api_credential_fields(): void {
		$guide = 'https://github.com/Kntnt/kntnt-ad-attribution-gads#';

		$fields = [
			'login_customer_id'    => [
				'label'       => __( 'Manager Account ID', 'kntnt-ad-attr-gads' ),
				'type'        => 'text',
				'description' => sprintf(
					/* translators: %s: URL to the configuration guide section */
					__( 'Your Manager Account (MCC) customer ID.<br><a href="%s" target="_blank" rel="noopener noreferrer" class="kntnt-ad-attr-gads-hint">Configuration guide</a>', 'kntnt-ad-attr-gads' ),
					$guide . 'login-customer-id-mcc',
				),
			],
			'customer_id'          => [
				'label'       => __( 'Customer ID', 'kntnt-ad-attr-gads' ),
				'type'        => 'text',
				'description' => sprintf(
					/* translators: %s: URL to the configuration guide section */
					__( '10-digit Google Ads Customer ID (dashes are removed automatically).<br><a href="%s" target="_blank" rel="noopener noreferrer" class="kntnt-ad-attr-gads-hint">Configuration guide</a>', 'kntnt-ad-attr-gads' ),
					$guide . 'customer-id',
				),
			],
			'developer_token'      => [
				'label'       => __( 'Developer Token', 'kntnt-ad-attr-gads' ),
				'type'        => 'text',
				'description' => sprintf(
					/* translators: %s: URL to the configuration guide section */
					__( '<a href="%s" target="_blank" rel="noopener noreferrer" class="kntnt-ad-attr-gads-hint">Configuration guide</a>', 'kntnt-ad-attr-gads' ),
					$guide . 'developer-token',
				),
			],
			'client_id'            => [
				'label'       => __( 'OAuth2 Client ID', 'kntnt-ad-attr-gads' ),
				'type'        => 'text',
				'description' => sprintf(
					/* translators: %s: URL to the configuration guide section */
					__( '<a href="%s" target="_blank" rel="noopener noreferrer" class="kntnt-ad-attr-gads-hint">Configuration guide</a>', 'kntnt-ad-attr-gads' ),
					$guide . 'oauth2-client-id-and-client-secret',
				),
			],
			'client_secret'        => [
				'label'       => __( 'OAuth2 Client Secret', 'kntnt-ad-attr-gads' ),
				'type'        => 'password',
				'description' => sprintf(
					/* translators: %s: URL to the configuration guide section */
					__( '<a href="%s" target="_blank" rel="noopener noreferrer" class="kntnt-ad-attr-gads-hint">Configuration guide</a>', 'kntnt-ad-attr-gads' ),
					$guide . 'oauth2-client-id-and-client-secret',
				),
			],
			'refresh_token'        => [
				'label'       => __( 'OAuth2 Refresh Token', 'kntnt-ad-attr-gads' ),
				'type'        => 'password',
				'description' => sprintf(
					/* translators: %s: URL to the configuration guide section */
					__( '<a href="%s" target="_blank" rel="noopener noreferrer" class="kntnt-ad-attr-gads-hint">Configuration guide</a>', 'kntnt-ad-attr-gads' ),
					$guide . 'oauth2-refresh-token',
				),
			],
		];

		foreach ( $fields as $key => $config ) {
			add_settings_field(
				$key,
				$config['label'],
				fn() => $this->render_text_field( $key, $config['type'], $config['description'] ?? '' ),
				self::PAGE_SLUG,
				self::SECTION_API_CREDENTIALS,
				[ 'label_for' => $key ],
			);
		}
	}

	/**
	 * Registers conversion action fields.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	private function add_conversion_action_fields(): void {
		$guide = 'https://github.com/Kntnt/kntnt-ad-attribution-gads#';

		// Conversion Action ID.
		add_settings_field(
			'conversion_action_id',
			__( 'Conversion Action ID', 'kntnt-ad-attr-gads' ),
			fn() => $this->render_text_field( 'conversion_action_id', 'text', sprintf(
				/* translators: %s: URL to the configuration guide section */
				__( 'Numeric ID of the conversion action.<br><a href="%s" target="_blank" rel="noopener noreferrer" class="kntnt-ad-attr-gads-hint">Configuration guide</a>', 'kntnt-ad-attr-gads' ),
				$guide . 'conversion-action-id',
			) ),
			self::PAGE_SLUG,
			self::SECTION_CONVERSION_ACTION,
			[ 'label_for' => 'conversion_action_id' ],
		);

		// Conversion Action Name (disabled when ID exists, auto-fetched from API).
		add_settings_field(
			'conversion_action_name',
			__( 'Conversion Action Name', 'kntnt-ad-attr-gads' ),
			fn() => $this->render_text_field( 'conversion_action_name', 'text', __( 'Fetched from Google Ads when an ID is set, or enter a name for a new action.', 'kntnt-ad-attr-gads' ) ),
			self::PAGE_SLUG,
			self::SECTION_CONVERSION_ACTION,
			[ 'label_for' => 'conversion_action_name' ],
		);

		// Conversion Action Category (disabled when ID exists, auto-fetched from API).
		add_settings_field(
			'conversion_action_category',
			__( 'Conversion Action Category', 'kntnt-ad-attr-gads' ),
			fn() => $this->render_category_select_field( 'conversion_action_category' ),
			self::PAGE_SLUG,
			self::SECTION_CONVERSION_ACTION,
			[ 'label_for' => 'conversion_action_category' ],
		);

		// Create conversion action button (not a form field — no name attribute).
		add_settings_field(
			'create_conversion_action',
			__( 'Create Conversion Action', 'kntnt-ad-attr-gads' ),
			[ $this, 'render_create_conversion_action_field' ],
			self::PAGE_SLUG,
			self::SECTION_CONVERSION_ACTION,
		);
	}

	/**
	 * Registers conversion default fields.
	 *
	 * @return void
	 * @since 0.2.0
	 */
	private function add_conversion_default_fields(): void {

		// Conversion value (number input).
		add_settings_field(
			'conversion_value',
			__( 'Default Conversion Value', 'kntnt-ad-attr-gads' ),
			fn() => $this->render_number_field( 'conversion_value' ),
			self::PAGE_SLUG,
			self::SECTION_CONVERSION_DEFAULTS,
			[ 'label_for' => 'conversion_value' ],
		);

		// Currency code (select).
		add_settings_field(
			'currency_code',
			__( 'Currency Code', 'kntnt-ad-attr-gads' ),
			fn() => $this->render_select_field( 'currency_code', self::CURRENCY_CODES ),
			self::PAGE_SLUG,
			self::SECTION_CONVERSION_DEFAULTS,
			[ 'label_for' => 'currency_code' ],
		);
	}

	/**
	 * Renders the test connection button and result area.
	 *
	 * Rendered outside the settings table, after the Save button.
	 * Disabled by default — enabled by JavaScript when all auth fields
	 * have values.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	private function render_test_connection_button(): void {
		printf(
			'<p><button type="button" id="kntnt-ad-attr-gads-test-connection" class="button button-secondary" disabled>%s</button> ',
			esc_html__( 'Test Connection', 'kntnt-ad-attr-gads' ),
		);
		echo '<span id="kntnt-ad-attr-gads-test-result"></span></p>';
	}

	/**
	 * Renders the create conversion action button and result area.
	 *
	 * Disabled by default — enabled by JavaScript when auth fields are
	 * filled, a name is entered, and no ID exists yet.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	public function render_create_conversion_action_field(): void {
		printf(
			'<button type="button" id="kntnt-ad-attr-gads-create-conversion-action" class="button button-secondary" disabled>%s</button> ',
			esc_html__( 'Create Conversion Action', 'kntnt-ad-attr-gads' ),
		);
		echo '<span id="kntnt-ad-attr-gads-create-result"></span>';
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Creates a new conversion action in Google Ads using the name and category above. The ID will be filled in automatically.', 'kntnt-ad-attr-gads' ),
		);
	}

	/**
	 * Renders a single settings section with its heading and fields.
	 *
	 * Replicates the output of `do_settings_sections()` but for a single
	 * section, allowing custom layout between sections.
	 *
	 * @param string $section_id Section ID to render.
	 *
	 * @return void
	 * @since 1.6.0
	 */
	private function render_section( string $section_id ): void {
		global $wp_settings_sections, $wp_settings_fields;

		if ( ! isset( $wp_settings_sections[ self::PAGE_SLUG ][ $section_id ] ) ) {
			return;
		}

		$section = $wp_settings_sections[ self::PAGE_SLUG ][ $section_id ];

		// Section heading.
		if ( $section['title'] ) {
			echo '<h2>' . esc_html( $section['title'] ) . '</h2>';
		}

		// Section callback (description text).
		if ( $section['callback'] ) {
			call_user_func( $section['callback'], $section );
		}

		// Fields table.
		if ( ! empty( $wp_settings_fields[ self::PAGE_SLUG ][ $section_id ] ) ) {
			echo '<table class="form-table" role="presentation">';
			do_settings_fields( self::PAGE_SLUG, $section_id );
			echo '</table>';
		}
	}

	/**
	 * Extracts and sanitizes API credentials from a parameter array.
	 *
	 * Works with both `$_POST` data and `WP_REST_Request::get_params()`.
	 * Strips dashes from customer IDs.
	 *
	 * @param array<string,mixed> $params Parameter array.
	 *
	 * @return array{customer_id: string, developer_token: string, client_id: string, client_secret: string, refresh_token: string, login_customer_id: string} Sanitized credentials.
	 * @since 1.6.0
	 */
	private function extract_credentials( array $params ): array {
		$fields = [ 'customer_id', 'developer_token', 'client_id', 'client_secret', 'refresh_token', 'login_customer_id' ];
		$credentials = [];

		foreach ( $fields as $field ) {
			$credentials[ $field ] = sanitize_text_field( wp_unslash( $params[ $field ] ?? '' ) );
		}

		// Strip dashes from customer IDs.
		$credentials['customer_id']       = str_replace( '-', '', $credentials['customer_id'] );
		$credentials['login_customer_id'] = str_replace( '-', '', $credentials['login_customer_id'] );

		return $credentials;
	}

	/**
	 * Renders a select dropdown for conversion action categories.
	 *
	 * @param string $key Setting key.
	 *
	 * @return void
	 * @since 1.5.0
	 */
	private function render_category_select_field( string $key ): void {
		$current = $this->settings->get( $key );
		printf(
			'<select id="%s" name="%s[%s]">',
			esc_attr( $key ),
			esc_attr( Settings::OPTION_KEY ),
			esc_attr( $key ),
		);
		foreach ( self::CONVERSION_ACTION_CATEGORIES as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label ),
			);
		}
		echo '</select>';
	}

	/**
	 * Renders a text or password input field.
	 *
	 * @param string $key         Setting key.
	 * @param string $type        Input type (`text` or `password`).
	 * @param string $description Optional help text below the field.
	 *
	 * @return void
	 * @since 0.2.0
	 */
	private function render_text_field( string $key, string $type = 'text', string $description = '' ): void {
		$value = $this->settings->get( $key );
		printf(
			'<input type="%s" id="%s" name="%s[%s]" value="%s" class="regular-text">',
			esc_attr( $type ),
			esc_attr( $key ),
			esc_attr( Settings::OPTION_KEY ),
			esc_attr( $key ),
			esc_attr( (string) $value ),
		);
		if ( $description !== '' ) {
			printf(
				'<p class="description">%s</p>',
				wp_kses( $description, [
					'a'  => [ 'href' => [], 'target' => [], 'rel' => [], 'class' => [] ],
					'br' => [],
				] ),
			);
		}
	}

	/**
	 * Renders a number input field.
	 *
	 * @param string $key Setting key.
	 *
	 * @return void
	 * @since 0.2.0
	 */
	private function render_number_field( string $key ): void {
		$value = $this->settings->get( $key );
		printf(
			'<input type="number" id="%s" name="%s[%s]" value="%s" min="0" step="0.01" class="small-text">',
			esc_attr( $key ),
			esc_attr( Settings::OPTION_KEY ),
			esc_attr( $key ),
			esc_attr( (string) $value ),
		);
	}

	/**
	 * Renders a select dropdown field.
	 *
	 * @param string   $key     Setting key.
	 * @param string[] $options Available options.
	 *
	 * @return void
	 * @since 0.2.0
	 */
	private function render_select_field( string $key, array $options ): void {
		$current = $this->settings->get( $key );
		printf(
			'<select id="%s" name="%s[%s]">',
			esc_attr( $key ),
			esc_attr( Settings::OPTION_KEY ),
			esc_attr( $key ),
		);
		foreach ( $options as $option ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $option ),
				selected( $current, $option, false ),
				esc_html( $option ),
			);
		}
		echo '</select>';
	}

	/**
	 * Masks a string, revealing only the last 4 characters.
	 *
	 * @param string $value String to mask.
	 *
	 * @return string Masked string (e.g. "••••hA_Z") or empty if input is empty.
	 * @since 1.2.0
	 */
	private static function mask( string $value ): string {
		$visible = 4;
		$length  = strlen( $value );

		if ( $length === 0 ) {
			return '';
		}

		if ( $length <= $visible ) {
			return str_repeat( '*', $length );
		}

		return str_repeat( '*', $length - $visible ) . substr( $value, -$visible );
	}

}
