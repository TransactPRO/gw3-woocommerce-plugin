<?php
/* TransactPro AIM Payment Gateway Class */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Class WC_Transactpro_Gateway
 *
 * @property $id                 string
 * @property $method_title       string
 * @property $method_description string
 * @property $title              string
 * @property $icon               string
 * @property $has_fields         boolean
 * @property $supports           array
 * @property $enabled            boolean
 * @property $description        string
 * @property $gateway_url        string
 * @property $user_id            string
 * @property $secret_key         string
 * @property $payment_method     string
 * @property $return_url         string
 * @property $callback_url       string
 *
 */
class WC_Transactpro_Gateway extends WC_Payment_Gateway {

	const PAYMENT_METHODS = [
			'Sms'          => 'Sms',
			'Dms'          => 'Dms',
			'Credit'       => 'Credit',
			'P2P'          => 'P2P',
			//'RecurrentSms' => 'RecurrentSms',
			//'RecurrentDms' => 'RecurrentDms',
		];

	protected $gateway;
	public $log;

	/**
	 * Constructor
	 */
	public function __construct() {

		$this->id                 = 'transactpro';
		$this->method_title       = __( 'Transactpro', 'woocommerce-transactpro' );
		$this->method_description = __( 'Transactpro works by adding payments fields in an iframe and then sending the details to Transactpro for verification and processing.', 'woocommerce-transactpro' );
		$this->title              = __( 'Transactpro', 'woocommerce-transactpro' );
		$this->icon               = null;
		$this->has_fields         = true;
		$this->supports           = [
			'default_credit_card_form',
			'products',
			'refunds',
		];

		// Load the form fields
		$this->init_form_fields();

		// Load the settings.
		$this->init_settings();

		$this->return_url   = $this->settings['return_url'] = WC_HTTPS::force_https_url( add_query_arg( 'wc-api', 'return_url_gateway', home_url( '/' ) ) );
		$this->callback_url = $this->settings['callback_url'] = WC_HTTPS::force_https_url( add_query_arg( 'wc-api', 'callback_url_gateway', home_url( '/' ) ) );

		// Get setting values
		$this->enabled        = $this->get_option( 'enabled' );
		$this->description    = $this->get_option( 'description' );
		$this->gateway_url    = $this->get_option( 'gateway_url' );
		$this->user_id        = $this->get_option( 'user_id' );
		$this->secret_key     = $this->get_option( 'secret_key' );
		$this->payment_method = $this->get_option( 'payment_method' );


		if ( ! ( empty( $this->user_id ) || empty( $this->secret_key ) || empty( $this->gateway_url ) ) ) {
			$transactpro_client = new \TransactPro\Gateway\Gateway( $this->gateway_url );
			$transactpro_client->auth()->setAccountID( $this->user_id )->setSecretKey( $this->secret_key );
			$this->gateway = $transactpro_client;
		}

		// Hooks
		add_action( 'wp_enqueue_scripts', [ $this, 'payment_scripts' ] );
		add_action( 'admin_notices', [ $this, 'admin_notices' ] );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
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
		$is_available = true;

		if ( 'yes' === $this->enabled ) {
			if ( ! wc_checkout_is_https() ) {
				$is_available = false;
			}

			if ( empty( $this->gateway ) ) {
				$is_available = false;
			}

			// Transactpro only supports USD and EUR
			if ( ( 'USD' !== get_woocommerce_currency() && 'EUR' !== get_woocommerce_currency() ) ) {
				$is_available = false;
			}
		} else {
			$is_available = false;
		}

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
				'title'       => __( 'Secret keys', 'woocommerce-transactpro' ),
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
		] );
	}

	/**
	 * Payment form on checkout page
	 */
	public function payment_fields() {
        // todo: add on-form validation and in case when transaction will be fired on gateway side just show link or button to go
		?>
        <fieldset>
			<?php
			$allowed = [
				'a'      => [
					'href'  => [],
					'title' => [],
				],
				'br'     => [],
				'em'     => [],
				'strong' => [],
				'span'   => [
					'class' => [],
				],
			];
			if ( $this->description ) {
				echo apply_filters( 'woocommerce_transactpro_description', wpautop( wp_kses( $this->description, $allowed ) ) );
			}
			?>
            <p class="form-row form-row-wide">
                <label for="sq-card-number"><?php esc_html_e( 'Card Number', 'woocommerce-transactpro' ); ?> <span class="required">*</span></label>
                <input id="sq-card-number" type="text" maxlength="20" autocomplete="off" placeholder="•••• •••• •••• ••••" name="<?php echo esc_attr( $this->id ); ?>-card-number"/>
            </p>

            <p class="form-row form-row-first">
                <label for="sq-expiration-date"><?php esc_html_e( 'Expiry (MM/YY)', 'woocommerce-transactpro' ); ?> <span class="required">*</span></label>
                <input id="sq-expiration-date" type="text" autocomplete="off" placeholder="<?php esc_attr_e( 'MM / YY', 'woocommerce-transactpro' ); ?>" name="<?php echo esc_attr( $this->id ); ?>-card-expiry"/>
            </p>

            <p class="form-row form-row-last">
                <label for="sq-cvv"><?php esc_html_e( 'Card Code', 'woocommerce-transactpro' ); ?> <span class="required">*</span></label>
                <input id="sq-cvv" type="text" autocomplete="off" placeholder="<?php esc_attr_e( 'CVV', 'woocommerce-transactpro' ); ?>" name="<?php echo esc_attr( $this->id ); ?>-card-cvv"/>
            </p>

            <p class="form-row form-row-wide">
                <label for="sq-card-holder"><?php esc_html_e( 'Card holder name', 'woocommerce-transactpro' ); ?> <span class="required">*</span></label>
                <input id="sq-card-holder" type="text" autocomplete="off" placeholder="<?php esc_attr_e( 'Card holder name', 'woocommerce-transactpro' ); ?>" name="<?php echo esc_attr( $this->id ); ?>-card-holder"/>
            </p>
        </fieldset>
		<?php
	}

	/**
	 * Get payment form input styles.
	 * This function is pass to the JS script in order to style the
	 * input fields within the iFrame.
	 *
	 * Possible styles are: mediaMinWidth, mediaMaxWidth, backgroundColor, boxShadow,
	 * color, fontFamily, fontSize, fontWeight, lineHeight and padding.
	 *
	 * @access  public
	 * @return json $styles
	 */
	public function get_input_styles() {
		$styles = [
			[
				'fontSize'        => '1.2em',
				'padding'         => '.618em',
				'fontWeight'      => 400,
				'backgroundColor' => 'transparent',
				'lineHeight'      => 1.7,
			],
			[
				'mediaMaxWidth' => '1200px',
				'fontSize'      => '1em',
			],
		];

		return apply_filters( 'woocommerce_transactpro_payment_input_styles', wp_json_encode( $styles ) );
	}

	/**
	 * payment_scripts function.
	 *
	 * @access public
	 */
	public function payment_scripts() {
		if ( ! is_checkout() ) {
			return;
		}

		//      $suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		//      wp_register_script( 'woocommerce-transactpro', WC_TRANSACTPRO_PLUGIN_URL . '/assets/js/wc-transactpro-payments' . $suffix . '.js', [ 'jquery', 'transactpro' ], WC_TRANSACTPRO_VERSION, true );

		wp_localize_script( 'woocommerce-transactpro', 'transactpro_params', [
			//'application_id'               => 'transactpro',
			//'environment'                  => 'production', // todo: check environment var
			'placeholder_card_number'     => __( '•••• •••• •••• ••••', 'woocommerce-transactpro' ),
			'placeholder_card_expiration' => __( 'MM / YY', 'woocommerce-transactpro' ),
			'placeholder_card_cvv'        => __( 'CVV', 'woocommerce-transactpro' ),
			'payment_form_input_styles'   => esc_js( $this->get_input_styles() ),
			// todo: is this will be helpful somehow ?
			//'placeholder_card_postal_code' => __( 'Card Postal Code1', 'woocommerce-transactpro' ),
            //'custom_form_trigger_element'  => apply_filters( 'woocommerce_transactpro_payment_form_trigger_element', esc_js( '' ) ),

		] );

		wp_enqueue_script( 'woocommerce-transactpro' );

		return true;
	}

	/**
	 * @param int  $order_id
	 * @param bool $retry
	 *
	 * @return array
	 */
	public function process_payment( $order_id, $retry = true ) {

		$order = wc_get_order( $order_id );
		//$nonce    = isset( $_POST['transactpro_nonce'] ) ? wc_clean( $_POST['transactpro_nonce'] ) : '';
		$pan         = isset( $_POST[ $this->id . '-card-number' ] ) ? wc_clean( $_POST[ $this->id . '-card-number' ] ) : '';
		$card_exp    = isset( $_POST[ $this->id . '-card-expiry' ] ) ? wc_clean( $_POST[ $this->id . '-card-expiry' ] ) : '';
		$cvv         = isset( $_POST[ $this->id . '-card-cvv' ] ) ? wc_clean( $_POST[ $this->id . '-card-cvv' ] ) : '';
		$card_holder = isset( $_POST[ $this->id . '-card-holder' ] ) ? wc_clean( $_POST[ $this->id . '-card-holder' ] ) : '';

		$currency = $order->get_currency();

		$this->log( "Info: Begin processing payment for order {$order_id} for the amount of {$order->get_total()}" );

		//      todo: should we log that ?
		//		echo $order . "\n";
		//		echo $pan . "\n";
		//		echo $card_exp . "\n";
		//		echo $cvv . "\n";
		//		echo $card_holder . "\n";
		//		echo $currency . "\n";

		try {

			$endpoint_name = $this->payment_method;
			if ( in_array( $this->payment_method, [ 'RecurrentSms', 'RecurrentDms' ] ) ) {
				$endpoint_name = 'Init' . $endpoint_name;
			}
			if ($this->payment_method == self::PAYMENT_METHODS['Dms']) {
				$endpoint_name = 'DmsHold';
            }

			$endpoint = $this->gateway->{'create' . $endpoint_name}();

			$endpoint->customer()
			         ->setEmail( $order->get_billing_email() )
			         ->setPhone( $order->get_billing_phone() )
			         ->setBillingAddressCountry( $order->get_billing_country() )
			         ->setBillingAddressState( $order->get_billing_state() | 'N/A' )
			         ->setBillingAddressCity( $order->get_billing_city() | 'N/A' )
			         ->setBillingAddressStreet( $order->get_billing_address_1() | 'N/A' )
			         ->setBillingAddressHouse( $order->get_billing_address_2() | 'N/A' )
			         ->setBillingAddressFlat( ' ' )
			         ->setBillingAddressZIP( $order->get_billing_postcode() | 'N/A' )
			         ->setShippingAddressCountry( $order->get_shipping_country() | 'N/A' )
			         ->setShippingAddressState( $order->get_shipping_state() | 'N/A' )
			         ->setShippingAddressCity( $order->get_shipping_city() | 'N/A' )
			         ->setShippingAddressStreet( $order->get_shipping_address_1() | 'N/A' )
			         ->setShippingAddressHouse( $order->get_shipping_address_2() | 'N/A' )
			         ->setShippingAddressFlat( ' ' )
			         ->setShippingAddressZIP( $order->get_shipping_postcode() | 'N/A' );


			$endpoint->order()
			         ->setDescription( apply_filters( 'woocommerce_transactpro_payment_order_note', 'WooCommerce: Order #' . (string) $order->get_order_number(), $order ) )
			         ->setMerchantSideUrl( WC_HTTPS::force_https_url( home_url( '/' ) ) );

			// $endpoint->system()->setUserIP( $order->get_customer_ip_address() );
			// TODO: Remove fake ip address
			$endpoint->system()->setUserIP( '81.219.241.101' );

			$endpoint->money()
			         ->setAmount( (int) WC_Transactpro_Utils::format_amount_to_transactpro( $order->get_total(), $currency ) )
			         ->setCurrency( $currency );

			$endpoint->paymentMethod()
			         ->setPAN( $pan )
			         ->setExpire( $card_exp )
			         ->setCVV( $cvv )
			         ->setCardHolderName( $card_holder );


            $json = $this->process_endpoint($endpoint);

			$transaction_id = !empty($json['gw']['gateway-transaction-id']) ? $json['gw']['gateway-transaction-id'] : false;


			if (! empty($json['gw']['redirect-url'])) {

				update_post_meta( $order_id, '_transactpro_charge_captured', 'no' );
				update_post_meta( $order_id, '_transaction_id', $transaction_id );
				update_post_meta( $order_id, '_transactpro_payment_method', $this->payment_method);
				update_post_meta( $order_id, '_payment_response', json_encode($json));

				// Mark as on-hold
				$authorized_message = sprintf( __( 'Transactpro redirected to the gateway', 'woocommerce-transactpro' ), $transaction_id );
				$order->update_status( 'on-hold', $authorized_message );
				$this->log( "Success: $authorized_message" );

                return [
                    'result'   => 'success',
                    'redirect' => $json['gw']['redirect-url']
                ];
			}

			if (!empty($json['gw']['status-text'])) {

				update_post_meta( $order_id, '_transaction_id', $transaction_id );
				update_post_meta( $order_id, '_transactpro_payment_method', $this->payment_method );

                if (strtoupper($json['gw']['status-text']) == 'HOLD OK') {
	                update_post_meta( $order_id, '_transactpro_charge_captured', 'no' );
	                $order->update_status( 'on-hold' );
                }

			    if (strtoupper($json['gw']['status-text']) == 'SUCCESS') {
				    update_post_meta( $order_id, '_transactpro_charge_captured', 'yes' );
				    $order->update_status( 'completed' );
				    wc_reduce_stock_levels( $order_id );
			    }

				$this->log( "Success: $transaction_id" );
				WC()->cart->empty_cart();
				return [
					'result'   => 'success',
					'redirect' => $this->get_return_url( $order ),
				];
			}

			echo "end \n";
			var_dump($json);
			exit;

		} catch ( Exception $e ) {
			$this->log( sprintf( __( 'Error: %s', 'woocommerce-transactpro' ), $e->getMessage() ) );

			$order->update_status( 'failed', $e->getMessage() );

			return;
		}
	}

	/**
	 * Refund a charge
	 *
	 * @param  int   $order_id
	 * @param  float $amount
	 *
	 * @return bool
	 */
	public function process_refund( $order_id, $amount = null, $reason = '' ) {
		$order = wc_get_order( $order_id );

		if ( ! $order || ! $order->get_transaction_id() ) {
			return false;
		}

		if ( 'transactpro' === $order->get_payment_method() ) {
			try {
				$this->log( "Info: Begin refund for order {$order_id} for the amount of {$amount}" );

				$transaction_id = get_post_meta( $order_id, '_transaction_id', true );
				if ( 'yes' === get_post_meta( $order->get_id(), '_transactpro_charge_captured', true ) ) {
					if ( ! is_null( $amount ) ) {
						$refund = $this->gateway->createRefund();
						$refund->command()->setGatewayTransactionID( $transaction_id );
						$endpoint = $refund->money()->setAmount( $amount );

						$json = $this->process_endpoint($endpoint);

                        if ( $json )  {
                            //$refund_message = sprintf( __( 'Refunded %1$s - Refund ID: %2$s - Reason: %3$s', 'woocommerce-transactpro' ), wc_price( $result->refund->amount_money->amount / 100 ), $result->refund->id, $reason );
	                        $refund_message = '';

                            $order->add_order_note( $refund_message );

                            $this->log( 'Success: ' . html_entity_decode( strip_tags( $refund_message ) ) );

                            return true;
                        }
					}
				}


			} catch ( Exception $e ) {
				$this->log( sprintf( __( 'Error: %s', 'woocommerce-transactpro' ), $e->getMessage() ) );

				return false;
			}
		}
	}

	private function process_endpoint($endpoint) {
		$request  = $this->gateway->generateRequest( $endpoint );
		$response = $this->gateway->process( $request );

		if ( 200 !== $response->getStatusCode() ) {
			throw new Exception( $response->getBody(), $response->getStatusCode() );
		}

		$json        = json_decode( $response->getBody(), true );
		$json_status = json_last_error();

		if ( JSON_ERROR_NONE !== $json_status ) {
			throw new Exception( 'JSON ' . json_last_error_msg(), $json_status );
		}

		$this->log( 'TransactPro response: ' .  json_encode($json));

		if ( empty( $json ) || ( empty( $json['gw'] ) && empty( $json['error'] ) ) ) {
			wc_add_notice( __( 'Error: Transactpro was unable to complete the transaction. Please try again later or use another means of payment.', 'woocommerce-transactpro' ), 'error' );
			throw new Exception( 'Unexpected payment gateway response.' );
		}

		if ( ! empty( $json['error'] ) ) {
			// format errors for display
			$error_html = __( 'Payment Error: ', 'woocommerce-transactpro' );
			$error_html .= '<br />';
			$error_html .= '<ul>';
			$error_html .= '<li>' . $json['error']['code'] . ' : ' . $json['error']['message'] . '</li>';
			$error_html .= '</ul>';
			wc_add_notice( $error_html, 'error' );

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
	public function log($message) {
		WC_Transactpro_Payment_Logger::log( $message );
	}
}
