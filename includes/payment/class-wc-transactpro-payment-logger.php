<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Transactpro payment logging class which saves important data to the log
 *
 */
class WC_Transactpro_Payment_Logger {

	public static $logger;

	/**
	 * Utilize WC logger class
	 *
	 */
	public static function log( $message ) {
		if ( empty( self::$logger ) ) {
			self::$logger = new WC_Logger();
		}

		self::$logger->add( 'woocommerce-gateway-transactpro', $message );
	}
}
