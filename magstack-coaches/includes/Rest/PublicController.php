<?php
namespace Magstack\Coaches\Rest;

use Magstack\Coaches\Booking\Repository;
use Magstack\Coaches\Booking\Service;
use Magstack\Coaches\Coach\Availability;
use Magstack\Coaches\Coach\Coach;
use Magstack\Coaches\Facility\Facility;
use Magstack\Coaches\Helpers;
use Magstack\Coaches\Notifications\Notifier;
use Magstack\Coaches\Payments\Registry;
use Magstack\Coaches\Settings;
use Magstack\Coaches\Spam;

defined( 'ABSPATH' ) || exit;

/** Public REST API (namespace magstack/v1). No coach contact details are ever returned here. */
final class PublicController {

	public const NS = 'magstack/v1';

	public static function hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'routes' ) );
	}

	public static function routes(): void {
		$public = '__return_true';
		register_rest_route( self::NS, '/coaches', array( 'methods' => 'GET', 'callback' => array( self::class, 'coaches' ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/coaches/(?P<id>\d+)', array( 'methods' => 'GET', 'callback' => array( self::class, 'coach' ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/coaches/(?P<id>\d+)/slots', array( 'methods' => 'GET', 'callback' => array( self::class, 'slots' ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/coaches/(?P<id>\d+)/dates', array( 'methods' => 'GET', 'callback' => array( self::class, 'dates' ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/facilities', array( 'methods' => 'GET', 'callback' => array( self::class, 'facilities' ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/bookings', array( 'methods' => 'POST', 'callback' => array( self::class, 'create_booking' ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/bookings/lookup', array( 'methods' => 'GET', 'callback' => array( self::class, 'lookup' ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/bookings/cancel', array( 'methods' => 'POST', 'callback' => array( self::class, 'cancel' ), 'permission_callback' => $public ) );
		register_rest_route( self::NS, '/package-check', array( 'methods' => 'POST', 'callback' => array( self::class, 'package_check' ), 'permission_callback' => $public ) );
	}

	// ---- Coaches ----------------------------------------------------------------------------

	public static function card( int $id ): array {
		$d = Coach::public_data( $id );
		unset( $d['bio'], $d['certs'] );
		return $d;
	}

	public static function coaches( \WP_REST_Request $r ) {
		$ids = Coach::query(
			array(
				'city'  => (string) $r->get_param( 'city' ),
				'level' => (string) $r->get_param( 'level' ),
			)
		);
		return rest_ensure_response( array_map( array( self::class, 'card' ), $ids ) );
	}

	public static function coach( \WP_REST_Request $r ) {
		$id = (int) $r['id'];
		if ( ! Coach::is_listable( $id ) ) {
			return new \WP_Error( 'mc_nf', __( 'Coach not found.', 'magstack-coaches' ), array( 'status' => 404 ) );
		}
		$d               = self::card( $id );
		$d['packages']   = array_map(
			static fn( $p ) => array(
				'id'       => (int) $p['id'],
				'name'     => $p['name'],
				'sessions' => (int) $p['sessions'],
				'price'    => (float) $p['price'],
			),
			Repository::packages( $id )
		);
		$d['venues']     = array_values(
			array_map(
				static fn( $v ) => array(
					'name'    => (string) ( $v['name'] ?? '' ),
					'address' => (string) ( $v['address'] ?? '' ),
				),
				Coach::venues( $id )
			)
		);
		$d['prices']     = array();
		foreach ( $d['durations'] as $m ) {
			$d['prices'][ $m ] = Coach::price_for( $id, (int) $m );
		}
		return rest_ensure_response( $d );
	}

	public static function slots( \WP_REST_Request $r ) {
		$id = (int) $r['id'];
		if ( ! Coach::is_listable( $id ) ) {
			return new \WP_Error( 'mc_nf', __( 'Coach not found.', 'magstack-coaches' ), array( 'status' => 404 ) );
		}
		$date = (string) $r->get_param( 'date' );
		$dur  = self::duration( $id, (int) $r->get_param( 'duration' ) );
		if ( ! Helpers::valid_date( $date ) ) {
			return new \WP_Error( 'mc_date', __( 'Invalid date.', 'magstack-coaches' ), array( 'status' => 400 ) );
		}
		$res = rest_ensure_response(
			array(
				'date'     => $date,
				'duration' => $dur,
				'price'    => Coach::price_for( $id, $dur ),
				'slots'    => Availability::slots( $id, $date, $dur ),
			)
		);
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	public static function dates( \WP_REST_Request $r ) {
		$id = (int) $r['id'];
		if ( ! Coach::is_listable( $id ) ) {
			return new \WP_Error( 'mc_nf', __( 'Coach not found.', 'magstack-coaches' ), array( 'status' => 404 ) );
		}
		$from = (string) $r->get_param( 'from' );
		if ( ! Helpers::valid_date( $from ) ) {
			$from = Helpers::now()->format( 'Y-m-d' );
		}
		$dur  = self::duration( $id, (int) $r->get_param( 'duration' ) );
		$days = (int) $r->get_param( 'days' ) ?: 30;
		$res  = rest_ensure_response(
			array(
				'duration' => $dur,
				'dates'    => (object) Availability::dates( $id, $dur, $from, $days ),
			)
		);
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	private static function duration( int $coach_id, int $requested ): int {
		$allowed = Coach::durations( $coach_id );
		return in_array( $requested, $allowed, true ) ? $requested : $allowed[0];
	}

	public static function facilities( \WP_REST_Request $r ) {
		$ids = Facility::query( array( 'partner_only' => (bool) $r->get_param( 'partner' ) ) );
		return rest_ensure_response( array_map( array( Facility::class, 'public_data' ), $ids ) );
	}

	// ---- Bookings ---------------------------------------------------------------------------

	public static function create_booking( \WP_REST_Request $r ) {
		$data = $r->get_json_params() ?: $r->get_body_params();
		$spam = Spam::check( (array) $data, 'booking', 8 );
		if ( is_wp_error( $spam ) ) {
			return $spam;
		}
		$b = Service::create( (array) $data );
		if ( is_wp_error( $b ) ) {
			return $b;
		}
		return rest_ensure_response( self::booking_out( $b, true ) );
	}

	/** Player-facing booking view. Coach contact only when Confirmed/Completed. */
	public static function booking_out( array $b, bool $with_payment = false ): array {
		$coach_id = (int) $b['coach_id'];
		$out      = array(
			'reference'      => $b['reference'],
			'token'          => $b['token'],
			'status'         => $b['status'],
			'status_label'   => Helpers::status_labels()[ $b['status'] ] ?? $b['status'],
			'payment_status' => $b['payment_status'],
			'coach'          => array(
				'id'   => $coach_id,
				'name' => get_the_title( $coach_id ),
				'url'  => get_permalink( $coach_id ),
			),
			'date'           => $b['booking_date'],
			'date_label'     => Helpers::fmt_date( $b['booking_date'] ),
			'time_label'     => Helpers::fmt_time( $b['start_time'] ) . ' – ' . Helpers::fmt_time( $b['end_time'] ),
			'court'          => $b['venue_label'],
			'coach_fee'      => (float) $b['coach_fee'],
			'court_fee'      => (float) $b['court_fee'],
			'court_fee_note' => $b['court_fee_note'],
			'total'          => (float) $b['total'],
			'can_cancel'     => Service::can_player_cancel( $b ),
			'ics'            => null,
		);
		if ( (int) $b['purchase_id'] ) {
			$p = Repository::purchase( (int) $b['purchase_id'] );
			if ( $p ) {
				$out['package'] = array(
					'code'      => $p['code'],
					'total'     => (int) $p['sessions_total'],
					'remaining' => (int) $p['sessions_remaining'],
				);
			}
		}
		// Contact-hiding rule: coach email/mobile are revealed only once the booking is Confirmed.
		if ( in_array( $b['status'], array( 'confirmed', 'completed' ), true ) ) {
			$out['coach_contact'] = array(
				'email'  => (string) Coach::meta( $coach_id, 'email', '' ),
				'mobile' => (string) Coach::meta( $coach_id, 'mobile', '' ),
			);
		}
		if ( $with_payment && 'unpaid' === $b['payment_status'] && (float) $b['coach_fee'] > 0 ) {
			$out['payment_html'] = $b['payment_html'] ?? ( Registry::active()->instructions_html( $b ) );
		}
		return $out;
	}

	private static function owned( \WP_REST_Request $r ) {
		$b = Repository::by_reference( strtoupper( sanitize_text_field( (string) $r->get_param( 'ref' ) ) ) );
		if ( ! $b || ! hash_equals( (string) $b['token'], (string) $r->get_param( 't' ) ) ) {
			return new \WP_Error( 'mc_nf', __( 'Booking not found.', 'magstack-coaches' ), array( 'status' => 404 ) );
		}
		return $b;
	}

	public static function lookup( \WP_REST_Request $r ) {
		$b = self::owned( $r );
		if ( is_wp_error( $b ) ) {
			return $b;
		}
		$res = rest_ensure_response( self::booking_out( $b, true ) );
		$res->header( 'Cache-Control', 'no-store' );
		return $res;
	}

	public static function cancel( \WP_REST_Request $r ) {
		$b = self::owned( $r );
		if ( is_wp_error( $b ) ) {
			return $b;
		}
		$res = Service::player_cancel( $b['reference'], (string) $b['token'] );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return rest_ensure_response( self::booking_out( Repository::get( (int) $b['id'] ) ) );
	}

	public static function package_check( \WP_REST_Request $r ) {
		$data = $r->get_json_params() ?: array();
		$spam = Spam::check( array_intersect_key( (array) $data, array_flip( array( 'mc_website', 'mc_ts', 'mc_sig' ) ) ), 'pkgcheck', 20 );
		if ( is_wp_error( $spam ) ) {
			return $spam;
		}
		$p = Repository::purchase_by_code( (string) ( $data['code'] ?? '' ) );
		if (
			! $p || (int) $p['coach_id'] !== (int) ( $data['coach_id'] ?? 0 )
			|| 0 !== strcasecmp( $p['player_email'], sanitize_email( (string) ( $data['email'] ?? '' ) ) )
			|| $p['sessions_remaining'] < 1
		) {
			return new \WP_Error( 'mc_package_code', __( 'Package code not valid for this coach and email, or no sessions left.', 'magstack-coaches' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response(
			array(
				'valid'     => true,
				'remaining' => (int) $p['sessions_remaining'],
				'total'     => (int) $p['sessions_total'],
			)
		);
	}
}
