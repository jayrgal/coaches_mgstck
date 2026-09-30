<?php
namespace Magstack\Coaches;

defined( 'ABSPATH' ) || exit;

final class Activator {

	public static function activate(): void {
		Database::install();
		Roles::add();
		self::create_pages();
		PostTypes::register();
		Seo\LocationPages::detect_conflicts();
		Seo\LocationPages::add_rewrites();
		flush_rewrite_rules();
		Booking\Reminders::schedule();
	}

	public static function deactivate(): void {
		Booking\Reminders::unschedule();
		flush_rewrite_rules();
	}

	private static function create_pages(): void {
		$settings = Settings::get( 'pages', array() );
		$defs     = array(
			'coaches'   => array( __( 'Find a Coach', 'magstack-coaches' ), '[magstack_coaches]' ),
			'booking'   => array( __( 'Book a Coach', 'magstack-coaches' ), '[magstack_booking]' ),
			'register'  => array( __( 'Become a Coach', 'magstack-coaches' ), '[magstack_coach_register]' ),
			'dashboard' => array( __( 'Coach Dashboard', 'magstack-coaches' ), '[magstack_coach_dashboard]' ),
			'map'       => array( __( 'Pickleball Courts Map', 'magstack-coaches' ), '[magstack_map]' ),
		);
		foreach ( $defs as $key => $def ) {
			$id = (int) ( $settings[ $key ] ?? 0 );
			if ( $id && get_post( $id ) && 'trash' !== get_post_status( $id ) ) {
				continue;
			}
			$new = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => $def[0],
					'post_content' => $def[1],
				)
			);
			if ( $new && ! is_wp_error( $new ) ) {
				$settings[ $key ] = (int) $new;
			}
		}
		Settings::update( array( 'pages' => $settings ) );
	}
}
