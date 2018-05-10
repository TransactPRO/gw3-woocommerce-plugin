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


    const STATUS_INIT = 1;
    const STATUS_SENT_TO_BANK = 2;
    const STATUS_HOLD_OK = 3;
    const STATUS_DMS_HOLD_FAILED = 4;
    const STATUS_SMS_FAILED_SMS = 5;
    const STATUS_DMS_CHARGE_FAILED = 6;
    const STATUS_SUCCESS = 7;
    const STATUS_EXPIRED = 8;
    const STATUS_HOLD_EXPIRED = 9;
    const STATUS_REFUND_FAILED = 11;
    const STATUS_REFUND_PENDING = 12;
    const STATUS_REFUND_SUCCESS = 13;
    const STATUS_DMS_CANCEL_OK = 15;
    const STATUS_DMS_CANCEL_FAILED = 16;
    const STATUS_REVERSED = 17;
    const STATUS_INPUT_VALIDATION_FAILED = 18;
    const STATUS_BR_VALIDATION_FAILED = 19;
    const STATUS_TERMINAL_GROUP_SELECT_FAILED = 20;
    const STATUS_TERMINAL_SELECT_FAILED = 21;
    const STATUS_DECLINED_BY_BR_ACTION = 23;
    const STATUS_WAITING_CARD_FORM_FILL = 25;
    const STATUS_MPI_URL_GENERATED = 26;
    const STATUS_WAITING_MPI = 27;
    const STATUS_MPI_FAILED = 28;
    const STATUS_MPI_NOT_REACHABLE = 29;
    const STATUS_INSIDE_FORM_URL_SENT = 30;
    const STATUS_MPI_AUTH_FAILED = 31;
    const STATUS_ACQUIRER_NOT_REACHABLE = 32;
    const STATUS_REVERSAL_FAILED = 33;
    const STATUS_CREDIT_FAILED = 34;
    const STATUS_P2P_FAILED = 35;

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

    public static function getTransactionStatus( $status_code ) {
        $status_names = [
            self::STATUS_INIT                         => 'Successful transaction start.',
            self::STATUS_SENT_TO_BANK                 => 'Awaiting response from acquirer.',
            self::STATUS_HOLD_OK                      => 'Funds successfully reserved.',
            self::STATUS_DMS_HOLD_FAILED              => 'Fund reservation failed.',
            self::STATUS_SMS_FAILED_SMS               => 'SMS transaction failed.',
            self::STATUS_DMS_CHARGE_FAILED            => 'Reserved fund charge failed.',
            self::STATUS_SUCCESS                      => 'Funds successfully transferred.',
            self::STATUS_EXPIRED                      => 'Time given to perform current action is expired.',
            self::STATUS_HOLD_EXPIRED                 => 'Fund reservation is expired.',
            self::STATUS_REFUND_FAILED                => 'Failed to perform REFUND transaction.',
            self::STATUS_REFUND_PENDING               => 'Refund request is in process.',
            self::STATUS_REFUND_SUCCESS               => 'Successful refund operation.',
            self::STATUS_DMS_CANCEL_OK                => 'Reservation successfully canceled.',
            self::STATUS_DMS_CANCEL_FAILED            => 'Failed to cancel reserved funds.',
            self::STATUS_REVERSED                     => 'Operation successfully reversed.',
            self::STATUS_INPUT_VALIDATION_FAILED      => 'Invalid payload data provided.',
            self::STATUS_BR_VALIDATION_FAILED         => 'Business rules declined current action.',
            self::STATUS_TERMINAL_GROUP_SELECT_FAILED => 'Failed to select terminal group.',
            self::STATUS_TERMINAL_SELECT_FAILED       => 'Failed to select terminal.',
            self::STATUS_DECLINED_BY_BR_ACTION        => 'Business rules declined current action.',
            self::STATUS_WAITING_CARD_FORM_FILL       => 'Transaction is waiting till cardholder enters card data.',
            self::STATUS_MPI_URL_GENERATED            => 'Gateway provided URL to proceed with 3D authentication.',
            self::STATUS_WAITING_MPI                  => 'Transaction is waiting for 3D authentication.',
            self::STATUS_MPI_FAILED                   => '3D authentication failed.',
            self::STATUS_MPI_NOT_REACHABLE            => '3D authentication service is unavailable.',
            self::STATUS_INSIDE_FORM_URL_SENT         => 'Gateway provided URL where inside form resides.',
            self::STATUS_MPI_AUTH_FAILED              => '3D service declined transaction.',
            self::STATUS_ACQUIRER_NOT_REACHABLE       => 'Acquirer service is unavailable.',
            self::STATUS_REVERSAL_FAILED              => 'Failed to reverse given transaction.',
            self::STATUS_CREDIT_FAILED                => 'Failed to process credit transaction.',
            self::STATUS_P2P_FAILED                   => 'Failed to process P2P transaction.',
        ];

        if ( array_key_exists( $status_code, $status_names ) ) {
            return __( $status_names[ $status_code ] );
        } else {
            return 'UNKNOWN';
        }
    }

    public static function is_currency_supported() {
        return true;
	//return in_array(get_woocommerce_currency(), ['USD', 'EUR']);
    }

    public static function process_endpoint($gateway, $operation) {

        $request  = $gateway->generateRequest( $operation );
        $response = $gateway->process( $request );
        self::log( 'TransactPro response: ' . $response->getBody() );

        if ( 200 !== $response->getStatusCode() ) {
            throw new Exception( $response->getBody(), $response->getStatusCode() );
        }

        $json        = json_decode( $response->getBody(), true );
        $json_status = json_last_error();

        if ( JSON_ERROR_NONE !== $json_status ) {
            throw new Exception( 'JSON: ' . json_last_error_msg(), $json_status );
        }

        if ( empty( $json ) || ( empty( $json['gw'] ) && empty( $json['error'] ) ) ) {
            throw new Exception( 'Unexpected payment gateway response.' );
        }

        if ( ! empty( $json['error'] ) ) {
            throw new Exception( 'ERROR: ' . $json['error']['message'], $json['error']['code'] );
        } else {
            unset( $json['error'] );
        }

        return $json;
    }

    public static function log( $message ) {
        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            WC_Transactpro_Payment_Logger::log( $message );
        }
    }
}
