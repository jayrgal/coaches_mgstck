<?php
namespace Magstack\Coaches\Payments;

use Magstack\Coaches\Booking\Service;
use Magstack\Coaches\Helpers;
use Magstack\Coaches\Settings;

defined( 'ABSPATH' ) || exit;

/** Manual GCash / QR payment: show instructions, coach/admin marks as paid. */
final class ManualGateway implements GatewayInterface {

	public function id(): string {
		return 'manual';
	}

	public function title(): string {
		return __( 'GCash / QR (manual)', 'magstack-coaches' );
	}

	public function is_available(): bool {
		return true;
	}

	public function initiate( array $booking ): array {
		return array(
			'status'   => 'pending',
			'redirect' => null,
			'html'     => $this->instructions_html( $booking ),
		);
	}

	public function instructions_html( array $booking ): string {
		$text = (string) Settings::get( 'payment_instructions', '' );
		$qr   = (int) Settings::get( 'payment_qr_id', 0 );
		$html = '<div class="mc-pay">';
		$html .= '<p><strong>' . esc_html(
			sprintf(
				/* translators: 1: amount 2: booking reference */
				__( 'Pay the coach fee of %1$s using reference %2$s', 'magstack-coaches' ),
				Helpers::money( $booking['coach_fee'] ),
				$booking['reference']
			)
		) . '</strong></p>';
		if ( $text ) {
			$html .= wpautop( esc_html( $text ) );
		}
		if ( $qr && wp_attachment_is_image( $qr ) ) {
			$html .= '<p>' . wp_get_attachment_image( $qr, 'medium', false, array( 'alt' => __( 'Payment QR code', 'magstack-coaches' ), 'class' => 'mc-pay__qr' ) ) . '</p>';
		}
		if ( (float) $booking['court_fee'] > 0 || ! empty( $booking['court_fee_note'] ) ) {
			$html .= '<p class="mc-muted">' . esc_html__( 'The court fee is separate and paid as noted in your booking summary.', 'magstack-coaches' ) . '</p>';
		}
		return $html . '</div>';
	}

	public function mark_paid( int $booking_id, array $context = array() ): bool {
		return Service::set_payment_status( $booking_id, 'paid', $context );
	}
}
