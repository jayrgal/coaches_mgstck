<?php
namespace Magstack\Coaches\Booking;

use Magstack\Coaches\Database;
use Magstack\Coaches\Helpers;

defined( 'ABSPATH' ) || exit;

/** 24h and 2h reminders via WP-Cron (every 15 minutes). */
final class Reminders {

	public const HOOK = 'magstack_coaches_reminders';

	public static function hooks(): void {
		add_filter( 'cron_schedules', array( self::class, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval
		add_action( self::HOOK, array( self::class, 'run' ) );
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			self::schedule();
		}
	}

	public static function schedules( array $s ): array {
		$s['magstack_15min'] = array(
			'interval' => 15 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 15 minutes', 'magstack-coaches' ),
		);
		return $s;
	}

	public static function schedule(): void {
		add_filter( 'cron_schedules', array( self::class, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + 60, 'magstack_15min', self::HOOK );
		}
	}

	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	public static function run(): void {
		global $wpdb;
		$t   = Database::bookings();
		$now = Helpers::now();
		$fmt = 'Y-m-d H:i:s';

		// 2h reminder: session starts within the next 2 hours.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$t} WHERE status = 'confirmed' AND reminded_2 = 0 AND TIMESTAMP(booking_date, start_time) > %s AND TIMESTAMP(booking_date, start_time) <= %s LIMIT 100", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$now->format( $fmt ),
				$now->modify( '+2 hours' )->format( $fmt )
			),
			ARRAY_A
		);
		foreach ( $rows as $b ) {
			// Mark first so a mail failure never causes a reminder storm.
			Repository::update( (int) $b['id'], array( 'reminded_2' => 1, 'reminded_24' => 1 ) );
			do_action( 'magstack_booking_reminder', $b, '2' );
		}

		// 24h reminder: starts between 3h and 24h from now.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$t} WHERE status = 'confirmed' AND reminded_24 = 0 AND TIMESTAMP(booking_date, start_time) > %s AND TIMESTAMP(booking_date, start_time) <= %s LIMIT 100", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$now->modify( '+3 hours' )->format( $fmt ),
				$now->modify( '+24 hours' )->format( $fmt )
			),
			ARRAY_A
		);
		foreach ( $rows as $b ) {
			Repository::update( (int) $b['id'], array( 'reminded_24' => 1 ) );
			do_action( 'magstack_booking_reminder', $b, '24' );
		}
	}
}
