<?php
/**
 * WordPress settings page for Google Ads API configuration.
 *
 * Registers an options page under Settings > Google Ads Attribution with
 * fields for API credentials and conversion defaults. Includes a test
 * connection button that verifies credentials via AJAX.
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
	 * @since 0.2.0
	 */
	private const SECTION_CREDENTIALS = 'kntnt_ad_attr_gads_credentials';

	/**
	 * Section ID for conversion defaults fields.
	 *
	 * @var string
	 * @since 0.2.0
	 */
	private const SECTION_CONVERSION = 'kntnt_ad_attr_gads_conversion';

	/**
	 * AJAX action name for testing the connection.
	 *
	 * @var string
	 * @since 0.4.0
	 */
	private const AJAX_TEST_CONNECTION = 'kntnt_ad_attr_gads_test_connection';

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
			add_action( 'wp_ajax_' . self::AJAX_TEST_CONNECTION, [ $this, 'handle_test_connection' ] );
		}
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
			self::SECTION_CREDENTIALS,
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

		// Conversion Defaults section.
		add_settings_section(
			self::SECTION_CONVERSION,
			__( 'Conversion Defaults', 'kntnt-ad-attr-gads' ),
			fn() => printf(
				'<p>%s</p>',
				esc_html__( 'Default values for offline conversion uploads.', 'kntnt-ad-attr-gads' ),
			),
			self::PAGE_SLUG,
		);

		// Register individual fields.
		$this->add_credential_fields();
		$this->add_conversion_fields();
	}

	/**
	 * Sanitizes settings before they are saved.
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

		return $clean;
	}

	/**
	 * Renders the settings page HTML.
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
				do_settings_sections( self::PAGE_SLUG );
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
			false,
			true,
		);

		wp_localize_script( 'kntnt-ad-attr-gads-settings', 'kntntAdAttrGads', [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( self::AJAX_TEST_CONNECTION ),
			'action'  => self::AJAX_TEST_CONNECTION,
		] );
	}

	/**
	 * Handles the AJAX test connection request.
	 *
	 * Verifies nonce and capability, creates a Google_Ads_Client from
	 * current settings, and attempts a fresh token refresh.
	 *
	 * @return void
	 * @since 0.4.0
	 */
	public function handle_test_connection(): void {
		check_ajax_referer( self::AJAX_TEST_CONNECTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Permission denied.', 'kntnt-ad-attr-gads' ) ] );
			return;
		}

		// Require all credentials before testing.
		if ( ! $this->settings->is_configured() ) {
			wp_send_json_error( [ 'message' => __( 'Please fill in all required credentials first.', 'kntnt-ad-attr-gads' ) ] );
			return;
		}

		$settings = $this->settings->get_all();

		$client = new Google_Ads_Client(
			customer_id: $settings['customer_id'],
			developer_token: $settings['developer_token'],
			client_id: $settings['client_id'],
			client_secret: $settings['client_secret'],
			refresh_token: $settings['refresh_token'],
			login_customer_id: $settings['login_customer_id'],
		);

		$result = $client->test_connection();

		if ( $result['success'] ) {
			wp_send_json_success( [ 'message' => __( 'Connection successful! OAuth2 token refresh succeeded.', 'kntnt-ad-attr-gads' ) ] );
			return;
		}

		// TODO: Remove debug details after troubleshooting.
		$debug = sprintf(
			"\n\nDebug: client_id=%s… (len %d) | client_secret=%s… (len %d) | refresh_token=%s… (len %d)",
			substr( $settings['client_id'], 0, 15 ),
			strlen( $settings['client_id'] ),
			substr( $settings['client_secret'], 0, 10 ),
			strlen( $settings['client_secret'] ),
			substr( $settings['refresh_token'], 0, 10 ),
			strlen( $settings['refresh_token'] ),
		);

		wp_send_json_error( [ 'message' => $result['error'] . $debug ] );
	}

	/**
	 * Registers API credential fields.
	 *
	 * @return void
	 * @since 0.2.0
	 */
	private function add_credential_fields(): void {
		$guide = 'https://github.com/Kntnt/kntnt-ad-attribution-gads#';

		$fields = [
			'customer_id'          => [
				'label'       => __( 'Customer ID', 'kntnt-ad-attr-gads' ),
				'type'        => 'text',
				'description' => sprintf(
					/* translators: %s: URL to the configuration guide section */
					__( '10-digit Google Ads Customer ID (dashes are removed automatically).<br><a href="%s" target="_blank" rel="noopener noreferrer" class="kntnt-ad-attr-gads-hint">Configuration guide</a>', 'kntnt-ad-attr-gads' ),
					$guide . 'customer-id',
				),
			],
			'conversion_action_id' => [
				'label'       => __( 'Conversion Action ID', 'kntnt-ad-attr-gads' ),
				'type'        => 'text',
				'description' => sprintf(
					/* translators: %s: URL to the configuration guide section */
					__( 'Numeric ID of the conversion action.<br><a href="%s" target="_blank" rel="noopener noreferrer" class="kntnt-ad-attr-gads-hint">Configuration guide</a>', 'kntnt-ad-attr-gads' ),
					$guide . 'conversion-action-id',
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
			'login_customer_id'    => [
				'label'       => __( 'Login Customer ID (MCC)', 'kntnt-ad-attr-gads' ),
				'type'        => 'text',
				'description' => sprintf(
					/* translators: %s: URL to the configuration guide section */
					__( 'Your Manager Account (MCC) customer ID.<br><a href="%s" target="_blank" rel="noopener noreferrer" class="kntnt-ad-attr-gads-hint">Configuration guide</a>', 'kntnt-ad-attr-gads' ),
					$guide . 'login-customer-id-mcc',
				),
			],
		];

		foreach ( $fields as $key => $config ) {
			add_settings_field(
				$key,
				$config['label'],
				fn() => $this->render_text_field( $key, $config['type'], $config['description'] ?? '' ),
				self::PAGE_SLUG,
				self::SECTION_CREDENTIALS,
				[ 'label_for' => $key ],
			);
		}

		// Test connection button (not a form field — no name attribute).
		add_settings_field(
			'test_connection',
			__( 'Test Connection', 'kntnt-ad-attr-gads' ),
			[ $this, 'render_test_connection_field' ],
			self::PAGE_SLUG,
			self::SECTION_CREDENTIALS,
		);
	}

	/**
	 * Registers conversion default fields.
	 *
	 * @return void
	 * @since 0.2.0
	 */
	private function add_conversion_fields(): void {

		// Conversion value (number input).
		add_settings_field(
			'conversion_value',
			__( 'Default Conversion Value', 'kntnt-ad-attr-gads' ),
			fn() => $this->render_number_field( 'conversion_value' ),
			self::PAGE_SLUG,
			self::SECTION_CONVERSION,
			[ 'label_for' => 'conversion_value' ],
		);

		// Currency code (select).
		add_settings_field(
			'currency_code',
			__( 'Currency Code', 'kntnt-ad-attr-gads' ),
			fn() => $this->render_select_field( 'currency_code', self::CURRENCY_CODES ),
			self::PAGE_SLUG,
			self::SECTION_CONVERSION,
			[ 'label_for' => 'currency_code' ],
		);
	}

	/**
	 * Renders the test connection button and result area.
	 *
	 * This is a UI-only field — no input name, not part of the form data.
	 *
	 * @return void
	 * @since 0.4.0
	 */
	public function render_test_connection_field(): void {
		printf(
			'<button type="button" id="kntnt-ad-attr-gads-test-connection" class="button button-secondary">%s</button> ',
			esc_html__( 'Test Connection', 'kntnt-ad-attr-gads' ),
		);
		echo '<span id="kntnt-ad-attr-gads-test-result"></span>';
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

}
