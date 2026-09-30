<?php
namespace Magstack\Coaches\Coach;

use Magstack\Coaches\Helpers;
use Magstack\Coaches\PostTypes;
use Magstack\Coaches\Settings;
use Magstack\Coaches\Notifications\Notifier;

defined( 'ABSPATH' ) || exit;

/** Coach domain helpers (a coach is a magstack_coach post; coach_id === post ID). */
final class Coach {

	public static function hooks(): void {
		add_action( 'save_post_' . PostTypes::COACH, array( self::class, 'clear_caches' ) );
		add_action( 'save_post_' . PostTypes::FACILITY, array( self::class, 'clear_caches' ) );
		add_action( 'deleted_post', array( self::class, 'clear_caches' ) );
	}

	public static function clear_caches(): void {
		delete_transient( 'magstack_cities' );
	}

	/** @return mixed */
	public static function meta( int $id, string $key, $default = '' ) {
		$v = get_post_meta( $id, '_mc_' . $key, true );
		return ( '' === $v || null === $v ) ? $default : $v;
	}

	public static function is_coach( int $id ): bool {
		return $id > 0 && PostTypes::COACH === get_post_type( $id );
	}

	public static function status( int $id ): string {
		return (string) self::meta( $id, 'status', 'pending' );
	}

	public static function tier( int $id ): string {
		$t = (string) self::meta( $id, 'tier', 'free' );
		return in_array( $t, array( 'free', 'pro' ), true ) ? $t : 'free';
	}

	public static function is_verified( int $id ): bool {
		return (bool) self::meta( $id, 'verified', 0 );
	}

	public static function levels( int $id ): array {
		$l = self::meta( $id, 'levels', array() );
		return is_array( $l ) ? array_values( array_intersect( $l, Helpers::LEVELS ) ) : array();
	}

	public static function durations( int $id ): array {
		$d = self::meta( $id, 'durations', array( 60 ) );
		$d = is_array( $d ) ? array_values( array_unique( array_filter( array_map( 'intval', $d ) ) ) ) : array( 60 );
		sort( $d );
		return $d ?: array( 60 );
	}

	public static function rate( int $id ): float {
		return (float) self::meta( $id, 'rate', 0 );
	}

	/** Coach fee for a session of $minutes (rate is per 60 minutes, pro-rated). */
	public static function price_for( int $id, int $minutes ): float {
		return round( self::rate( $id ) * $minutes / 60, 2 );
	}

	public static function venues( int $id ): array {
		$v = self::meta( $id, 'venues', array() );
		return is_array( $v ) ? $v : array();
	}

	public static function socials( int $id ): array {
		$s = self::meta( $id, 'socials', array() );
		return is_array( $s ) ? array_filter( $s ) : array();
	}

	public static function user_id( int $id ): int {
		return (int) get_post_field( 'post_author', $id );
	}

	public static function for_user( int $user_id ): int {
		$posts = get_posts(
			array(
				'post_type'      => PostTypes::COACH,
				'post_status'    => array( 'publish', 'pending', 'draft' ),
				'author'         => $user_id,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		return $posts ? (int) $posts[0] : 0;
	}

	// ---- Founding period / fee logic --------------------------------------------------------

	public static function founding_end( int $id ): string {
		return (string) self::meta( $id, 'founding_ends', '' );
	}

	public static function listing_fee( int $id ): float {
		$tiers = Settings::get( 'tiers', array() );
		return (float) ( $tiers[ self::tier( $id ) ]['listing_fee'] ?? 0 );
	}

	/**
	 * founding | paid | due | na
	 * "na" = not applicable (commission mode, zero fee, or not approved yet).
	 */
	public static function fee_status( int $id ): string {
		if ( 'approved' !== self::status( $id ) ) {
			return 'na';
		}
		if ( 'listing' !== Settings::get( 'fee_mode', 'listing' ) || self::listing_fee( $id ) <= 0 ) {
			return 'na';
		}
		$today = Helpers::now()->format( 'Y-m-d' );
		$end   = self::founding_end( $id );
		if ( $end && $today <= $end ) {
			return 'founding';
		}
		$paid = (string) self::meta( $id, 'fee_paid_until', '' );
		if ( $paid && $today <= $paid ) {
			return 'paid';
		}
		return 'due';
	}

	public static function fee_status_label( string $s ): string {
		$map = array(
			'founding' => __( 'Founding (free)', 'magstack-coaches' ),
			'paid'     => __( 'Paid', 'magstack-coaches' ),
			'due'      => __( 'Fee due', 'magstack-coaches' ),
			'na'       => __( 'n/a', 'magstack-coaches' ),
		);
		return $map[ $s ] ?? $s;
	}

	/** Admin action: record a manual payment, extending coverage by one month. */
	public static function mark_fee_paid( int $id ): void {
		$today = Helpers::now()->format( 'Y-m-d' );
		$base  = max( $today, (string) self::meta( $id, 'fee_paid_until', '' ), self::founding_end( $id ) );
		$new   = ( new \DateTimeImmutable( $base, Helpers::tz() ) )->modify( '+1 month' )->format( 'Y-m-d' );
		update_post_meta( $id, '_mc_fee_paid_until', $new );
	}

	/** Visible in the directory and bookable? */
	public static function is_listable( int $id ): bool {
		if ( ! self::is_coach( $id ) || 'approved' !== self::status( $id ) || 'publish' !== get_post_status( $id ) ) {
			return false;
		}
		$ok = true;
		if ( Settings::get( 'hide_unpaid', 0 ) && 'due' === self::fee_status( $id ) ) {
			$ok = false;
		}
		return (bool) apply_filters( 'magstack_coach_listable', $ok, $id );
	}

	// ---- Moderation -------------------------------------------------------------------------

	public static function approve( int $id ): void {
		if ( ! self::is_coach( $id ) ) {
			return;
		}
		$first = ! self::meta( $id, 'approved_at', '' );
		update_post_meta( $id, '_mc_status', 'approved' );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'publish',
			)
		);
		if ( $first ) {
			$now  = Helpers::now();
			$days = max( 0, (int) Settings::get( 'founding_days', 90 ) );
			update_post_meta( $id, '_mc_approved_at', $now->format( 'Y-m-d H:i:s' ) );
			update_post_meta( $id, '_mc_founding_ends', $now->modify( '+' . $days . ' days' )->format( 'Y-m-d' ) );
		}
		self::ensure_user( $id );
		$user = get_userdata( self::user_id( $id ) );
		if ( $user ) {
			$user->add_role( \Magstack\Coaches\Roles::COACH );
		}
		Notifier::coach_event( 'coach_approved', $id );
		do_action( 'magstack_coach_approved', $id );
	}

	/**
	 * Make sure the coach post is owned by a WP user that matches the coach email
	 * (admin-created coaches have no account yet).
	 */
	public static function ensure_user( int $id ): void {
		$author = get_userdata( self::user_id( $id ) );
		$email  = sanitize_email( (string) self::meta( $id, 'email', '' ) );
		if ( $author && ( in_array( \Magstack\Coaches\Roles::COACH, (array) $author->roles, true ) || 0 === strcasecmp( $author->user_email, $email ) ) ) {
			return;
		}
		if ( ! is_email( $email ) ) {
			return;
		}
		$uid = email_exists( $email );
		if ( ! $uid ) {
			$login = sanitize_user( strstr( $email, '@', true ), true ) ?: 'coach';
			$base  = $login;
			$i     = 1;
			while ( username_exists( $login ) ) {
				$login = $base . ( ++$i );
			}
			$uid = wp_insert_user(
				array(
					'user_login'   => $login,
					'user_pass'    => wp_generate_password( 24 ),
					'user_email'   => $email,
					'display_name' => get_the_title( $id ),
					'role'         => \Magstack\Coaches\Roles::COACH,
				)
			);
		}
		if ( $uid && ! is_wp_error( $uid ) ) {
			wp_update_post(
				array(
					'ID'          => $id,
					'post_author' => (int) $uid,
				)
			);
		}
	}

	public static function reject( int $id ): void {
		if ( ! self::is_coach( $id ) ) {
			return;
		}
		update_post_meta( $id, '_mc_status', 'rejected' );
		wp_update_post(
			array(
				'ID'          => $id,
				'post_status' => 'draft',
			)
		);
		Notifier::coach_event( 'coach_rejected', $id );
		do_action( 'magstack_coach_rejected', $id );
	}

	// ---- Presentation -----------------------------------------------------------------------

	public static function booking_link( int $id ): string {
		return add_query_arg( 'coach', $id, Settings::page_url( 'booking' ) );
	}

	/** Public (safe) data. Never contains email or mobile. */
	public static function public_data( int $id ): array {
		$thumb = get_the_post_thumbnail_url( $id, 'medium' );
		return array(
			'id'          => $id,
			'name'        => get_the_title( $id ),
			'url'         => get_permalink( $id ),
			'photo'       => $thumb ?: '',
			'verified'    => self::is_verified( $id ),
			'rating'      => (float) self::meta( $id, 'rating_avg', 0 ),
			'rating_n'    => (int) self::meta( $id, 'rating_count', 0 ),
			'rate'        => self::rate( $id ),
			'levels'      => self::levels( $id ),
			'city'        => (string) self::meta( $id, 'city', '' ),
			'durations'   => self::durations( $id ),
			'bio'         => (string) self::meta( $id, 'bio', '' ),
			'dupr'        => (string) self::meta( $id, 'dupr', '' ),
			'years'       => (int) self::meta( $id, 'years', 0 ),
			'certs'       => (string) self::meta( $id, 'certifications', '' ),
			'booking_url' => self::booking_link( $id ),
		);
	}

	/**
	 * Listable coaches, partner-agnostic.
	 *
	 * @param array $f city, level, min_price, max_price, date, search, ids
	 * @return int[]
	 */
	public static function query( array $f = array() ): array {
		$args = array(
			'post_type'      => PostTypes::COACH,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'key'   => '_mc_status',
					'value' => 'approved',
				),
			),
		);
		if ( ! empty( $f['ids'] ) ) {
			$args['post__in'] = array_map( 'intval', (array) $f['ids'] );
		}
		if ( ! empty( $f['search'] ) ) {
			$args['s'] = sanitize_text_field( $f['search'] );
		}
		$ids = array_filter( array_map( 'intval', get_posts( $args ) ), array( self::class, 'is_listable' ) );

		$out = array();
		foreach ( $ids as $id ) {
			if ( ! empty( $f['city'] ) && 0 !== strcasecmp( trim( (string) self::meta( $id, 'city', '' ) ), trim( (string) $f['city'] ) ) ) {
				continue;
			}
			if ( ! empty( $f['level'] ) && ! in_array( $f['level'], self::levels( $id ), true ) ) {
				continue;
			}
			$rate = self::rate( $id );
			if ( isset( $f['min_price'] ) && '' !== $f['min_price'] && $rate < (float) $f['min_price'] ) {
				continue;
			}
			if ( isset( $f['max_price'] ) && '' !== $f['max_price'] && (float) $f['max_price'] > 0 && $rate > (float) $f['max_price'] ) {
				continue;
			}
			if ( ! empty( $f['date'] ) && Helpers::valid_date( $f['date'] ) ) {
				$d = self::durations( $id );
				if ( ! Availability::slots( $id, $f['date'], $d[0] ) ) {
					continue;
				}
			}
			$out[] = $id;
		}
		return $out;
	}

	/** Distinct cities from listable coaches and published facilities: slug => label. */
	public static function cities(): array {
		$cached = get_transient( 'magstack_cities' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$cities = array( 'coaches' => array(), 'facilities' => array() );
		foreach ( self::query() as $id ) {
			$c = trim( (string) self::meta( $id, 'city', '' ) );
			if ( $c ) {
				$cities['coaches'][ sanitize_title( $c ) ] = $c;
			}
		}
		$fac = get_posts(
			array(
				'post_type'      => PostTypes::FACILITY,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		foreach ( $fac as $fid ) {
			$c = trim( (string) get_post_meta( $fid, '_mc_city', true ) );
			if ( $c ) {
				$cities['facilities'][ sanitize_title( $c ) ] = $c;
			}
		}
		asort( $cities['coaches'] );
		asort( $cities['facilities'] );
		set_transient( 'magstack_cities', $cities, DAY_IN_SECONDS );
		return $cities;
	}
}
