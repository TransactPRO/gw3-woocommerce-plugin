<?php
/* TransactPro AIM Payment Gateway Class */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Class WC_Transactpro_Gateway_Subscriptions
 */
class WC_Transactpro_Gateway_Subscriptions extends WC_Transactpro_Gateway {

    function __construct() {

        parent::__construct();

        add_action( 'woocommerce_scheduled_subscription_payment_' . $this->id, array( $this, 'scheduled_subscription_payment' ), 10, 2 );

        add_filter( 'woocommerce_my_subscriptions_payment_method', array( $this, 'maybe_render_subscription_payment_method' ), 10, 2 );

        // allow store managers to manually set Transactpro as the payment method on a subscription
        add_filter( 'woocommerce_subscription_payment_meta', array( $this, 'add_subscription_payment_meta' ), 10, 2 );
        add_filter( 'woocommerce_subscription_validate_payment_meta', array( $this, 'validate_subscription_payment_meta' ), 10, 2 );
    }


    /**
     * @param $payment_meta
     * @param $subscription
     *
     * @return mixed
     */
    public function add_subscription_payment_meta( $payment_meta, $subscription ) {

        if ( $parent_order = $subscription->get_parent() ) {
            $transaction_id = $parent_order->get_transaction_id();
        } else {
            $transaction_id = false;
        }

        WC_Transactpro_Utils::log( "INIT META : id - " . $subscription->get_id() . " transaction_id - " . $transaction_id );

        $payment_meta[ $this->id ] = array(
            'post_meta' => array(
                '_transaction_id' => array(
                    'value' => $transaction_id,
                    'label' => 'Transactpro Transaction ID',
                ),
            ),
        );

        return $payment_meta;
    }


    /**
     * Render the payment method used for a subscription in the "My Subscriptions" table
     *
     * @param $payment_method_to_display
     * @param $subscription
     *
     * @return string
     */
    public function maybe_render_subscription_payment_method( $payment_method_to_display, $subscription ) {
        // basically this function does nothing but - can be useful in featured payment methods.

        WC_Transactpro_Utils::log( "maybe_render_subscription_payment_method: \n payment_method_to_display: " . var_export( $payment_method_to_display, 1 ) );

        $subscription_attributes = wc_get_payment_gateway_by_order( $subscription );

        //bail for other payment methods
        if ( ! is_a( $subscription_attributes->gateway, get_class() ) ) {
            return $payment_method_to_display;
        }
        return $payment_method_to_display;
    }


    /**
     * scheduled_subscription_payment function.
     *
     * @param $amount_to_charge float The amount to charge.
     * @param $order            WC_Order The WC_Order object of the order which the subscription was purchased in.
     *
     * @access public
     * @return void
     */
    function scheduled_subscription_payment( $amount_to_charge, $order ) {

        WC_Transactpro_Utils::log( "Scheduled Subscription Payment" );

        $result = $this->process_subscription_payment( $order, $amount_to_charge );

        if ( is_wp_error( $result ) ) {

            $order->add_order_note( sprintf( __( 'TransactPro subscription renewal failed - %s', 'woocommerce-transactpro' ), $result->get_error_message() ) );
        }
    }


    /**
     * process_subscription_payment function.
     *
     * @param string $order
     * @param int    $amount
     *
     * @return bool|\WP_Error
     */
    function process_subscription_payment( $order = '', $amount = 0 ) {

        WC_Transactpro_Utils::log( "process_subscription_payment: \n order: " . $order->get_id() . " \n amount: " . var_export( $amount, 1 ) );

        $parent_transaction_id = $this->get_transaction_token( $order );

        if ( ! $parent_transaction_id ) {
            return new WP_Error( 'woocommerce-transactpro', __( 'Parent Transaction ID not found', 'woocommerce-transactpro' ) );
        }

        // Charge the customer
        try {
            WC_Transactpro_Utils::log( "Charge the customer. Parent Transaction ID : " . $parent_transaction_id );


            $orders = wc_get_orders( [ 'transaction_id' => $parent_transaction_id ] );

            if ( ! empty( $orders ) && count( $orders ) == 1 ) {
                $parent_order = array_shift( $orders );

                WC_Transactpro_Utils::log( "Found appropriate Parent Order Id : " . $parent_order->get_id() );

            } else {
                return new WP_Error( 'woocommerce-transactpro', __( 'Parent order not found', 'woocommerce-transactpro' ) );
            }

            $payment_method    = get_post_meta( $parent_order->get_id(), '_transactpro_payment_method', true );
            $parent_is_charged = get_post_meta( $parent_order->get_id(), '_transactpro_charge_captured', true );

            if ( $parent_is_charged == 'no' ) {
                return new WP_Error( 'woocommerce-transactpro', __( 'Parent order is not charged. Can not process.', 'woocommerce-transactpro' ) );
            }

            $currency = $parent_order->get_currency();
            $amount   = (int) WC_Transactpro_Utils::format_amount_to_transactpro( $amount, $currency );

            $operation = $this->gateway->{'createRecurrent' . $payment_method}();


            $operation->command()->setGatewayTransactionID( (string) $parent_transaction_id );
            $operation->money()->setAmount( (int) $amount );

            $json = WC_Transactpro_Utils::process_endpoint( $this->gateway, $operation );

            $transaction_id = ! empty( $json['gw']['gateway-transaction-id'] ) ? $json['gw']['gateway-transaction-id'] : false;
            $status_code    = $json['gw']['status-code'];
            $status         = WC_Transactpro_Utils::getTransactionStatus( $status_code );

            update_post_meta( $order->get_id(), '_transaction_id', $transaction_id );
            update_post_meta( $order->get_id(), '_transactpro_payment_method', $payment_method );


            if ( $status_code ) {
                WC_Transactpro_Utils::log( "Transaction : " . $transaction_id . " Status : " . $status );

                if (in_array($status_code, [ WC_Transactpro_Utils::STATUS_HOLD_OK, WC_Transactpro_Utils::STATUS_SUCCESS])) {

                    if ( $status_code == WC_Transactpro_Utils::STATUS_HOLD_OK ) {
                        update_post_meta( $order->get_id(), '_transactpro_charge_captured', 'no' );
                        $order->update_status( 'on-hold', __( 'Transaction accepted - you can charge or cancel payment', 'woocommerce-transactpro' ) );
                    }

                    if ( $status_code == WC_Transactpro_Utils::STATUS_SUCCESS  ) {
                        update_post_meta( $order->get_id(), '_transactpro_charge_captured', 'yes' );
                        $order->update_status( 'completed', __( 'Transaction completed', 'woocommerce-transactpro' ) );
                        wc_reduce_stock_levels( $order->get_id() );
                    }

                    return true;

                }  else {
                    update_post_meta( $order->get_id(), '_transactpro_charge_captured', 'no' );
                    $order->update_status( 'failed', __( $status, 'woocommerce-transactpro' ) );
                }
            } else {
                WC_Transactpro_Utils::log( 'Can\'t process response. Unknown status' );
            }

            return false;

        } catch ( Exception $e ) {
            return new WP_Error( 'woocommerce-transactpro', $e->getMessage() );
        }
    }


    /**
     * Get the token customer id for an order
     *
     * @param WC_Order $order
     *
     * @return array|mixed
     */
    protected function get_transaction_token( $order ) {

        WC_Transactpro_Utils::log( "get_transaction_token: order " . $order->get_id() );

        if ( $subscription_id = $order->get_meta( '_subscription_renewal' ) ) {
            $subscription = wcs_get_subscription( $subscription_id );

            if ( $parent_order = $subscription->get_parent() ) {
                return $parent_order->get_transaction_id();
            }
        }

        $subscriptions = $this->get_subscriptions_from_order( $order );

        WC_Transactpro_Utils::log( "subscriptions ? : " . var_export( $subscriptions, 1 ) );

        if ( $subscriptions ) {

            $subscription = array_shift( $subscriptions );

            return get_post_meta( $subscription->get_id(), '_transaction_id', true );

        }

        return false;
    }

    /**
     * Returns the WC_Subscription(s) tied to a WC_Order, or a boolean false.
     *
     * @param $order
     *
     * @return array|bool
     */
    protected function get_subscriptions_from_order( $order ) {

        WC_Transactpro_Utils::log( "get_subscriptions_from_order: order id " . $order->get_id() );

        if ( function_exists( 'wcs_order_contains_subscription' ) && ( wcs_order_contains_subscription( $order ) ) ) {

            $subscriptions = wcs_get_subscriptions_for_order( $order );

            if ( $subscriptions ) {

                return $subscriptions;

            }
        }

        return false;
    }


    /**
     * Validate the payment meta data required to process automatic recurring payments
     *
     * @param $payment_method_id
     * @param $payment_meta
     *
     * @throws \Exception
     */
    public function validate_subscription_payment_meta( $payment_method_id, $payment_meta ) {

        WC_Transactpro_Utils::log( "validate_subscription_payment_meta: \n payment_method_id: " . var_export( $payment_method_id, 1 ) . " \n payment_meta: " . var_export( $payment_meta, 1 ) );

        if ( $this->id === $payment_method_id ) {

            if ( ! isset( $payment_meta['post_meta']['_transaction_id']['value'] ) || empty( $payment_meta['post_meta']['_transaction_id']['value'] ) ) {

                throw new Exception( 'A "_transaction_id" value is required.' );

            }
        }
    }

}