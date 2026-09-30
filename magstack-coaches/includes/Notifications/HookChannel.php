<?php
namespace Magstack\Coaches\Notifications;

use Magstack\Coaches\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Provider-less channel for SMS / Viber. Phase 1 ships no provider: a provider plugin
 * (or theme code) implements delivery by hooking:
 *
 *   add_filter( 'magstack_channel_sms_send', function ( $sent, $recipient, $text, $event, $context ) { ... return true; }, 10, 5 );
 *   add_filter( 'magstack_channel_viber_send', ... same signature ... );
 *
 * The channel only runs when enabled in settings and at least one provider is hooked.
 */
final class HookChannel implements ChannelInterface {

	public function __construct( private string $id, private string $label ) {}

	public function id(): string {
		return $this->id;
	}

	public function label(): string {
		return $this->label;
	}

	public function is_enabled(): bool {
		return (bool) Settings::get( 'channel_' . $this->id, 0 ) && has_filter( 'magstack_channel_' . $this->id . '_send' );
	}

	public function send( string $event, array $recipient, array $message, array $context = array() ): bool {
		if ( empty( $recipient['mobile'] ) ) {
			return false;
		}
		return (bool) apply_filters( 'magstack_channel_' . $this->id . '_send', false, $recipient, (string) ( $message['text'] ?? '' ), $event, $context );
	}
}
