<?php
/**
 * MMG Payments Blocks Integration
 *
 * Integrates MMG Checkout payment method with WooCommerce Blocks.
 *
 * @package MMG_Checkout_Payment
 */

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

/**
 * MMGCP_Payments_Blocks class.
 */
class MMGCP_Payments_Blocks extends AbstractPaymentMethodType {

	/**
	 * Payment method name.
	 *
	 * @var string
	 */
	protected $name = 'mmg_checkout';

	/**
	 * Initialize the payment method type.
	 */
	public function initialize() {
		$this->settings = get_option( 'woocommerce_mmg_checkout_settings', array() );
	}

	/**
	 * Check if the payment method is active.
	 *
	 * @return boolean
	 */
	public function is_active() {
		return ! empty( $this->settings['enabled'] ) && 'yes' === $this->settings['enabled'];
	}

	/**
	 * Enqueue payment method scripts.
	 */
	public function enqueue_payment_method_scripts() {
		wp_enqueue_script(
			'mmgcp-payments-blocks',
			plugins_url( '/admin/js/mmg-checkout-blocks.js', __DIR__ ),
			array( 'wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n' ),
			filemtime( plugin_dir_path( __DIR__ ) . '/admin/js/mmg-checkout-blocks.js' ),
			true
		);
	}

	/**
	 * Returns an array of scripts/handles to be registered for this payment method.
	 *
	 * @return string[]
	 */
	public function get_payment_method_script_handles() {
		$this->enqueue_payment_method_scripts();
		return array( 'mmgcp-payments-blocks' );
	}

	/**
	 * Returns an array of key=>value pairs of data made available to the payment methods script.
	 *
	 * @return array
	 */
	public function mmgcp_get_payment_method_data() {
		return array(
			'title'       => $this->settings['title'],
			'description' => $this->settings['description'],
		);
	}
}
