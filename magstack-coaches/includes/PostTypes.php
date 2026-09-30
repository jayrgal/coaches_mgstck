<?php
namespace Magstack\Coaches;

defined( 'ABSPATH' ) || exit;

final class PostTypes {

	public const COACH    = 'magstack_coach';
	public const FACILITY = 'magstack_facility';

	public static function hooks(): void {
		add_action( 'init', array( self::class, 'register' ) );
	}

	public static function register(): void {
		register_post_type(
			self::COACH,
			array(
				'labels'          => array(
					'name'               => __( 'Coaches', 'magstack-coaches' ),
					'singular_name'      => __( 'Coach', 'magstack-coaches' ),
					'add_new_item'       => __( 'Add New Coach', 'magstack-coaches' ),
					'edit_item'          => __( 'Edit Coach', 'magstack-coaches' ),
					'all_items'          => __( 'Coaches', 'magstack-coaches' ),
					'search_items'       => __( 'Search Coaches', 'magstack-coaches' ),
					'not_found'          => __( 'No coaches found.', 'magstack-coaches' ),
					'featured_image'     => __( 'Coach photo', 'magstack-coaches' ),
					'set_featured_image' => __( 'Set coach photo', 'magstack-coaches' ),
				),
				'public'          => true,
				'show_ui'         => true,
				'show_in_menu'    => 'magstack-coaches',
				'show_in_rest'    => false,
				'has_archive'     => false,
				'rewrite'         => array(
					'slug'       => 'coach',
					'with_front' => false,
				),
				'supports'        => array( 'title', 'thumbnail' ),
				'capability_type' => 'post',
				'menu_icon'       => 'dashicons-groups',
			)
		);

		register_post_type(
			self::FACILITY,
			array(
				'labels'          => array(
					'name'               => __( 'Facilities', 'magstack-coaches' ),
					'singular_name'      => __( 'Facility', 'magstack-coaches' ),
					'add_new_item'       => __( 'Add New Facility', 'magstack-coaches' ),
					'edit_item'          => __( 'Edit Facility', 'magstack-coaches' ),
					'all_items'          => __( 'Facilities', 'magstack-coaches' ),
					'not_found'          => __( 'No facilities found.', 'magstack-coaches' ),
					'featured_image'     => __( 'Facility photo', 'magstack-coaches' ),
					'set_featured_image' => __( 'Set facility photo', 'magstack-coaches' ),
				),
				'public'          => true,
				'show_ui'         => true,
				'show_in_menu'    => 'magstack-coaches',
				'show_in_rest'    => false,
				'has_archive'     => false,
				'rewrite'         => array(
					'slug'       => 'facility',
					'with_front' => false,
				),
				'supports'        => array( 'title', 'editor', 'thumbnail' ),
				'capability_type' => 'post',
			)
		);
	}
}
