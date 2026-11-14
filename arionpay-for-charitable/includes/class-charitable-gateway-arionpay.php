<?php
/**
 * ArionPay gateway for Charitable: one-time crypto donations paid on the ArionPay payment page.
 *
 * A donation is completed only by a signed invoice.paid webhook, or a signed read of the invoice when the donor comes
 * back, for the same amount and currency as the donation.
 *
 * @package ArionPay_For_Charitable
 */

defined( 'ABSPATH' ) || exit;

/**
 * The ArionPay gateway.
 */
class Charitable_Gateway_ArionPay extends Charitable_Gateway {

	const ID = 'arionpay';

	/** Invoice statuses (signed read) that mean "a payment is on its way". */
	const OPEN = array( 'pending', 'paid_partial', 'claim_pending' );

	const CURRENCIES = array( 'USD', 'EUR', 'GBP', 'CAD', 'AUD', 'CHF', 'JPY', 'TRY', 'AED', 'INR', 'BRL', 'RUB', 'CNY', 'SGD', 'HKD', 'KRW', 'MXN', 'PLN', 'SEK', 'NOK', 'ZAR' );

	const MAX_BODY_BYTES = 262144;

	/**
	 * Set up the gateway.
	 */
	public function __construct() {
		$this->name     = __( 'ArionPay (crypto)', 'arionpay-for-charitable' );
		$this->defaults = array(
			'label' => __( 'Crypto (Bitcoin, USDT and more)', 'arionpay-for-charitable' ),
		);
		$this->supports = array( '1.3.0' );
	}

	/**
	 * The gateway id (Charitable_Gateway_Interface).
	 *
	 * @return string
	 */
	public static function get_gateway_id() {
		return self::ID;
	}

	/**
	 * Settings shown under Charitable > Settings > Payment Gateways > ArionPay.
	 *
	 * @param array $settings Settings.
	 * @return array
	 */
	public function gateway_settings( $settings ) {
		$view = array(
			'type'      => 'arionpay-secret',
			'base_path' => ARIONPAY_CHARITABLE_DIR . 'views/',
		);
		return array_merge(
			$settings,
			array(
				'api_key'        => array(
					'type'     => 'text',
					'title'    => __( 'API key', 'arionpay-for-charitable' ),
					'priority' => 6,
					'help'     => __( 'ArionPay dashboard > your store > API key (starts with pk_).', 'arionpay-for-charitable' ),
				),
				'api_secret'     => $view + array(
					'title'    => __( 'API secret', 'arionpay-for-charitable' ),
					'priority' => 7,
					'help'     => __( 'ArionPay dashboard > your store > API secret (starts with sk_). Never shown again after saving.', 'arionpay-for-charitable' ),
				),
				'webhook_secret' => $view + array(
					'title'    => __( 'Webhook signing secret', 'arionpay-for-charitable' ),
					'priority' => 8,
					'help'     => __( 'ArionPay dashboard > Webhooks > signing secret (starts with whsec_). Never shown again after saving.', 'arionpay-for-charitable' ),
				),
				'arionpay_info'  => array(
					'type'     => 'content',
					'priority' => 9,
					'content'  => '<div class="charitable-settings-notice"><p>'
						. esc_html__( 'Nothing to paste into ArionPay: every invoice tells ArionPay to send its payment updates to', 'arionpay-for-charitable' )
						. '<br /><code>' . esc_html( charitable_get_ipn_url( self::ID ) ) . '</code></p><p>'
						. esc_html__( 'Test donations: turn on Test Mode in Charitable > Settings > Payment Gateways (no real money moves). In your ArionPay store settings, keep "Old ArionPay (v1) integration compatibility" off.', 'arionpay-for-charitable' )
						. '</p></div>',
				),
				'base_url'       => array(
					'type'     => 'text',
					'title'    => __( 'ArionPay address', 'arionpay-for-charitable' ),
					'priority' => 10,
					'help'     => __( 'Leave empty unless ArionPay tells you otherwise.', 'arionpay-for-charitable' ),
				),
			)
		);
	}

	/**
	 * Keep saved secrets when the field is left empty; refuse values that cannot be right.
	 *
	 * @param mixed  $value     Submitted value.
	 * @param array  $field     Field.
	 * @param array  $submitted Submitted section.
	 * @param string $key       Field key.
	 * @param string $section   Section.
	 * @return mixed
	 */
	public static function sanitize_setting( $value, $field, $submitted, $key, $section ) {
		if ( 'gateways_' . self::ID !== $section ) {
			return $value;
		}
		$checks = array(
			'api_key'        => '/^pk_[A-Za-z0-9_-]{8,64}$/',
			'api_secret'     => '/^sk_/',
			'webhook_secret' => '/^whsec_/',
			'base_url'       => '#^https?://#i',
		);
		if ( ! isset( $checks[ $key ] ) ) {
			return $value;
		}
		$value = trim( (string) $value );
		$old   = (string) charitable_get_option( array( $section, $key ), '' );
		if ( '' === $value && in_array( $key, array( 'api_secret', 'webhook_secret' ), true ) ) {
			return $old;
		}
		if ( '' !== $value && ! preg_match( $checks[ $key ], $value ) ) {
			/* translators: %s: setting name */
			charitable_get_admin_notices()->add_notice( sprintf( __( 'ArionPay: the %s does not look right, so it was not saved. Copy it again from your ArionPay dashboard.', 'arionpay-for-charitable' ), $field['title'] ), 'error', false, true );
			return $old;
		}
		return $value;
	}

	/**
	 * Refuse the donation before it is created when the gateway cannot take it.
	 *
	 * @param bool   $valid   Valid so far.
	 * @param string $gateway Chosen gateway.
	 * @param array  $values  Form values.
	 * @return bool
	 */
	public static function validate_donation( $valid, $gateway, $values ) {
		if ( self::ID !== $gateway ) {
			return $valid;
		}
		$g = new self();
		if ( '' === trim( (string) $g->get_value( 'api_key' ) ) || '' === trim( (string) $g->get_value( 'api_secret' ) ) ) {
			charitable_get_notices()->add_error( __( 'Crypto donations are not set up yet. Please choose another payment method.', 'arionpay-for-charitable' ) );
			return false;
		}
		if ( ! in_array( strtoupper( charitable_get_currency() ), self::CURRENCIES, true ) ) {
			charitable_get_notices()->add_error( __( 'Crypto donations are not available in this currency.', 'arionpay-for-charitable' ) );
			return false;
		}
		return $valid;
	}

	// ------------------------------------------------------------------ donating

	/**
	 * The donation is saved (pending): send the donor to the ArionPay payment page.
	 *
	 * @param mixed                         $return      Previous value.
	 * @param int                           $donation_id Donation id.
	 * @param Charitable_Donation_Processor $processor   Processor.
	 * @return array|false
	 */
	public static function process_donation( $return, $donation_id, $processor ) {
		$donation = charitable_get_donation( $donation_id );
		if ( ! $donation ) {
			return false;
		}
		$g = new self();
		update_post_meta( $donation_id, '_arionpay_amount', number_format( (float) $donation->get_total_donation_amount( true ), 2, '.', '' ) );
		update_post_meta( $donation_id, '_arionpay_currency', strtoupper( charitable_get_currency() ) );
		update_post_meta( $donation_id, '_arionpay_test', charitable_get_option( 'test_mode', false ) ? 1 : 0 );
		update_post_meta( $donation_id, '_arionpay_attempt', 1 );
		try {
			$url = $g->payment_page_url( $donation_id );
		} catch ( \Throwable $e ) {
			$donation->log()->add( 'ArionPay: could not start the payment: ' . $e->getMessage() );
			charitable_get_notices()->add_error( __( 'The crypto payment could not be started right now. Please try again in a minute.', 'arionpay-for-charitable' ) );
			return false;
		}
		return array(
			'redirect' => null === $url ? charitable_get_permalink( 'donation_receipt_page', array( 'donation_id' => $donation_id ) ) : $url,
			'safe'     => false,
		);
	}

	/**
	 * The open ArionPay invoice of this donation when it still fits, otherwise a new one. Null when ArionPay already
	 * has it as paid (it is then completed here too).
	 *
	 * @param int $donation_id Donation id.
	 * @return string|null
	 * @throws \RuntimeException When no invoice can be made.
	 */
	private function payment_page_url( $donation_id ) {
		$client     = $this->client();
		$attempt    = max( 1, (int) get_post_meta( $donation_id, '_arionpay_attempt', true ) );
		$invoice_id = (string) get_post_meta( $donation_id, '_arionpay_invoice_id', true );
		if ( '' !== $invoice_id ) {
			try {
				$read = $client->getInvoice( $invoice_id );
			} catch ( \ArionPay\ApiException $e ) {
				if ( 404 !== $e->getStatus() ) {
					throw $e;
				}
				$read = array(
					'status' => 'missing',
					'raw'    => array(),
				);
			}
			if ( 'paid' === $read['status'] ) {
				$this->complete( $donation_id, $read, 'signed read' );
				return null;
			}
			$expires = isset( $read['raw']['expiresAt'] ) ? strtotime( (string) $read['raw']['expiresAt'] ) : 0;
			if ( in_array( $read['status'], self::OPEN, true ) || ( 'new' === $read['status'] && ( 0 === $expires || $expires > time() + 300 ) ) ) {
				return (string) get_post_meta( $donation_id, '_arionpay_invoice_url', true );
			}
			$attempt++;
		}
		$donation = charitable_get_donation( $donation_id );
		$amount   = (string) get_post_meta( $donation_id, '_arionpay_amount', true );
		$currency = (string) get_post_meta( $donation_id, '_arionpay_currency', true );
		$test     = (bool) get_post_meta( $donation_id, '_arionpay_test', true );
		for ( $tries = 0; $tries < 20; $tries++, $attempt++ ) {
			$created = $client->createInvoice(
				array(
					'amount'         => $amount,
					'currency'       => $currency,
					'orderId'        => (string) $donation_id,
					'description'    => mb_substr( wp_strip_all_tags( html_entity_decode( get_bloginfo( 'name' ) . ': ' . $donation->get_campaigns_donated_to(), ENT_QUOTES, 'UTF-8' ) ), 0, 500 ),
					'successUrl'     => self::return_url( $donation_id ),
					'cancelUrl'      => self::return_url( $donation_id ) . '&cancel=1',
					'callbackUrl'    => charitable_get_ipn_url( self::ID ),
					'metadata'       => array(
						'charitable_donation_id' => (int) $donation_id,
						'charitable_amount'      => $amount,
						'charitable_currency'    => $currency,
						'charitable_site'        => self::site_id(),
						'platform'               => 'charitable',
					),
					'idempotencyKey' => 'charitable-' . self::site_id() . '-' . (int) $donation_id . '-' . str_replace( '.', '', $amount ) . $currency . ( $test ? '-test' : '' ) . '-' . $attempt,
					'test'           => $test,
				)
			);
			if ( 'paid' === $created['status'] ) {
				return null;
			}
			$expires = $created['expiresAt'] ? strtotime( (string) $created['expiresAt'] ) : 0;
			if ( in_array( $created['status'], self::OPEN, true ) || ( 'new' === $created['status'] && ( 0 === $expires || $expires > time() + 300 ) ) ) {
				update_post_meta( $donation_id, '_arionpay_invoice_id', $created['id'] );
				update_post_meta( $donation_id, '_arionpay_invoice_url', $created['invoiceUrl'] );
				update_post_meta( $donation_id, '_arionpay_attempt', $attempt );
				$donation->set_gateway_transaction_id( $created['id'] );
				return $created['invoiceUrl'];
			}
		}
		throw new \RuntimeException( 'Too many finished ArionPay invoices for this donation.' );
	}

	// ------------------------------------------------------------------ notifications

	/**
	 * ?charitable-listener=arionpay: ArionPay webhooks, the donor's return and the "Pay with crypto" link.
	 */
	public static function process_ipn() {
		$g = new self();
		if ( ! empty( $_SERVER['HTTP_ARIONPAY_SIGNATURE'] ) ) {
			$g->handle_webhook( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ARIONPAY_SIGNATURE'] ) ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- signed links (HMAC in k)
		$donation_id = isset( $_GET['donation_id'] ) ? absint( $_GET['donation_id'] ) : 0;
		$key         = isset( $_GET['k'] ) ? sanitize_text_field( wp_unslash( $_GET['k'] ) ) : '';
		$pay         = ! empty( $_GET['arionpay_pay'] );
		// phpcs:enable
		if ( ! $donation_id || ! hash_equals( self::link_key( $donation_id ), $key ) || self::ID !== get_post_meta( $donation_id, 'donation_gateway', true ) ) {
			wp_safe_redirect( home_url( '/' ) );
			exit;
		}
		$pay ? $g->handle_pay_again( $donation_id ) : $g->handle_return( $donation_id );
	}

	/**
	 * A signed ArionPay webhook.
	 *
	 * @param string $header ArionPay-Signature header.
	 */
	private function handle_webhook( $header ) {
		$raw = (string) file_get_contents( 'php://input', false, null, 0, self::MAX_BODY_BYTES + 1 );
		if ( strlen( $raw ) > self::MAX_BODY_BYTES ) {
			self::respond( 413, array( 'error' => 'too large' ) );
		}
		$secret = trim( (string) $this->get_value( 'webhook_secret' ) );
		if ( '' === $secret ) {
			self::respond( 401, array( 'error' => 'webhook secret not set' ) );
		}
		try {
			$event = \ArionPay\Webhook::verify( $raw, $header, $secret );
		} catch ( \ArionPay\SignatureException $e ) {
			self::respond( 401, array( 'error' => 'invalid signature' ) );
		}
		if ( \ArionPay\Webhook::isTestEvent( $event ) ) {
			self::respond( 200, array( 'received' => true, 'test' => true ) );
		}
		$invoice     = $event['data']['invoice'];
		$meta        = (array) ( $invoice['metadata'] ?? array() );
		$donation_id = (int) ( $meta['charitable_donation_id'] ?? 0 );
		if ( ! $donation_id || ( $meta['charitable_site'] ?? '' ) !== self::site_id() || self::ID !== get_post_meta( $donation_id, 'donation_gateway', true ) ) {
			self::respond( 200, array( 'received' => true, 'ignored' => 'not a donation of this site' ) );
		}
		if ( (bool) ( $invoice['test'] ?? false ) !== (bool) get_post_meta( $donation_id, '_arionpay_test', true ) ) {
			self::respond( 409, array( 'error' => 'test/live mismatch' ) );
		}
		if ( 'invoice.paid' === $event['type'] ) {
			$result = $this->complete( $donation_id, $invoice, 'webhook' );
			self::respond( 'locked' === $result ? 503 : 200, array( 'received' => true, 'result' => $result ) );
		}
		$texts = array(
			'invoice.pending'      => 'ArionPay: payment seen for invoice %s, waiting for network confirmations.',
			'invoice.partial'      => 'ArionPay: partial payment for invoice %s. The donation is completed only when it is fully paid.',
			'invoice.needs_review' => 'ArionPay flagged invoice %s for review (Payment Radar). Decide in your ArionPay dashboard.',
			'invoice.expired'      => 'ArionPay invoice %s expired without full payment. A late payment still completes the donation.',
		);
		if ( isset( $texts[ $event['type'] ] ) ) {
			self::note_once( $donation_id, (string) $event['id'], ( ! empty( $invoice['test'] ) ? '[TEST] ' : '' ) . sprintf( $texts[ $event['type'] ], $invoice['id'] ) );
		}
		self::respond( 200, array( 'received' => true ) );
	}

	/**
	 * The donor came back from the ArionPay page: check the invoice with ArionPay (never trust the link), then show
	 * Charitable's receipt.
	 *
	 * @param int $donation_id Donation id.
	 */
	private function handle_return( $donation_id ) {
		$state      = 'unpaid';
		$invoice_id = (string) get_post_meta( $donation_id, '_arionpay_invoice_id', true );
		if ( '' !== $invoice_id ) {
			try {
				$read = $this->client()->getInvoice( $invoice_id );
				if ( 'paid' === $read['status'] && (bool) $read['test'] === (bool) get_post_meta( $donation_id, '_arionpay_test', true ) ) {
					$this->complete( $donation_id, $read, 'signed read on return' );
				}
				$state = in_array( $read['status'], self::OPEN, true ) ? 'pending' : $read['status'];
			} catch ( \Throwable $e ) {
				$state = 'unknown';
			}
		}
		$url = charitable_get_permalink( 'donation_receipt_page', array( 'donation_id' => $donation_id ) );
		wp_safe_redirect( add_query_arg( 'arionpay', rawurlencode( $state ), $url ) );
		exit;
	}

	/**
	 * Signed "Pay with crypto" link: the open invoice again, or a new one when it expired.
	 *
	 * @param int $donation_id Donation id.
	 */
	private function handle_pay_again( $donation_id ) {
		$donation = charitable_get_donation( $donation_id );
		$receipt  = charitable_get_permalink( 'donation_receipt_page', array( 'donation_id' => $donation_id ) );
		if ( ! $donation || 'charitable-pending' !== $donation->get_status() ) {
			wp_safe_redirect( $receipt );
			exit;
		}
		try {
			$url = $this->payment_page_url( $donation_id );
		} catch ( \Throwable $e ) {
			$donation->log()->add( 'ArionPay: could not reopen the payment: ' . $e->getMessage() );
			wp_safe_redirect( add_query_arg( 'arionpay', 'error', $receipt ) );
			exit;
		}
		wp_redirect( null === $url ? add_query_arg( 'arionpay', 'paid', $receipt ) : $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- the ArionPay payment page
		exit;
	}

	/**
	 * On Charitable's receipt page: where the crypto payment stands, with a "Pay with crypto" button while it is unpaid.
	 *
	 * @param Charitable_Donation $donation Donation.
	 */
	public static function receipt_notice( $donation ) {
		if ( ! $donation || self::ID !== $donation->get_gateway() ) {
			return;
		}
		$id = (int) $donation->get_donation_id();
		if ( 'charitable-completed' === $donation->get_status() ) {
			echo '<div class="charitable-notice arionpay-notice arionpay-paid"><p>' . esc_html__( 'Thank you! Your crypto donation is confirmed.', 'arionpay-for-charitable' ) . '</p></div>';
			return;
		}
		if ( 'charitable-pending' !== $donation->get_status() ) {
			return;
		}
		$state = isset( $_GET['arionpay'] ) ? sanitize_key( wp_unslash( $_GET['arionpay'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only
		if ( 'pending' === $state ) {
			echo '<div class="charitable-notice arionpay-notice arionpay-pending"><p>' . esc_html__( 'Your payment is on its way. The donation is confirmed as soon as the network confirms it.', 'arionpay-for-charitable' ) . '</p></div>';
			return;
		}
		echo '<div class="charitable-notice arionpay-notice arionpay-unpaid"><p>' . esc_html__( 'We have not received the crypto payment for this donation yet.', 'arionpay-for-charitable' )
			. ' <a class="button arionpay-pay-again" id="arionpay_pay_again" href="' . esc_url( self::pay_again_url( $id ) ) . '">' . esc_html__( 'Pay with crypto', 'arionpay-for-charitable' ) . '</a></p></div>';
	}

	/**
	 * Complete the donation for a paid ArionPay invoice. Safe to call many times (under a database lock).
	 *
	 * @param int    $donation_id Donation id.
	 * @param array  $invoice     Invoice (webhook field names).
	 * @param string $source      Where it came from.
	 * @return string completed | duplicate | already_done | mismatch | not_waiting | locked
	 */
	private function complete( $donation_id, array $invoice, $source ) {
		global $wpdb;
		$meta     = (array) ( $invoice['metadata'] ?? array() );
		$amount   = (string) get_post_meta( $donation_id, '_arionpay_amount', true );
		$currency = (string) get_post_meta( $donation_id, '_arionpay_currency', true );
		$paid     = is_numeric( $invoice['amountFiat'] ?? null ) ? number_format( (float) $invoice['amountFiat'], 2, '.', '' ) : '';
		if ( (int) ( $meta['charitable_donation_id'] ?? 0 ) !== (int) $donation_id || ( $meta['charitable_site'] ?? '' ) !== self::site_id()
			|| $paid !== $amount || strtoupper( (string) ( $invoice['currency'] ?? '' ) ) !== strtoupper( $currency ) ) {
			self::note_once( $donation_id, 'mismatch-' . ( $invoice['id'] ?? '' ), 'ArionPay invoice ' . ( $invoice['id'] ?? '' ) . ' is paid, but its amount or currency does not match this donation. NOT completed: check it in your ArionPay dashboard.' );
			return 'mismatch';
		}
		$lock = 'charitable_arionpay_' . (int) $donation_id;
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 20)', $lock ) ) ) {
			return 'locked';
		}
		try {
			wp_cache_delete( $donation_id, 'post_meta' );
			$done = (string) get_post_meta( $donation_id, '_arionpay_paid_invoice', true );
			if ( '' !== $done ) {
				if ( $done === $invoice['id'] ) {
					return 'duplicate';
				}
				self::note_once( $donation_id, 'again-' . $invoice['id'], 'Another ArionPay invoice (' . $invoice['id'] . ') for this donation was paid too. Possible double payment: check your wallet and refund it.' );
				return 'already_done';
			}
			clean_post_cache( $donation_id );
			$donation = charitable_get_donation( $donation_id, true );
			if ( ! $donation || 'charitable-pending' !== $donation->get_status() ) {
				self::note_once( $donation_id, 'state-' . $invoice['id'], 'ArionPay invoice ' . $invoice['id'] . ' is paid, but the donation status is "' . ( $donation ? $donation->get_status() : '?' ) . '". NOT changed: check it.' );
				return 'not_waiting';
			}
			update_post_meta( $donation_id, '_arionpay_paid_invoice', $invoice['id'] );
			$text = ( ! empty( $invoice['test'] ) ? '[TEST - no real money] ' : '' ) . 'ArionPay invoice ' . $invoice['id'] . ' is paid (' . $paid . ' ' . $currency . ')';
			if ( ! empty( $invoice['asset'] ) ) {
				$text .= '; ' . trim( ( $invoice['paidCrypto'] ?? '' ) . ' ' . $invoice['asset'] . ( ! empty( $invoice['network'] ) ? ' on ' . $invoice['network'] : '' ) );
			}
			foreach ( array( 'late' => 'paid late', 'underpaidWithinTolerance' => 'slightly under, inside your payment tolerance', 'overpaid' => 'overpaid', 'manual' => 'accepted by hand in ArionPay' ) as $flag => $label ) {
				if ( ! empty( $invoice[ $flag ] ) ) {
					$text .= '; ' . $label;
				}
			}
			$donation->set_gateway_transaction_id( $invoice['id'] );
			$donation->log()->add( $text . ' [' . $source . '].' );
			$donation->update_status( 'charitable-completed' );
			return 'completed';
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	// ------------------------------------------------------------------ helpers

	/**
	 * The ArionPay client.
	 *
	 * @return \ArionPay\Client
	 */
	private function client() {
		$base = trim( (string) $this->get_value( 'base_url' ) );
		return new \ArionPay\Client(
			trim( (string) $this->get_value( 'api_key' ) ),
			trim( (string) $this->get_value( 'api_secret' ) ),
			array(
				'baseUrl' => '' !== $base ? $base : \ArionPay\Client::DEFAULT_BASE_URL,
				'appInfo' => 'charitable/' . ( class_exists( 'Charitable' ) ? Charitable::VERSION : '?' ) . ' arionpay-charitable/' . ARIONPAY_CHARITABLE_VERSION,
			)
		);
	}

	/**
	 * Key of the signed return and pay links.
	 *
	 * @param int $donation_id Donation id.
	 * @return string
	 */
	private static function link_key( $donation_id ) {
		return substr( hash_hmac( 'sha256', 'charitable-arionpay|' . (int) $donation_id, wp_salt( 'auth' ) ), 0, 32 );
	}

	/**
	 * Where the donor comes back to.
	 *
	 * @param int $donation_id Donation id.
	 * @return string
	 */
	private static function return_url( $donation_id ) {
		return add_query_arg(
			array(
				'arionpay_return' => 1,
				'donation_id'     => (int) $donation_id,
				'k'               => self::link_key( $donation_id ),
			),
			charitable_get_ipn_url( self::ID )
		);
	}

	/**
	 * "Pay with crypto" link for an unpaid donation.
	 *
	 * @param int $donation_id Donation id.
	 * @return string
	 */
	private static function pay_again_url( $donation_id ) {
		return add_query_arg( 'arionpay_pay', 1, self::return_url( $donation_id ) );
	}

	/**
	 * One donation-log entry per key (event id); the status does not change.
	 *
	 * @param int    $donation_id Donation id.
	 * @param string $key         Key.
	 * @param string $text        Text.
	 */
	private static function note_once( $donation_id, $key, $text ) {
		$seen = json_decode( (string) get_post_meta( $donation_id, '_arionpay_seen', true ), true );
		$seen = is_array( $seen ) ? $seen : array();
		if ( in_array( $key, $seen, true ) ) {
			return;
		}
		$seen[] = $key;
		update_post_meta( $donation_id, '_arionpay_seen', wp_json_encode( array_slice( $seen, -100 ) ) );
		$donation = charitable_get_donation( $donation_id );
		if ( $donation ) {
			$donation->log()->add( $text );
		}
	}

	/**
	 * Random id of this site (made once), so two sites on one ArionPay store never touch each other's donations.
	 *
	 * @return string
	 */
	private static function site_id() {
		$id = (string) get_option( 'arionpay_charitable_site_id', '' );
		if ( ! preg_match( '/^[a-f0-9]{12}$/', $id ) ) {
			$id = bin2hex( random_bytes( 6 ) );
			update_option( 'arionpay_charitable_site_id', $id, false );
		}
		return $id;
	}

	/**
	 * Answer ArionPay and stop.
	 *
	 * @param int   $status HTTP status.
	 * @param array $body   JSON body.
	 */
	private static function respond( $status, array $body ) {
		status_header( (int) $status );
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( $body );
		exit;
	}
}
