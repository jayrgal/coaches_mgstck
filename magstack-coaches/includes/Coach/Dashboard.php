<?php
namespace Magstack\Coaches\Coach;

use Magstack\Coaches\Assets;
use Magstack\Coaches\Helpers;
use Magstack\Coaches\Settings;

defined( 'ABSPATH' ) || exit;

/** [magstack_coach_dashboard] – front-end dashboard for approved coaches (rendered by dashboard.js over REST). */
final class Dashboard {

	public static function hooks(): void {
		add_shortcode( 'magstack_coach_dashboard', array( self::class, 'shortcode' ) );
	}

	public static function shortcode(): string {
		Assets::base();
		nocache_headers();
		if ( ! is_user_logged_in() ) {
			return '<div class="mc mc-notice"><h2>' . esc_html__( 'Coach login', 'magstack-coaches' ) . '</h2>'
				. wp_login_form( array( 'echo' => false, 'redirect' => get_permalink() ?: home_url() ) ) // phpcs:ignore WordPress.Security.EscapeOutput
				. '<p><a href="' . esc_url( Settings::page_url( 'register' ) ) . '">' . esc_html__( 'Not a coach yet? Apply here.', 'magstack-coaches' ) . '</a></p></div>';
		}
		$id = Coach::for_user( get_current_user_id() );
		if ( ! $id ) {
			return '<div class="mc mc-notice"><p>' . esc_html__( 'No coach profile is linked to your account.', 'magstack-coaches' ) . ' <a href="' . esc_url( Settings::page_url( 'register' ) ) . '">' . esc_html__( 'Apply to become a coach', 'magstack-coaches' ) . '</a></p></div>';
		}
		$status = Coach::status( $id );
		if ( 'pending' === $status ) {
			return '<div class="mc mc-notice"><h2>' . esc_html__( 'Application pending', 'magstack-coaches' ) . '</h2><p>' . esc_html__( 'Your application is being reviewed. We will email you once it is approved.', 'magstack-coaches' ) . '</p></div>';
		}
		if ( 'approved' !== $status ) {
			return '<div class="mc mc-notice mc-notice--err"><p>' . esc_html__( 'Your application was not approved. Please contact us for details.', 'magstack-coaches' ) . '</p></div>';
		}

		wp_enqueue_script( 'magstack-dashboard' );
		wp_localize_script(
			'magstack-dashboard',
			'magstackDash',
			Assets::rest_config(
				array(
					'days'   => Helpers::day_labels(),
					'fee'    => Coach::fee_status( $id ),
					'feeEnd' => Coach::founding_end( $id ) ? Helpers::fmt_date( Coach::founding_end( $id ) ) : '',
					'i18n'   => array(
						'bookings'     => __( 'Bookings', 'magstack-coaches' ),
						'availability' => __( 'Availability', 'magstack-coaches' ),
						'packages'     => __( 'Packages', 'magstack-coaches' ),
						'profile'      => __( 'Profile & link', 'magstack-coaches' ),
						'pending'      => __( 'Pending', 'magstack-coaches' ),
						'confirmed'    => __( 'Confirmed', 'magstack-coaches' ),
						'all'          => __( 'All', 'magstack-coaches' ),
						'accept'       => __( 'Accept', 'magstack-coaches' ),
						'decline'      => __( 'Decline', 'magstack-coaches' ),
						'complete'     => __( 'Mark completed', 'magstack-coaches' ),
						'cancel'       => __( 'Cancel', 'magstack-coaches' ),
						'markPaid'     => __( 'Mark paid', 'magstack-coaches' ),
						'markUnpaid'   => __( 'Mark unpaid', 'magstack-coaches' ),
						'noBookings'   => __( 'No bookings here yet.', 'magstack-coaches' ),
						'save'         => __( 'Save', 'magstack-coaches' ),
						'saved'        => __( 'Saved', 'magstack-coaches' ),
						'error'        => __( 'Something went wrong. Please try again.', 'magstack-coaches' ),
						'addRange'     => __( 'Add hours', 'magstack-coaches' ),
						'closed'       => __( 'Closed', 'magstack-coaches' ),
						'closedDay'    => __( 'Closed all day', 'magstack-coaches' ),
						'weekly'       => __( 'Weekly hours', 'magstack-coaches' ),
						'overrides'    => __( 'Date overrides (days off or special hours)', 'magstack-coaches' ),
						'addOverride'  => __( 'Add override', 'magstack-coaches' ),
						'remove'       => __( 'Remove', 'magstack-coaches' ),
						'copy'         => __( 'Copy link', 'magstack-coaches' ),
						'copied'       => __( 'Copied!', 'magstack-coaches' ),
						'downloadQr'   => __( 'Download QR (PNG)', 'magstack-coaches' ),
						'sessionsLeft' => __( 'sessions left', 'magstack-coaches' ),
						'confirmAct'   => __( 'Are you sure?', 'magstack-coaches' ),
					),
				)
			)
		);
		return Helpers::template( 'dashboard', array( 'coach_id' => $id, 'fee' => Coach::fee_status( $id ) ) );
	}
}
