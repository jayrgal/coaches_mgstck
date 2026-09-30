<?php
namespace Magstack\Coaches\Rest;

use Magstack\Coaches\Booking\Repository;
use Magstack\Coaches\Booking\Service;
use Magstack\Coaches\Coach\Availability;
use Magstack\Coaches\Coach\Coach;
use Magstack\Coaches\Helpers;
use Magstack\Coaches\Payments\Registry;

defined( 'ABSPATH' ) || exit;

/** Authenticated REST endpoints for the front-end coach dashboard. */
final class CoachController {

	public static function hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	public static function routes(): void {
		$ns   = PublicController::NS;
		$perm = array( self::class, 'can' );
		register_rest_route( $ns, '/coach/me', array( 'methods' => 'GET', 'callback' => array( self::class, 'me' ), 'permission_callback' => $perm ) );
		register_rest_route( $ns, '/coach/availability', array( 'methods' => 'POST', 'callback' => array( self::class, 'save_availability' ), 'permission_callback' => $perm ) );
		register_rest_route( $ns, '/coach/packages', array( 'methods' => 'POST', 'callback' => array( self::class, 'save_package' ), 'permission_callback' => $perm ) );
		register_rest_route( $ns, '/coach/packages/(?P<id>\d+)', array( 'methods' => 'DELETE', 'callback' => array( self::class, 'delete_package' ), 'permission_callback' => $perm ) );
		register_rest_route( $ns, '/coach/bookings', array( 'methods' => 'GET', 'callback' => array( self::class, 'bookings' ), 'permission_callback' => $perm ) );
		register_rest_route( $ns, '/coach/bookings/(?P<id>\d+)', array( 'methods' => 'POST', 'callback' => array( self::class, 'booking_action' ), 'permission_callback' => $perm ) );
		register_rest_route( $ns, '/coach/profile', array( 'methods' => 'POST', 'callback' => array( self::class, 'save_profile' ), 'permission_callback' => $perm ) );
	}

	/** The signed-in user must own an approved coach profile (admins may pass coach_id). */
	public static function can( \WP_REST_Request $r ): bool {
		return self::coach_id( $r ) > 0;
	}

	private static function coach_id( \WP_REST_Request $r ): int {
		if ( ! is_user_logged_in() ) {
			return 0;
		}
		if ( current_user_can( 'manage_options' ) && (int) $r->get_param( 'coach_id' ) ) {
			$id = (int) $r->get_param( 'coach_id' );
			return Coach::is_coach( $id ) ? $id : 0;
		}
		$id = Coach::for_user( get_current_user_id() );
		return ( $id && 'approved' === Coach::status( $id ) ) ? $id : 0;
	}

	public static function me( \WP_REST_Request $r ) {
		$id = self::coach_id( $r );
		return rest_ensure_response(
			array(
				'id'           => $id,
				'name'         => get_the_title( $id ),
				'profile_url'  => get_permalink( $id ),
				'booking_link' => Coach::booking_link( $id ),
				'availability' => self::avail_out( Availability::get( $id ) ),
				'packages'     => self::packages_out( $id ),
				'purchases'    => array_map(
					static fn( $p ) => array(
						'code'      => $p['code'],
						'player'    => $p['player_name'],
						'email'     => $p['player_email'],
						'total'     => (int) $p['sessions_total'],
						'remaining' => (int) $p['sessions_remaining'],
						'paid'      => 'paid' === $p['payment_status'],
					),
					Repository::purchases_for_coach( $id )
				),
				'profile'      => array(
					'bio'       => (string) Coach::meta( $id, 'bio', '' ),
					'rate'      => Coach::rate( $id ),
					'durations' => Coach::durations( $id ),
					'city'      => (string) Coach::meta( $id, 'city', '' ),
					'venues'    => implode( "\n", array_map( static fn( $v ) => trim( $v['name'] . ( ! empty( $v['address'] ) ? ' | ' . $v['address'] : '' ) ), array_filter( Coach::venues( $id ), static fn( $v ) => empty( $v['facility_id'] ) ) ) ),
				),
			)
		);
	}

	/** Force maps to JSON objects (empty PHP arrays would encode as []). */
	private static function avail_out( array $a ): array {
		return array(
			'weekly'    => (object) $a['weekly'],
			'overrides' => (object) $a['overrides'],
		);
	}

	private static function packages_out( int $id ): array {
		return array_map(
			static fn( $p ) => array(
				'id'            => (int) $p['id'],
				'name'          => $p['name'],
				'sessions'      => (int) $p['sessions'],
				'price'         => (float) $p['price'],
				'validity_days' => (int) $p['validity_days'],
				'active'        => (bool) $p['active'],
			),
			Repository::packages( $id, false )
		);
	}

	public static function save_availability( \WP_REST_Request $r ) {
		$id    = self::coach_id( $r );
		$clean = Availability::sanitize( $r->get_json_params() );
		update_post_meta( $id, '_mc_availability', $clean );
		return rest_ensure_response( array( 'availability' => self::avail_out( $clean ) ) );
	}

	public static function save_package( \WP_REST_Request $r ) {
		$id = self::coach_id( $r );
		$d  = (array) $r->get_json_params();
		if ( (int) ( $d['price'] ?? 0 ) <= 0 || (int) ( $d['sessions'] ?? 0 ) < 2 ) {
			return new \WP_Error( 'mc_package', __( 'A package needs at least 2 sessions and a price.', 'magstack-coaches' ), array( 'status' => 400 ) );
		}
		$pid = Repository::save_package( $id, $d, (int) ( $d['id'] ?? 0 ) );
		if ( ! $pid ) {
			return new \WP_Error( 'mc_package', __( 'Could not save the package.', 'magstack-coaches' ), array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'packages' => self::packages_out( $id ) ) );
	}

	public static function delete_package( \WP_REST_Request $r ) {
		$id = self::coach_id( $r );
		Repository::delete_package( $id, (int) $r['id'] );
		return rest_ensure_response( array( 'packages' => self::packages_out( $id ) ) );
	}

	public static function bookings( \WP_REST_Request $r ) {
		$id  = self::coach_id( $r );
		$res = Repository::query(
			array(
				'coach_id' => $id,
				'status'   => sanitize_key( (string) $r->get_param( 'status' ) ),
				'per_page' => 100,
				'orderby'  => 'booking_date',
				'order'    => 'DESC',
			)
		);
		$out = array_map( array( self::class, 'out' ), $res['rows'] );
		return rest_ensure_response( $out );
	}

	/** Coach-facing view (player contact included: the coach needs it). */
	private static function out( array $b ): array {
		$pkg = null;
		if ( (int) $b['purchase_id'] ) {
			$p   = Repository::purchase( (int) $b['purchase_id'] );
			$pkg = $p ? array( 'code' => $p['code'], 'remaining' => (int) $p['sessions_remaining'], 'total' => (int) $p['sessions_total'] ) : null;
		}
		return array(
			'id'             => (int) $b['id'],
			'reference'      => $b['reference'],
			'status'         => $b['status'],
			'status_label'   => Helpers::status_labels()[ $b['status'] ] ?? $b['status'],
			'payment_status' => $b['payment_status'],
			'date'           => $b['booking_date'],
			'date_label'     => Helpers::fmt_date( $b['booking_date'] ),
			'time_label'     => Helpers::fmt_time( $b['start_time'] ) . ' – ' . Helpers::fmt_time( $b['end_time'] ),
			'player'         => $b['player_name'],
			'email'          => $b['player_email'],
			'mobile'         => $b['player_mobile'],
			'level'          => $b['player_level'],
			'notes'          => (string) $b['notes'],
			'court'          => $b['venue_label'],
			'coach_fee'      => (float) $b['coach_fee'],
			'court_fee'      => (float) $b['court_fee'],
			'court_fee_note' => $b['court_fee_note'],
			'total'          => (float) $b['total'],
			'package'        => $pkg,
		);
	}

	public static function booking_action( \WP_REST_Request $r ) {
		$coach_id = self::coach_id( $r );
		$b        = Repository::get( (int) $r['id'] );
		if ( ! $b || (int) $b['coach_id'] !== $coach_id ) {
			return new \WP_Error( 'mc_nf', __( 'Booking not found.', 'magstack-coaches' ), array( 'status' => 404 ) );
		}
		$action = sanitize_key( (string) ( $r->get_json_params()['action'] ?? '' ) );
		$map    = array(
			'confirm'  => 'confirmed',
			'decline'  => 'declined',
			'complete' => 'completed',
			'cancel'   => 'cancelled',
		);
		if ( isset( $map[ $action ] ) ) {
			$res = Service::set_status( (int) $b['id'], $map[ $action ] );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		} elseif ( in_array( $action, array( 'paid', 'unpaid' ), true ) ) {
			$gw = Registry::get( (string) $b['gateway'] ) ?? Registry::active();
			if ( 'paid' === $action ) {
				$gw->mark_paid( (int) $b['id'], array( 'by' => get_current_user_id() ) );
			} else {
				Service::set_payment_status( (int) $b['id'], 'unpaid' );
			}
		} else {
			return new \WP_Error( 'mc_action', __( 'Unknown action.', 'magstack-coaches' ), array( 'status' => 400 ) );
		}
		return rest_ensure_response( self::out( Repository::get( (int) $b['id'] ) ) );
	}

	public static function save_profile( \WP_REST_Request $r ) {
		$id = self::coach_id( $r );
		$d  = (array) $r->get_json_params();
		if ( isset( $d['bio'] ) ) {
			update_post_meta( $id, '_mc_bio', mb_substr( sanitize_textarea_field( (string) $d['bio'] ), 0, 1200 ) );
		}
		if ( isset( $d['rate'] ) ) {
			update_post_meta( $id, '_mc_rate', max( 0, round( (float) $d['rate'], 2 ) ) );
		}
		if ( isset( $d['durations'] ) ) {
			update_post_meta( $id, '_mc_durations', self::parse_durations( $d['durations'] ) );
		}
		if ( isset( $d['city'] ) ) {
			update_post_meta( $id, '_mc_city', sanitize_text_field( (string) $d['city'] ) );
			Coach::clear_caches();
		}
		if ( isset( $d['venues'] ) ) {
			update_post_meta( $id, '_mc_venues', \Magstack\Coaches\Coach\Registration::parse_venues( (string) $d['venues'], array_map( 'intval', (array) Coach::meta( $id, 'venue_facilities', array() ) ) ) );
		}
		return self::me( $r );
	}

	public static function parse_durations( $raw ): array {
		$raw = is_array( $raw ) ? $raw : preg_split( '/[\s,]+/', (string) $raw );
		$out = array();
		foreach ( (array) $raw as $m ) {
			$m = (int) $m;
			if ( $m >= 15 && $m <= 240 ) {
				$out[] = $m;
			}
		}
		$out = array_values( array_unique( $out ) );
		sort( $out );
		return $out ?: array( 60 );
	}
}
