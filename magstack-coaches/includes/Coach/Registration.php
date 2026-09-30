<?php
namespace Magstack\Coaches\Coach;

use Magstack\Coaches\Assets;
use Magstack\Coaches\Booking\Repository;
use Magstack\Coaches\Facility\Facility;
use Magstack\Coaches\Helpers;
use Magstack\Coaches\Notifications\Notifier;
use Magstack\Coaches\PostTypes;
use Magstack\Coaches\Rest\CoachController;
use Magstack\Coaches\Roles;
use Magstack\Coaches\Settings;
use Magstack\Coaches\Spam;

defined( 'ABSPATH' ) || exit;

/** [magstack_coach_register] – submit -> Pending -> admin approves/rejects. */
final class Registration {

	/** @var array{errors:string[],values:array} */
	private static array $state = array( 'errors' => array(), 'values' => array() );

	public static function hooks(): void {
		add_shortcode( 'magstack_coach_register', array( self::class, 'shortcode' ) );
		add_action( 'template_redirect', array( self::class, 'maybe_handle' ) );
	}

	public static function shortcode(): string {
		Assets::base();
		if ( isset( $_GET['mc_registered'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return '<div class="mc mc-notice mc-notice--ok" role="status"><h2>' . esc_html__( 'Application received!', 'magstack-coaches' ) . '</h2><p>' . esc_html__( 'Thanks for applying. Our team will review your profile and email you once it is approved. You will then be able to log in to your coach dashboard.', 'magstack-coaches' ) . '</p></div>';
		}
		$site_key = (string) Settings::get( 'recaptcha_site', '' );
		if ( $site_key ) {
			wp_enqueue_script( 'magstack-recaptcha' );
		}
		return Helpers::template(
			'registration-form',
			array(
				'errors'     => self::$state['errors'],
				'v'          => self::$state['values'],
				'facilities' => array_map( array( Facility::class, 'public_data' ), Facility::query( array( 'partner_only' => true ) ) ),
				'site_key'   => $site_key,
			)
		);
	}

	public static function maybe_handle(): void {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || empty( $_POST['mc_register'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( ! isset( $_POST['_mc_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_mc_nonce'] ) ), 'mc_register' ) ) {
			self::$state['errors'][] = __( 'Your session expired. Please reload the page and try again.', 'magstack-coaches' );
			return;
		}
		$post = wp_unslash( $_POST );
		self::$state['values'] = $post;

		$spam = Spam::check( $post, 'register', 5 );
		if ( is_wp_error( $spam ) ) {
			self::$state['errors'][] = $spam->get_error_message();
			return;
		}

		$id = self::create( $post, $_FILES['mc_photo'] ?? array() );
		if ( is_wp_error( $id ) ) {
			self::$state['errors'] = array_merge( self::$state['errors'], $id->get_error_messages() );
			return;
		}
		wp_safe_redirect( add_query_arg( 'mc_registered', '1', remove_query_arg( 'mc_registered', Settings::page_url( 'register' ) ) ) );
		exit;
	}

	/**
	 * Validate and create a pending coach application.
	 *
	 * @return int|\WP_Error Coach (post) ID.
	 */
	public static function create( array $d, array $file ) {
		$err = new \WP_Error();

		$name   = mb_substr( sanitize_text_field( $d['name'] ?? '' ), 0, 120 );
		$email  = sanitize_email( $d['email'] ?? '' );
		$mobile = mb_substr( Helpers::clean_phone( $d['mobile'] ?? '' ), 0, 40 );
		$bio    = mb_substr( sanitize_textarea_field( $d['bio'] ?? '' ), 0, 1200 );
		$rate   = round( (float) ( $d['rate'] ?? 0 ), 2 );
		$city   = mb_substr( sanitize_text_field( $d['city'] ?? '' ), 0, 80 );
		$levels = array_values( array_intersect( (array) ( $d['levels'] ?? array() ), Helpers::LEVELS ) );

		if ( '' === $name ) {
			$err->add( 'name', __( 'Please enter your full name.', 'magstack-coaches' ) );
		}
		if ( ! is_email( $email ) ) {
			$err->add( 'email', __( 'Please enter a valid email address.', 'magstack-coaches' ) );
		}
		if ( strlen( preg_replace( '/\D/', '', $mobile ) ) < 7 ) {
			$err->add( 'mobile', __( 'Please enter a valid mobile number.', 'magstack-coaches' ) );
		}
		if ( '' === $bio ) {
			$err->add( 'bio', __( 'Please add a short bio.', 'magstack-coaches' ) );
		}
		if ( ! $levels ) {
			$err->add( 'levels', __( 'Select at least one coaching type.', 'magstack-coaches' ) );
		}
		if ( $rate <= 0 ) {
			$err->add( 'rate', __( 'Please enter your session rate.', 'magstack-coaches' ) );
		}
		if ( '' === $city ) {
			$err->add( 'city', __( 'Please enter your service area (city).', 'magstack-coaches' ) );
		}
		if ( empty( $d['terms'] ) ) {
			$err->add( 'terms', __( 'Please accept the terms.', 'magstack-coaches' ) );
		}

		$user_id = get_current_user_id();
		if ( $user_id ) {
			if ( Coach::for_user( $user_id ) ) {
				$err->add( 'dup', __( 'You already have a coach application or profile.', 'magstack-coaches' ) );
			}
		} elseif ( $email && email_exists( $email ) ) {
			$err->add( 'dup', __( 'An account with this email already exists. Please log in first, then submit this form.', 'magstack-coaches' ) );
		}

		$has_photo = ! empty( $file['name'] ) && UPLOAD_ERR_NO_FILE !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
		if ( $has_photo ) {
			$check = wp_check_filetype_and_ext( (string) $file['tmp_name'], (string) $file['name'], array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ) );
			if ( empty( $check['type'] ) ) {
				$err->add( 'photo', __( 'Photo must be a JPG, PNG or WebP image.', 'magstack-coaches' ) );
			} elseif ( (int) $file['size'] > 5 * MB_IN_BYTES ) {
				$err->add( 'photo', __( 'Photo must be smaller than 5MB.', 'magstack-coaches' ) );
			}
		}
		if ( $err->has_errors() ) {
			return $err;
		}

		if ( ! $user_id ) {
			$login = sanitize_user( strstr( $email, '@', true ), true ) ?: 'coach';
			$base  = $login;
			$i     = 1;
			while ( username_exists( $login ) ) {
				$login = $base . ( ++$i );
			}
			$user_id = wp_insert_user(
				array(
					'user_login'   => $login,
					'user_pass'    => wp_generate_password( 24 ),
					'user_email'   => $email,
					'display_name' => $name,
					'first_name'   => $name,
					'role'         => Roles::COACH,
				)
			);
			if ( is_wp_error( $user_id ) ) {
				return $user_id;
			}
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => PostTypes::COACH,
				'post_status' => 'pending',
				'post_title'  => $name,
				'post_author' => $user_id,
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		$fac_ids = array();
		foreach ( (array) ( $d['venue_facilities'] ?? array() ) as $fid ) {
			if ( Facility::is_facility( (int) $fid ) ) {
				$fac_ids[] = (int) $fid;
			}
		}
		$socials = array();
		foreach ( array( 'facebook', 'instagram', 'tiktok', 'youtube', 'website' ) as $net ) {
			$u = esc_url_raw( trim( (string) ( $d[ 'social_' . $net ] ?? '' ) ), array( 'http', 'https' ) );
			if ( $u ) {
				$socials[ $net ] = $u;
			}
		}
		$dupr = trim( (string) ( $d['dupr'] ?? '' ) );
		$meta = array(
			'status'           => 'pending',
			'tier'             => 'free',
			'verified'         => 0,
			'email'            => $email,
			'mobile'           => $mobile,
			'bio'              => $bio,
			'dupr'             => preg_match( '/^\d(\.\d{1,3})?$/', $dupr ) ? $dupr : '',
			'certifications'   => mb_substr( sanitize_textarea_field( $d['certifications'] ?? '' ), 0, 600 ),
			'years'            => max( 0, min( 60, (int) ( $d['years'] ?? 0 ) ) ),
			'levels'           => $levels,
			'rate'             => max( 0, $rate ),
			'durations'        => CoachController::parse_durations( $d['durations'] ?? '60' ),
			'city'             => $city,
			'venue_facilities' => $fac_ids,
			'venues'           => self::parse_venues( (string) ( $d['venues'] ?? '' ), $fac_ids ),
			'socials'          => $socials,
			'availability'     => Availability::sanitize( array() ),
		);
		foreach ( $meta as $k => $v ) {
			update_post_meta( $post_id, '_mc_' . $k, $v );
		}

		// Packages from the form (repeater rows).
		$rows = (array) ( $d['pkg'] ?? array() );
		foreach ( array_slice( $rows, 0, 6 ) as $row ) {
			if ( is_array( $row ) && (int) ( $row['sessions'] ?? 0 ) >= 2 && (float) ( $row['price'] ?? 0 ) > 0 ) {
				Repository::save_package( $post_id, $row );
			}
		}

		if ( $has_photo ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			$_FILES['mc_photo_upload'] = $file;
			$att = media_handle_upload( 'mc_photo_upload', $post_id, array(), array( 'test_form' => false, 'mimes' => array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ) ) );
			if ( ! is_wp_error( $att ) ) {
				set_post_thumbnail( $post_id, $att );
			}
		}

		Coach::clear_caches();
		Notifier::coach_event( 'coach_registered', $post_id );
		do_action( 'magstack_coach_registered', $post_id, $user_id );
		return $post_id;
	}

	/**
	 * Venues = selected partner facilities + free-text lines ("Name | Address").
	 */
	public static function parse_venues( string $text, array $facility_ids = array() ): array {
		$venues = array();
		foreach ( $facility_ids as $fid ) {
			if ( Facility::is_facility( (int) $fid ) ) {
				$f        = Facility::public_data( (int) $fid );
				$venues[] = array(
					'name'        => $f['name'],
					'address'     => $f['address'],
					'facility_id' => (int) $fid,
				);
			}
		}
		foreach ( array_slice( preg_split( '/\r\n|\r|\n/', $text ) ?: array(), 0, 10 ) as $line ) {
			$line = trim( sanitize_text_field( $line ) );
			if ( '' === $line ) {
				continue;
			}
			$parts    = array_map( 'trim', explode( '|', $line, 2 ) );
			$venues[] = array(
				'name'        => mb_substr( $parts[0], 0, 120 ),
				'address'     => mb_substr( $parts[1] ?? '', 0, 200 ),
				'facility_id' => 0,
			);
		}
		return $venues;
	}
}
