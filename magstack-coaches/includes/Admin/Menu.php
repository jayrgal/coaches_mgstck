<?php
namespace Magstack\Coaches\Admin;

use Magstack\Coaches\Booking\Reminders;
use Magstack\Coaches\Coach\Coach;
use Magstack\Coaches\Database;
use Magstack\Coaches\PostTypes;

defined( 'ABSPATH' ) || exit;

final class Menu {

	public const CAP = 'manage_options';

	public static function hooks(): void {
		add_action( 'admin_menu', array( self::class, 'register' ), 9 );
		add_action( 'admin_menu', array( self::class, 'reorder' ), 999 );
		add_action( 'admin_notices', array( self::class, 'notices' ) );
	}

	public static function register(): void {
		add_menu_page(
			__( 'Magstack Coaches', 'magstack-coaches' ),
			__( 'Magstack Coaches', 'magstack-coaches' ),
			self::CAP,
			'magstack-coaches',
			array( self::class, 'overview' ),
			'dashicons-awards',
			30
		);
		add_submenu_page( 'magstack-coaches', __( 'Overview', 'magstack-coaches' ), __( 'Overview', 'magstack-coaches' ), self::CAP, 'magstack-coaches', array( self::class, 'overview' ) );
	}

	public static function reorder(): void {
		global $submenu;
		if ( empty( $submenu['magstack-coaches'] ) ) {
			return;
		}
		$order = array(
			'magstack-coaches',
			'edit.php?post_type=' . PostTypes::COACH,
			'edit.php?post_type=' . PostTypes::FACILITY,
			'magstack-bookings',
			'magstack-settings',
		);
		$items = $submenu['magstack-coaches'];
		usort(
			$items,
			static function ( $a, $b ) use ( $order ) {
				$ia = array_search( $a[2], $order, true );
				$ib = array_search( $b[2], $order, true );
				return ( false === $ia ? 99 : $ia ) <=> ( false === $ib ? 99 : $ib );
			}
		);
		// Drop the "Add New" duplicates from the sub-menu (still reachable from list screens).
		$items = array_values(
			array_filter(
				$items,
				static fn( $i ) => ! in_array( $i[2], array( 'post-new.php?post_type=' . PostTypes::COACH, 'post-new.php?post_type=' . PostTypes::FACILITY ), true )
			)
		);
		$submenu['magstack-coaches'] = $items; // phpcs:ignore WordPress.WP.GlobalVariablesOverride
	}

	public static function notices(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		if ( isset( $_GET['mc_notice'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$msg = sanitize_text_field( wp_unslash( $_GET['mc_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
		if ( isset( $_GET['mc_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$msg = sanitize_text_field( wp_unslash( $_GET['mc_error'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
		}
	}

	public static function overview(): void {
		global $wpdb;
		$b        = Database::bookings();
		$pending  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$b} WHERE status = 'pending'" ); // phpcs:ignore WordPress.DB
		$upcoming = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$b} WHERE status = 'confirmed' AND booking_date >= %s", current_time( 'Y-m-d' ) ) ); // phpcs:ignore WordPress.DB
		$applic   = count(
			get_posts(
				array(
					'post_type'      => PostTypes::COACH,
					'post_status'    => array( 'pending', 'draft', 'publish' ),
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'meta_key'       => '_mc_status', // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'     => 'pending', // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			)
		);
		$due = count( CoachAdmin::ids_by_fee( 'due' ) );
		$cron = wp_next_scheduled( Reminders::HOOK );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Magstack Coaches', 'magstack-coaches' ); ?></h1>
			<div style="display:flex;gap:16px;flex-wrap:wrap;margin:16px 0">
				<?php
				$cards = array(
					array( __( 'Coach applications', 'magstack-coaches' ), $applic, admin_url( 'edit.php?post_type=' . PostTypes::COACH . '&mc_status=pending' ) ),
					array( __( 'Listing fees due', 'magstack-coaches' ), $due, admin_url( 'edit.php?post_type=' . PostTypes::COACH . '&mc_fee=due' ) ),
					array( __( 'Pending bookings', 'magstack-coaches' ), $pending, admin_url( 'admin.php?page=magstack-bookings&status=pending' ) ),
					array( __( 'Upcoming confirmed', 'magstack-coaches' ), $upcoming, admin_url( 'admin.php?page=magstack-bookings&status=confirmed' ) ),
				);
				foreach ( $cards as $c ) :
					?>
					<a href="<?php echo esc_url( $c[2] ); ?>" style="display:block;min-width:180px;padding:16px 20px;background:#fff;border:1px solid #c3c4c7;border-radius:6px;text-decoration:none">
						<span style="font-size:32px;font-weight:700;display:block"><?php echo (int) $c[1]; ?></span>
						<span><?php echo esc_html( $c[0] ); ?></span>
					</a>
				<?php endforeach; ?>
			</div>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . PostTypes::COACH ) ); ?>"><?php esc_html_e( 'Add coach', 'magstack-coaches' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . PostTypes::FACILITY ) ); ?>"><?php esc_html_e( 'Add facility', 'magstack-coaches' ); ?></a>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=magstack-settings' ) ); ?>"><?php esc_html_e( 'Settings', 'magstack-coaches' ); ?></a>
			</p>
			<p class="description">
				<?php
				echo esc_html(
					$cron
						? sprintf( /* translators: %s: datetime */ __( 'Reminder cron next runs: %s', 'magstack-coaches' ), wp_date( 'Y-m-d H:i', $cron ) )
						: __( 'Reminder cron is not scheduled. Deactivate and re-activate the plugin.', 'magstack-coaches' )
				);
				?>
			</p>
		</div>
		<?php
	}
}
