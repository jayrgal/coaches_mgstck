<?php
/**
 * Plugin Name:       Magstack Coaches
 * Plugin URI:        https://magstack.com/
 * Description:       Pickleball coach booking marketplace: coach registration, directory, booking flow, facility map and SEO location pages.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            Magstack
 * License:           GPL-2.0-or-later
 * Text Domain:       magstack-coaches
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'MAGSTACK_COACHES_VERSION', '1.0.0' );
define( 'MAGSTACK_COACHES_FILE', __FILE__ );
define( 'MAGSTACK_COACHES_DIR', plugin_dir_path( __FILE__ ) );
define( 'MAGSTACK_COACHES_URL', plugin_dir_url( __FILE__ ) );
define( 'MAGSTACK_COACHES_DB_VERSION', '1.0.0' );

if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Magstack Coaches requires PHP 8.1 or newer.', 'magstack-coaches' ) . '</p></div>';
		}
	);
	return;
}

require_once MAGSTACK_COACHES_DIR . 'includes/Autoloader.php';
\Magstack\Coaches\Autoloader::register( 'Magstack\\Coaches\\', MAGSTACK_COACHES_DIR . 'includes/' );

register_activation_hook( __FILE__, array( \Magstack\Coaches\Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Magstack\Coaches\Activator::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function () {
		\Magstack\Coaches\Plugin::instance()->boot();
	}
);
