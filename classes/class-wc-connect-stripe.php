<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_Connect_Stripe' ) ) {

	class WC_Connect_Stripe {

		/**
		 * @var WC_Connect_API_Client
		 */
		private $api;

		/**
		 * @var WC_Connect_Options
		 */
		private $options;

		/**
		 * @var WC_Connect_Logger
		 */
		private $logger;

		/**
		 * @var WC_Connect_Nux
		 */
		private $nux;

		const STATE_VAR_NAME = 'stripe_state';
		const SETTINGS_OPTION = 'woocommerce_stripe_settings';

		public function __construct( WC_Connect_API_Client $client, WC_Connect_Options $options, WC_Connect_Logger $logger, WC_Connect_Nux $nux ) {
			$this->api = $client;
			$this->options = $options;
			$this->logger = $logger;
			$this->nux = $nux;
		}

		public function is_stripe_plugin_enabled() {
			return class_exists( 'WC_Stripe' );
		}

		public function get_oauth_url( $return_url ) {
			$result = $this->api->get_stripe_oauth_init( $return_url );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$this->options->update_option( self::STATE_VAR_NAME, $result->state );

			return $result->oauthUrl;
		}

		public function create_account( $email, $country ) {
			$response = $this->api->create_stripe_account( $email, $country );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			return $this->save_stripe_keys( $response );
		}

		public function get_account_details() {
			return $this->api->get_stripe_account_details();
		}

		public function deauthorize_account() {
			$response = $this->api->deauthorize_stripe_account();
			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$this->clear_stripe_keys();
			return $response;
		}

		public function connect_oauth( $state, $code ) {
			if ( $state !== $this->options->get_option( self::STATE_VAR_NAME, false ) ) {
				return new WP_Error( 'Invalid stripe state' );
			}

			$response = $this->api->get_stripe_oauth_keys( $code );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			return $this->save_stripe_keys( $response );
		}

		private function save_stripe_keys( $result ) {
			if ( ! isset( $result->publishableKey, $result->secretKey ) ) {
				return new WP_Error( 'Invalid credentials received from server' );
			}

			$is_test = false !== strpos( $result->publishableKey, '_test_' );
			$prefix = $is_test ? 'test_' : '';

			$default_options = $this->get_default_stripe_config();

			$options = array_merge( $default_options, get_option( self::SETTINGS_OPTION, array() ) );
			$options['enabled']                     = 'yes';
			$options['testmode']                    = $is_test ? 'yes' : 'no';
			$options[ $prefix . 'publishable_key' ] = $result->publishableKey;
			$options[ $prefix . 'secret_key' ]      = $result->secretKey;

			// While we are at it, let's also clear the account_id and
			// test_account_id if present

			// Those used to be stored by save_stripe_keys but should not have
			// been since they were not used by anyone

			unset( $options[ 'account_id' ] );
			unset( $options[ 'test_account_id' ] );

			update_option( self::SETTINGS_OPTION, $options );
			return $result;
		}

		/**
		 * Clears keys for test or production (whichever is presently enabled).
		 * Especially useful after Stripe Connect account deauthorization.
		 */
		private function clear_stripe_keys() {
			$default_options = $this->get_default_stripe_config();
			$options = array_merge( $default_options, get_option( self::SETTINGS_OPTION, array() ) );

			if ( 'yes' === $options['testmode'] ) {
				$options[ 'test_publishable_key' ] = '';
				$options[ 'test_secret_key' ] = '';
			} else {
				$options[ 'publishable_key' ] = '';
				$options[ 'secret_key' ] = '';
			}

			// While we are at it, let's also clear the account_id and
			// test_account_id if present

			// Those used to be stored by save_stripe_keys but should not have
			// been since they were not used by anyone

			unset( $options[ 'account_id' ] );
			unset( $options[ 'test_account_id' ] );

			update_option( self::SETTINGS_OPTION, $options );
		}

		private function get_default_stripe_config() {
			if ( ! class_exists( 'WC_Gateway_Stripe' ) ) {
				return array();
			}

			$result = array();
			$gateway = new WC_Gateway_Stripe();
			foreach ( $gateway->form_fields as $key => $value ) {
				if ( isset( $value['default'] ) ) {
					$result[ $key ] = $value['default'];
				}
			}

			return $result;
		}

		public function maybe_show_notice() {
			$setting = WC_Connect_Options::get_option( 'banner_stripe', null );
			if ( is_null( $setting ) ) {
				return;
			}

			if ( 'success' === $setting ) {
				add_action( 'admin_notices', array( $this, 'connection_success_notice' ) );
				WC_Connect_Options::delete_option( 'banner_stripe' );
				return;
			}

			if ( isset( $_GET[ 'wcs_stripe_code' ] ) ) {
				$response = $this->connect_oauth( $_GET[ 'wcs_stripe_state' ], $_GET[ 'wcs_stripe_code' ] );
				if ( ! is_wp_error( $response ) ) {
					WC_Connect_Options::update_option( 'banner_stripe', 'success' );
				}

				wp_safe_redirect( remove_query_arg( array( 'wcs_stripe_state', 'wcs_stripe_code' ) ) );
				exit;
			}

			$screen = get_current_screen();

			if ( // Display if on any of these admin pages.
				'dashboard' === $screen->id
				|| 'plugins' === $screen->id
				|| ( // WooCommerce checkout settings.
					'woocommerce_page_wc-settings' === $screen->base
					&& isset( $_GET['tab'] ) && 'checkout' === $_GET['tab']
					)
				|| ( // WooCommerce payment gateway extension page.
					'woocommerce_page_wc-addons' === $screen->base
					&& isset( $_GET['section'] ) && 'payment-gateways' === $_GET['section']
					)
			) {
				wp_enqueue_style( 'wc_connect_banner' );
				add_action( 'admin_notices', array( $this, 'connection_banner' ) );
			}
		}

		public function connection_banner() {
			$result = $this->get_oauth_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=stripe' ) );

			if ( is_wp_error( $result ) ) {
				$this->logger->log( $result, __CLASS__ );
				return;
			}

			$this->nux->show_nux_banner( array(
				'title'          => __( 'Connect your account to activate Stripe', 'woocommerce-services' ),
				'description'    => esc_html( __( 'To start accepting payments with Stripe, you\'ll need to connect your site to a Stripe account.', 'woocommerce-services' ) ),
				'button_text'    => __( 'Connect', 'woocommerce-services' ),
				'button_link'    => $result,
				'image_url'      => plugins_url( 'images/stripe.png', dirname( __FILE__ ) ),
				'should_show_jp' => false,
				'dismissible_id' => 'stripe_connect',
				'compact_logo'   => true,
			) );
		}

		public function connection_success_notice() {
			?>
				<div class="notice notice-success">
					<p><?php echo wp_kses( '<strong>Ready to accept Stripe payments!</strong> Your Stripe account has been successfully connected. Additional settings can be configured on this screen.', array( 'strong' => array() ) ); ?></p>
				</div>
			<?php
		}
	}
}
