<?php
/**
 * Removes this plugin's own option. The gateway settings live in Charitable's settings; donation notes stay.
 *
 * @package ArionPay_For_Charitable
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'arionpay_charitable_site_id' );
