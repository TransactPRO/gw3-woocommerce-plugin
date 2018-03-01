<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Class WC_Transactpro_Utils
 *
 * Static helper methods for the WC <-> Transactpro integration, used in multiple
 * places throughout the extension, with no dependencies of their own.
 *
 * Mostly data formatting and entity retrieval methods.
 */
class WC_Transactpro_Utils {

	const WC_TERM_TRANSACTPRO_ID          = 'transactpro_cat_id';
	const WC_PRODUCT_TRANSACTPRO_ID       = '_transactpro_item_id';
	const WC_VARIATION_TRANSACTPRO_ID     = '_transactpro_item_variation_id';
	const WC_PRODUCT_IMAGE_TRANSACTPRO_ID = '_transactpro_item_image_id';




	/**
	 * Process amount to be passed to Transactpro.
	 * @return float
	 */
	public static function format_amount_to_transactpro( $total, $currency = '' ) {
		if ( ! $currency ) {
			$currency = get_woocommerce_currency();
		}

		switch ( strtoupper( $currency ) ) {
			// Zero decimal currencies
			case 'BIF':
			case 'CLP':
			case 'DJF':
			case 'GNF':
			case 'JPY':
			case 'KMF':
			case 'KRW':
			case 'MGA':
			case 'PYG':
			case 'RWF':
			case 'VND':
			case 'VUV':
			case 'XAF':
			case 'XOF':
			case 'XPF':
				$total = absint( $total );
				break;
			default:
				$total = absint( wc_format_decimal( ( (float) $total * 100 ), wc_get_price_decimals() ) ); // In cents.
				break;
		}

		return $total;
	}


}
