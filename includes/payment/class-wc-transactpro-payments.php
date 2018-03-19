<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Class WC_Transactpro_Payments
 *
 * @property $gateway \TransactPro\Gateway\Gateway
 */
class WC_Transactpro_Payments
{
    private $gateway;

    public function __construct() {
        $this->init();

        add_filter( 'woocommerce_payment_gateways', [ $this, 'register_gateway' ] );

        add_action( 'woocommerce_api_return_url_gateway', [ $this, 'return_url_gateway' ] );
        add_action( 'woocommerce_api_callback_url_gateway', [ $this, 'callback_url_gateway' ] );

        // we can fire events on status change - example : 'woocommerce_order_status_on-hold_to_processing' ... to_completed, to_cancelled, to_refunded

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
        include_once( dirname( __FILE__ ) . '/class-wc-transactpro-gateway-subscriptions.php' );

        return true;
    }

    /**
     * @param $methods
     *
     * @return array
     */
    public function register_gateway( $methods ) {

        if ( class_exists( 'WC_Subscriptions_Order' ) ) {
            $methods[] = 'WC_Transactpro_Gateway_Subscriptions';
        } else {
            $methods[] = 'WC_Transactpro_Gateway';
        }

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
                WC_Transactpro_Utils::log( "Info: Begin charge for order {" . $order->get_id() . "}" );

                $transaction_id = get_post_meta( $order->get_id(), '_transaction_id', true );
                $payment_method = get_post_meta( $order->get_id(), '_transactpro_payment_method', true );
                $is_charged     = get_post_meta( $order->get_id(), '_transactpro_charge_captured', true );

                if ( $payment_method == WC_Transactpro_Gateway::PAYMENT_METHODS['Dms'] && $is_charged == 'no' ) {

                    $currency = $order->get_currency();
                    $amount   = (int) WC_Transactpro_Utils::format_amount_to_transactpro( $order->get_total(), $currency );

                    $operation = $this->gateway->createDmsCharge();
                    $operation->command()->setGatewayTransactionID( (string) $transaction_id );
                    $operation->money()->setAmount( (int) $amount );

                    $json = WC_Transactpro_Utils::process_endpoint( $this->gateway, $operation );

                    // in this point we receive new transaction_id

                    $transaction_id = ! empty( $json['gw']['gateway-transaction-id'] ) ? $json['gw']['gateway-transaction-id'] : false;
                    $status_code = $json['gw']['status-code'];
                    $status      = WC_Transactpro_Utils::getTransactionStatus( $status_code );

                    if ( $status_code == WC_Transactpro_Utils::STATUS_SUCCESS ) {
                        update_post_meta( $order->get_id(), '_transactpro_charge_captured', 'yes' );
                        update_post_meta( $order->get_id(), '_transaction_id', $transaction_id );

                        wc_reduce_stock_levels( $order->get_id() );

                        $order->update_status( 'completed', __( 'Payment Processed.', 'woocommerce-transactpro' ) . ' ' . $status );
                        WC_Transactpro_Utils::log( "Payment Processed. Transaction Id : " . $transaction_id );
                        return true;
                    }

                } else {
                    $status = sprintf( __( 'Payment can\'t be processed. Method : %1$s  Is Charged : %2$s', 'woocommerce-transactpro' ), $payment_method, $is_charged );
                }
            } catch ( Exception $e ) {
                $status = sprintf( __( 'Error unable to capture charge: %s', 'woocommerce-transactpro' ), $e->getMessage() );
            }

            $order->update_status( 'failed',  $status );
            WC_Transactpro_Utils::log( "DMS Charge Failed. " . $status );
        }
    }

    public function dms_cancel( $order ) {
        if ( 'transactpro' === $order->get_payment_method() ) {
            try {
                WC_Transactpro_Utils::log( "Info: Begin cancel for order {4" . $order->get_id() . "}" );

                $transaction_id = get_post_meta( $order->get_id(), '_transaction_id', true );
                $payment_method = get_post_meta( $order->get_id(), '_transactpro_payment_method', true );
                $is_charged     = get_post_meta( $order->get_id(), '_transactpro_charge_captured', true );

                if ( $payment_method == WC_Transactpro_Gateway::PAYMENT_METHODS['Dms'] && 'no' == $is_charged ) {

                    $operation = $this->gateway->createCancel();;
                    $operation->command()->setGatewayTransactionID( (string) $transaction_id );

                    $json = WC_Transactpro_Utils::process_endpoint( $this->gateway, $operation );

                    $status_code = $json['gw']['status-code'];
                    $status      = WC_Transactpro_Utils::getTransactionStatus( $status_code );

                    if ( $status_code == WC_Transactpro_Utils::STATUS_DMS_CANCEL_OK ) {
                        delete_post_meta( $order->get_id(), '_transactpro_charge_captured' );

                        $order->update_status( 'cancelled', __( 'Payment Cancelled.', 'woocommerce-transactpro' ) . ' ' . $status );
                        WC_Transactpro_Utils::log( "Payment Cancelled. Transaction Id : " . $transaction_id );
                        return true;
                    }

                } else {
                    $status = sprintf( __( 'Payment can\'t be processed. Method : %1$s  Is Charged : %2$s', 'woocommerce-transactpro' ), $payment_method, $is_charged );
                }
            } catch ( Exception $e ) {
                $status = sprintf( __( 'Error unable to cancel charge: %s', 'woocommerce-transactpro' ), $e->getMessage() );
            }

            $order->update_status( 'failed',  $status );
            WC_Transactpro_Utils::log( "DMS Cancel Failed. " . $status );
        }
    }

    public function sms_reversal( $order ) {
        if ( 'transactpro' === $order->get_payment_method() ) {
            try {
                WC_Transactpro_Utils::log( "Info: Begin REVERSAL for order {4" . $order->get_id() . "}" );

                $transaction_id = get_post_meta( $order->get_id(), '_transaction_id', true );
                $payment_method = get_post_meta( $order->get_id(), '_transactpro_payment_method', true );
                $is_charged     = get_post_meta( $order->get_id(), '_transactpro_charge_captured', true );

                if ( $payment_method == WC_Transactpro_Gateway::PAYMENT_METHODS['Sms'] && $is_charged == 'yes') {

                    $operation = $this->gateway->createReversal();;
                    $operation->command()->setGatewayTransactionID( (string) $transaction_id );

                    $json = WC_Transactpro_Utils::process_endpoint( $this->gateway, $operation );

                    $status_code = $json['gw']['status-code'];
                    $status      = WC_Transactpro_Utils::getTransactionStatus( $status_code );

                    if ( $status_code == WC_Transactpro_Utils::STATUS_REVERSED ) {
                        delete_post_meta( $order->get_id(), '_transactpro_charge_captured' );
                        $this->restore_order_stock( $order->get_id() );

                        $order->update_status( 'cancelled', __( 'Payment Reversed.', 'woocommerce-transactpro' ) . ' ' . $status );
                        WC_Transactpro_Utils::log( "Payment Reversed. Transaction Id : " . $transaction_id );

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
        WC_Transactpro_Utils::log( "Server got request on Return URL. Request: \n " . var_export($_REQUEST, 1));

        if ($order_id = WC()->session->get( 'waiting_for_return_order_id')){

            $order = wc_get_order( $order_id );
            $order->add_order_note( __( 'User returned from gateway.', 'woocommerce-transactpro' ) );
            $return_url = $order->get_checkout_order_received_url();

            if ( is_ssl() || get_option( 'woocommerce_force_ssl_checkout' ) == 'yes' ) {
                $return_url = str_replace( 'http:', 'https:', $return_url );
            }

            WC()->session->set( 'waiting_for_return_order_id', null);
            $url = apply_filters( 'woocommerce_get_return_url', $return_url, $order );

            /**
             * todo: we can get order status and show some info to user - in situation when something goes wrong it cat be helpful
             * but it's not clear what to do when return fired before callback
             */

            wp_safe_redirect( $url );
        }
    }

    public function callback_url_gateway() {
        WC_Transactpro_Utils::log( "Server got request on Callback URL. Request: \n " . var_export($_REQUEST, 1) );

        if ( isset( $_POST['json'] ) ) {

            $json = json_decode( html_entity_decode( $_POST['json'] ), true );
            $json_status = json_last_error();

            if ( JSON_ERROR_NONE == $json_status && isset( $json['result-data']['gw']['gateway-transaction-id'] ) && isset( $json['result-data']['gw']['status-code'] ) ) {

                $transaction_id = $json['result-data']['gw']['gateway-transaction-id'];
                $status_code    = $json['gw']['status-code'];
                $status         = WC_Transactpro_Utils::getTransactionStatus( $status_code );

                $orders = wc_get_orders( [ 'transaction_id' => $transaction_id ] );

                if (!empty($orders) && count($orders) == 1) {
                    $order = array_shift($orders);

                    WC_Transactpro_Utils::log( "Found appropriate Order Id : " .  $order->get_id() . "\n Status : " . $status);

                    if ( $status_code == WC_Transactpro_Utils::STATUS_SUCCESS) {
                        update_post_meta( $order->get_id(), '_transactpro_charge_captured', 'yes' );
                        wc_reduce_stock_levels( $order->get_id() );

                        $order->update_status( 'completed', __( 'Payment Processed.', 'woocommerce-transactpro' ) . ' ' . $status );
                        WC_Transactpro_Utils::log( "Payment Processed. Transaction Id : " . $transaction_id );

                        return true;
                    }

                    if ( $status_code == WC_Transactpro_Utils::STATUS_HOLD_OK ) {
                        $order->update_status( 'on-hold', __( 'Transaction accepted - you can charge or cancel payment', 'woocommerce-transactpro' ) . ' ' . $status );
                        WC_Transactpro_Utils::log( "Payment on hold. Transaction Id : " . $transaction_id );
                        return true;
                    }

                    $order->update_status( 'failed', __( 'Payment Failed.', 'woocommerce-transactpro' ) . ' ' . $status );
                    WC_Transactpro_Utils::log( "Payment Failed. " . $status );

                    return false;
                }
                WC_Transactpro_Utils::log( "Order not found. Transaction ID : " . $transaction_id . " Status : " . $status );
            }
        }
        WC_Transactpro_Utils::log( "Order processing error." );
    }

    public function restore_order_stock( $order_id ) {
        $order = new WC_Order( $order_id );

        if ( ! get_option('woocommerce_manage_stock') == 'yes' && ! sizeof( $order->get_items() ) > 0 ) {
            return;
        }

        foreach ( $order->get_items() as $item ) {
            if ( $item['product_id'] > 0 ) {
                $_product = $item->get_product();

                if ( $_product && $_product->exists() && $_product->managing_stock() ) {
                    $old_stock = $_product->stock;
                    $qty = apply_filters( 'woocommerce_order_item_quantity', $item['qty'], $this, $item );

                    wc_update_product_stock( $_product, $qty, 'increase' );
                    do_action( 'woocommerce_auto_stock_restored', $_product, $item );
                    $order->add_order_note( sprintf( __( 'Item #%s stock incremented from %s to %s.', 'woocommerce' ), $item['product_id'], $old_stock, $qty) );
                }
            }
        }
    }
}

new WC_Transactpro_Payments();
