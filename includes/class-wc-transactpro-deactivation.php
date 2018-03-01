<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

class WC_Transactpro_Deactivation {

	/**
	 * Constructor not to be instantiated
	 *
	 * @access private
	 */
	private function __construct() {}

	/**
	 * Perform deactivation tasks
	 *
	 * @access public
	 * @return bool
	 */
	public static function deactivate() {
		// todo: wtf ?
		wp_clear_scheduled_hook( 'woocommerce_transactpro_inventory_poll' );

		return true;
	}
}
