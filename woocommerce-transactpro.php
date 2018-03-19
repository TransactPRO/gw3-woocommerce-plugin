<?php
/**
 * Plugin Name: WooCommerce Transactpro
 * Version: 0.0.1
 * Description: Adds ability to make purchases through the Transactpro payment gateway.
 * Author: Yury Bratuhin
 *
 * @package WordPress
 * @author YuryBratuhin
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Woocommerce_Transactpro' ) ) :

	define( 'WC_TRANSACTPRO_VERSION', '0.0.1' );

	/**
	 * Main class.
	 *
	 * @package Woocommerce_Transactpro
	 */
	class Woocommerce_Transactpro {

		private static $_instance = null;

		/**
		 * @var \TransactPro\Gateway\Gateway
		 */
		public $transactpro_client;

		/**
		 * Get the single instance aka Singleton
		 *
		 * @access public
		 * @return bool
		 */
		public static function instance() {
			if ( is_null( self::$_instance ) ) {
				self::$_instance = new self();
			}

			return self::$_instance;
		}

		/**
		 * Prevent cloning
		 *
		 * @access public
		 * @return bool
		 */
		public function __clone() {
			_doing_it_wrong( __FUNCTION__, __( 'Forbidden', 'woocommerce-transactpro' ), WC_TRANSACTPRO_VERSION );
		}

		/**
		 * Prevent unserializing instances
		 *
		 * @access public
		 * @return bool
		 */
		public function __wakeup() {
			_doing_it_wrong( __FUNCTION__, __( 'Forbidden', 'woocommerce-transactpro' ), WC_TRANSACTPRO_VERSION );
		}

		/**
		 * Woocommerce_Transactpro constructor.
		 */
		private function __construct() {
			add_action( 'woocommerce_loaded', array( $this, 'bootstrap' ) );
		}

		public function bootstrap() {
			add_action( 'admin_notices', array( $this, 'check_environment' ) );

			$this->define_constants();
			$this->includes();
			$this->init();
			$this->init_hooks();

			do_action( 'wc_transactpro_loaded' );
		}

		public function init() {

			if ($transactpro_setting = get_option( 'woocommerce_transactpro_settings' )) {
				if (!(empty($transactpro_setting['user_id']) || empty($transactpro_setting['secret_key']) || empty($transactpro_setting['gateway_url']))) {

					$transactpro_client = new \TransactPro\Gateway\Gateway( $transactpro_setting['gateway_url'] );

					$transactpro_client->auth()->setAccountID( $transactpro_setting['user_id'] )->setSecretKey( $transactpro_setting['secret_key'] );

					$this->transactpro_client = $transactpro_client;

				}
			}
		}

		/**
		 * Define constants
		 *
		 * @access public
		 * @return bool
		 */
		public function define_constants() {

			define( 'TRANSACTPRO_APPLICATION_ID', untrailingslashit( plugin_dir_path( __FILE__ ) ) );
			return true;
		}


		/**
		 * Check if currency is set to allowed currency.
		 *
		 * @since 1.0.10
		 * @version 1.0.10
		 */
		public function is_allowed_currencies() {

			// todo: check

			if ( 'USD' !== get_woocommerce_currency() && 'EUR' !== get_woocommerce_currency() ) {
				return false;
			}

			return true;
		}

		/**
		 * Check required environment
		 *
		 * @access public
		 * @return null
		 */
		public function check_environment() {
			if ( ! current_user_can( 'manage_woocommerce' ) ) {
				return;
			}

			if (version_compare( WC_VERSION, '3.0.0', '<' )) {
				echo '<div class="error"><p>' . __( 'Transactpro requires WP Version > 3.0.0', 'woocommerce-transactpro' ) . '</p></div>';

			}

			if ( ! $this->is_allowed_currencies() ) {
				$admin_page = 'wc-settings';

				echo '<div class="error"><p>' . sprintf( __( 'Transactpro requires that the <a href="%s">currency</a> is set to EUR or USD.', 'woocommerce-transactpro' ), admin_url( 'admin.php?page=' . $admin_page . '&tab=general' ) ) . '</p></div>';
			}
		}

		/**
		 * Include all files needed
		 *
		 * @access public
		 * @return bool
		 */
		public function includes() {
			require_once( dirname( __FILE__ ) . '/includes/class-wc-transactpro-install.php' );
			require_once( dirname( __FILE__ ) . '/includes/class-wc-transactpro-deactivation.php' );
			require_once( dirname( __FILE__ ) . '/includes/class-wc-transactpro-utils.php' );
			require_once( dirname( __FILE__ ) . '/includes/payment/class-wc-transactpro-payment-logger.php' );

			require_once( dirname( __FILE__ ) . '/includes/gw3-php-client/vendor/autoload.php' );
			require_once( dirname( __FILE__ ) . '/includes/payment/class-wc-transactpro-payments.php' );
		}

		/**
		 * Initializes hooks
		 *
		 * @access public
		 * @return bool
		 */
		public function init_hooks() {
			register_deactivation_hook( __FILE__, array( 'WC_Transactpro_Deactivation', 'deactivate' ) );

			if ( class_exists( 'WooCommerce' ) ) {

				add_action( 'plugins_loaded', array( $this, 'load_plugin_textdomain' ) );

				add_action( 'admin_notices', array( $this, 'is_connected_to_transactpro' ) );

				add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'spyr_transactpro_aim_action_links' );

				function spyr_transactpro_aim_action_links( $links ) {
					$plugin_links = [
						'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=transactpro' ) . '">' . __( 'Settings', 'woocommerce-transactpro-aim' ) . '</a>',
					];

					return array_merge( $plugin_links, $links );
				}

			} else {

				add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );

			}
		}

		/**
		 * Load the plugin text domain for translation.
		 *
		 * @access public
		 * @return bool
		 */
		public function load_plugin_textdomain() {
			// todo : if needed we can add locales

			$locale = apply_filters( 'woocommerce_transactpro_plugin_locale', get_locale(), 'woocommerce-transactpro' );

			load_textdomain( 'woocommerce-transactpro', trailingslashit( WP_LANG_DIR ) . 'woocommerce-transactpro/woocommerce-transactpro' . '-' . $locale . '.mo' );

			load_plugin_textdomain( 'woocommerce-transactpro', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

			return true;
		}

		/**
		 * WooCommerce fallback notice.
		 *
		 * @access public
		 * @return string
		 */
		public function woocommerce_missing_notice() {
			echo '<div class="error"><p>' . sprintf( __( 'WooCommerce Transactpro Plugin requires WooCommerce to be installed and active. You can download %s here.', 'woocommerce-transactpro' ), '<a href="https://woocommerce.com/woocommerce/" target="_blank">WooCommerce</a>' ) . '</p></div>';

			return true;
		}

		/**
		 * Shows a notice when the site is not yet connected to transactpro.
		 *
		 * @access public
		 * @return string
		 */
		public function is_connected_to_transactpro() {

			if (empty($this->transactpro_client)) {
				echo '<div class="error"><p>' . sprintf( __( 'WooCommerce Transactpro is almost ready. To get started, %1$sconnect your Transactpro Account.%2$s', 'woocommerce-transactpro' ), '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=transactpro' ) . '">', '</a>' ) . '</p></div>';
			}

			return true;
		}
	}

	Woocommerce_Transactpro::instance();

endif;
