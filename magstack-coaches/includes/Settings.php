<?php
namespace Magstack\Coaches;

defined( 'ABSPATH' ) || exit;

/** Central settings store (single option array, merged over defaults). */
final class Settings {

	public const OPTION = 'magstack_coaches_settings';

	private static ?array $cache = null;

	public static function defaults(): array {
		return array(
			// General.
			'currency'             => 'PHP',
			'currency_symbol'      => '₱',
			'brand_color'          => '#0f766e',
			'admin_email'          => '',
			'pages'                => array(
				'coaches'   => 0,
				'booking'   => 0,
				'register'  => 0,
				'dashboard' => 0,
				'map'       => 0,
			),
			// Booking.
			'lead_time_hours'      => 12,
			'max_advance_days'     => 60,
			'cancel_window_hours'  => 24,
			'slot_step'            => 30,
			'ics_attach'           => 1,
			// Monetization.
			'fee_mode'             => 'listing', // listing | commission (commission is stored but not enforced in Phase 1).
			'founding_days'        => 90,
			'hide_unpaid'          => 0,
			'tiers'                => array(
				'free' => array(
					'listing_fee' => 300,
					'commission'  => 15,
				),
				'pro'  => array(
					'listing_fee' => 800,
					'commission'  => 8,
				),
			),
			// Payments.
			'payment_gateway'      => 'manual',
			'payment_instructions' => "Please send your payment via GCash to 09XX XXX XXXX (Account name: Your Name).\nUse your booking reference as the message and send us a screenshot of the receipt.",
			'payment_qr_id'        => 0,
			// SEO.
			'seo_enabled'          => 1,
			'seo_intro_extra'      => '',
			// Spam.
			'recaptcha_site'       => '',
			'recaptcha_secret'     => '',
			'min_submit_seconds'   => 3,
			// Notifications.
			'channel_sms'          => 0,
			'channel_viber'        => 0,
			// Emails (subject/body per template key; empty = built-in default).
			'emails'               => array(),
			// Maintenance.
			'uninstall_cleanup'    => 0,
		);
	}

	public static function all(): array {
		if ( null === self::$cache ) {
			$saved       = get_option( self::OPTION, array() );
			$saved       = is_array( $saved ) ? $saved : array();
			self::$cache = self::merge( self::defaults(), $saved );
		}
		return self::$cache;
	}

	/** @return mixed */
	public static function get( string $key, $default = null ) {
		$all = self::all();
		return $all[ $key ] ?? $default;
	}

	public static function update( array $values ): void {
		$current = get_option( self::OPTION, array() );
		$current = is_array( $current ) ? $current : array();
		update_option( self::OPTION, self::merge( $current, $values ) );
		self::$cache = null;
	}

	public static function flush_cache(): void {
		self::$cache = null;
	}

	public static function page_url( string $key ): string {
		$id = (int) ( self::get( 'pages', array() )[ $key ] ?? 0 );
		if ( $id && 'publish' === get_post_status( $id ) ) {
			return (string) get_permalink( $id );
		}
		return home_url( '/' );
	}

	private static function merge( array $base, array $over ): array {
		foreach ( $over as $k => $v ) {
			if ( is_array( $v ) && isset( $base[ $k ] ) && is_array( $base[ $k ] ) && self::is_assoc( $v ) ) {
				$base[ $k ] = self::merge( $base[ $k ], $v );
			} else {
				$base[ $k ] = $v;
			}
		}
		return $base;
	}

	private static function is_assoc( array $a ): bool {
		return array() !== $a && array_keys( $a ) !== range( 0, count( $a ) - 1 );
	}
}
