<?php
namespace Magstack\Coaches\Booking;

use Magstack\Coaches\Assets;
use Magstack\Coaches\Coach\Coach;
use Magstack\Coaches\Facility\Facility;
use Magstack\Coaches\Helpers;
use Magstack\Coaches\Spam;

defined( 'ABSPATH' ) || exit;

/** [magstack_booking coach="" facility=""] – 4-step booking wizard (booking.js) + player booking view. */
final class Shortcode {

	public static function hooks(): void {
		add_shortcode( 'magstack_booking', array( self::class, 'shortcode' ) );
	}

	public static function shortcode( $atts ): string {
		$a = shortcode_atts(
			array(
				'coach'    => 0,
				'facility' => 0,
			),
			$atts,
			'magstack_booking'
		);
		Assets::base();
		nocache_headers();

		// phpcs:disable WordPress.Security.NonceVerification
		$coach    = (int) ( $_GET['coach'] ?? $a['coach'] );
		$facility = (int) ( $_GET['facility'] ?? $a['facility'] );
		$date     = isset( $_GET['date'] ) && Helpers::valid_date( sanitize_text_field( wp_unslash( $_GET['date'] ) ) ) ? sanitize_text_field( wp_unslash( $_GET['date'] ) ) : '';
		$ref      = isset( $_GET['mc_ref'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['mc_ref'] ) ) ) : '';
		$tok      = isset( $_GET['mc_t'] ) ? sanitize_text_field( wp_unslash( $_GET['mc_t'] ) ) : '';
		// phpcs:enable

		if ( ! Coach::is_listable( $coach ) ) {
			$coach = 0;
		}
		if ( ! Facility::is_facility( $facility ) || ! Facility::is_partner( $facility ) ) {
			$facility = 0;
		}

		$partners = array_map( array( Facility::class, 'public_data' ), Facility::query( array( 'partner_only' => true ) ) );
		$user     = wp_get_current_user();

		wp_enqueue_script( 'magstack-booking' );
		if ( $partners ) {
			wp_enqueue_style( 'magstack-leaflet' );
			wp_enqueue_script( 'magstack-map' );
		}
		$site_key = (string) \Magstack\Coaches\Settings::get( 'recaptcha_site', '' );
		if ( $site_key ) {
			wp_enqueue_script( 'magstack-recaptcha' );
		}

		$ts  = time();
		wp_localize_script(
			'magstack-booking',
			'magstackBooking',
			Assets::rest_config(
				array(
					'coach'      => $coach,
					'facility'   => $facility,
					'date'       => $date,
					'ref'        => $ref,
					'token'      => $tok,
					'facilities' => $partners,
					'levels'     => array(
						'beginner'     => __( 'Beginner', 'magstack-coaches' ),
						'intermediate' => __( 'Intermediate', 'magstack-coaches' ),
						'advanced'     => __( 'Advanced', 'magstack-coaches' ),
					),
					'user'       => $user->exists() ? array( 'name' => $user->display_name, 'email' => $user->user_email ) : null,
					'spam'       => array(
						'mc_ts'  => $ts,
						'mc_sig' => wp_hash( 'mc_ts|' . $ts ),
					),
					'directory'  => \Magstack\Coaches\Settings::page_url( 'coaches' ),
					'i18n'       => self::strings(),
				)
			)
		);

		return Helpers::template( 'booking' );
	}

	private static function strings(): array {
		return array(
			'step'         => __( 'Step %1$d of %2$d', 'magstack-coaches' ),
			'stepNames'    => array( __( 'Coach', 'magstack-coaches' ), __( 'Date & time', 'magstack-coaches' ), __( 'Court', 'magstack-coaches' ), __( 'Your details', 'magstack-coaches' ) ),
			'back'         => __( 'Back', 'magstack-coaches' ),
			'next'         => __( 'Continue', 'magstack-coaches' ),
			'chooseCoach'  => __( 'Choose your coach', 'magstack-coaches' ),
			'searchCoach'  => __( 'Search by name or city', 'magstack-coaches' ),
			'noCoaches'    => __( 'No coaches available right now.', 'magstack-coaches' ),
			'perHour'      => __( '/ hour', 'magstack-coaches' ),
			'pickDate'     => __( 'Pick a date', 'magstack-coaches' ),
			'pickTime'     => __( 'Pick a time', 'magstack-coaches' ),
			'duration'     => __( 'Session length', 'magstack-coaches' ),
			'min'          => __( 'min', 'magstack-coaches' ),
			'noDates'      => __( 'No open dates in the next 30 days for this coach.', 'magstack-coaches' ),
			'noSlots'      => __( 'No free times on this date.', 'magstack-coaches' ),
			'loading'      => __( 'Loading…', 'magstack-coaches' ),
			'courtTitle'   => __( 'Where will you play?', 'magstack-coaches' ),
			'modePartner'  => __( 'Partner facility', 'magstack-coaches' ),
			'modePartnerD' => __( 'Choose from our partner courts.', 'magstack-coaches' ),
			'modeVenue'    => __( 'Coach\'s preferred venue', 'magstack-coaches' ),
			'modeVenueD'   => __( 'Play at one of the coach\'s usual venues.', 'magstack-coaches' ),
			'modeCoach'    => __( 'Let the coach pick', 'magstack-coaches' ),
			'modeCoachD'   => __( 'The coach will choose a court and tell you.', 'magstack-coaches' ),
			'partner'      => __( 'Partner', 'magstack-coaches' ),
			'liveSoon'     => __( 'Live court availability coming soon', 'magstack-coaches' ),
			'showMap'      => __( 'Show map', 'magstack-coaches' ),
			'hideMap'      => __( 'Hide map', 'magstack-coaches' ),
			'chooseCourt'  => __( 'Choose this court', 'magstack-coaches' ),
			'courts'       => __( 'courts', 'magstack-coaches' ),
			'noVenues'     => __( 'This coach has not listed venues.', 'magstack-coaches' ),
			'payAtVenue'   => __( 'Pay at venue', 'magstack-coaches' ),
			'yourDetails'  => __( 'Your details', 'magstack-coaches' ),
			'name'         => __( 'Full name', 'magstack-coaches' ),
			'email'        => __( 'Email', 'magstack-coaches' ),
			'mobile'       => __( 'Mobile number', 'magstack-coaches' ),
			'level'        => __( 'Your skill level', 'magstack-coaches' ),
			'notes'        => __( 'Notes for the coach (optional)', 'magstack-coaches' ),
			'single'       => __( 'Single session', 'magstack-coaches' ),
			'sessions'     => __( 'sessions', 'magstack-coaches' ),
			'firstSession' => __( 'This booking is your first session.', 'magstack-coaches' ),
			'havePackage'  => __( 'I have a package code', 'magstack-coaches' ),
			'packageCode'  => __( 'Package code', 'magstack-coaches' ),
			'checkCode'    => __( 'Check code', 'magstack-coaches' ),
			'codeOk'       => __( 'Code accepted. Sessions left:', 'magstack-coaches' ),
			'summary'      => __( 'Price summary', 'magstack-coaches' ),
			'coachFee'     => __( 'Coach fee', 'magstack-coaches' ),
			'coachFeePkg'  => __( 'Coach fee (package)', 'magstack-coaches' ),
			'coachFeePre'  => __( 'Coach fee (prepaid package)', 'magstack-coaches' ),
			'courtFee'     => __( 'Court fee', 'magstack-coaches' ),
			'courtTba'     => __( 'To be advised by coach', 'magstack-coaches' ),
			'total'        => __( 'Total', 'magstack-coaches' ),
			'confirm'      => __( 'Confirm booking', 'magstack-coaches' ),
			'sending'      => __( 'Booking…', 'magstack-coaches' ),
			'required'     => __( 'Please fill in your name, email and mobile number.', 'magstack-coaches' ),
			'error'        => __( 'Something went wrong. Please try again.', 'magstack-coaches' ),
			'taken'        => __( 'That slot was just taken. Please pick another time.', 'magstack-coaches' ),
			'doneTitle'    => __( 'Booking request sent!', 'magstack-coaches' ),
			'doneText'     => __( 'Your coach will confirm shortly. We emailed you the details.', 'magstack-coaches' ),
			'reference'    => __( 'Reference', 'magstack-coaches' ),
			'status'       => __( 'Status', 'magstack-coaches' ),
			'payTitle'     => __( 'How to pay', 'magstack-coaches' ),
			'manage'       => __( 'Manage this booking', 'magstack-coaches' ),
			'coachContact' => __( 'Coach contact', 'magstack-coaches' ),
			'contactHidden'=> __( 'Coach contact details will be shown here once your booking is confirmed.', 'magstack-coaches' ),
			'cancelBook'   => __( 'Cancel booking', 'magstack-coaches' ),
			'cancelSure'   => __( 'Cancel this booking?', 'magstack-coaches' ),
			'cancelClosed' => __( 'Online cancellation is closed for this session. Please contact your coach.', 'magstack-coaches' ),
			'session'      => __( 'Session', 'magstack-coaches' ),
			'date'         => __( 'Date', 'magstack-coaches' ),
			'time'         => __( 'Time', 'magstack-coaches' ),
			'court'        => __( 'Court', 'magstack-coaches' ),
			'coach'        => __( 'Coach', 'magstack-coaches' ),
			'bookAnother'  => __( 'Book another session', 'magstack-coaches' ),
			'pkgLeft'      => __( 'Package sessions left', 'magstack-coaches' ),
			'notFound'     => __( 'Booking not found.', 'magstack-coaches' ),
		);
	}
}
