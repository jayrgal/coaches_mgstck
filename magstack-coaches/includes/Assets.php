<?php
namespace Magstack\Coaches;

defined( 'ABSPATH' ) || exit;

final class Assets {

	public static function hooks(): void {
		// Register on init (not wp_enqueue_scripts): block themes render post content before wp_head.
		add_action( 'init', array( self::class, 'register' ) );
		add_action( 'wp_head', array( self::class, 'brand_css' ), 5 );
	}

	public static function register(): void {
		$v = MAGSTACK_COACHES_VERSION;
		$u = MAGSTACK_COACHES_URL . 'assets/';

		wp_register_style( 'magstack-coaches', $u . 'css/magstack.css', array(), $v );
		wp_register_style( 'magstack-leaflet', $u . 'vendor/leaflet/leaflet.css', array(), '1.9.4' );
		wp_register_script( 'magstack-leaflet', $u . 'vendor/leaflet/leaflet.js', array(), '1.9.4', true );
		wp_register_script( 'magstack-qrcode', $u . 'vendor/qrcode/qrcode.js', array(), '1.4.4', true );
		wp_register_script( 'magstack-map', $u . 'js/map.js', array( 'magstack-leaflet' ), $v, true );
		wp_register_script( 'magstack-booking', $u . 'js/booking.js', array(), $v, true );
		wp_register_script( 'magstack-dashboard', $u . 'js/dashboard.js', array( 'magstack-qrcode' ), $v, true );

		$site_key = (string) Settings::get( 'recaptcha_site', '' );
		if ( $site_key ) {
			wp_register_script( 'magstack-recaptcha', 'https://www.google.com/recaptcha/api.js?render=' . rawurlencode( $site_key ), array(), null, true ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		}
	}

	/** Enqueue base CSS whenever a plugin shortcode/view renders. */
	public static function base(): void {
		wp_enqueue_style( 'magstack-coaches' );
	}

	public static function brand_css(): void {
		$color = sanitize_hex_color( (string) Settings::get( 'brand_color', '#0f766e' ) ) ?: '#0f766e';
		echo '<style id="magstack-brand">:root{--mc-brand:' . esc_html( $color ) . ';}</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public static function rest_config( array $extra = array() ): array {
		return array_merge(
			array(
				'root'     => esc_url_raw( rest_url( 'magstack/v1/' ) ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'currency' => (string) Settings::get( 'currency_symbol', '₱' ),
				'siteKey'  => (string) Settings::get( 'recaptcha_site', '' ),
				'i18n'     => array(),
			),
			$extra
		);
	}
}
