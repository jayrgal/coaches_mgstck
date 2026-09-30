<?php
namespace Magstack\Coaches\Coach;

use Magstack\Coaches\Assets;
use Magstack\Coaches\Booking\Repository;
use Magstack\Coaches\Helpers;
use Magstack\Coaches\PostTypes;
use Magstack\Coaches\Settings;

defined( 'ABSPATH' ) || exit;

/** [magstack_coaches] directory and the single coach profile. */
final class Directory {

	public static function hooks(): void {
		add_shortcode( 'magstack_coaches', array( self::class, 'shortcode' ) );
		add_filter( 'the_content', array( self::class, 'profile_content' ) );
		add_action( 'template_redirect', array( self::class, 'guard_profile' ) );
	}

	/** Filters accepted from the query string (GET). */
	public static function filters_from_request( array $defaults = array() ): array {
		// phpcs:disable WordPress.Security.NonceVerification
		$g = wp_unslash( $_GET );
		// phpcs:enable
		$f = array(
			'city'      => sanitize_text_field( $g['mc_city'] ?? ( $defaults['city'] ?? '' ) ),
			'level'     => sanitize_key( $g['mc_level'] ?? ( $defaults['level'] ?? '' ) ),
			'min_price' => isset( $g['mc_min'] ) && '' !== $g['mc_min'] ? max( 0, (float) $g['mc_min'] ) : '',
			'max_price' => isset( $g['mc_max'] ) && '' !== $g['mc_max'] ? max( 0, (float) $g['mc_max'] ) : '',
			'date'      => isset( $g['mc_date'] ) && Helpers::valid_date( (string) $g['mc_date'] ) ? (string) $g['mc_date'] : '',
		);
		if ( $f['level'] && ! in_array( $f['level'], Helpers::LEVELS, true ) ) {
			$f['level'] = '';
		}
		return $f;
	}

	/** [magstack_coaches city="" level="" filters="1"] */
	public static function shortcode( $atts ): string {
		$a = shortcode_atts(
			array(
				'city'    => '',
				'level'   => '',
				'filters' => '1',
			),
			$atts,
			'magstack_coaches'
		);
		Assets::base();
		$f   = self::filters_from_request( $a );
		$ids = Coach::query( $f );
		return Helpers::template(
			'coach-directory',
			array(
				'coaches'      => array_map( array( Coach::class, 'public_data' ), $ids ),
				'f'            => $f,
				'show_filters' => '0' !== $a['filters'],
				'cities'       => Coach::cities()['coaches'],
				'action'       => get_permalink() ?: '',
			)
		);
	}

	/** Grid of coach cards (also used by SEO location pages). */
	public static function cards_html( array $coach_ids ): string {
		Assets::base();
		return Helpers::template( 'coach-grid', array( 'coaches' => array_map( array( Coach::class, 'public_data' ), $coach_ids ) ) );
	}

	public static function guard_profile(): void {
		if ( is_singular( PostTypes::COACH ) ) {
			$id = get_queried_object_id();
			if ( ! Coach::is_listable( $id ) && ! current_user_can( 'edit_post', $id ) ) {
				global $wp_query;
				$wp_query->set_404();
				status_header( 404 );
				nocache_headers();
			}
		}
	}

	public static function profile_content( string $content ): string {
		if ( ! is_singular( PostTypes::COACH ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		Assets::base();
		$id   = get_the_ID();
		$d    = Coach::public_data( $id );
		$dur  = $d['durations'][0] ?? 60;
		$next = array();
		if ( Coach::is_listable( $id ) ) {
			$next = array_slice( Availability::dates( $id, $dur, Helpers::now()->format( 'Y-m-d' ), 21 ), 0, 8, true );
		}
		$avail = Availability::get( $id );
		return Helpers::template(
			'coach-profile',
			array(
				'c'        => $d,
				'packages' => Repository::packages( $id ),
				'venues'   => array_map( static fn( $v ) => (string) ( $v['name'] ?? '' ), Coach::venues( $id ) ),
				'socials'  => Coach::socials( $id ),
				'weekly'   => $avail['weekly'],
				'next'     => $next,
				'reviews'  => apply_filters( 'magstack_coach_reviews', array(), $id ), // Phase 2: reviews.
				'listable' => Coach::is_listable( $id ),
			)
		);
	}
}
