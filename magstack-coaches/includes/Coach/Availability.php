<?php
namespace Magstack\Coaches\Coach;

use Magstack\Coaches\Booking\Repository;
use Magstack\Coaches\Helpers;
use Magstack\Coaches\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Availability model. Stored in post meta _mc_availability:
 * { weekly: { mon: [["09:00","12:00"],...], ... }, overrides: { "2026-10-01": { closed: true } | { ranges: [["10:00","14:00"]] } } }
 */
final class Availability {

	public static function get( int $coach_id ): array {
		$a = get_post_meta( $coach_id, '_mc_availability', true );
		$a = is_array( $a ) ? $a : array();
		return array(
			'weekly'    => is_array( $a['weekly'] ?? null ) ? $a['weekly'] : array(),
			'overrides' => is_array( $a['overrides'] ?? null ) ? $a['overrides'] : array(),
		);
	}

	/** Sanitize untrusted availability input into the stored shape. */
	public static function sanitize( $raw ): array {
		$raw = is_array( $raw ) ? $raw : array();
		$out = array(
			'weekly'    => array(),
			'overrides' => array(),
		);
		foreach ( Helpers::DAYS as $day ) {
			$out['weekly'][ $day ] = self::clean_ranges( $raw['weekly'][ $day ] ?? array() );
		}
		$today = Helpers::now()->format( 'Y-m-d' );
		$ov    = is_array( $raw['overrides'] ?? null ) ? $raw['overrides'] : array();
		foreach ( $ov as $date => $o ) {
			$date = sanitize_text_field( (string) $date );
			if ( ! Helpers::valid_date( $date ) || $date < $today || ! is_array( $o ) ) {
				continue;
			}
			if ( ! empty( $o['closed'] ) ) {
				$out['overrides'][ $date ] = array( 'closed' => true );
			} else {
				$out['overrides'][ $date ] = array( 'ranges' => self::clean_ranges( $o['ranges'] ?? array() ) );
			}
		}
		ksort( $out['overrides'] );
		return $out;
	}

	private static function clean_ranges( $ranges ): array {
		$clean = array();
		if ( ! is_array( $ranges ) ) {
			return $clean;
		}
		foreach ( $ranges as $r ) {
			$s = is_array( $r ) ? (string) ( $r[0] ?? '' ) : '';
			$e = is_array( $r ) ? (string) ( $r[1] ?? '' ) : '';
			if ( Helpers::valid_time( $s ) && Helpers::valid_time( $e ) && Helpers::to_minutes( $e ) > Helpers::to_minutes( $s ) ) {
				$clean[] = array( $s, $e );
			}
		}
		usort( $clean, static fn( $a, $b ) => strcmp( $a[0], $b[0] ) );
		return $clean;
	}

	public static function ranges_for( array $avail, string $date ): array {
		if ( isset( $avail['overrides'][ $date ] ) ) {
			$o = $avail['overrides'][ $date ];
			return ! empty( $o['closed'] ) ? array() : (array) ( $o['ranges'] ?? array() );
		}
		$dow = strtolower( Helpers::dt( $date )->format( 'D' ) );
		return (array) ( $avail['weekly'][ $dow ] ?? array() );
	}

	/**
	 * Free slots for one date.
	 *
	 * @param array|null $booked Pre-loaded booked intervals [[startMin,endMin],...] for that date (null = query).
	 * @return array<int,array{start:string,end:string,label:string}>
	 */
	public static function slots( int $coach_id, string $date, int $duration, ?array $booked = null, ?array $avail = null ): array {
		if ( ! Helpers::valid_date( $date ) || $duration < 15 ) {
			return array();
		}
		$now      = Helpers::now();
		$earliest = $now->modify( '+' . (int) Settings::get( 'lead_time_hours', 12 ) . ' hours' );
		$latest   = $now->modify( '+' . (int) Settings::get( 'max_advance_days', 60 ) . ' days' )->setTime( 23, 59 );
		$day_end  = Helpers::dt( $date, '23:59' );
		if ( $day_end < $earliest || Helpers::dt( $date, '00:00' ) > $latest ) {
			return array();
		}

		$avail  = $avail ?? self::get( $coach_id );
		$ranges = self::ranges_for( $avail, $date );
		if ( ! $ranges ) {
			return array();
		}
		if ( null === $booked ) {
			$booked = Repository::booked_intervals( $coach_id, $date, $date )[ $date ] ?? array();
		}
		$step  = max( 15, (int) Settings::get( 'slot_step', 30 ) );
		$slots = array();
		foreach ( $ranges as $r ) {
			$rs = Helpers::to_minutes( $r[0] );
			$re = Helpers::to_minutes( $r[1] );
			for ( $s = $rs; $s + $duration <= $re; $s += $step ) {
				$e = $s + $duration;
				if ( Helpers::dt( $date, Helpers::from_minutes( $s ) ) < $earliest ) {
					continue;
				}
				foreach ( $booked as $b ) {
					if ( $s < $b[1] && $e > $b[0] ) {
						continue 2;
					}
				}
				$start   = Helpers::from_minutes( $s );
				$slots[] = array(
					'start' => $start,
					'end'   => Helpers::from_minutes( $e ),
					'label' => Helpers::fmt_time( $start ),
				);
			}
		}
		return $slots;
	}

	/** Dates (Y-m-d => free slot count) with availability in a window. One booking query for the window. */
	public static function dates( int $coach_id, int $duration, string $from, int $days = 30 ): array {
		if ( ! Helpers::valid_date( $from ) ) {
			return array();
		}
		$days   = max( 1, min( 90, $days ) );
		$to     = Helpers::dt( $from )->modify( '+' . ( $days - 1 ) . ' days' )->format( 'Y-m-d' );
		$booked = Repository::booked_intervals( $coach_id, $from, $to );
		$avail  = self::get( $coach_id );
		$out    = array();
		for ( $i = 0; $i < $days; $i++ ) {
			$d     = Helpers::dt( $from )->modify( '+' . $i . ' days' )->format( 'Y-m-d' );
			$slots = self::slots( $coach_id, $d, $duration, $booked[ $d ] ?? array(), $avail );
			if ( $slots ) {
				$out[ $d ] = count( $slots );
			}
		}
		return $out;
	}
}
