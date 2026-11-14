<?php
/**
 * ArionPay secret field for Charitable's settings: the saved value is never printed. Empty keeps the saved one.
 *
 * @package ArionPay_For_Charitable
 */

defined( 'ABSPATH' ) || exit;

$arionpay_saved = '' !== (string) charitable_get_option( $view_args['key'] );
?>
<input type="password" autocomplete="new-password" value=""
	id="<?php printf( 'charitable_settings_%s', esc_attr( implode( '_', $view_args['key'] ) ) ); ?>"
	name="<?php printf( 'charitable_settings[%s]', esc_attr( $view_args['name'] ) ); ?>"
	class="<?php echo esc_attr( isset( $view_args['classes'] ) ? $view_args['classes'] : '' ); ?>"
	placeholder="<?php echo $arionpay_saved ? esc_attr__( 'Saved. Leave empty to keep it.', 'arionpay-for-charitable' ) : ''; ?>" />
<?php if ( isset( $view_args['help'] ) ) : ?>
	<div class="charitable-help"><?php echo wp_kses_post( $view_args['help'] ); ?></div>
<?php endif; ?>
