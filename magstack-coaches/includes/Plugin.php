<?php
namespace Magstack\Coaches;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?Plugin $instance = null;
	private bool $booted             = false;

	public static function instance(): Plugin {
		return self::$instance ??= new self();
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		load_plugin_textdomain( 'magstack-coaches', false, dirname( plugin_basename( MAGSTACK_COACHES_FILE ) ) . '/languages' );
		Database::maybe_upgrade();

		PostTypes::hooks();
		Roles::register_hooks();
		Assets::hooks();

		Payments\Registry::hooks();
		Notifications\Notifier::hooks();
		Booking\Service::hooks();
		Booking\Reminders::hooks();
		Booking\Shortcode::hooks();
		Rest\PublicController::hooks();
		Rest\CoachController::hooks();
		Coach\Registration::hooks();
		Coach\Directory::hooks();
		Coach\Dashboard::hooks();
		Coach\Coach::hooks();
		Facility\Map::hooks();
		Seo\LocationPages::hooks();

		if ( is_admin() ) {
			Admin\Menu::hooks();
			Admin\CoachAdmin::hooks();
			Admin\FacilityAdmin::hooks();
			Admin\BookingsPage::hooks();
			Admin\SettingsPage::hooks();
		}
	}
}
