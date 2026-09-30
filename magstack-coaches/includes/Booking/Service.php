<?php
namespace Magstack\Coaches\Booking;

use Magstack\Coaches\Coach\Availability;
use Magstack\Coaches\Coach\Coach;
use Magstack\Coaches\Database;
use Magstack\Coaches\Facility\Facility;
use Magstack\Coaches\Helpers;
use Magstack\Coaches\Payments\Registry;
use Magstack\Coaches\Settings;

defined( 'ABSPATH' ) || exit;

/** Booking business logic: creation (atomic), status transitions, payments. */
final class Service {

	public const TRANSITIONS = array(
		'pending'   => array( 'confirmed', 'declined', 'cancelled' ),
		'confirmed' => array( 'completed', 'cancelled' ),
		'completed' => array(),
		'declined'  => array(),
		'cancelled' => array(),
	);

	public static function hooks(): void {
		// Reserved for gateway webhooks in later phases.
	}

	public static function slot_key( int $coach_id, string $date, string $start ): string {
		return $coach_id . ':' . $date . ':' . str_replace( ':', '', substr( $start, 0, 5 ) );
	}

	/**
	 * Create a booking atomically.
	 *
	 * @return array|\WP_Error The booking row plus `payment_html`.
	 */
	public static function create( array $in ) {
		global $wpdb;

		$coach_id = (int) ( $in['coach_id'] ?? 0 );
		if ( ! Coach::is_listable( $coach_id ) ) {
			return new \WP_Error( 'mc_coach', __( 'This coach is not available for booking.', 'magstack-coaches' ), array( 'status' => 404 ) );
		}

		// --- Player details -------------------------------------------------------------
		$name   = mb_substr( sanitize_text_field( (string) ( $in['name'] ?? '' ) ), 0, 190 );
		$email  = sanitize_email( (string) ( $in['email'] ?? '' ) );
		$mobile = mb_substr( Helpers::clean_phone( (string) ( $in['mobile'] ?? '' ) ), 0, 40 );
		$level  = sanitize_key( (string) ( $in['level'] ?? '' ) );
		$notes  = mb_substr( sanitize_textarea_field( (string) ( $in['notes'] ?? '' ) ), 0, 1000 );
		if ( '' === $name || ! is_email( $email ) || strlen( preg_replace( '/\D/', '', $mobile ) ) < 7 ) {
			return new \WP_Error( 'mc_player', __( 'Please enter your name, a valid email and a valid mobile number.', 'magstack-coaches' ), array( 'status' => 400 ) );
		}
		if ( $level && ! in_array( $level, array( 'beginner', 'intermediate', 'advanced' ), true ) ) {
			$level = '';
		}

		// --- Date / time / duration -----------------------------------------------------
		$date     = (string) ( $in['date'] ?? '' );
		$start    = (string) ( $in['time'] ?? '' );
		$duration = (int) ( $in['duration'] ?? 0 );
		if ( ! Helpers::valid_date( $date ) || ! Helpers::valid_time( $start ) ) {
			return new \WP_Error( 'mc_time', __( 'Please choose a valid date and time.', 'magstack-coaches' ), array( 'status' => 400 ) );
		}
		if ( ! in_array( $duration, Coach::durations( $coach_id ), true ) ) {
			$duration = Coach::durations( $coach_id )[0];
		}
		$end = Helpers::from_minutes( Helpers::to_minutes( $start ) + $duration );

		// --- Court mode -----------------------------------------------------------------
		$court = self::resolve_court( $coach_id, $in );
		if ( is_wp_error( $court ) ) {
			return $court;
		}

		// --- Pricing / package ----------------------------------------------------------
		$coach_fee  = Coach::price_for( $coach_id, $duration );
		$package    = null;
		$purchase   = null;
		$package_id = (int) ( $in['package_id'] ?? 0 );
		$code       = strtoupper( trim( (string) ( $in['package_code'] ?? '' ) ) );
		if ( $code ) {
			$purchase = Repository::purchase_by_code( $code );
			if (
				! $purchase || (int) $purchase['coach_id'] !== $coach_id
				|| 0 !== strcasecmp( $purchase['player_email'], $email )
				|| $purchase['sessions_remaining'] < 1
				|| ( $purchase['expires_on'] && $purchase['expires_on'] < Helpers::now()->format( 'Y-m-d' ) )
			) {
				return new \WP_Error( 'mc_package_code', __( 'That package code is invalid, expired, or has no sessions left for this coach and email.', 'magstack-coaches' ), array( 'status' => 400 ) );
			}
			$coach_fee  = 0.0; // Prepaid.
			$package_id = (int) $purchase['package_id'];
		} elseif ( $package_id ) {
			$package = Repository::package( $package_id );
			if ( ! $package || (int) $package['coach_id'] !== $coach_id || ! (int) $package['active'] ) {
				return new \WP_Error( 'mc_package', __( 'That package is not available.', 'magstack-coaches' ), array( 'status' => 400 ) );
			}
			$coach_fee = (float) $package['price']; // Whole package is paid up front; this is the first session.
		}

		$total = round( $coach_fee + (float) $court['fee'], 2 );

		$tiers = Settings::get( 'tiers', array() );
		$now   = current_time( 'mysql' );
		$row   = array(
			'reference'         => '',
			'token'             => wp_generate_password( 20, false ),
			'coach_id'          => $coach_id,
			'user_id'           => (int) get_current_user_id(),
			'player_name'       => $name,
			'player_email'      => $email,
			'player_mobile'     => $mobile,
			'player_level'      => $level,
			'notes'             => $notes,
			'booking_date'      => $date,
			'start_time'        => $start . ':00',
			'end_time'          => $end . ':00',
			'duration'          => $duration,
			'court_mode'        => $court['mode'],
			'facility_id'       => $court['facility_id'],
			'venue_label'       => $court['label'],
			'coach_fee'         => $coach_fee,
			'court_fee'         => $court['fee'],
			'court_fee_note'    => $court['note'],
			'total'             => $total,
			'currency'          => (string) Settings::get( 'currency', 'PHP' ),
			'commission_rate'   => (float) ( $tiers[ Coach::tier( $coach_id ) ]['commission'] ?? 0 ), // Stored for Phase 2; not enforced.
			'commission_amount' => 0,
			'package_id'        => $package_id,
			'purchase_id'       => $purchase ? (int) $purchase['id'] : 0,
			'status'            => 'pending',
			'payment_status'    => $coach_fee > 0 ? 'unpaid' : 'paid',
			'gateway'           => Registry::active()->id(),
			'slot_key'          => self::slot_key( $coach_id, $date, $start ),
			'created_at'        => $now,
			'updated_at'        => $now,
		);

		// --- Atomic insert: named lock + re-check + UNIQUE(slot_key) backstop -----------
		$lock = 'mc_coach_' . $coach_id;
		$got  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 5)', $lock ) );
		// '1' = locked, '0' = timed out (busy), null = named locks unsupported: fall back to the UNIQUE(slot_key) guard.
		if ( null !== $got && '1' !== (string) $got ) {
			return new \WP_Error( 'mc_busy', __( 'The system is busy. Please try again in a moment.', 'magstack-coaches' ), array( 'status' => 503 ) );
		}

		$booking_id = 0;
		$error      = null;
		try {
			$wpdb->query( 'START TRANSACTION' );

			$free    = Availability::slots( $coach_id, $date, $duration );
			$starts  = wp_list_pluck( $free, 'start' );
			if ( ! in_array( $start, $starts, true ) ) {
				$error = new \WP_Error( 'mc_taken', __( 'Sorry, that time slot was just taken. Please pick another.', 'magstack-coaches' ), array( 'status' => 409 ) );
			}

			if ( ! $error && $purchase ) {
				$fresh = Repository::purchase( (int) $purchase['id'] );
				if ( ! $fresh || $fresh['sessions_remaining'] < 1 ) {
					$error = new \WP_Error( 'mc_package_code', __( 'That package has no sessions left.', 'magstack-coaches' ), array( 'status' => 400 ) );
				}
			}

			if ( ! $error ) {
				$inserted = false;
				for ( $i = 0; $i < 5 && ! $inserted; $i++ ) {
					$row['reference'] = self::new_reference();
					$suppress         = $wpdb->suppress_errors( true );
					$inserted         = $wpdb->insert( Database::bookings(), $row );
					$dup_slot         = $wpdb->last_error && false !== stripos( $wpdb->last_error, 'slot_key' );
					$wpdb->suppress_errors( $suppress );
					if ( ! $inserted && $dup_slot ) {
						$error = new \WP_Error( 'mc_taken', __( 'Sorry, that time slot was just taken. Please pick another.', 'magstack-coaches' ), array( 'status' => 409 ) );
						break;
					}
				}
				if ( ! $error && ! $inserted ) {
					$error = new \WP_Error( 'mc_db', __( 'Could not save your booking. Please try again.', 'magstack-coaches' ), array( 'status' => 500 ) );
				}
				if ( $inserted ) {
					$booking_id = (int) $wpdb->insert_id;
					if ( $package ) {
						$wpdb->insert(
							Database::purchases(),
							array(
								'code'           => self::new_code( 'PK' ),
								'package_id'     => (int) $package['id'],
								'coach_id'       => $coach_id,
								'player_name'    => $name,
								'player_email'   => $email,
								'sessions_total' => (int) $package['sessions'],
								'sessions_used'  => 0,
								'price'          => (float) $package['price'],
								'payment_status' => 'unpaid',
								'expires_on'     => (int) $package['validity_days'] ? Helpers::now()->modify( '+' . (int) $package['validity_days'] . ' days' )->format( 'Y-m-d' ) : null,
								'created_at'     => $now,
							)
						);
						$purchase_id = (int) $wpdb->insert_id;
						$wpdb->update( Database::bookings(), array( 'purchase_id' => $purchase_id ), array( 'id' => $booking_id ) );
						Repository::recalc_purchase( $purchase_id );
					} elseif ( $purchase ) {
						Repository::recalc_purchase( (int) $purchase['id'] );
					}
				}
			}

			$wpdb->query( $error ? 'ROLLBACK' : 'COMMIT' );
		} finally {
			if ( null !== $got ) {
				$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			}
		}

		if ( $error ) {
			return $error;
		}

		$booking = Repository::get( $booking_id );
		$pay     = Registry::active()->initiate( $booking );
		do_action( 'magstack_booking_created', $booking );

		$booking['payment_html']  = $pay['html'];
		$booking['payment_redirect'] = $pay['redirect'];
		return $booking;
	}

	/**
	 * @return array{mode:string,facility_id:int,label:string,fee:float,note:string}|\WP_Error
	 */
	private static function resolve_court( int $coach_id, array $in ) {
		$mode = sanitize_key( (string) ( $in['court_mode'] ?? 'coach_picks' ) );
		$pay_at_venue = __( 'Pay at venue', 'magstack-coaches' );

		if ( 'partner' === $mode ) {
			$fid = (int) ( $in['facility_id'] ?? 0 );
			if ( ! Facility::is_facility( $fid ) || ! Facility::is_partner( $fid ) ) {
				return new \WP_Error( 'mc_court', __( 'Please choose a partner facility.', 'magstack-coaches' ), array( 'status' => 400 ) );
			}
			$d = Facility::public_data( $fid );
			return array(
				'mode'        => 'partner',
				'facility_id' => $fid,
				'label'       => trim( $d['name'] . ( $d['city'] ? ', ' . $d['city'] : '' ) ),
				'fee'         => (float) ( $d['court_fee'] ?? 0 ),
				'note'        => $d['pay_at_venue'] ? $pay_at_venue : $d['court_fee_note'],
			);
		}

		if ( 'coach_venue' === $mode ) {
			$venues = Coach::venues( $coach_id );
			$idx    = (int) ( $in['venue_index'] ?? -1 );
			if ( ! isset( $venues[ $idx ] ) ) {
				return new \WP_Error( 'mc_court', __( 'Please choose one of the coach\'s venues.', 'magstack-coaches' ), array( 'status' => 400 ) );
			}
			$v   = $venues[ $idx ];
			$fid = (int) ( $v['facility_id'] ?? 0 );
			$fee = 0.0;
			$note = $pay_at_venue;
			if ( $fid && Facility::is_facility( $fid ) ) {
				$d    = Facility::public_data( $fid );
				$fee  = (float) ( $d['court_fee'] ?? 0 );
				$note = $d['pay_at_venue'] ? $pay_at_venue : ( $d['court_fee_note'] ?: $pay_at_venue );
			}
			return array(
				'mode'        => 'coach_venue',
				'facility_id' => $fid,
				'label'       => trim( (string) $v['name'] . ( ! empty( $v['address'] ) ? ', ' . $v['address'] : '' ) ),
				'fee'         => $fee,
				'note'        => $note,
			);
		}

		return array(
			'mode'        => 'coach_picks',
			'facility_id' => 0,
			'label'       => __( 'Coach will choose the court', 'magstack-coaches' ),
			'fee'         => 0.0,
			'note'        => __( 'Court fee to be advised by the coach', 'magstack-coaches' ),
		);
	}

	private static function new_reference(): string {
		return self::new_code( 'MC' );
	}

	public static function new_code( string $prefix ): string {
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$code     = '';
		for ( $i = 0; $i < 6; $i++ ) {
			$code .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
		}
		return $prefix . '-' . $code;
	}

	// ---- Status transitions -----------------------------------------------------------------

	/**
	 * @param bool $force Admin override: ignore the transition table.
	 * @return true|\WP_Error
	 */
	public static function set_status( int $id, string $new, bool $force = false ) {
		global $wpdb;
		$b = Repository::get( $id );
		if ( ! $b ) {
			return new \WP_Error( 'mc_nf', __( 'Booking not found.', 'magstack-coaches' ), array( 'status' => 404 ) );
		}
		$old = $b['status'];
		if ( ! array_key_exists( $new, self::TRANSITIONS ) ) {
			return new \WP_Error( 'mc_status', __( 'Invalid status.', 'magstack-coaches' ), array( 'status' => 400 ) );
		}
		if ( $old === $new ) {
			return true;
		}
		if ( ! $force && ! in_array( $new, self::TRANSITIONS[ $old ] ?? array(), true ) ) {
			return new \WP_Error( 'mc_transition', __( 'That status change is not allowed.', 'magstack-coaches' ), array( 'status' => 409 ) );
		}

		$data = array( 'status' => $new );
		if ( in_array( $new, Repository::ACTIVE, true ) ) {
			// Re-activating (admin force): re-claim the slot atomically; unique key rejects conflicts.
			$data['slot_key'] = self::slot_key( (int) $b['coach_id'], $b['booking_date'], $b['start_time'] );
			$suppress         = $wpdb->suppress_errors( true );
			$ok               = $wpdb->update( Database::bookings(), $data + array( 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
			$wpdb->suppress_errors( $suppress );
			if ( false === $ok ) {
				return new \WP_Error( 'mc_taken', __( 'That time slot is already taken by another booking.', 'magstack-coaches' ), array( 'status' => 409 ) );
			}
		} else {
			$data['slot_key'] = null; // Release the slot.
			Repository::update( $id, $data );
		}

		if ( (int) $b['purchase_id'] ) {
			Repository::recalc_purchase( (int) $b['purchase_id'] );
		}
		$updated = Repository::get( $id );
		do_action( 'magstack_booking_status_changed', $updated, $old, $new );
		return true;
	}

	/**
	 * @return true|\WP_Error
	 */
	public static function set_payment_status( int $id, string $status, array $ctx = array() ) {
		$b = Repository::get( $id );
		if ( ! $b ) {
			return false;
		}
		if ( ! in_array( $status, array( 'paid', 'unpaid' ), true ) ) {
			return false;
		}
		Repository::update( $id, array( 'payment_status' => $status ) );
		if ( (int) $b['purchase_id'] && 'paid' === $status && (float) $b['coach_fee'] > 0 && (int) $b['package_id'] ) {
			global $wpdb;
			$wpdb->update( Database::purchases(), array( 'payment_status' => 'paid' ), array( 'id' => (int) $b['purchase_id'] ) );
		}
		do_action( 'magstack_booking_payment_changed', Repository::get( $id ), $b['payment_status'], $status, $ctx );
		return true;
	}

	/** Player self-service cancellation via reference + token. @return true|\WP_Error */
	public static function player_cancel( string $reference, string $token ) {
		$b = Repository::by_reference( $reference );
		if ( ! $b || ! hash_equals( (string) $b['token'], $token ) ) {
			return new \WP_Error( 'mc_nf', __( 'Booking not found.', 'magstack-coaches' ), array( 'status' => 404 ) );
		}
		if ( ! in_array( $b['status'], array( 'pending', 'confirmed' ), true ) ) {
			return new \WP_Error( 'mc_transition', __( 'This booking can no longer be cancelled.', 'magstack-coaches' ), array( 'status' => 409 ) );
		}
		if ( ! self::can_player_cancel( $b ) ) {
			return new \WP_Error(
				'mc_window',
				sprintf( /* translators: %d: hours */ __( 'Bookings can only be cancelled at least %d hours before the session. Please contact your coach.', 'magstack-coaches' ), (int) Settings::get( 'cancel_window_hours', 24 ) ),
				array( 'status' => 403 )
			);
		}
		return self::set_status( (int) $b['id'], 'cancelled' );
	}

	public static function can_player_cancel( array $b ): bool {
		if ( ! in_array( $b['status'], array( 'pending', 'confirmed' ), true ) ) {
			return false;
		}
		$start  = Helpers::dt( $b['booking_date'], $b['start_time'] );
		$cutoff = Helpers::now()->modify( '+' . (int) Settings::get( 'cancel_window_hours', 24 ) . ' hours' );
		return $start >= $cutoff;
	}
}
