<?php
namespace Magstack\Coaches\Facility;

use Magstack\Coaches\Assets;
use Magstack\Coaches\Helpers;
use Magstack\Coaches\PostTypes;

defined( 'ABSPATH' ) || exit;

final class Map {

	public static function hooks(): void {
		add_shortcode( 'magstack_map', array( self::class, 'shortcode' ) );
		add_filter( 'the_content', array( self::class, 'facility_content' ) );
	}

	public static function enqueue(): void {
		Assets::base();
		wp_enqueue_style( 'magstack-leaflet' );
		wp_enqueue_script( 'magstack-map' );
	}

	public static function data( array $args = array() ): array {
		return array_map( array( Facility::class, 'public_data' ), Facility::query( $args ) );
	}

	/** [magstack_map height="480" city="" partner_only="0"] */
	public static function shortcode( $atts ): string {
		$a = shortcode_atts(
			array(
				'height'       => '480',
				'city'         => '',
				'partner_only' => '0',
			),
			$atts,
			'magstack_map'
		);
		self::enqueue();
		$data = self::data(
			array(
				'city'         => $a['city'],
				'partner_only' => '1' === (string) $a['partner_only'],
			)
		);
		return Helpers::template(
			'map',
			array(
				'height'     => max( 240, (int) $a['height'] ),
				'facilities' => $data,
			)
		);
	}

	/** Facility single page: details + "Book a coach here". */
	public static function facility_content( string $content ): string {
		if ( ! is_singular( PostTypes::FACILITY ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		self::enqueue();
		$id = get_the_ID();
		return $content . Helpers::template(
			'facility-single',
			array( 'f' => Facility::public_data( $id ) )
		);
	}
}
