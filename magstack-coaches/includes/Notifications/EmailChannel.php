<?php
namespace Magstack\Coaches\Notifications;

defined( 'ABSPATH' ) || exit;

final class EmailChannel implements ChannelInterface {

	public function id(): string {
		return 'email';
	}

	public function label(): string {
		return __( 'Email', 'magstack-coaches' );
	}

	public function is_enabled(): bool {
		return true;
	}

	public function send( string $event, array $recipient, array $message, array $context = array() ): bool {
		$to = sanitize_email( (string) ( $recipient['email'] ?? '' ) );
		if ( ! $to || empty( $message['subject'] ) ) {
			return false;
		}
		return (bool) wp_mail(
			$to,
			$message['subject'],
			$message['html'],
			array( 'Content-Type: text/html; charset=UTF-8' ),
			$message['attachments'] ?? array()
		);
	}
}
