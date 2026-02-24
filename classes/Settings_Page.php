<?php
/**
 * WordPress settings page for Google Ads API configuration.
 *
 * Registers an options page under Settings → Google Ads Attribution with
 * fields for API credentials and conversion defaults.
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
		}
	}

	/**
	 * Adds the settings page under the WordPress Settings menu.
	 *
	 * @return void
	 * @since 0.2.0
	 */
	public function add_page(): void {
		add_options_page(
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
				esc_html__( 'Enter your Google Ads API credentials. All fields except Login Customer ID are required.', 'kntnt-ad-attr-gads' ),
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
	 * Registers API credential fields.
	 *
	 * @return void
	 * @since 0.2.0
	 */
	private function add_credential_fields(): void {
		$fields = [
			'customer_id'          => [
				'label'       => __( 'Customer ID', 'kntnt-ad-attr-gads' ),
				'type'        => 'text',
				'description' => __( '10-digit Google Ads Customer ID (dashes are removed automatically).', 'kntnt-ad-attr-gads' ),
			],
			'conversion_action_id' => [
				'label'       => __( 'Conversion Action ID', 'kntnt-ad-attr-gads' ),
				'type'        => 'text',
				'description' => __( 'Numeric ID of the conversion action in Google Ads.', 'kntnt-ad-attr-gads' ),
			],
			'developer_token'      => [
				'label' => __( 'Developer Token', 'kntnt-ad-attr-gads' ),
				'type'  => 'text',
			],
			'client_id'            => [
				'label' => __( 'OAuth2 Client ID', 'kntnt-ad-attr-gads' ),
				'type'  => 'text',
			],
			'client_secret'        => [
				'label' => __( 'OAuth2 Client Secret', 'kntnt-ad-attr-gads' ),
				'type'  => 'password',
			],
			'refresh_token'        => [
				'label' => __( 'OAuth2 Refresh Token', 'kntnt-ad-attr-gads' ),
				'type'  => 'password',
			],
			'login_customer_id'    => [
				'label'       => __( 'Login Customer ID (MCC)', 'kntnt-ad-attr-gads' ),
				'type'        => 'text',
				'description' => __( 'Optional. Required only if using a manager account (MCC).', 'kntnt-ad-attr-gads' ),
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
			printf( '<p class="description">%s</p>', esc_html( $description ) );
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
