<?php
namespace Magstack\Coaches\Payments;

defined( 'ABSPATH' ) || exit;

/**
 * Payment gateway contract. Phase 1 ships ManualGateway (GCash/QR instructions).
 * Later gateways (PayMongo, WooCommerce) implement this and register via the
 * `magstack_payment_gateways` filter — no core refactor needed.
 */
interface GatewayInterface {

	public function id(): string;

	public function title(): string;

	public function is_available(): bool;

	/**
	 * Start payment for a freshly created booking.
	 *
	 * @param array $booking Booking row (assoc array).
	 * @return array{status:string,redirect:?string,html:string} status: pending|paid|failed
	 */
	public function initiate( array $booking ): array;

	/** HTML shown to the player (confirmation screen and emails) while unpaid. */
	public function instructions_html( array $booking ): string;

	/** Mark a booking paid (manual gateways) or reconcile a webhook (online gateways). */
	public function mark_paid( int $booking_id, array $context = array() ): bool;
}
