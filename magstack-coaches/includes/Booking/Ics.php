<?php
namespace Magstack\Coaches\Booking;

use Magstack\Coaches\Helpers;

defined( 'ABSPATH' ) || exit;

final class Ics {

	public static function build( array $b ): string {
		$start = Helpers::dt( $b['booking_date'], $b['start_time'] )->setTimezone( new \DateTimeZone( 'UTC' ) );
		$end   = Helpers::dt( $b['booking_date'], $b['end_time'] )->setTimezone( new \DateTimeZone( 'UTC' ) );
		$coach = get_the_title( (int) $b['coach_id'] );
		$lines = array(
			'BEGIN:VCALENDAR',
			'VERSION:2.0',
			'PRODID:-//Magstack Coaches//EN',
			'CALSCALE:GREGORIAN',
			'METHOD:PUBLISH',
			'BEGIN:VEVENT',
			'UID:' . $b['reference'] . '@' . wp_parse_url( home_url(), PHP_URL_HOST ),
			'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ),
			'DTSTART:' . $start->format( 'Ymd\THis\Z' ),
			'DTEND:' . $end->format( 'Ymd\THis\Z' ),
			'SUMMARY:' . self::esc( sprintf( /* translators: %s: coach */ __( 'Pickleball session with %s', 'magstack-coaches' ), $coach ) ),
			'DESCRIPTION:' . self::esc( sprintf( /* translators: %s: reference */ __( 'Booking %s', 'magstack-coaches' ), $b['reference'] ) ),
			'LOCATION:' . self::esc( (string) $b['venue_label'] ),
			'STATUS:CONFIRMED',
			'END:VEVENT',
			'END:VCALENDAR',
		);
		return implode( "\r\n", $lines ) . "\r\n";
	}

	private static function esc( string $s ): string {
		return str_replace( array( '\\', ';', ',', "\r\n", "\n" ), array( '\\\\', '\;', '\,', '\n', '\n' ), $s );
	}

	/** Write a temp .ics file; caller must unlink. */
	public static function temp_file( array $b ): string {
		$dir  = get_temp_dir();
		$file = trailingslashit( $dir ) . 'session-' . sanitize_file_name( $b['reference'] ) . '.ics';
		file_put_contents( $file, self::build( $b ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $file;
	}
}
