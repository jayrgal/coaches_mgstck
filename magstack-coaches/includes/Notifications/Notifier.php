<?php
namespace Magstack\Coaches\Notifications;

use Magstack\Coaches\Booking\Ics;
use Magstack\Coaches\Coach\Coach;
use Magstack\Coaches\Helpers;
use Magstack\Coaches\Settings;

defined( 'ABSPATH' ) || exit;

final class Notifier {

	/** @var ChannelInterface[]|null */
	private static ?array $channels = null;

	public static function hooks(): void {
		add_action( 'magstack_booking_created', array( self::class, 'on_created' ), 10, 1 );
		add_action( 'magstack_booking_status_changed', array( self::class, 'on_status' ), 10, 3 );
		add_action( 'magstack_booking_reminder', array( self::class, 'on_reminder' ), 10, 2 );
	}

	/** @return ChannelInterface[] */
	public static function channels(): array {
		if ( null === self::$channels ) {
			$list = array(
				new EmailChannel(),
				new HookChannel( 'sms', __( 'SMS', 'magstack-coaches' ) ),
				new HookChannel( 'viber', __( 'Viber', 'magstack-coaches' ) ),
			);
			/** Add or replace notification channels. */
			$list = apply_filters( 'magstack_notification_channels', $list );
			self::$channels = array_values( array_filter( $list, static fn( $c ) => $c instanceof ChannelInterface ) );
		}
		return self::$channels;
	}

	// ---- Booking events ---------------------------------------------------------------------

	public static function on_created( array $b ): void {
		self::booking( 'created', $b );
	}

	public static function on_status( array $b, string $old, string $new ): void {
		self::booking( $new, $b );
	}

	public static function on_reminder( array $b, string $window ): void {
		self::booking( 'reminder', $b, array( 'when' => '24' === $window ? __( 'in 24 hours', 'magstack-coaches' ) : __( 'in 2 hours', 'magstack-coaches' ) ) );
	}

	/**
	 * @param string $event created|confirmed|declined|cancelled|completed|reminder
	 */
	public static function booking( string $event, array $b, array $extra = array() ): void {
		$player_tpl = array(
			'created'   => 'player_pending',
			'pending'   => 'player_pending',
			'confirmed' => 'player_confirmed',
			'declined'  => 'player_declined',
			'cancelled' => 'player_cancelled',
			'completed' => 'player_completed',
			'reminder'  => 'player_reminder',
		)[ $event ] ?? '';

		$coach_id = (int) $b['coach_id'];
		$vars     = self::booking_vars( $b, $extra );

		$recipients = array();
		if ( $player_tpl ) {
			$recipients[] = array(
				'tpl'       => $player_tpl,
				'recipient' => array(
					'role'   => 'player',
					'name'   => $b['player_name'],
					'email'  => $b['player_email'],
					'mobile' => $b['player_mobile'],
				),
			);
		}
		$staff = array(
			'role'   => 'coach',
			'name'   => get_the_title( $coach_id ),
			'email'  => (string) Coach::meta( $coach_id, 'email', '' ) ?: (string) ( get_userdata( Coach::user_id( $coach_id ) )->user_email ?? '' ),
			'mobile' => (string) Coach::meta( $coach_id, 'mobile', '' ),
		);
		$recipients[] = array( 'tpl' => 'staff_update', 'recipient' => $staff );
		if ( 'reminder' !== $event ) {
			$recipients[] = array(
				'tpl'       => 'staff_update',
				'recipient' => array(
					'role'   => 'admin',
					'name'   => __( 'Admin', 'magstack-coaches' ),
					'email'  => self::admin_email(),
					'mobile' => '',
				),
			);
		}

		foreach ( $recipients as $r ) {
			$v = $vars;
			$v['recipient_name'] = esc_html( $r['recipient']['name'] );
			if ( 'staff_update' === $r['tpl'] && 'reminder' === $event ) {
				$v['status_label'] = esc_html( sprintf( /* translators: %s: when */ __( 'Reminder – starts %s', 'magstack-coaches' ), $extra['when'] ?? '' ) );
			}
			if ( 'admin' === $r['recipient']['role'] ) {
				$v['dashboard_url'] = esc_url( admin_url( 'admin.php?page=magstack-bookings&booking=' . (int) $b['id'] ) );
			}
			$attachments = array();
			$tmp         = '';
			if ( 'confirmed' === $event && Settings::get( 'ics_attach', 1 ) && in_array( $r['recipient']['role'], array( 'player', 'coach' ), true ) ) {
				$tmp = Ics::temp_file( $b );
				if ( $tmp && file_exists( $tmp ) ) {
					$attachments[] = $tmp;
				}
			}
			$message = array(
				'subject'     => Templates::subject( $r['tpl'], $v ),
				'html'        => Templates::body_html( $r['tpl'], $v ),
				'text'        => self::sms_text( $b, $event, $extra ),
				'attachments' => $attachments,
			);
			self::dispatch( 'booking_' . $event, $r['recipient'], $message, array( 'booking' => $b, 'coach_id' => $coach_id ) );
			if ( $tmp && file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
		}
	}

	private static function sms_text( array $b, string $event, array $extra ): string {
		$text = sprintf(
			'%s: %s %s – %s %s %s (%s)',
			get_bloginfo( 'name' ),
			Helpers::status_labels()[ 'created' === $event ? 'pending' : $event ] ?? ucfirst( $event ),
			$extra['when'] ?? '',
			get_the_title( (int) $b['coach_id'] ),
			Helpers::fmt_date( $b['booking_date'] ),
			Helpers::fmt_time( $b['start_time'] ),
			$b['reference']
		);
		return (string) apply_filters( 'magstack_notification_text', trim( $text ), $event, $b );
	}

	/** Build the safe-HTML placeholder map for a booking. */
	public static function booking_vars( array $b, array $extra = array() ): array {
		$coach_id  = (int) $b['coach_id'];
		$gateway   = \Magstack\Coaches\Payments\Registry::get( (string) $b['gateway'] ) ?? \Magstack\Coaches\Payments\Registry::active();
		$show_pay  = in_array( $b['status'], array( 'pending', 'confirmed' ), true ) && 'paid' !== $b['payment_status'] && (float) $b['coach_fee'] > 0;
		$contact   = '';
		if ( in_array( $b['status'], array( 'confirmed', 'completed' ), true ) ) {
			$contact = self::coach_contact_html( $coach_id );
		}
		$manage = add_query_arg(
			array(
				'mc_ref' => $b['reference'],
				'mc_t'   => $b['token'],
			),
			Settings::page_url( 'booking' )
		);
		$status_labels = Helpers::status_labels();
		return array_merge(
			array(
				'site_name'            => esc_html( get_bloginfo( 'name' ) ),
				'player_name'          => esc_html( $b['player_name'] ),
				'player_email'         => esc_html( $b['player_email'] ),
				'player_mobile'        => esc_html( $b['player_mobile'] ),
				'player_level'         => esc_html( $b['player_level'] ?: '—' ),
				'notes'                => esc_html( (string) $b['notes'] ?: '—' ),
				'coach_name'           => esc_html( get_the_title( $coach_id ) ),
				'reference'            => esc_html( $b['reference'] ),
				'date'                 => esc_html( Helpers::fmt_date( $b['booking_date'] ) ),
				'time'                 => esc_html( Helpers::fmt_time( $b['start_time'] ) . ' – ' . Helpers::fmt_time( $b['end_time'] ) ),
				'status'               => esc_html( $b['status'] ),
				'status_label'         => esc_html( $status_labels[ $b['status'] ] ?? $b['status'] ),
				'court'                => esc_html( $b['venue_label'] ),
				'coach_fee'            => esc_html( Helpers::money( $b['coach_fee'] ) ),
				'court_fee'            => esc_html( Helpers::money( $b['court_fee'] ) ),
				'total'                => esc_html( Helpers::money( $b['total'] ) ),
				'booking_details'      => self::details_html( $b ),
				'payment_instructions' => $show_pay && $gateway ? $gateway->instructions_html( $b ) : '',
				'coach_contact'        => $contact,
				'manage_url'           => esc_url( $manage ),
				'directory_url'        => esc_url( Settings::page_url( 'coaches' ) ),
				'dashboard_url'        => esc_url( Settings::page_url( 'dashboard' ) ),
				'when'                 => esc_html( $extra['when'] ?? '' ),
			),
			array()
		);
	}

	public static function coach_contact_html( int $coach_id ): string {
		$email  = (string) Coach::meta( $coach_id, 'email', '' );
		$mobile = (string) Coach::meta( $coach_id, 'mobile', '' );
		if ( ! $email && ! $mobile ) {
			return '';
		}
		$out = '<strong>' . esc_html__( 'Coach contact', 'magstack-coaches' ) . ':</strong> ' . esc_html( get_the_title( $coach_id ) );
		if ( $mobile ) {
			$out .= ' · ' . esc_html( $mobile );
		}
		if ( $email ) {
			$out .= ' · ' . esc_html( $email );
		}
		return $out;
	}

	/** Coach fee and court fee are ALWAYS separate line items. */
	public static function details_html( array $b ): string {
		$row = static fn( $l, $v, $bold = false ) => '<tr><td style="padding:6px 0;color:#6b7280">' . esc_html( $l ) . '</td><td style="padding:6px 0;text-align:right' . ( $bold ? ';font-weight:700' : '' ) . '">' . $v . '</td></tr>';
		$court_val = esc_html( Helpers::money( $b['court_fee'] ) );
		if ( (float) $b['court_fee'] <= 0 ) {
			$court_val = esc_html( $b['court_fee_note'] ?: __( 'Pay at venue / as advised', 'magstack-coaches' ) );
		} elseif ( $b['court_fee_note'] ) {
			$court_val .= ' <small style="color:#6b7280">(' . esc_html( $b['court_fee_note'] ) . ')</small>';
		}
		$coach_label = $b['purchase_id'] && (float) $b['coach_fee'] <= 0
			? __( 'Coach fee (prepaid package)', 'magstack-coaches' )
			: ( $b['package_id'] ? __( 'Coach fee (package)', 'magstack-coaches' ) : __( 'Coach fee', 'magstack-coaches' ) );
		$html  = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-top:1px solid #e5e7eb;border-bottom:1px solid #e5e7eb;margin:8px 0">';
		$html .= $row( __( 'Coach', 'magstack-coaches' ), esc_html( get_the_title( (int) $b['coach_id'] ) ) );
		$html .= $row( __( 'Date', 'magstack-coaches' ), esc_html( Helpers::fmt_date( $b['booking_date'] ) ) );
		$html .= $row( __( 'Time', 'magstack-coaches' ), esc_html( Helpers::fmt_time( $b['start_time'] ) . ' – ' . Helpers::fmt_time( $b['end_time'] ) ) );
		$html .= $row( __( 'Court', 'magstack-coaches' ), esc_html( $b['venue_label'] ) );
		$html .= $row( $coach_label, esc_html( Helpers::money( $b['coach_fee'] ) ) );
		$html .= $row( __( 'Court fee', 'magstack-coaches' ), $court_val );
		$html .= $row( __( 'Total', 'magstack-coaches' ), esc_html( Helpers::money( $b['total'] ) ), true );
		$html .= $row( __( 'Reference', 'magstack-coaches' ), esc_html( $b['reference'] ) );
		return $html . '</table>';
	}

	public static function admin_email(): string {
		return sanitize_email( (string) Settings::get( 'admin_email', '' ) ) ?: (string) get_option( 'admin_email' );
	}

	// ---- Coach lifecycle events -------------------------------------------------------------

	public static function coach_event( string $event, int $coach_id ): void {
		$name  = get_the_title( $coach_id );
		$email = (string) Coach::meta( $coach_id, 'email', '' );
		$vars  = array(
			'site_name'     => esc_html( get_bloginfo( 'name' ) ),
			'coach_name'    => esc_html( $name ),
			'coach_city'    => esc_html( (string) Coach::meta( $coach_id, 'city', '' ) ),
			'profile_url'   => esc_url( get_permalink( $coach_id ) ),
			'dashboard_url' => esc_url( Settings::page_url( 'dashboard' ) ),
			'booking_link'  => esc_url( Coach::booking_link( $coach_id ) ),
			'admin_url'     => esc_url( admin_url( 'post.php?post=' . $coach_id . '&action=edit' ) ),
			'password_url'  => '',
			'founding_note' => '',
		);
		if ( 'coach_approved' === $event ) {
			$user = get_userdata( Coach::user_id( $coach_id ) );
			if ( $user ) {
				$key = get_password_reset_key( $user );
				if ( ! is_wp_error( $key ) ) {
					$vars['password_url'] = esc_url( network_site_url( 'wp-login.php?action=rp&key=' . $key . '&login=' . rawurlencode( $user->user_login ), 'login' ) );
				}
			}
			$end = Coach::founding_end( $coach_id );
			if ( $end && 'founding' === Coach::fee_status( $coach_id ) ) {
				$vars['founding_note'] = esc_html(
					sprintf( /* translators: %s: date */ __( 'As a Founding Coach your listing is free until %s.', 'magstack-coaches' ), Helpers::fmt_date( $end ) )
				);
			}
		}
		if ( 'coach_registered' === $event ) {
			$vars['recipient_name'] = esc_html__( 'Admin', 'magstack-coaches' );
			$to = array(
				'role'  => 'admin',
				'name'  => 'Admin',
				'email' => self::admin_email(),
			);
		} else {
			$to = array(
				'role'   => 'coach',
				'name'   => $name,
				'email'  => $email,
				'mobile' => (string) Coach::meta( $coach_id, 'mobile', '' ),
			);
		}
		$message = array(
			'subject'     => Templates::subject( $event, $vars ),
			'html'        => Templates::body_html( $event, $vars ),
			'text'        => wp_strip_all_tags( Templates::subject( $event, $vars ) ),
			'attachments' => array(),
		);
		self::dispatch( $event, $to, $message, array( 'coach_id' => $coach_id ) );
	}

	/** Send via every enabled channel. */
	public static function dispatch( string $event, array $recipient, array $message, array $context = array() ): void {
		/**
		 * Fires before delivery; return false from `magstack_notification_send` to suppress.
		 */
		if ( ! apply_filters( 'magstack_notification_send', true, $event, $recipient, $message, $context ) ) {
			return;
		}
		foreach ( self::channels() as $channel ) {
			if ( $channel->is_enabled() ) {
				$channel->send( $event, $recipient, $message, $context );
			}
		}
		do_action( 'magstack_notification_sent', $event, $recipient, $message, $context );
	}
}
