<?php
/* TransactPro AIM Payment Gateway Class */
setlocale(LC_ALL, 'en_US.UTF-8');
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * Class WC_Transactpro_Gateway
 *
 * @property $id                      string
 * @property $method_title            string
 * @property $method_description      string
 * @property $title                   string
 * @property $icon                    string
 * @property $has_fields              boolean
 * @property $supports                array
 * @property $enabled                 boolean
 * @property $description             string
 * @property $gateway_url             string
 * @property $user_id                 string
 * @property $secret_key              string
 * @property $payment_method          string
 * @property $show_card_form          boolean
 * @property $return_url              string
 * @property $callback_url            string
 * @property $p2p_recipient_name      string
 * @property $p2p_recipient_birthdate string
 *
 */

class WC_Transactpro_Gateway extends WC_Payment_Gateway_CC
{
    const PAYMENT_METHODS = [
        'Sms'    => 'Sms',
        'Dms'    => 'Dms',
        'Credit' => 'Credit',
        'P2P'    => 'P2P',
    ];

    protected $gateway;

    public function __construct() {

        $this->id                 = 'transactpro';
        $this->method_title       = __( 'Transactpro', 'woocommerce-transactpro' );
        $this->method_description = __( 'Transactpro works by adding payments fields in an iframe and then sending the details to Transactpro for verification and processing.', 'woocommerce-transactpro' );
        $this->title              = __( 'Transactpro', 'woocommerce-transactpro' );
        $this->icon               = null;
        $this->has_fields         = true;
        $this->supports           = [
            'products',
            'refunds',
            'subscriptions',
            'subscription_cancellation',
            'subscription_reactivation',
            'subscription_suspension',
            'subscription_amount_changes',
            'subscription_date_changes',
            'multiple_subscriptions',
        ];

        // Load the form fields
        $this->init_form_fields();

        // Load the settings.
        $this->init_settings();

        $this->return_url   = $this->settings['return_url'] = WC_HTTPS::force_https_url( add_query_arg( 'wc-api', 'return_url_gateway', home_url( '/' ) ) );
        $this->callback_url = $this->settings['callback_url'] = WC_HTTPS::force_https_url( add_query_arg( 'wc-api', 'callback_url_gateway', home_url( '/' ) ) );

        // Get setting values
        $this->enabled                 = $this->get_option( 'enabled' );
        $this->description             = $this->get_option( 'description' );
        $this->gateway_url             = $this->get_option( 'gateway_url' );
        $this->user_id                 = $this->get_option( 'user_id' );
        $this->secret_key              = $this->get_option( 'secret_key' );
        $this->payment_method          = $this->get_option( 'payment_method' );
        $this->show_card_form          = $this->get_option( 'show_card_form' );
        $this->p2p_recipient_name      = $this->get_option( 'p2p_recipient_name' );
        $this->p2p_recipient_birthdate = $this->get_option( 'p2p_recipient_birthdate' );


        if ( ! ( empty( $this->user_id ) || empty( $this->secret_key ) || empty( $this->gateway_url ) ) ) {
            $transactpro_client = new \TransactPro\Gateway\Gateway( $this->gateway_url );
            $transactpro_client->auth()->setAccountID( $this->user_id )->setSecretKey( $this->secret_key );
            $this->gateway = $transactpro_client;
        }

        add_action( 'admin_notices', [ $this, 'admin_notices' ] );
        add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
        add_action( 'woocommerce_order_status_refunded', [ $this, 'order_refunded' ] );

        // we do this trick with total because when we update status to refunded - WC try to fully refund order and do one more refund on difference in sums
        remove_action( 'woocommerce_order_status_refunded', 'wc_order_fully_refunded' );
    }

    /**
     * get_icon function.
     *
     * @access public
     * @return string
     */
    public function get_icon() {
        $icon = '<img src="' . WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/visa.svg' ) . '" alt="Visa" width="32" style="margin-left: 0.3em" />';
        $icon .= '<img src="' . WC_HTTPS::force_https_url( WC()->plugin_url() . '/assets/images/icons/credit-cards/mastercard.svg' ) . '" alt="Mastercard" width="32" style="margin-left: 0.3em" />';

        return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
    }

    /**
     * Check if required fields are set
     */
    public function admin_notices() {
        if ( 'yes' !== $this->enabled ) {
            return;
        }
        // Show message if enabled and FORCE SSL is disabled and WordpressHTTPS plugin is not detected
        if ( get_option( 'woocommerce_force_ssl_checkout' ) === 'no' && ! class_exists( 'WordPressHTTPS' ) ) {
            echo '<div class="error"><p>' . sprintf( __( 'Transactpro is enabled, but the <a href="%s">force SSL option</a> is disabled; your checkout is not secured! Please enable SSL and ensure your server has a valid SSL certificate.', 'woocommerce-transactpro' ), admin_url( 'admin.php?page=wc-settings&tab=checkout' ) ) . '</p></div>';
        }
    }

    /**
     * Check if this gateway is enabled
     */
    public function is_available() {
        //$is_available = $this->enabled  == 'no' || !wc_checkout_is_https() || empty($this->gateway) || !WC_Transactpro_Utils::is_currency_supported() ? false : true;
        $is_available = $this->enabled  == 'no' || empty($this->gateway) || !WC_Transactpro_Utils::is_currency_supported() ? false : true;
        return apply_filters( 'woocommerce_transactpro_payment_gateway_is_available', $is_available );
    }

    /**
     * Initialize Gateway Settings Form Fields
     */
    public function init_form_fields() {
        $payment_method_options = [];
        foreach ( self::PAYMENT_METHODS as $k => $v ) {
            $payment_method_options[ $k ] = __( $v, 'woocommerce-transactpro' );
        }

        $this->form_fields = apply_filters( 'woocommerce_transactpro_gateway_settings', [
            'enabled'        => [
                'title'       => __( 'Enable/Disable', 'woocommerce-transactpro' ),
                'label'       => __( 'Enable Transactpro', 'woocommerce-transactpro' ),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'no',
            ],
            'description'    => [
                'title'       => __( 'Description', 'woocommerce-transactpro' ),
                'type'        => 'textarea',
                'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce-transactpro' ),
                'default'     => __( 'Pay with your credit card via Transactpro.', 'woocommerce-transactpro' ),
            ],
            'gateway_url'    => [
                'title'       => __( 'Gateway URL', 'woocommerce-transactpro' ),
                'type'        => 'text',
                'description' => __( 'Transactpro Gateway URL', 'woocommerce-transactpro' ),
                'default'     => 'https://api.sandbox.transactpro.io/v3.0',
            ],
            'user_id'        => [
                'title'       => __( 'User ID', 'woocommerce-transactpro' ),
                'type'        => 'text',
                'description' => __( 'Transactpro User ID', 'woocommerce-transactpro' ),
                'default'     => '',
            ],
            'secret_key'     => [
                'title'       => __( 'Secret key', 'woocommerce-transactpro' ),
                'type'        => 'text',
                'description' => __( 'Transactpro Secret key', 'woocommerce-transactpro' ),
                'default'     => '',
            ],
            'payment_method' => [
                'title'       => __( 'Payment method', 'woocommerce-transactpro' ),
                'type'        => 'select',
                'description' => __( 'Transactpro payment method', 'woocommerce-transactpro' ),
                'options'     => $payment_method_options,
            ],
            'show_card_form'    => [
                'title'       => __( 'Show Card Form', 'woocommerce-transactpro' ),
                'label'       => __( 'Is card details collected on site or on gateway ', 'woocommerce-transactpro' ),
                'type'        => 'checkbox',
                'description' => '',
                'default'     => 'yes',
            ],
            'return_url'     => [
                'title'       => __( 'Return URL', 'woocommerce-transactpro' ),
                'type'        => 'text',
                'description' => __( 'TransactPro Return URL', 'woocommerce-transactpro' ),
                'disabled'    => true,
            ],
            'callback_url'   => [
                'title'       => __( 'Callback URL', 'woocommerce-transactpro' ),
                'type'        => 'text',
                'description' => __( 'Transactpro Callback URL', 'woocommerce-transactpro' ),
                'disabled'    => true,
            ],
            'p2p_recipient_name'    => [
                'title'       => __( 'P2P Recipient Name', 'woocommerce-transactpro' ),
                'type'        => 'text',
                'description' => __( 'For P2P Method only', 'woocommerce-transactpro' ),
                'default'     => '',
            ],
            'p2p_recipient_birthdate'    => [
                'title'       => __( 'P2P Recipient BirthDate', 'woocommerce-transactpro' ),
                'type'        => 'text',
                'description' => __( 'For P2P Method only', 'woocommerce-transactpro' ),
                'default'     => '',
            ],
        ] );
    }

    public function form() {
        if($this->show_card_form=='yes') {
            wp_enqueue_script( 'wc-credit-card-form' );

            $fields = array();

            $default_fields = array(
                'card-number-field' => '<p class="form-row form-row-wide">
                                    <label for="' . esc_attr( $this->id ) . '-card-number">' . esc_html__( 'Card number', 'woocommerce' ) . ' <span class="required">*</span></label>
                                    <input id="' . esc_attr( $this->id ) . '-card-number" class="input-text wc-credit-card-form-card-number" inputmode="numeric" autocomplete="cc-number" autocorrect="no" autocapitalize="no" spellcheck="no" type="tel" placeholder="&bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull;" ' . $this->field_name( 'card-number' ) . ' />
                            </p>',
                'card-expiry-field' => '<p class="form-row form-row-first">
                                    <label for="' . esc_attr( $this->id ) . '-card-expiry">' . esc_html__( 'Expiry (MM/YY)', 'woocommerce' ) . ' <span class="required">*</span></label>
                                    <input id="' . esc_attr( $this->id ) . '-card-expiry" class="input-text wc-credit-card-form-card-expiry" inputmode="numeric" autocomplete="cc-exp" autocorrect="no" autocapitalize="no" spellcheck="no" type="tel" placeholder="' . esc_attr__( 'MM / YY', 'woocommerce' ) . '" ' . $this->field_name( 'card-expiry' ) . ' />
                            </p>',
                'card-cvc-field' => '<p class="form-row form-row-last">
                                <label for="' . esc_attr( $this->id ) . '-card-cvc">' . esc_html__( 'Card code', 'woocommerce' ) . ' <span class="required">*</span></label>
                                <input id="' . esc_attr( $this->id ) . '-card-cvc" class="input-text wc-credit-card-form-card-cvc" inputmode="numeric" autocomplete="off" autocorrect="no" autocapitalize="no" spellcheck="no" type="tel" maxlength="4" placeholder="' . esc_attr__( 'CVC', 'woocommerce' ) . '" ' . $this->field_name( 'card-cvc' ) . ' style="width:100px" />
                        </p>',
                'card_holder_field' => '<p class="form-row form-row-wide">
                            <label for="' . esc_attr( $this->id ) . '-card-holder">' . esc_html__( 'Card Holder', 'woocommerce' ) . ' <span class="required">*</span></label>
                            <input id="' . esc_attr( $this->id ) . '-card-holder" class="input-text wc-credit-card-form-card-holder" inputmode="text" autocomplete="off" autocorrect="no" autocapitalize="no" spellcheck="no"  maxlength="32" placeholder="' . esc_attr__( 'Card Holder', 'woocommerce' ) . '" ' . $this->field_name( 'card-holder' ) . ' " />
                        </p>'
            );

            $fields = wp_parse_args( $fields, apply_filters( 'woocommerce_credit_card_form_fields', $default_fields, $this->id ) );
            ?>

            <fieldset id="wc-<?php echo esc_attr( $this->id ); ?>-cc-form" class='wc-credit-card-form wc-payment-form'>
                <?php do_action( 'woocommerce_credit_card_form_start', $this->id ); ?>
                <?php
                foreach ( $fields as $field ) {
                    echo $field;
                }
                ?>
                <?php do_action( 'woocommerce_credit_card_form_end', $this->id ); ?>
                <div class="clear"></div>
            </fieldset>
            <?php
        }
    }

    /**
     * @param $txt
     *
     * @return string
     */
    private function _escape_helper($txt) {
        if (empty(trim($txt))) {
            $txt = 'NA';
        }

        if ( $this->payment_method == self::PAYMENT_METHODS['P2P'] ) {
            return (string) preg_replace( '/\W/', ' ', iconv("UTF-8", "ASCII//TRANSLIT", $txt));
            //return (string) preg_replace( '/\W/', ' ', $txt );
        }
        return (string) $txt;
    }


    /**
     * @param int  $order_id
     * @param bool $retry
     *
     * @return array
     * @throws \Exception
     */
    public function process_payment( $order_id, $retry = true ) {

        $order = wc_get_order( $order_id );

        $pan         = preg_replace('/\s+/', '', (isset( $_POST[ $this->id . '-card-number' ] ) ? wc_clean( $_POST[ $this->id . '-card-number' ] ) : ''));
        $card_exp    = preg_replace('/\s+/', '', (isset( $_POST[ $this->id . '-card-expiry' ] ) ? wc_clean( $_POST[ $this->id . '-card-expiry' ] ) : ''));
        $cvv         = isset( $_POST[ $this->id . '-card-cvc' ] )    ? wc_clean( $_POST[ $this->id . '-card-cvc' ] )    : '';
        $card_holder = isset( $_POST[ $this->id . '-card-holder' ] ) ? wc_clean( $_POST[ $this->id . '-card-holder' ] ) : '';

        $currency = $order->get_currency();

        WC_Transactpro_Utils::log( "Info: Begin processing payment for order {$order_id} for the amount of {$order->get_total()}" );

        try {
            $endpoint_name = $this->payment_method;

            if ( function_exists( 'wcs_order_contains_subscription' ) && wcs_order_contains_subscription($order)) {
                if ( !in_array( $this->payment_method, [ 'Sms', 'Dms' ] ) ) {
                    throw new Exception( 'Selected payment method can\'t by used for subscriptions products - SMS and DMS allowed only' );
                }
                $endpoint_name = 'InitRecurrent' . $endpoint_name;
            } else {
                if ( $this->payment_method == self::PAYMENT_METHODS['Dms'] ) {
                    $endpoint_name = 'DmsHold';
                }
            };

            WC_Transactpro_Utils::log( "EndPoint: " . $endpoint_name );

            $operation = $this->gateway->{'create' . $endpoint_name}();

            $operation->customer()
                ->setEmail( $order->get_billing_email() )
                ->setPhone( $order->get_billing_phone() )
                ->setBirthDate($this->_escape_helper($this->p2p_recipient_birthdate))
                ->setBillingAddressCountry($this->_escape_helper($order->get_billing_country()) )
                ->setBillingAddressState($this->_escape_helper($order->get_billing_state()))
                ->setBillingAddressCity($this->_escape_helper($order->get_billing_city()))
                ->setBillingAddressStreet($this->_escape_helper($order->get_billing_address_1()))
                ->setBillingAddressHouse($this->_escape_helper($order->get_billing_address_2()) )
                ->setBillingAddressFlat($this->_escape_helper('') )
                ->setBillingAddressZIP($this->_escape_helper($order->get_billing_postcode()) )
                ->setShippingAddressCountry($this->_escape_helper($order->get_shipping_country()))
                ->setShippingAddressState($this->_escape_helper($order->get_shipping_state()))
                ->setShippingAddressCity($this->_escape_helper($order->get_shipping_city()))
                ->setShippingAddressStreet($this->_escape_helper($order->get_shipping_address_1()))
                ->setShippingAddressHouse($this->_escape_helper($order->get_shipping_address_2()))
                ->setShippingAddressFlat( $this->_escape_helper('') )
                ->setShippingAddressZIP($this->_escape_helper($order->get_shipping_postcode()));
            $operation->order()
                ->setDescription( apply_filters( 'woocommerce_transactpro_payment_order_note', 'WooCommerce: Order #' . (string) $order->get_order_number(), $order ) )
                ->setMerchantSideUrl( WC_HTTPS::force_https_url( home_url( '/' ) ) )
                ->setRecipientName($this->_escape_helper($this->p2p_recipient_name));

            $operation->system()->setUserIP( $order->get_customer_ip_address() );

            // TODO: IF tested on localhost use that and comment ^
            // $operation->system()->setUserIP( '109.254.67.165' );

            $operation->money()->setAmount( (int) WC_Transactpro_Utils::format_amount_to_transactpro( $order->get_total(), $currency ) )->setCurrency( $currency );

            $operation->paymentMethod()
                ->setPAN( $pan )
                ->setExpire( $card_exp )
                ->setCVV( $cvv )
                ->setCardHolderName( $card_holder );
            $json = WC_Transactpro_Utils::process_endpoint($this->gateway, $operation);
            
            $transaction_id = ! empty( $json['gw']['gateway-transaction-id'] ) ? $json['gw']['gateway-transaction-id'] : false;

            update_post_meta( $order_id, '_transaction_id', $transaction_id );
            update_post_meta( $order_id, '_transactpro_payment_method', $this->payment_method );

            if ( ! empty( $json['gw']['redirect-url'] ) ) {

                WC()->session->set( 'waiting_for_return_order_id', $order_id );

                update_post_meta( $order_id, '_transactpro_charge_captured', 'no' );
                update_post_meta( $order_id, '_payment_response', json_encode( $json ) );

                $status = __( 'Transactpro redirected to the Gateway', 'woocommerce-transactpro' );
                $order->update_status( 'processing', $status );
                WC_Transactpro_Utils::log( $status );

                return [
                    'result'   => 'success',
                    'redirect' => $json['gw']['redirect-url'],
                ];
            }

            $status_code = !empty($json['gw']['status-code']) ? $json['gw']['status-code'] : false;
            $status = WC_Transactpro_Utils::getTransactionStatus( $status_code );
            $result = 'error';

            if ( $status_code ) {
                WC_Transactpro_Utils::log( "Transaction : " . $transaction_id . " Status : " . $status );

                if (in_array($status_code, [ WC_Transactpro_Utils::STATUS_HOLD_OK, WC_Transactpro_Utils::STATUS_SUCCESS])) {

                    if ( $status_code == WC_Transactpro_Utils::STATUS_HOLD_OK ) {
                        update_post_meta( $order_id, '_transactpro_charge_captured', 'no' );
                        $order->update_status( 'on-hold', __( 'Transaction accepted - you can charge or cancel payment', 'woocommerce-transactpro' ) );
                    }

                    if ( $status_code == WC_Transactpro_Utils::STATUS_SUCCESS  ) {
                        update_post_meta( $order_id, '_transactpro_charge_captured', 'yes' );
                        $order->update_status( 'completed', __( 'Transaction completed', 'woocommerce-transactpro' ) );
                        wc_reduce_stock_levels( $order_id );
                    }
                    WC()->cart->empty_cart();
                    $result = 'success';
                }  else {
                    update_post_meta( $order_id, '_transactpro_charge_captured', 'no' );
                    $order->update_status( 'failed', __( $status, 'woocommerce-transactpro' ) );
                }
            } else {
                WC_Transactpro_Utils::log( 'Can\'t process response. Unknown status' );
            }

            return [
                'result'   => $result,
                'redirect' => $this->get_return_url( $order ),
            ];

        } catch ( Exception $e ) {
            WC_Transactpro_Utils::log( sprintf( __( 'Error: %s', 'woocommerce-transactpro' ), $e->getMessage() ) );
            $order->update_status( 'failed', $e->getMessage() );

            //throw new Exception($e->getMessage());
        }
    }

    /**
     * Refund a charge
     *
     * @param  int   $order_id
     * @param  float $amount
     * @param  string $reason
     *
     * @return bool
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {

        WC_Transactpro_Utils::log( "Info: Begin refund for order {$order_id} for the amount of {$amount}" );

        $order = wc_get_order( $order_id );

        //wc_refund_payment()

        if ( ! $order || ! $order->get_transaction_id() ) {
            return false;
        }
        $status = false;
        if ( 'transactpro' === $order->get_payment_method() ) {
            try {

                $transaction_id = get_post_meta( $order_id, '_transaction_id', true );
                $is_charged     = get_post_meta( $order->get_id(), '_transactpro_charge_captured', true );

                if ( $is_charged == 'yes' ) {
                    if ( ! is_null( $amount ) ) {

                        $operation = $this->gateway->createRefund();
                        $operation->command()->setGatewayTransactionID( (string) $transaction_id );

                        $currency = $order->get_currency();
                        $operation->money()->setAmount( (int) WC_Transactpro_Utils::format_amount_to_transactpro( $amount, $currency ) );

                        $json = WC_Transactpro_Utils::process_endpoint($this->gateway, $operation);

                        $status_code = $json['gw']['status-code'];
                        $status = WC_Transactpro_Utils::getTransactionStatus( $status_code );

                        if ( $status_code == WC_Transactpro_Utils::STATUS_REFUND_SUCCESS ) {
                            $order->add_order_note( ' refund transaction-id:' . $json['gw']['gateway-transaction-id'] );
                            $order->update_status( 'refunded', sprintf( __( 'Refunded %1$s - Reason: %2$s', 'woocommerce-transactpro' ), wc_price( $amount ), $reason ), true);
                            return true;

                        }
                    } else {
                        $status = __( 'Refunded amount can\'t be null' );
                    }
                } else {
                    $status = __( 'Can\'t be refunded because not charged' );
                }
            } catch ( Exception $e ) {
                $status = sprintf( __( 'Error unable to capture charge: %s', 'woocommerce-transactpro' ), $e->getMessage() );
            }
            $order->add_order_note( $status );
        }

        return $status ? new WP_Error( 'woocommerce-transactpro', __( $status, 'woocommerce-transactpro' ) ) : false;
    }
}
