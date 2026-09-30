<?php
namespace Magstack\Coaches;

defined( 'ABSPATH' ) || exit;

final class Roles {

	public const COACH = 'magstack_coach';

	public static function add(): void {
		add_role(
			self::COACH,
			__( 'Magstack Coach', 'magstack-coaches' ),
			array( 'read' => true )
		);
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( 'manage_magstack_coaches' );
		}
	}

	public static function remove(): void {
		remove_role( self::COACH );
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->remove_cap( 'manage_magstack_coaches' );
		}
	}

	public static function register_hooks(): void {
		// Coaches use the front-end dashboard: keep them out of wp-admin and hide the toolbar.
		add_action( 'admin_init', array( self::class, 'block_admin' ) );
		add_filter( 'show_admin_bar', array( self::class, 'admin_bar' ) );
	}

	public static function is_coach_user( ?\WP_User $user = null ): bool {
		$user = $user ?: wp_get_current_user();
		return $user && $user->exists() && in_array( self::COACH, (array) $user->roles, true ) && ! user_can( $user, 'manage_options' );
	}

	public static function block_admin(): void {
		if ( wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		global $pagenow;
		if ( in_array( $pagenow, array( 'admin-post.php', 'async-upload.php' ), true ) ) {
			return;
		}
		if ( self::is_coach_user() ) {
			wp_safe_redirect( Settings::page_url( 'dashboard' ) );
			exit;
		}
	}

	public static function admin_bar( bool $show ): bool {
		return self::is_coach_user() ? false : $show;
	}
}
