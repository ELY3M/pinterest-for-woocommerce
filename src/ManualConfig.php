<?php
/**
 * Manual Pinterest configuration.
 *
 * Replaces the Pinterest OAuth "Connect" flow with a settings page where the
 * merchant enters the Pinterest account IDs, Pinterest Tag ID and an access
 * token directly.
 *
 * @package Pinterest_For_WooCommerce/Classes/
 */

namespace Automattic\WooCommerce\Pinterest;

use Automattic\WooCommerce\Pinterest\API\APIV5;
use Automattic\WooCommerce\Pinterest\API\Base;
use Automattic\WooCommerce\Pinterest\Utilities\Utilities;
use Defuse\Crypto\Crypto as DefuseCrypto;
use Pinterest_For_Woocommerce;
use Throwable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ManualConfig
 */
class ManualConfig {

	/**
	 * Option holding the manual configuration. Kept separate from the plugin's data
	 * option so it survives the plugin flushing its connection data.
	 */
	const OPTION = 'pinterest_for_woocommerce_manual_config';

	/**
	 * Admin page slug.
	 */
	const PAGE_SLUG = 'pinterest-for-woocommerce-config';

	/**
	 * Key in the plugin data option recording which configuration was last applied.
	 */
	const APPLIED_HASH_KEY = 'manual_config_hash';

	/**
	 * Option holding the last Pinterest API error seen while syncing with Ads Manager.
	 */
	const LAST_ERROR_OPTION = 'pinterest_for_woocommerce_manual_config_last_error';

	/**
	 * Admin-post actions.
	 */
	const SAVE_ACTION  = 'pinterest_for_woocommerce_save_manual_config';
	const CLEAR_ACTION = 'pinterest_for_woocommerce_clear_manual_config';

	/**
	 * Hook everything up.
	 *
	 * @return void
	 */
	public static function init() {
		// Never wipe a manually entered configuration because Pinterest answered 401 once.
		add_filter( 'pinterest_for_woocommerce_disconnect_on_authentication_failure', array( self::class, 'filter_disconnect_on_auth_failure' ), 99 );

		// A manually entered token has no refresh token; never try the OAuth refresh service.
		add_filter( PINTEREST_FOR_WOOCOMMERCE_OPTION_NAME . '_maybe_refresh_token', array( self::class, 'filter_maybe_refresh_token' ), 99 );

		// Push the configuration into the plugin's own settings before anything reads them.
		add_action( 'init', array( self::class, 'maybe_sync' ), 1 );

		if ( is_admin() ) {
			add_action( 'admin_menu', array( self::class, 'register_page' ), 99 );
			add_action( 'admin_init', array( self::class, 'redirect_connection_pages' ), 1 );
			add_action( 'admin_post_' . self::SAVE_ACTION, array( self::class, 'handle_save' ) );
			add_action( 'admin_post_' . self::CLEAR_ACTION, array( self::class, 'handle_clear' ) );
		}
	}

	/**
	 * URL of the configuration page.
	 *
	 * @return string
	 */
	public static function get_page_url(): string {
		return admin_url( 'admin.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * Whether the current request is for the configuration page.
	 *
	 * @return bool
	 */
	public static function is_config_page(): bool {
		return isset( $_GET['page'] ) && self::PAGE_SLUG === $_GET['page']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Default configuration values.
	 *
	 * @return array
	 */
	protected static function defaults(): array {
		return array(
			'account_id'          => '',
			'username'            => '',
			'advertiser_id'       => '',
			'tag_id'              => '',
			'merchant_id'         => '',
			'access_token'        => '', // Encrypted.
			'domain_verify_code'  => '',
			'domain_verified'     => true,
		);
	}

	/**
	 * Returns the stored configuration.
	 *
	 * @return array
	 */
	public static function get_config(): array {
		$config = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $config ) ? $config : array(), self::defaults() );
	}

	/**
	 * Whether a manual configuration (with an access token) is present.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		return '' !== self::get_config()['access_token'];
	}

	/**
	 * Password used to encrypt the access token at rest.
	 *
	 * @return string
	 */
	protected static function get_password(): string {
		$salt = defined( 'AUTH_KEY' ) ? AUTH_KEY : 'pinterest-for-woocommerce';
		$salt = is_multisite() ? $salt . get_current_blog_id() : $salt;
		return 'pfw_manual_' . hash( 'sha256', $salt . ( defined( 'AUTH_SALT' ) ? AUTH_SALT : '' ) );
	}

	/**
	 * Encrypts a value for storage.
	 *
	 * @param string $value Plain value.
	 * @return string
	 */
	protected static function encrypt( string $value ): string {
		return DefuseCrypto::encryptWithPassword( $value, self::get_password() );
	}

	/**
	 * Returns the decrypted access token.
	 *
	 * @return string
	 */
	public static function get_access_token(): string {
		$encrypted = self::get_config()['access_token'];
		if ( '' === $encrypted ) {
			return '';
		}
		try {
			return (string) DefuseCrypto::decryptWithPassword( $encrypted, self::get_password() );
		} catch ( Throwable $th ) {
			Logger::log( 'Could not decrypt the manually configured Pinterest access token. Please enter it again on the Pinterest configuration page.', 'error' );
			return '';
		}
	}

	/**
	 * Turns an API exception into a readable explanation, with a hint for the usual causes.
	 *
	 * @param Throwable $th The exception.
	 * @return string
	 */
	public static function describe_exception( Throwable $th ): string {
		$http    = (int) $th->getCode();
		$message = trim( wp_strip_all_tags( (string) $th->getMessage() ) );
		$text    = sprintf( 'HTTP %d%s', $http, '' !== $message ? ' - ' . $message : '' );

		if ( $th instanceof PinterestApiException && $th->get_pinterest_code() && $th->get_pinterest_code() !== $http ) {
			$text .= sprintf( ' (Pinterest code %d)', $th->get_pinterest_code() );
		}

		switch ( $http ) {
			case 401:
				$hint = __( 'Pinterest rejected the access token. It may be expired, a sandbox token, or a Conversions-only token from Ads Manager, which cannot read your account, ad account or tag. Use an OAuth access token from your app on developers.pinterest.com.', 'pinterest-for-woocommerce' );
				break;
			case 403:
				$hint = __( 'The token is valid but is not allowed to do this. Make sure it has the ads:read scope and that the Pinterest user who created it has access to this ad account.', 'pinterest-for-woocommerce' );
				break;
			case 404:
				$hint = __( 'Pinterest could not find it. Check that the Ad Account ID is right and that the Pinterest Tag ID belongs to that same ad account.', 'pinterest-for-woocommerce' );
				break;
			case 1:
				$hint = __( 'Your server could not reach api.pinterest.com.', 'pinterest-for-woocommerce' );
				break;
			default:
				$hint = '';
		}

		return '' !== $hint ? $text . ' — ' . $hint : $text;
	}

	/**
	 * Remembers the last Ads Manager sync error so it can be shown on the configuration page.
	 *
	 * @param string $message Error message.
	 * @return void
	 */
	public static function record_last_error( string $message ) {
		update_option(
			self::LAST_ERROR_OPTION,
			array(
				'message' => $message,
				'time'    => time(),
			),
			false
		);
	}

	/**
	 * Clears the remembered Ads Manager sync error.
	 *
	 * @return void
	 */
	public static function clear_last_error() {
		delete_option( self::LAST_ERROR_OPTION );
	}

	/**
	 * Filter: disable the automatic disconnect on 401 when the manual configuration is used.
	 *
	 * @param mixed $disconnect Current value.
	 * @return mixed
	 */
	public static function filter_disconnect_on_auth_failure( $disconnect ) {
		return self::is_enabled() ? false : $disconnect;
	}

	/**
	 * Filter: never try to refresh a manually configured token.
	 *
	 * @param bool $maybe_refresh Current value.
	 * @return bool
	 */
	public static function filter_maybe_refresh_token( $maybe_refresh ) {
		return self::is_enabled() ? false : $maybe_refresh;
	}

	/**
	 * Hash of the stored configuration, to detect when it needs to be (re)applied.
	 *
	 * @return string
	 */
	protected static function config_hash(): string {
		return md5( wp_json_encode( self::get_config() ) . get_home_url() );
	}

	/**
	 * Applies the configuration if it was changed or if the plugin flushed its data.
	 *
	 * @return void
	 */
	public static function maybe_sync() {
		if ( ! self::is_enabled() ) {
			return;
		}

		if ( Pinterest_For_Woocommerce::get_data( self::APPLIED_HASH_KEY ) === self::config_hash() && Pinterest_For_Woocommerce::is_connected() ) {
			return;
		}

		self::apply();
	}

	/**
	 * Domains to report as verified on Pinterest (matches both the PHP and JS checks).
	 *
	 * @return array
	 */
	protected static function get_site_domains(): array {
		$parts = wp_parse_url( get_home_url() );
		$host  = $parts['host'] ?? '';
		$path  = isset( $parts['path'] ) ? untrailingslashit( $parts['path'] ) : '';

		$domains = array( $host );
		if ( '' !== $path && '/' !== $path ) {
			$domains[] = $host . $path;
			$domains[] = $host . $path . '/';
		}
		return array_values( array_unique( array_filter( $domains ) ) );
	}

	/**
	 * Writes the manual configuration into the plugin's own settings and data.
	 *
	 * @return void
	 */
	public static function apply() {
		$config = self::get_config();
		$token  = self::get_access_token();

		if ( '' === $token ) {
			return;
		}

		// Access token. A long expiry keeps every "is the token about to expire" check quiet.
		Pinterest_For_Woocommerce::save_token_data(
			array(
				'access_token' => $token,
				'expires_in'   => 10 * YEAR_IN_SECONDS,
				'scopes'       => 'manual',
			)
		);
		self::reset_api_token_cache();

		Pinterest_For_Woocommerce::set_default_settings();

		// Account data, shaped the way the OAuth flow would have stored it.
		$account_data = Pinterest_For_Woocommerce::get_setting( 'account_data', true );
		$account_data = is_array( $account_data ) ? $account_data : array();
		$verified     = ! empty( $config['domain_verified'] ) ? self::get_site_domains() : array();

		$account_data = array_merge(
			$account_data,
			array(
				'username'                => $config['username'],
				'full_name'               => $account_data['full_name'] ?? '',
				'id'                      => $config['account_id'],
				'image_medium_url'        => $account_data['image_medium_url'] ?? '',
				'is_partner'              => true,
				'verified_user_websites'  => $verified,
				'is_any_website_verified' => ! empty( $verified ),
			)
		);
		if ( ! isset( $account_data['is_billing_setup'] ) ) {
			$account_data['is_billing_setup'] = false;
		}
		if ( ! isset( $account_data['coupon_redeem_info'] ) ) {
			$account_data['coupon_redeem_info'] = array( 'redeem_status' => false );
		}
		if ( ! isset( $account_data['currency_credit_info'] ) && function_exists( 'get_woocommerce_currency' ) ) {
			$account_data['currency_credit_info'] = AdsCreditCurrency::get_currency_credits();
		}
		Pinterest_For_Woocommerce::save_setting( 'account_data', $account_data );

		// Ad account (advertiser) and Pinterest Tag.
		Pinterest_For_Woocommerce::save_setting( 'tracking_advertiser', '' !== $config['advertiser_id'] ? $config['advertiser_id'] : null );
		Pinterest_For_Woocommerce::save_setting( 'tracking_tag', '' !== $config['tag_id'] ? $config['tag_id'] : null );

		if ( '' !== $config['merchant_id'] ) {
			Pinterest_For_Woocommerce::save_data( 'merchant_id', $config['merchant_id'] );
		} else {
			Pinterest_For_Woocommerce::remove_data( 'merchant_id' );
		}

		Pinterest_For_Woocommerce::save_connection_info_data(
			array(
				'advertiser_id' => $config['advertiser_id'],
				'tag_id'        => $config['tag_id'],
				'merchant_id'   => $config['merchant_id'],
			)
		);

		// Optional <meta name="p:domain_verify"> tag.
		if ( '' !== $config['domain_verify_code'] ) {
			Pinterest_For_Woocommerce::save_data( 'verification_data', array( 'verification_code' => $config['domain_verify_code'] ) );
		} else {
			Pinterest_For_Woocommerce::remove_data( 'verification_data' );
		}

		Pinterest_For_Woocommerce::set_api_version( 'v5' );

		if ( ! Utilities::get_account_connection_timestamp() ) {
			Utilities::set_account_connection_timestamp();
		}

		Pinterest_For_Woocommerce::save_data( self::APPLIED_HASH_KEY, self::config_hash() );
	}

	/**
	 * Clears the token cached by the API client during this request.
	 *
	 * @return void
	 */
	protected static function reset_api_token_cache() {
		if ( method_exists( Base::class, 'reset_token' ) ) {
			Base::reset_token();
		}
	}

	/**
	 * Redirects the OAuth landing / onboarding / connection screens to the configuration page.
	 *
	 * @return void
	 */
	public static function redirect_connection_pages() {
		if ( wp_doing_ajax() || ! isset( $_GET['page'], $_GET['path'] ) || 'wc-admin' !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$path = untrailingslashit( sanitize_text_field( wp_unslash( $_GET['path'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( in_array( $path, array( '/pinterest/landing', '/pinterest/onboarding', '/pinterest/connection' ), true ) ) {
			wp_safe_redirect( self::get_page_url() );
			exit;
		}
	}

	/**
	 * Registers the configuration page under Marketing (or WooCommerce as a fallback).
	 *
	 * @return void
	 */
	public static function register_page() {
		global $admin_page_hooks;

		$parent = isset( $admin_page_hooks['woocommerce-marketing'] ) ? 'woocommerce-marketing' : 'woocommerce';

		add_submenu_page(
			$parent,
			esc_html__( 'Pinterest Configuration', 'pinterest-for-woocommerce' ),
			esc_html__( 'Pinterest Setup', 'pinterest-for-woocommerce' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Sanitizes a Pinterest ID.
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	protected static function sanitize_id( $value ): string {
		return preg_replace( '/[^A-Za-z0-9_\-]/', '', sanitize_text_field( wp_unslash( (string) $value ) ) );
	}

	/**
	 * Handles the configuration form submission.
	 *
	 * @return void
	 */
	public static function handle_save() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'pinterest-for-woocommerce' ) );
		}
		check_admin_referer( self::SAVE_ACTION );

		// phpcs:disable WordPress.Security.NonceVerification.Missing --- verified above.
		$old    = self::get_config();
		$config = array(
			'account_id'         => self::sanitize_id( $_POST['account_id'] ?? '' ),
			'username'           => sanitize_text_field( wp_unslash( $_POST['username'] ?? '' ) ),
			'advertiser_id'      => self::sanitize_id( $_POST['advertiser_id'] ?? '' ),
			'tag_id'             => self::sanitize_id( $_POST['tag_id'] ?? '' ),
			'merchant_id'        => self::sanitize_id( $_POST['merchant_id'] ?? '' ),
			'access_token'       => $old['access_token'],
			'domain_verify_code' => self::sanitize_id( $_POST['domain_verify_code'] ?? '' ),
			'domain_verified'    => ! empty( $_POST['domain_verified'] ),
		);

		$new_token = trim( sanitize_text_field( wp_unslash( $_POST['access_token'] ?? '' ) ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' !== $new_token ) {
			$config['access_token'] = self::encrypt( $new_token );
		}

		$messages = array();

		if ( '' === $config['access_token'] ) {
			$messages[] = array( 'error', __( 'An access token is required.', 'pinterest-for-woocommerce' ) );
			update_option( self::OPTION, $config, false );
			self::redirect_back( $messages );
		}

		update_option( self::OPTION, $config, false );
		self::reset_api_token_cache();

		// Make sure the plugin uses the new token before checking it.
		Pinterest_For_Woocommerce::save_data( self::APPLIED_HASH_KEY, '' );
		self::apply();

		// Check the token against Pinterest and fill in what is missing.
		try {
			$account = APIV5::get_account_info();
			$changed = false;
			if ( '' === $config['account_id'] && ! empty( $account['id'] ) ) {
				$config['account_id'] = (string) $account['id'];
				$changed              = true;
			}
			if ( '' === $config['username'] && ! empty( $account['username'] ) ) {
				$config['username'] = (string) $account['username'];
				$changed            = true;
			}
			if ( $changed ) {
				update_option( self::OPTION, $config, false );
				self::apply();
			}
			$messages[] = array(
				'success',
				sprintf(
					/* translators: %s: Pinterest username */
					__( 'Configuration saved. The access token works for Pinterest account "%s".', 'pinterest-for-woocommerce' ),
					$account['username'] ?? $config['username']
				),
			);
		} catch ( Throwable $th ) {
			$messages[] = array(
				'warning',
				sprintf(
					/* translators: %s: error message */
					__( 'Configuration saved, but Pinterest did not accept the access token: %s', 'pinterest-for-woocommerce' ),
					self::describe_exception( $th )
				),
			);
		}

		if ( '' !== $config['advertiser_id'] ) {
			$ad_account_ok = true;
			try {
				APIV5::make_request( 'ad_accounts/' . $config['advertiser_id'], 'GET' );
			} catch ( Throwable $th ) {
				$ad_account_ok = false;
				$messages[]    = array(
					'warning',
					sprintf(
						/* translators: %s: error message */
						__( 'Could not read ad account %1$s with this token: %2$s', 'pinterest-for-woocommerce' ),
						$config['advertiser_id'],
						self::describe_exception( $th )
					),
				);
			}

			// The same call the Settings page makes when it syncs with Ads Manager.
			if ( $ad_account_ok && '' !== $config['tag_id'] ) {
				try {
					$tag = APIV5::get_advertiser_tag( $config['advertiser_id'], $config['tag_id'] );
					self::clear_last_error();
					if ( isset( $tag['configs']['aem_enabled'] ) ) {
						Pinterest_For_Woocommerce::save_setting( 'automatic_enhanced_match_support', (bool) $tag['configs']['aem_enabled'] );
					}
				} catch ( Throwable $th ) {
					$messages[] = array(
						'warning',
						sprintf(
							/* translators: 1: tag ID, 2: ad account ID, 3: error message */
							__( 'Could not read Pinterest Tag %1$s in ad account %2$s: %3$s', 'pinterest-for-woocommerce' ),
							$config['tag_id'],
							$config['advertiser_id'],
							self::describe_exception( $th )
						),
					);
				}
			}
		} else {
			$messages[] = array( 'warning', __( 'No Ad Account ID set: the product catalog sync and the Conversions API need it.', 'pinterest-for-woocommerce' ) );
		}

		self::redirect_back( $messages );
	}

	/**
	 * Removes the configuration and disconnects the plugin.
	 *
	 * @return void
	 */
	public static function handle_clear() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'pinterest-for-woocommerce' ) );
		}
		check_admin_referer( self::CLEAR_ACTION );

		delete_option( self::OPTION );
		self::clear_last_error();
		self::reset_api_token_cache();
		Pinterest_For_Woocommerce::disconnect();

		self::redirect_back( array( array( 'success', __( 'Pinterest configuration removed.', 'pinterest-for-woocommerce' ) ) ) );
	}

	/**
	 * Redirects back to the configuration page with notices.
	 *
	 * @param array $messages List of [type, message] pairs.
	 * @return void
	 */
	protected static function redirect_back( array $messages ) {
		set_transient( 'pfw_manual_config_notices_' . get_current_user_id(), $messages, 5 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::get_page_url() );
		exit;
	}

	/**
	 * Renders the configuration page.
	 *
	 * @return void
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$config    = self::get_config();
		$has_token = '' !== $config['access_token'];
		$notices   = get_transient( 'pfw_manual_config_notices_' . get_current_user_id() );
		delete_transient( 'pfw_manual_config_notices_' . get_current_user_id() );

		$catalog_url  = admin_url( 'admin.php?page=wc-admin&path=' . rawurlencode( '/pinterest/catalog' ) );
		$settings_url = admin_url( 'admin.php?page=wc-admin&path=' . rawurlencode( '/pinterest/settings' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Pinterest Configuration', 'pinterest-for-woocommerce' ); ?></h1>

			<?php
			if ( is_array( $notices ) ) {
				foreach ( $notices as $notice ) {
					printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $notice[0] ), esc_html( $notice[1] ) );
				}
			}
			?>

			<?php
			$last_error = get_option( self::LAST_ERROR_OPTION );
			if ( is_array( $last_error ) && ! empty( $last_error['message'] ) ) {
				printf(
					'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s<br /><small>%3$s</small></p></div>',
					esc_html__( 'Last sync with Pinterest Ads Manager failed:', 'pinterest-for-woocommerce' ),
					esc_html( $last_error['message'] ),
					esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $last_error['time'] ) )
				);
			}
			?>

			<p><?php esc_html_e( 'Enter your Pinterest account details below. No Pinterest login or "Connect" step is needed: the plugin uses this access token for every request to the Pinterest API.', 'pinterest-for-woocommerce' ); ?></p>

			<?php if ( Pinterest_For_Woocommerce::is_setup_complete() ) : ?>
				<p>
					<strong><?php esc_html_e( 'Status:', 'pinterest-for-woocommerce' ); ?></strong>
					<?php esc_html_e( 'Configured', 'pinterest-for-woocommerce' ); ?> &mdash;
					<a href="<?php echo esc_url( $catalog_url ); ?>"><?php esc_html_e( 'Catalog', 'pinterest-for-woocommerce' ); ?></a> |
					<a href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Settings', 'pinterest-for-woocommerce' ); ?></a>
				</p>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" autocomplete="off">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>" />
				<?php wp_nonce_field( self::SAVE_ACTION ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="pfw-access-token"><?php esc_html_e( 'Access token', 'pinterest-for-woocommerce' ); ?></label></th>
						<td>
							<input type="password" id="pfw-access-token" name="access_token" class="regular-text code" value="" placeholder="<?php echo $has_token ? esc_attr__( '•••••••• saved — leave blank to keep', 'pinterest-for-woocommerce' ) : 'pina_...'; ?>" autocomplete="new-password" />
							<p class="description"><?php esc_html_e( 'Pinterest API v5 access token (e.g. from your app on developers.pinterest.com). Recommended scopes: user_accounts:read, ads:read, ads:write, catalogs:read, catalogs:write.', 'pinterest-for-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pfw-advertiser-id"><?php esc_html_e( 'Ad Account ID', 'pinterest-for-woocommerce' ); ?></label></th>
						<td>
							<input type="text" id="pfw-advertiser-id" name="advertiser_id" class="regular-text code" value="<?php echo esc_attr( $config['advertiser_id'] ); ?>" />
							<p class="description"><?php esc_html_e( 'The advertiser / ad account ID shown in Pinterest Ads Manager (ads.pinterest.com/advertiser/<ID>/). Used for the catalog feed, conversions and billing.', 'pinterest-for-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pfw-tag-id"><?php esc_html_e( 'Pinterest Tag ID', 'pinterest-for-woocommerce' ); ?></label></th>
						<td>
							<input type="text" id="pfw-tag-id" name="tag_id" class="regular-text code" value="<?php echo esc_attr( $config['tag_id'] ); ?>" />
							<p class="description"><?php esc_html_e( 'The ID of your Pinterest Tag (Ads Manager → Conversions → Pinterest Tag). Leave empty to disable the tag on the storefront.', 'pinterest-for-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pfw-account-id"><?php esc_html_e( 'Pinterest User Account ID', 'pinterest-for-woocommerce' ); ?></label></th>
						<td>
							<input type="text" id="pfw-account-id" name="account_id" class="regular-text code" value="<?php echo esc_attr( $config['account_id'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Optional — filled in automatically from the access token when left empty.', 'pinterest-for-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pfw-username"><?php esc_html_e( 'Pinterest username', 'pinterest-for-woocommerce' ); ?></label></th>
						<td>
							<input type="text" id="pfw-username" name="username" class="regular-text" value="<?php echo esc_attr( $config['username'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Optional — filled in automatically from the access token when left empty.', 'pinterest-for-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="pfw-merchant-id"><?php esc_html_e( 'Merchant ID', 'pinterest-for-woocommerce' ); ?></label></th>
						<td>
							<input type="text" id="pfw-merchant-id" name="merchant_id" class="regular-text code" value="<?php echo esc_attr( $config['merchant_id'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Optional.', 'pinterest-for-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Domain verification', 'pinterest-for-woocommerce' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="domain_verified" value="1" <?php checked( ! empty( $config['domain_verified'] ) ); ?> />
								<?php
								/* translators: %s: site domain */
								printf( esc_html__( '%s is already claimed / verified on my Pinterest account', 'pinterest-for-woocommerce' ), '<code>' . esc_html( implode( ', ', self::get_site_domains() ) ) . '</code>' );
								?>
							</label>
							<p class="description"><?php esc_html_e( 'The product catalog sync only runs when this is checked.', 'pinterest-for-woocommerce' ); ?></p>
							<p>
								<label for="pfw-domain-verify-code"><?php esc_html_e( 'Verification meta tag code (optional):', 'pinterest-for-woocommerce' ); ?></label><br />
								<input type="text" id="pfw-domain-verify-code" name="domain_verify_code" class="regular-text code" value="<?php echo esc_attr( $config['domain_verify_code'] ); ?>" />
							</p>
							<p class="description"><?php echo wp_kses_post( __( 'If Pinterest gave you <code>&lt;meta name="p:domain_verify" content="XXXX"/&gt;</code> to claim your website, paste the <code>XXXX</code> value here and the plugin will add the tag to your site.', 'pinterest-for-woocommerce' ) ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save configuration', 'pinterest-for-woocommerce' ) ); ?>
			</form>

			<?php if ( $has_token ) : ?>
				<hr />
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return window.confirm('<?php echo esc_js( __( 'Remove the Pinterest configuration and disconnect the plugin?', 'pinterest-for-woocommerce' ) ); ?>');">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::CLEAR_ACTION ); ?>" />
					<?php wp_nonce_field( self::CLEAR_ACTION ); ?>
					<?php submit_button( __( 'Remove configuration', 'pinterest-for-woocommerce' ), 'delete', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
