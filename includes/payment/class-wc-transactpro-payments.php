<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Class WC_Transactpro_Payments
 *
 * @property $gateway \TransactPro\Gateway\Gateway
 */
class WC_Transactpro_Payments {

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

    private $gateway;

    public function __construct() {
        $this->init();

        add_filter( 'woocommerce_payment_gateways', [ $this, 'register_gateway' ] );

        add_action( 'woocommerce_api_return_url_gateway', [ $this, 'return_url_gateway' ] );
        add_action( 'woocommerce_api_callback_url_gateway', [ $this, 'callback_url_gateway' ] );

        // todo: should we fire events on status change ?
        //add_action( 'woocommerce_order_status_on-hold_to_processing', array( $this, 'capture_payment' ) );
        //add_action( 'woocommerce_order_status_on-hold_to_completed', array( $this, 'capture_payment' ) );
        //add_action( 'woocommerce_order_status_on-hold_to_cancelled', [ $this, 'cancel_payment' ] );
        //add_action( 'woocommerce_order_status_on-hold_to_refunded', [ $this, 'cancel_payment' ] );

        if ( is_admin() ) {
            add_filter( 'woocommerce_order_actions', [ $this, 'order_actions' ] );

            add_action( 'woocommerce_order_action_transactpro_dms_charge', [ $this, 'dms_charge' ] );
            add_action( 'woocommerce_order_action_transactpro_dms_cancel', [ $this, 'dms_cancel' ] );
            add_action( 'woocommerce_order_action_transactpro_sms_reversal', [ $this, 'sms_reversal' ] );
        }

        $gateway_settings = get_option( 'woocommerce_transactpro_settings' );

        if ( ! ( empty( $gateway_settings['user_id'] ) || empty( $gateway_settings['secret_key'] ) || empty( $gateway_settings['gateway_url'] ) ) ) {
            $transactpro_client = new \TransactPro\Gateway\Gateway( $gateway_settings['gateway_url'] );
            $transactpro_client->auth()->setAccountID( $gateway_settings['user_id'] )->setSecretKey( $gateway_settings['secret_key'] );
            $this->gateway = $transactpro_client;
        }

        return true;
    }

    /**
     * Init
     */
    public function init() {
        if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
            return;
        }
        include_once( dirname( __FILE__ ) . '/class-wc-transactpro-gateway.php' );

        return true;
    }

    /**
     * @param $methods
     *
     * @return array
     */
    public function register_gateway( $methods ) {
        $methods[] = 'WC_Transactpro_Gateway';

        return $methods;
    }

    public function order_actions( $actions ) {
        if ( ! isset( $_REQUEST['post'] ) ) {
            return $actions;
        }

        $order = wc_get_order( $_REQUEST['post'] );
        /* @var  WC_Order $order */

        // bail if the order wasn't paid for with this gateway
        if ( 'transactpro' !== $order->get_payment_method() ) {
            return $actions;
        }

        // basing on that 2 statuses we can give admin ability to case allowed actions
        $payment_method = get_post_meta( $order->get_id(), '_transactpro_payment_method', true );
        $is_charged     = get_post_meta( $order->get_id(), '_transactpro_charge_captured', true );

        if ( $payment_method == WC_Transactpro_Gateway::PAYMENT_METHODS['Dms'] && $is_charged == 'no' ) {
            $actions['transactpro_dms_charge'] = esc_html__( 'Charge', 'woocommerce-transactpro' );
            $actions['transactpro_dms_cancel'] = esc_html__( 'Cancel', 'woocommerce-transactpro' );
        }

        if ( $payment_method == WC_Transactpro_Gateway::PAYMENT_METHODS['Sms'] && $is_charged == 'yes' ) {
            $actions['transactpro_sms_reversal'] = esc_html__( 'Cancel', 'woocommerce-transactpro' );
        }

        return $actions;
    }

    public function dms_charge( $order ) {
        if ( 'transactpro' === $order->get_payment_method() ) {
            try {
                $this->log( "Info: Begin charge for order {4" . $order->get_id() . "}" );

                $transaction_id = get_post_meta( $order->get_id(), '_transaction_id', true );
                $payment_method = get_post_meta( $order->get_id(), '_transactpro_payment_method', true );
                $is_charged     = get_post_meta( $order->get_id(), '_transactpro_charge_captured', true );

                if ( $payment_method == WC_Transactpro_Gateway::PAYMENT_METHODS['Dms'] && 'no' == $is_charged ) {

                    $currency = $order->get_currency();
                    $amount   = (int) WC_Transactpro_Utils::format_amount_to_transactpro( $order->get_total(), $currency );

                    $operation = $this->gateway->createDmsCharge();
                    $operation->command()->setGatewayTransactionID( (string) $transaction_id );
                    $operation->money()->setAmount( (int) $amount );

                    $json = $this->process_endpoint( $operation );

                    $transaction_status = $json['gw']['status-code'];
                    $status             = $this->getTransactionStatusName( $transaction_status );

                    if ( self::STATUS_SUCCESS == $transaction_status ) {
                        update_post_meta( $order->get_id(), '_transactpro_charge_captured', 'yes' );
                        wc_reduce_stock_levels( $order->get_id() );
                        $order->add_order_note( __( 'Payment Processed.', 'woocommerce-transactpro' ) . ' ' . __( $status, 'woocommerce-transactpro' ) );
                        $order->update_status( 'completed' );
                        $this->log( "Payment Processed. Transaction Id : " . $transaction_id );

                        return true;
                    }

                } else {
                    $status = sprintf( __( 'Payment can\'t be processed. Method : %1$s Already charged : %2$s', 'woocommerce-transactpro' ), $payment_method, $is_charged );
                }
            } catch ( Exception $e ) {
                $status = sprintf( __( 'Error unable to capture charge: %s', 'woocommerce-transactpro' ), $e->getMessage() );
            }
            $order->update_status( 'failed' );
            $order->add_order_note( $status );
            $this->log( "Payment Failed. " . $status );
        }
    }

    public function dms_cancel( $order ) {
        if ( 'transactpro' === $order->get_payment_method() ) {
            try {
                $this->log( "Info: Begin cancel for order {4" . $order->get_id() . "}" );

                $transaction_id = get_post_meta( $order->get_id(), '_transaction_id', true );
                $payment_method = get_post_meta( $order->get_id(), '_transactpro_payment_method', true );
                $is_charged     = get_post_meta( $order->get_id(), '_transactpro_charge_captured', true );

                if ( $payment_method == WC_Transactpro_Gateway::PAYMENT_METHODS['Dms'] && 'no' == $is_charged ) {

                    $operation = $this->gateway->createCancel();;
                    $operation->command()->setGatewayTransactionID( (string) $transaction_id );

                    $json = $this->process_endpoint( $operation );

                    $transaction_status = $json['gw']['status-code'];
                    $status             = $this->getTransactionStatusName( $transaction_status );

                    if ( self::STATUS_DMS_CANCEL_OK == $transaction_status ) {
                        update_post_meta( $order->get_id(), '_transactpro_charge_captured', 'yes' );
                        $order->add_order_note( __( 'Payment Cancelled.', 'woocommerce-transactpro' ) . ' ' . __( $status, 'woocommerce-transactpro' ) );
                        $order->update_status( 'cancelled' );
                        $this->log( "Payment Cancelled. Transaction Id : " . $transaction_id );

                        return true;
                    }

                } else {
                    $status = sprintf( __( 'Payment can\'t be processed. Method : %1$s Already charged : %2$s', 'woocommerce-transactpro' ), $payment_method, $is_charged );
                }
            } catch ( Exception $e ) {
                $status = sprintf( __( 'Error unable to do refund: %s', 'woocommerce-transactpro' ), $e->getMessage() );
            }
            $order->update_status( 'failed' );
            $order->add_order_note( $status );
        }
    }

    public function sms_reversal( $order ) {
        if ( 'transactpro' === $order->get_payment_method() ) {
            try {
                $this->log( "Info: Begin REVERSAL for order {4" . $order->get_id() . "}" );

                $transaction_id = get_post_meta( $order->get_id(), '_transaction_id', true );
                $payment_method = get_post_meta( $order->get_id(), '_transactpro_payment_method', true );
                $is_charged     = get_post_meta( $order->get_id(), '_transactpro_charge_captured', true );

                if ( $payment_method == WC_Transactpro_Gateway::PAYMENT_METHODS['Sms'] && 'yes' == $is_charged ) {

                    $operation = $this->gateway->createReversal();;
                    $operation->command()->setGatewayTransactionID( (string) $transaction_id );

                    $json = $this->process_endpoint( $operation );

                    $transaction_status = $json['gw']['status-code'];
                    $status             = $this->getTransactionStatusName( $transaction_status );

                    if ( self::STATUS_REVERSED == $transaction_status ) {
                        $order->add_order_note( __( 'Payment Reversed.', 'woocommerce-transactpro' ) . ' ' . __( $status, 'woocommerce-transactpro' ) );
                        $order->update_status( 'cancelled' );
                        $this->log( "Payment Reversed. Transaction Id : " . $transaction_id );

                        return true;
                    }

                } else {
                    $status = sprintf( __( 'Payment can\'t be reversed. Method : %1$s Not charged : %2$s', 'woocommerce-transactpro' ), $payment_method, $is_charged );
                }
            } catch ( Exception $e ) {
                $status = sprintf( __( 'Error unable to do reverse: %s', 'woocommerce-transactpro' ), $e->getMessage() );
            }
            $order->add_order_note( $status );
        }
    }

    public function return_url_gateway() {
        $this->log( "Server got request on Return URL. Request: \n " . $_REQUEST );

        if ($order_id = WC()->session->get( 'waiting_for_return_order_id')){
            WC()->session->set( 'waiting_for_return_order_id', null);
            $order = wc_get_order( $order_id );
            $order->add_order_note( __( 'User returned from gateway.', 'woocommerce-transactpro' ) );
            $url = $this->get_return_url( $order );
            wp_safe_redirect( $url );
        }
    }

    public function callback_url_gateway() {
        $this->log( "Server got request on Callback URL. Request: \n " . $_REQUEST );

        if ( isset( $_POST['json'] ) ) {
            $json = json_decode( html_entity_decode( $_POST['json'] ), true );

            $json_status = json_last_error();

            if ( JSON_ERROR_NONE == $json_status && isset( $json['result-data']['gw']['gateway-transaction-id'] ) && isset( $json['result-data']['gw']['status-code'] ) ) {

                $transaction_id     = $json['result-data']['gw']['gateway-transaction-id'];
                $transaction_status = (int) $json['result-data']['gw']['status-code'];
                $status             = $this->getTransactionStatusName( $transaction_status );

                $orders = wc_get_orders( [ 'transaction_id' => $transaction_id ] );

                if (!empty($orders) && count($orders) == 1) {
                    $order = array_shift($orders);

                    $this->log( "Found appropriate Order Id : " .  $order->get_id() . "\n Status : " . $status);

                    if ( $transaction_status == self::STATUS_SUCCESS) {
                        update_post_meta( $order->get_id(), '_transactpro_charge_captured', 'yes' );
                        wc_reduce_stock_levels( $order->get_id() );
                        $order->add_order_note( __( 'Payment Processed.', 'woocommerce-transactpro' ) . ' ' . __( $status, 'woocommerce-transactpro' ) );
                        $order->update_status( 'completed' );
                        $this->log( "Payment Processed. Transaction Id : " . $transaction_id );

                        return true;
                    }

                    $order->update_status( 'failed' );
                    $order->add_order_note( __( 'Payment Failed.', 'woocommerce-transactpro' ) . ' ' . $status  );
                    $this->log( "Payment Failed. " . $status );

                    return false;
                }
                $this->log( "Order not found. Transaction ID : " . $transaction_id . " Status : " . $status );
            }
        }
        $this->log( "Order processing error." );
    }

    /**
     * Capture payment when the order is changed from on-hold to complete or processing
     * not used
     *
     * @param int $order_id
     */
    public function capture_payment( $order_id ) {
        $order = wc_get_order( $order_id );

        if ( 'transactpro' === $order->get_payment_method() ) {
            try {
                $this->log( "Info: Begin capture for order {$order_id}" );

                $transaction_id = get_post_meta( $order->get_id(), '_transaction_id', true );
                $payment_method = get_post_meta( $order->get_id(), '_transactpro_payment_method', true );

                if ( $payment_method == WC_Transactpro_Gateway::PAYMENT_METHODS['Dms'] && 'no' === get_post_meta( $order->get_id(), '_transactpro_charge_captured', true ) ) {


                    $currency = $order->get_currency();
                    $amount   = (int) WC_Transactpro_Utils::format_amount_to_transactpro( $order->get_total(), $currency );

                    $endpoint = $this->gateway->{'createDmsCharge'}();
                    $endpoint->command()->setGatewayTransactionID( $transaction_id );
                    $endpoint->money()->setAmount( $amount );

                    $json = $this->process_endpoint( $endpoint );
                    $this->log( "Payment Processed. Response from gateway : " . json_encode( $json ) );

                } else {
                    $this->log( "Payment not processed. Method : " . $payment_method . " Meta : " . get_post_meta( $order->get_id(), '_transactpro_charge_captured', true ) );
                }

            } catch ( Exception $e ) {
                $this->log( sprintf( __( 'Error unable to capture charge: %s', 'woocommerce-transactpro' ), $e->getMessage() ) );
            }
        }
    }

    /**
     * Cancel authorization
     *
     * @param  int $order_id
     */
    public function cancel_payment( $order_id ) {

        $order = wc_get_order( $order_id );

        if ( 'transactpro' === $order->get_payment_method() ) {
            try {
                $this->log( "Info: Cancel payment for order {$order_id}" );

                $transaction_id = get_post_meta( $order_id, '_transaction_id', true );
                $payment_method = get_post_meta( $order_id, '_transactpro_payment_method', true );

                if ( $payment_method == WC_Transactpro_Gateway::PAYMENT_METHODS['Sms'] ) {
                    $method = $this->gateway->createReversal();
                } else {
                    $method = $this->gateway->createCancel();
                }
                $method->command()->setGatewayTransactionID( $transaction_id );

                $json = $this->process_endpoint( $method );

                $transaction_status = $json['gw']['status-code'];
                $status_name        = $this->getTransactionStatusName( $transaction_status );
                $comment            = $this->getMessage( 'transaction_status_' . strtolower( $status_name ) . '_help' );

                if ( self::STATUS_REVERSED == $transaction_status || self::STATUS_DMS_CANCEL_OK == $transaction_status ) {
                    $order->add_order_note( sprintf( __( 'Transactpro charge voided! ' . $comment . ' (Charge ID: %s)', 'woocommerce-transactpro' ), $transaction_id ) );
                    delete_post_meta( $order_id, '_transactpro_charge_captured' );
                    delete_post_meta( $order_id, '_transaction_id' );
                } else {

                    $order->add_order_note( __( 'Unable to void charge!', 'woocommerce-transactpro' ) . ' ' . $comment );
                    throw new Exception( $comment );
                }

            } catch ( Exception $e ) {
                $this->log( sprintf( __( 'Unable to void charge!: %s', 'woocommerce-transactpro' ), $e->getMessage() ) );
            }
        }
    }

    public function getMessage( $a ) {
        $_['transaction_status_init_help']                         = 'Successful transaction start.';
        $_['transaction_status_sent_to_bank_help']                 = 'Awaiting response from acquirer.';
        $_['transaction_status_hold_ok_help']                      = 'Funds successfully reserved.';
        $_['transaction_status_dms_hold_failed_help']              = 'Fund reservation failed.';
        $_['transaction_status_sms_failed_sms_help']               = 'SMS transaction failed.';
        $_['transaction_status_dms_charge_failed_help']            = 'Reserved fund charge failed.';
        $_['transaction_status_success_help']                      = 'Funds successfully transferred.';
        $_['transaction_status_expired_help']                      = 'Time given to perform current action is expired.';
        $_['transaction_status_hold_expired_help']                 = 'Fund reservation is expired.';
        $_['transaction_status_refund_failed_help']                = 'Failed to perform REFUND transaction.';
        $_['transaction_status_refund_pending_help']               = 'Refund request is in process.';
        $_['transaction_status_refund_success_help']               = 'Successful refund operation.';
        $_['transaction_status_dms_cancel_ok_help']                = 'Reservation successfully canceled.';
        $_['transaction_status_dms_cancel_failed_help']            = 'Failed to cancel reserved funds.';
        $_['transaction_status_reversed_help']                     = 'Operation successfully reversed.';
        $_['transaction_status_input_validation_failed_help']      = 'Invalid payload data provided.';
        $_['transaction_status_br_validation_failed_help']         = 'Business rules declined current action.';
        $_['transaction_status_terminal_group_select_failed_help'] = 'Failed to select terminal group.';
        $_['transaction_status_terminal_select_failed_help']       = 'Failed to select terminal.';
        $_['transaction_status_declined_by_br_action_help']        = 'Business rules declined current action.';
        $_['transaction_status_waiting_card_form_fill_help']       = 'Transaction is waiting till cardholder enters card data.';
        $_['transaction_status_mpi_url_generated_help']            = 'Gateway provided URL to proceed with 3D authentication.';
        $_['transaction_status_waiting_mpi_help']                  = 'Transaction is waiting for 3D authentication.';
        $_['transaction_status_mpi_failed_help']                   = '3D authentication failed.';
        $_['transaction_status_mpi_not_reachable_help']            = '3D authentication service is unavailable.';
        $_['transaction_status_inside_form_url_sent_help']         = 'Gateway provided URL where inside form resides.';
        $_['transaction_status_mpi_auth_failed_help']              = '3D service declined transaction.';
        $_['transaction_status_acquirer_not_reachable_help']       = 'Acquirer service is unavailable.';
        $_['transaction_status_reversal_failed_help']              = 'Failed to reverse given transaction.';
        $_['transaction_status_credit_failed_help']                = 'Failed to process credit transaction.';
        $_['transaction_status_p2p_failed_help']                   = 'Failed to process P2P transaction.';

        if ( array_key_exists( $a, $_ ) ) {
            return $_[ $a ];
        } else {
            return 'UNKNOWN';
        }
    }

    public function getTransactionStatusName( $transaction_status ) {
        $status_names = [
            self::STATUS_INIT                         => 'INIT',
            self::STATUS_SENT_TO_BANK                 => 'SENT_TO_BANK',
            self::STATUS_HOLD_OK                      => 'HOLD_OK',
            self::STATUS_DMS_HOLD_FAILED              => 'DMS_HOLD_FAILED',
            self::STATUS_SMS_FAILED_SMS               => 'SMS_FAILED_SMS',
            self::STATUS_DMS_CHARGE_FAILED            => 'DMS_CHARGE_FAILED',
            self::STATUS_SUCCESS                      => 'SUCCESS',
            self::STATUS_EXPIRED                      => 'EXPIRED',
            self::STATUS_HOLD_EXPIRED                 => 'HOLD_EXPIRED',
            self::STATUS_REFUND_FAILED                => 'REFUND_FAILED',
            self::STATUS_REFUND_PENDING               => 'REFUND_PENDING',
            self::STATUS_REFUND_SUCCESS               => 'REFUND_SUCCESS',
            self::STATUS_DMS_CANCEL_OK                => 'Reservation successfully canceled.',
            self::STATUS_DMS_CANCEL_FAILED            => 'DMS_CANCEL_FAILED',
            self::STATUS_REVERSED                     => 'REVERSED',
            self::STATUS_INPUT_VALIDATION_FAILED      => 'INPUT_VALIDATION_FAILED',
            self::STATUS_BR_VALIDATION_FAILED         => 'BR_VALIDATION_FAILED',
            self::STATUS_TERMINAL_GROUP_SELECT_FAILED => 'TERMINAL_GROUP_SELECT_FAILED',
            self::STATUS_TERMINAL_SELECT_FAILED       => 'TERMINAL_SELECT_FAILED',
            self::STATUS_DECLINED_BY_BR_ACTION        => 'DECLINED_BY_BR_ACTION',
            self::STATUS_WAITING_CARD_FORM_FILL       => 'WAITING_CARD_FORM_FILL',
            self::STATUS_MPI_URL_GENERATED            => 'MPI_URL_GENERATED',
            self::STATUS_WAITING_MPI                  => 'WAITING_MPI',
            self::STATUS_MPI_FAILED                   => 'MPI_FAILED',
            self::STATUS_MPI_NOT_REACHABLE            => 'MPI_NOT_REACHABLE',
            self::STATUS_INSIDE_FORM_URL_SENT         => 'INSIDE_FORM_URL_SENT',
            self::STATUS_MPI_AUTH_FAILED              => 'MPI_AUTH_FAILED',
            self::STATUS_ACQUIRER_NOT_REACHABLE       => 'ACQUIRER_NOT_REACHABLE',
            self::STATUS_REVERSAL_FAILED              => 'REVERSAL_FAILED',
            self::STATUS_CREDIT_FAILED                => 'CREDIT_FAILED',
            self::STATUS_P2P_FAILED                   => 'P2P_FAILED',
        ];

        if ( array_key_exists( $transaction_status, $status_names ) ) {
            return $status_names[ $transaction_status ];
        } else {
            return 'UNKNOWN';
        }
    }

    private function process_endpoint( $operation ) {
        $request = $this->gateway->generateRequest( $operation );

        $response = $this->gateway->process( $request );

        if ( 200 !== $response->getStatusCode() ) {
            throw new Exception( $response->getBody(), $response->getStatusCode() );
        }

        $json        = json_decode( $response->getBody(), true );
        $json_status = json_last_error();

        if ( JSON_ERROR_NONE !== $json_status ) {
            throw new Exception( 'JSON ' . json_last_error_msg(), $json_status );
        }

        $this->log( 'TransactPro response: ' . json_encode( $json ) );

        if ( empty( $json ) || ( empty( $json['gw'] ) && empty( $json['error'] ) ) ) {
            throw new Exception( 'Unexpected payment gateway response.' );
        }

        if ( ! empty( $json['error'] ) ) {
            throw new Exception( $json['error']['message'], $json['error']['code'] );
        } else {
            unset( $json['error'] );
        }

        return $json;
    }

    /**
     * Logs
     *
     * @param string $message
     */
    public function log( $message ) {
        WC_Transactpro_Payment_Logger::log( $message );
    }
}

new WC_Transactpro_Payments();
