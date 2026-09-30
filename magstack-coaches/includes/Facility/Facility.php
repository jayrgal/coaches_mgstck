<?php
namespace Magstack\Coaches\Facility;

use Magstack\Coaches\PostTypes;
use Magstack\Coaches\Settings;

defined( 'ABSPATH' ) || exit;

final class Facility {

	/** @return mixed */
	public static function meta( int $id, string $key, $default = '' ) {
		$v = get_post_meta( $id, '_mc_' . $key, true );
		return ( '' === $v || null === $v ) ? $default : $v;
	}

	public static function is_facility( int $id ): bool {
		return $id > 0 && PostTypes::FACILITY === get_post_type( $id ) && 'publish' === get_post_status( $id );
	}

	public static function is_partner( int $id ): bool {
		return (bool) self::meta( $id, 'partner', 0 );
	}

	public static function public_data( int $id ): array {
		$fee = self::meta( $id, 'court_fee', '' );
		return array(
			'id'            => $id,
			'name'          => get_the_title( $id ),
			'url'           => get_permalink( $id ),
			'photo'         => (string) get_the_post_thumbnail_url( $id, 'medium' ),
			'address'       => (string) self::meta( $id, 'address', '' ),
			'city'          => (string) self::meta( $id, 'city', '' ),
			'lat'           => '' === self::meta( $id, 'lat', '' ) ? null : (float) self::meta( $id, 'lat', 0 ),
			'lng'           => '' === self::meta( $id, 'lng', '' ) ? null : (float) self::meta( $id, 'lng', 0 ),
			'courts'        => (int) self::meta( $id, 'courts', 0 ),
			'surface'       => (string) self::meta( $id, 'surface', '' ),
			'hours'         => (string) self::meta( $id, 'hours', '' ),
			'contact'       => (string) self::meta( $id, 'contact', '' ),
			'court_fee'     => '' === $fee ? null : (float) $fee,
			'court_fee_note' => (string) self::meta( $id, 'court_fee_note', '' ),
			'pay_at_venue'  => (bool) self::meta( $id, 'pay_at_venue', 0 ),
			'magstack_url'  => (string) self::meta( $id, 'url', '' ),
			'partner'       => self::is_partner( $id ),
			// Phase 3-ready: flag only, no live check is performed in Phase 1.
			'live_availability' => self::is_partner( $id ) && (bool) self::meta( $id, 'url', '' ),
			'book_url'      => add_query_arg( 'facility', $id, Settings::page_url( 'booking' ) ),
		);
	}

	/**
	 * Published facilities; partners first, then by name.
	 *
	 * @return int[]
	 */
	public static function query( array $f = array() ): array {
		$args = array(
			'post_type'      => PostTypes::FACILITY,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);
		$meta = array();
		if ( ! empty( $f['partner_only'] ) ) {
			$meta[] = array(
				'key'   => '_mc_partner',
				'value' => '1',
			);
		}
		if ( ! empty( $f['city'] ) ) {
			$meta[] = array(
				'key'   => '_mc_city',
				'value' => sanitize_text_field( $f['city'] ),
			);
		}
		if ( $meta ) {
			$args['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		$ids = array_map( 'intval', get_posts( $args ) );
		usort(
			$ids,
			static function ( $a, $b ) {
				$pa = self::is_partner( $a ) ? 0 : 1;
				$pb = self::is_partner( $b ) ? 0 : 1;
				return $pa <=> $pb ?: strcasecmp( get_the_title( $a ), get_the_title( $b ) );
			}
		);
		return $ids;
	}
}
