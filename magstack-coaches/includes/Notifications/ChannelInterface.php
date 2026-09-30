<?php
namespace Magstack\Coaches\Notifications;

defined( 'ABSPATH' ) || exit;

/** A delivery channel (email, SMS, Viber, ...). Register more with the `magstack_notification_channels` filter. */
interface ChannelInterface {

	public function id(): string;

	public function label(): string;

	public function is_enabled(): bool;

	/**
	 * @param string $event     e.g. booking_confirmed
	 * @param array  $recipient {role: player|coach|admin, name, email, mobile}
	 * @param array  $message   {subject, html, text, attachments}
	 * @param array  $context   {booking?, coach_id?}
	 */
	public function send( string $event, array $recipient, array $message, array $context = array() ): bool;
}
