<?php
/**
 * Plugin Name:       ArionPay for Charitable
 * Plugin URI:        https://arionpay.com
 * Description:       Accept crypto donations (Bitcoin, USDT, Litecoin, Ethereum, TRON and more) in Charitable through ArionPay. The money goes straight to your own wallet.
 * Version:           1.0.0
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Requires Plugins:  charitable
 * Author:            ArionPay
 * Author URI:        https://arionpay.com
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       arionpay-for-charitable
 *
 * @package ArionPay_For_Charitable
 */

defined( 'ABSPATH' ) || exit;

define( 'ARIONPAY_CHARITABLE_VERSION', '1.0.0' );
define( 'ARIONPAY_CHARITABLE_DIR', plugin_dir_path( __FILE__ ) );

/**
 * Register the gateway with Charitable (the class needs Charitable's gateway base class, so it loads here).
 *
 * @param array $gateways Gateway id => class name.
 * @return array
 */
function arionpay_charitable_register_gateway( $gateways ) {
	if ( ! class_exists( 'Charitable_Gateway_ArionPay' ) ) {
		require_once ARIONPAY_CHARITABLE_DIR . 'lib/arionpay-php/autoload.php';
		require_once ARIONPAY_CHARITABLE_DIR . 'includes/class-charitable-gateway-arionpay.php';
	}
	$gateways['arionpay'] = 'Charitable_Gateway_ArionPay';
	return $gateways;
}
add_filter( 'charitable_payment_gateways', 'arionpay_charitable_register_gateway' );

/**
 * Hooks that may run before Charitable lists its gateways (webhooks, saving settings, the receipt page).
 */
function arionpay_charitable_hooks() {
	if ( ! class_exists( 'Charitable' ) ) {
		return;
	}
	arionpay_charitable_register_gateway( array() );
	add_filter( 'charitable_process_donation_arionpay', array( 'Charitable_Gateway_ArionPay', 'process_donation' ), 10, 3 );
	add_filter( 'charitable_validate_donation_form_submission_gateway', array( 'Charitable_Gateway_ArionPay', 'validate_donation' ), 10, 3 );
	add_action( 'charitable_process_ipn_arionpay', array( 'Charitable_Gateway_ArionPay', 'process_ipn' ) );
	add_filter( 'charitable_sanitize_value', array( 'Charitable_Gateway_ArionPay', 'sanitize_setting' ), 10, 5 );
	add_action( 'charitable_donation_receipt_before', array( 'Charitable_Gateway_ArionPay', 'receipt_notice' ) );
}
add_action( 'plugins_loaded', 'arionpay_charitable_hooks', 20 );
