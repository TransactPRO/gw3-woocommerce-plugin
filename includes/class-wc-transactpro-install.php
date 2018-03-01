<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Installation/Activation Class.
 *
 * Handles the activation/installation of the plugin.
 *
 * @category Installation
 * @package  WooCommerce Transactpro/Install
 */
class WC_Transactpro_Install {
	/**
	 * Initialize
	 *
	 * @access public
	 * @return bool
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'check_version' ), 5 );

		return true;
	}

	/**
	 * Checks the plugin version
	 *
	 * @access public
	 * @return bool
	 */
	public static function check_version() {
		if ( ! defined( 'IFRAME_REQUEST' ) && ( get_option( 'wc_transactpro_version' ) != WC_TRANSACTPRO_VERSION ) ) {
			self::install();

			do_action( 'wc_transactpro_updated' );
		}

		return true;
	}

	/**
	 * Do installs.
	 *
	 * @access public
	 * @return bool
	 */
	public static function install() {
		self::update_plugin_version();

		return true;
	}

	/**
	 * Updates the plugin version in db
	 *
	 * @access public
	 * @return bool
	 */
	private static function update_plugin_version() {
		delete_option( 'wc_transactpro_version' );
		add_option( 'wc_transactpro_version', WC_TRANSACTPRO_VERSION );

		return true;
	}
}

WC_Transactpro_Install::init();
