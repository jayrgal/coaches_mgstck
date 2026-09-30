<?php
namespace Magstack\Coaches\Notifications;

use Magstack\Coaches\Settings;

defined( 'ABSPATH' ) || exit;

/** Editable email templates with {placeholders}. */
final class Templates {

	/** key => [label, default subject, default body] */
	public static function definitions(): array {
		return array(
			'player_pending'   => array(
				__( 'Player: booking received (Pending)', 'magstack-coaches' ),
				__( 'We received your booking {reference}', 'magstack-coaches' ),
				"Hi {player_name},\n\nThanks for booking {coach_name}! Your request is now <strong>pending</strong> until the coach accepts it.\n\n{booking_details}\n\n{payment_instructions}\n\nManage your booking: {manage_url}",
			),
			'player_confirmed' => array(
				__( 'Player: booking Confirmed', 'magstack-coaches' ),
				__( 'Confirmed: {coach_name} on {date} at {time}', 'magstack-coaches' ),
				"Hi {player_name},\n\nGreat news, your session is <strong>confirmed</strong>!\n\n{booking_details}\n\n{coach_contact}\n\n{payment_instructions}\n\nManage your booking: {manage_url}",
			),
			'player_declined'  => array(
				__( 'Player: booking Declined', 'magstack-coaches' ),
				__( 'Your booking {reference} was declined', 'magstack-coaches' ),
				"Hi {player_name},\n\nUnfortunately {coach_name} can't take this session.\n\n{booking_details}\n\nPlease pick another time or coach: {directory_url}",
			),
			'player_cancelled' => array(
				__( 'Player: booking Cancelled', 'magstack-coaches' ),
				__( 'Your booking {reference} was cancelled', 'magstack-coaches' ),
				"Hi {player_name},\n\nYour booking has been <strong>cancelled</strong>.\n\n{booking_details}\n\nBook again anytime: {directory_url}",
			),
			'player_completed' => array(
				__( 'Player: session Completed', 'magstack-coaches' ),
				__( 'Thanks for training with {coach_name}', 'magstack-coaches' ),
				"Hi {player_name},\n\nYour session is marked <strong>completed</strong>. We hope you had a great time!\n\n{booking_details}\n\nBook your next session: {directory_url}",
			),
			'player_reminder'  => array(
				__( 'Player: session reminder (24h / 2h)', 'magstack-coaches' ),
				__( 'Reminder: session with {coach_name} {when}', 'magstack-coaches' ),
				"Hi {player_name},\n\nFriendly reminder: your session starts {when}.\n\n{booking_details}\n\n{coach_contact}",
			),
			'staff_update'     => array(
				__( 'Coach & Admin: booking update', 'magstack-coaches' ),
				__( '[{site_name}] Booking {reference}: {status_label}', 'magstack-coaches' ),
				"Hi {recipient_name},\n\nBooking <strong>{reference}</strong> is now <strong>{status_label}</strong>.\n\n{booking_details}\n\nPlayer: {player_name} · {player_email} · {player_mobile}\nSkill level: {player_level}\nNotes: {notes}\n\nManage bookings: {dashboard_url}",
			),
			'coach_registered' => array(
				__( 'Admin: new coach application', 'magstack-coaches' ),
				__( '[{site_name}] New coach application: {coach_name}', 'magstack-coaches' ),
				"A new coach has applied: <strong>{coach_name}</strong> ({coach_city}).\n\nReview it: {admin_url}",
			),
			'coach_approved'   => array(
				__( 'Coach: application approved', 'magstack-coaches' ),
				__( 'Welcome to {site_name}, {coach_name}!', 'magstack-coaches' ),
				"Hi {coach_name},\n\nYour coach application has been <strong>approved</strong>. Your profile is live: {profile_url}\n\nSet your password and log in to your dashboard to add your availability and packages:\n{password_url}\n\nDashboard: {dashboard_url}\nYour shareable booking link: {booking_link}\n\n{founding_note}",
			),
			'coach_rejected'   => array(
				__( 'Coach: application rejected', 'magstack-coaches' ),
				__( 'Your coach application at {site_name}', 'magstack-coaches' ),
				"Hi {coach_name},\n\nThank you for applying. Unfortunately we can't approve your application right now.\n\nYou're welcome to contact us if you have questions.",
			),
		);
	}

	public static function get( string $key ): array {
		$defs = self::definitions();
		$def  = $defs[ $key ] ?? array( $key, '', '' );
		$saved = Settings::get( 'emails', array() );
		$subject = trim( (string) ( $saved[ $key ]['subject'] ?? '' ) ) ?: $def[1];
		$body    = trim( (string) ( $saved[ $key ]['body'] ?? '' ) ) ?: $def[2];
		return array(
			'subject' => $subject,
			'body'    => $body,
		);
	}

	/**
	 * Replace {placeholders}. Values in $vars are expected to be already-safe HTML.
	 */
	public static function replace( string $tpl, array $vars ): string {
		$search = array();
		$repl   = array();
		foreach ( $vars as $k => $v ) {
			$search[] = '{' . $k . '}';
			$repl[]   = (string) $v;
		}
		return str_replace( $search, $repl, $tpl );
	}

	public static function subject( string $key, array $vars ): string {
		$s = self::replace( self::get( $key )['subject'], $vars );
		return trim( html_entity_decode( wp_strip_all_tags( $s ), ENT_QUOTES, 'UTF-8' ) );
	}

	public static function body_html( string $key, array $vars ): string {
		$body = self::replace( wp_kses_post( self::get( $key )['body'] ), $vars );
		return self::layout( wpautop( $body ) );
	}

	public static function layout( string $inner ): string {
		$brand = sanitize_hex_color( (string) Settings::get( 'brand_color', '#0f766e' ) ) ?: '#0f766e';
		$site  = esc_html( get_bloginfo( 'name' ) );
		return '<!doctype html><html><body style="margin:0;background:#f4f5f7;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif;color:#1f2937">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px">'
			. '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:600px;width:100%;background:#fff;border-radius:10px;overflow:hidden">'
			. '<tr><td style="background:' . esc_attr( $brand ) . ';color:#fff;padding:18px 24px;font-size:18px;font-weight:600">' . $site . '</td></tr>'
			. '<tr><td style="padding:24px;font-size:15px;line-height:1.55">' . $inner . '</td></tr>'
			. '<tr><td style="padding:14px 24px;background:#f9fafb;color:#6b7280;font-size:12px">' . $site . ' · ' . esc_html( home_url() ) . '</td></tr>'
			. '</table></td></tr></table></body></html>';
	}
}
