<?php
/**
 * Plugin Name: Woocommerce Globalpayments
 * Plugin URI: https://wpstriker.com/plugins/woocommerce-globalpayments/
 * Description: This plugin adds a payment option in WooCommerce for customers to pay with their Credit Cards Via Globalpayments.
 * Version: 1.0.0
 * Author: wpstriker
 * Author URI: https://wpstriker.com
 * Author Email: wpstriker@gmail.com
 * License: GPL3
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

define( 'WC_GLOBALPAYMENTS_URL', plugin_dir_url( __FILE__ ) );
define( 'WC_GLOBALPAYMENTS_DIR', plugin_dir_path( __FILE__ ) );

require_once WC_GLOBALPAYMENTS_DIR . 'vendor/autoload.php';

use GlobalPayments\Api\PaymentMethods\CreditCardData;
use GlobalPayments\Api\ServicesConfig;
use GlobalPayments\Api\ServicesContainer;
use GlobalPayments\Api\Entities\Address;
use GlobalPayments\Api\Entities\Customer;
use GlobalPayments\Api\Entities\Enums\AddressType;
use GlobalPayments\Api\Builders\AuthorizationBuilder;
use GlobalPayments\Api\Entities\EcommerceInfo;
use GlobalPayments\Api\Entities\Exceptions\BuilderException;
use GlobalPayments\Api\PaymentMethods\RecurringPaymentMethod;
use GlobalPayments\Api\Entities\Exceptions\GatewayException;
use GlobalPayments\Api\Entities\Enums\RecurringSequence;
use GlobalPayments\Api\Entities\Enums\RecurringType;
use GlobalPayments\Api\Entities\Enums\ReasonCode;
use GlobalPayments\Api\Entities\Transaction;
use GlobalPayments\Api\Entities\Exceptions\ApiException;
use GlobalPayments\Api\Utils\GenerationUtils;
use GlobalPayments\Api\Entities\Enums\TransactionModifier;
use GlobalPayments\Api\Entities\Enums\EncyptedMobileType;
use GlobalPayments\Api\Entities\Enums\FraudFilterMode;
use GlobalPayments\Api\Entities\DecisionManager;
use GlobalPayments\Api\Entities\Enums\Risk;
					
function globalpayments_init() {
	
	function add_globalpayments_gateway_class( $methods ) {
		$methods[] 	= 'WC_Globalpayments_Gateway'; 
		return $methods;
	}
	add_filter( 'woocommerce_payment_gateways', 'add_globalpayments_gateway_class' );

	if( class_exists( 'WC_Payment_Gateway' ) ) {
		
		class WC_Globalpayments_Gateway extends WC_Payment_Gateway {
			
			public function __construct() {
					
				$this->id               = 'globalpayments';
				$this->icon             = WC_GLOBALPAYMENTS_URL . 'images/globalPayments.png';
				$this->has_fields       = true;
				$this->method_title     = 'Globalpayments Cards Settings';             
				$this->init_form_fields();
				$this->init_settings();

				$this->supports                 	= array( 'products', 'refunds' );
				
				$this->enable_log 					= true;

				$this->title                   		= $this->get_option( 'globalpayments_title' );
				$this->globalpayments_description   = $this->get_option( 'globalpayments_description');

				$this->globalpayments_testpublickey = $this->get_option( 'globalpayments_testpublickey' );
				$this->globalpayments_testsecretkey = $this->get_option( 'globalpayments_testsecretkey' );
				$this->globalpayments_livepublickey = $this->get_option( 'globalpayments_livepublickey' );
				$this->globalpayments_livesecretkey = $this->get_option( 'globalpayments_livesecretkey' );
				$this->globalpayments_sandbox       = $this->get_option( 'globalpayments_sandbox' ); 
				
				//add_action( 'wp_enqueue_scripts', array( $this, 'load_globalpayments_scripts' ) );
				
				if ( is_admin() ) {
					add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
				}
				
				add_action( 'woocommerce_after_checkout_validation', array( $this, 'checkout_validation' ), 10, 2 );
			}

			public function checkout_validation( $fields, $errors ){
				
				if ( WC()->cart->needs_payment() ) :
				
					if( $_POST['payment_method'] && $_POST['payment_method'] == 'globalpayments' ) {
						
						if( empty( $_POST['globalpayments-card-number'] ) ) {
							$errors->add( 'validation', '<strong>Card Number</strong> is a required field.' );
						}
						
						if( empty( $_POST['globalpayments-card-number'] ) ) {
							$errors->add( 'validation', '<strong>Card Expiry</strong> is a required field.' );
						}
						
						if( empty( $_POST['globalpayments-card-cvc'] ) ) {
							$errors->add( 'validation', '<strong>Card Code</strong> is a required field.' );
						}
						
					}
					
				endif;
			}
			
			public function admin_options() {
				?>
				<h3><?php _e( 'Globalpayments Credit cards payment gateway addon for Woocommerce', 'woocommerce' ); ?></h3>
				<p><?php  _e( 'Globalpayments is a company that provides a way for individuals and businesses to accept payments over the Internet.', 'woocommerce' ); ?></p>
				<table class="form-table">
					<?php $this->generate_settings_html(); ?>
					<script type="text/javascript">
						jQuery( '#woocommerce_globalpayments_globalpayments_sandbox' ).on( 'change', function() {
							var sandbox    = jQuery( '#woocommerce_globalpayments_globalpayments_testsecretkey, #woocommerce_globalpayments_globalpayments_testpublickey' ).closest( 'tr' ),
								production = jQuery( '#woocommerce_globalpayments_globalpayments_livesecretkey, #woocommerce_globalpayments_globalpayments_livepublickey' ).closest( 'tr' );

							if ( jQuery( this ).is( ':checked' ) ) {
								sandbox.show();
								production.hide();
							} else {
								sandbox.hide();
								production.show();
							}
						}).change();
					</script>
				</table>
				<?php
			}

			public function init_form_fields() {

				$this->form_fields = array(
					'enabled' => array(
						'title' => __( 'Enable/Disable', 'woocommerce' ),
						'type' => 'checkbox',
						'label' => __( 'Enable Globalpayments', 'woocommerce' ),
						'default' => 'yes'
						),

					'globalpayments_title' => array(
						'title' => __( 'Title', 'woocommerce' ),
						'type' => 'text',
						'description' => __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
						'default' => __( 'Credit Card', 'woocommerce' ),
						'desc_tip'      => true,
						),

					'globalpayments_description' => array(
						'title' => __( 'Description', 'woocommerce' ),
						'type' => 'textarea',
						'description' => __( 'This controls the description which the user sees during checkout.', 'woocommerce' ),
						'default' => __( 'We do not store any card details', 'woocommerce' ),
						'desc_tip'      => true,
						),

					'globalpayments_testsecretkey' => array(
						'title' => __( 'Test Secret Key', 'woocommerce' ),
						'type' => 'text',
						'description' => __( 'This is the Secret Key found in API Keys in Account Dashboard.', 'woocommerce' ),
						'default' => '',
						'desc_tip'      => true,
						'placeholder' => 'Globalpayments Test Secret Key'
						),

					'globalpayments_testpublickey' => array(
						'title' => __( 'Test Public Key', 'woocommerce' ),
						'type' => 'text',
						'description' => __( 'This is the Public Key found in API Keys in Account Dashboard.', 'woocommerce' ),
						'default' => '',
						'desc_tip'      => true,
						'placeholder' => 'Globalpayments Test Publishable Key'
						),

					'globalpayments_livesecretkey' => array(
						'title' => __( 'Live Secret Key', 'woocommerce' ),
						'type' => 'text',
						'description' => __( 'This is the Secret Key found in API Keys in Account Dashboard.', 'woocommerce' ),
						'default' => '',
						'desc_tip'      => true,
						'placeholder' => 'Globalpayments Live Secret Key'
						),

					'globalpayments_livepublickey' => array(
						'title' => __( 'Live Public Key', 'woocommerce' ),
						'type' => 'text',
						'description' => __( 'This is the Public Key found in API Keys in Account Dashboard.', 'woocommerce' ),
						'default' => '',
						'desc_tip'      => true,
						'placeholder' => 'Globalpayments Live Publishable Key'
						),

					'globalpayments_sandbox' => array(
						'title'       => __( 'Globalpayments Sandbox', 'woocommerce' ),
						'type'        => 'checkbox',
						'label'       => __( 'Enable globalpayments sandbox (Sandbox mode if checked)', 'woocommerce' ),
						'description' => __( 'If checked its in sanbox mode and if unchecked its in live mode', 'woocommerce' ),
						'desc_tip'      => true,
						'default'     => 'no',
						),
					);

			}
			
			public function get_description() {
				return apply_filters( 'woocommerce_gateway_description', wpautop( wptexturize( trim( $this->globalpayments_description ) ) ), $this->id );
			}
					
			public function is_available() {
				if( 'yes' == $this->globalpayments_sandbox && ( empty($this->globalpayments_testpublickey ) || empty( $this->globalpayments_testsecretkey ) ) ) { 
					return false; 
				}
			
				if( 'no' == $this->globalpayments_sandbox && ( empty($this->globalpayments_livepublickey ) || empty( $this->globalpayments_livesecretkey ) ) ) { 
					return false; 
				}
			
				return true;
			}
			
			public function load_globalpayments_scripts() {
			
				wp_enqueue_script( 'globalpayments', plugins_url( 'assets/js/globalpayments.js',  __FILE__  ), array( 'wc-credit-card-form' ), '', true );
										
			}
			
			public function get_icon() {
				$icon 	= '';
				
				$icon  .= '<img src="' . esc_url( WC_GLOBALPAYMENTS_URL . 'images/globalPayments.png' ) . '" alt="Globalpayments Gateway" />';       
						
				return apply_filters( 'woocommerce_globalpayments_icon', $icon, $this->id );
			}
			
			public function payment_fields() {
				echo apply_filters( 'wc_globalpayments_description', wpautop( wp_kses_post( wptexturize( trim( $this->globalpayments_description ) ) ) ) );
				$this->form();
			}
			
			public function field_name( $name ) {
				return $this->supports( 'tokenization' ) ? '' : ' name="' . esc_attr( $this->id . '-' . $name ) . '" ';
			}
			
			public function form() {
				wp_enqueue_script( 'wc-credit-card-form' );
				
				$fields = array();
				
				$cvc_field	= '	<p class="form-row form-row-last">
									<label for="' . esc_attr( $this->id ) . '-card-cvc">' . __( 'Card Code', 'woocommerce' ) . ' <span class="required">*</span></label>
									<input id="' . esc_attr( $this->id ) . '-card-cvc" class="input-text wc-credit-card-form-card-cvc" type="text" autocomplete="off" placeholder="' . esc_attr__( 'CVC', 'woocommerce' ) . '" ' . $this->field_name( 'card-cvc' ) . ' />
								</p>';
								
				$default_fields = array(
					'card-number-field' => '<p class="form-row form-row-wide">
												<label for="' . esc_attr( $this->id ) . '-card-number">' . __( 'Card Number', 'woocommerce' ) . ' <span class="required">*</span></label>
												<input id="' . esc_attr( $this->id ) . '-card-number" class="input-text wc-credit-card-form-card-number" type="text" maxlength="20" autocomplete="off" placeholder="&bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull; &bull;&bull;&bull;&bull;" ' . $this->field_name( 'card-number' ) . ' />
											</p>',
				'card-expiry-field' => '<p class="form-row form-row-first">
											<label for="' . esc_attr( $this->id ) . '-card-expiry">' . __( 'Expiry (MM/YY)', 'woocommerce' ) . ' <span class="required">*</span></label>
											<input id="' . esc_attr( $this->id ) . '-card-expiry" class="input-text wc-credit-card-form-card-expiry" type="text" autocomplete="off" placeholder="' . esc_attr__( 'MM / YY', 'woocommerce' ) . '" ' . $this->field_name( 'card-expiry' ) . ' />
										</p>',
				'card-cvc-field'  => $cvc_field
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
			
			public function process_payment( $order_id ) {       
				global $error;
				global $woocommerce;
				
				$wc_order	= wc_get_order( $order_id );
				
				$config = new ServicesConfig();
				
				$config->serviceUrl 	= 'https://cert.api2.heartlandportico.com';
				$config->secretApiKey 	= $this->globalpayments_livesecretkey;
				
				if( 'yes' == $this->globalpayments_sandbox ) { 
					$config->secretApiKey 	= $this->globalpayments_testsecretkey;
				}
							
				ServicesContainer::configure( $config );
				
				$exp_date	= explode( "/", sanitize_text_field( $_POST['globalpayments-card-expiry'] ) );
				$exp_month  = str_replace( ' ', '', $exp_date[0] );
				$exp_year   = str_replace( ' ', '', $exp_date[1] );
		
				if (strlen($exp_year) == 2) {
					$exp_year += 2000;
				}
						
				$card = new CreditCardData();
				$card->number 	= sanitize_text_field( str_replace(' ', '', $_POST['globalpayments-card-number'] ) );
				$card->expMonth = $exp_month;
				$card->expYear 	= $exp_year;
				$card->cvn 		= sanitize_text_field( $_POST['globalpayments-card-cvc'] );
				
				// supply the customer's data
				$customer = new Customer();
				$customer->id 			= $wc_order->get_user_id() . "-" . rand( 11111, 99999 );
				$customer->firstName 	= $wc_order->get_billing_first_name();
				$customer->lastName 	= $wc_order->get_billing_last_name();
				$customer->email 		= $wc_order->get_billing_email();
				
				$custom 	= [];
				$custom[] 	= array(
								'email'		=> $wc_order->get_billing_email(),
								'order_id'	=> $wc_order->get_order_number()  
								);
							
				try {
					$this->log( $card );
					
					$response = $card->charge( $wc_order->get_total() )
							->withCurrency( get_woocommerce_currency() )
							->withCustomerData( $customer )
                			->withCustomData( $custom )
							->withAllowDuplicates( true )
							->execute();
					
					$this->log( 'Payment completed: ' );
					$this->log( $response );
																				
					$timestamp	= date('Y-m-d H:i:s A e', current_time( 'timestamp' ) );

					$wc_order->add_order_note( __( 'Globalpayments payment completed at ' . $timestamp . ', Charge ID = ' . $response->transactionId, 'woocommerce' ) );
					
					add_post_meta( $wc_order->get_id(), '_globalpayments_charge_id', $response->transactionId );
					add_post_meta( $wc_order->get_id(), '_globalpayments_auth_code', $response->authorizationCode );
					
					$wc_order->payment_complete( $response->transactionId );
                    WC()->cart->empty_cart();
													
					return array (
							'result'   => 'success',
							'redirect' => $this->get_return_url( $wc_order ),
							);					
				} catch (Exception $e) {
					$this->log( 'Payment Failure: ' );
					$this->log( $e->getMessage() );
											
					$wc_order->add_order_note( __( 'Payment Failure: ' . $e->getMessage(), 'woocommerce' ) );
					wc_add_notice( 'Payment Failure: ' . $e->getMessage(),  $notice_type = 'error' );
				}
				
			} // end of function process_payment()

			public function process_refund( $order_id, $amount = NULL, $reason = '' ) {
				
				$wc_order    = new WC_Order( $order_id );
				
				if( $amount > 0 ) {
					
					$transactionId	= get_post_meta( $order_id , '_globalpayments_charge_id', true );
					$authCode		= get_post_meta( $order_id , '_globalpayments_auth_code', true );
					
					try {
						$config = new ServicesConfig();
				
						$config->serviceUrl 	= 'https://cert.api2.heartlandportico.com';
						$config->secretApiKey 	= $this->globalpayments_livesecretkey;
						
						if( 'yes' == $this->globalpayments_sandbox ) { 
							$config->secretApiKey 	= $this->globalpayments_testsecretkey;
						}
									
						ServicesContainer::configure( $config );
						
						// create the rebate transaction object
						$transaction = Transaction::fromId( $transactionId );
						$transaction->authorizationCode = $authCode;
				
						// send the settle request, we must specify the amount and currency
						$response = $transaction->refund( $amount )
								->withCurrency( $wc_order->get_currency() )
								->execute();
				
						$this->log( 'Refunded' );
						$this->log( $response );
											
						$rtimestamp  = date('Y-m-d H:i:s', current_time( 'timestamp' ) );
						
						$wc_order->add_order_note( __( $amount . ' Refunded at ' . $rtimestamp . ', Refund Ref ID = ' . $response->transactionId, 'woocommerce' ) );                         
						
						return true;																
					} catch (Exception $e) {
						$this->log( 'Refund failed: ' );
						$this->log( $e->getMessage() );
						
						$wc_order->add_order_note( __( 'Refund failed: ' . $e->getMessage(), 'woocommerce' ) );
						return false;
					}				                		
				} else {
					$wc_order->add_order_note( __('Refund cant proccess, amount is less than zero. ', 'woocommerce' ) );            
					return false;
				}

			} // end of  process_refund()
		
			public function log( $msg ) {
				
				if( ! $this->enable_log )
					return;
					
				$msg	= function_exists( 'maybe_unserialize' ) ? maybe_unserialize( $msg ) : $msg;
				
				$msg	= ( is_array( $msg ) || is_object( $msg ) ) ? print_r( $msg, 1 ) : $msg;
					
				error_log( date('[Y-m-d H:i:s e] ') . $msg . PHP_EOL, 3, __DIR__ . "/debug.log" );
			}
	
        }  // end of class WC_Globalpayments_Gateway

	} // end of if class exist WC_Gateway
	
}
add_action( 'plugins_loaded', 'globalpayments_init' );

function appointment_datepicker_save() {
	if( ! session_id() )
		@session_start();
		
	$_SESSION['appointment_date']	= $_POST['appointment_date']; 	
	die();	
}
add_action( 'wp_ajax_appointment_datepicker', 'appointment_datepicker_save' );
add_action( 'wp_ajax_nopriv_appointment_datepicker', 'appointment_datepicker_save' );

if( ! function_exists( 'print_rr' ) ):
	function print_rr( $content = "", $subject = NULL ) {
		if( $subject )
			echo '<strong>' . $subject . '</strong><br>'; 
		
		echo "<pre>";
		print_r( $content );
		echo "</pre>";
	} 
endif;

function _custom_debug() {
	if( ! isset( $_GET['_debug'] ) )
		return;
	
	/*print_rr( $_REQUEST );
	
	print_rr( $_COOKIE );*/
	
	exit;	
}
add_action( 'wp', '_custom_debug' );

function appointment_datepicker_content() {
	ob_start();
	?>
    <h4>Confirm Date</h4>
    <input type="text" name="appointment_datepicker" id="appointment_datepicker" value="" />
    <script type="text/javascript">
   	function setCookie(cname, cvalue, exdays) {
	  var d = new Date();
	  d.setTime(d.getTime() + (exdays*24*60*60*1000));
	  var expires = "expires="+ d.toUTCString();
	  document.cookie = cname + "=" + cvalue + ";" + expires + ";path=/";
	}

    jQuery(document).ready(function(e) {
    	jQuery("#appointment_datepicker").datepicker({ 
			minDate: 0
		}); 	
		jQuery("#appointment_datepicker").on( "change", function(){
			var appointment_date	= jQuery(this).val();
			setCookie( 'appointment_date', appointment_date, 1 );
        	jQuery.post("<?php echo admin_url( 'admin-ajax.php' );?>", { 'action': 'appointment_datepicker', 'appointment_date': appointment_date }, function(){				
			});
    	});   
    });
	
	jQuery(document).on('click', 'a.fl-button', function(event){
		var href	= jQuery(this).attr( 'href' ); 
		
		if( href == '<?php echo site_url( 'checkout' );?>' || href == '<?php echo site_url( 'checkout/' );?>' ) {
			var appointment_datepicker	= jQuery("#appointment_datepicker").val();
			 
			if( appointment_datepicker != '' )
				return;
				
			event.stopPropagation();	
			event.preventDefault();
			
			alert( 'Please select confirm date.' );
			jQuery("#appointment_datepicker").focus();
		}
	});
    </script>
    <?php
	
	return ob_get_clean();
}
add_shortcode( 'appointment_datepicker', 'appointment_datepicker_content' );

function custom__after_checkout_billing_form( $checkout ) {
	if( ! session_id() )
		@session_start();
		
	$appointment_date	= $_SESSION['appointment_date']; 	
	
	if( empty( $appointment_date ) ) {
		$appointment_date	= $_COOKIE['appointment_date']; 		
	}
	
	if( empty( $appointment_date ) ) {
		$appointment_date	= $_REQUEST['appointment_date']; 			
	}
	
	if( ! empty( $appointment_date ) ) {
		$appointment_date	= date( 'Y-m-d', strtotime( $appointment_date ) ); 			
	}
			
	echo '<input type="hidden" name="appointment_date" value="' . $appointment_date . '"/>';
}
add_action('woocommerce_after_checkout_billing_form', 'custom__after_checkout_billing_form', 10, 1 );

function custom__checkout_validation( $fields, $errors ){
	if( empty( $_POST['appointment_date'] ) ) {
		$errors->add( 'validation', '<strong>Staging Date</strong> is a required field. go to previous step and select date.' );
	}		
}
add_action( 'woocommerce_after_checkout_validation', 'custom__checkout_validation', 20, 2 );

function custom__order_detail_update( $order_id ) {
	update_post_meta( $order_id, 'appointment_date', $_POST['appointment_date'] );
	
	if( ! session_id() )
		@session_start();
		
	$_SESSION['appointment_date']	= NULL; 
	$_COOKIE['appointment_date']	= NULL;
	
	unset( $_SESSION['appointment_date'] );	 
	unset( $_COOKIE['appointment_date'] );	 
}
add_action('woocommerce_checkout_update_order_meta', 'custom__order_detail_update', 20, 1 );

function custom__order_detail_show( $order ) {
	$appointment_date	= get_post_meta( $order->get_id(), 'appointment_date', true );
	?>
    <p>&nbsp;</p>
    <h3>Staging Date: <?php echo $appointment_date ? date( get_option( 'date_format' ), strtotime( $appointment_date ) ) : "";?></h3>
    <?php		
}
add_action( 'woocommerce_admin_order_data_after_order_details', 'custom__order_detail_show', 9, 1 );