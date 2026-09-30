<?php
namespace Magstack\Coaches;

defined( 'ABSPATH' ) || exit;

/** Honeypot + timing + rate limit + reCAPTCHA-ready verification. */
final class Spam {

	public static function fields(): string {
		$ts = time();
		$sig = wp_hash( 'mc_ts|' . $ts );
		return '<div class="mc-hp" aria-hidden="true" style="position:absolute;left:-9999px;top:auto;width:1px;height:1px;overflow:hidden">'
			. '<label>' . esc_html__( 'Leave this field empty', 'magstack-coaches' ) . '<input type="text" name="mc_website" value="" tabindex="-1" autocomplete="off"></label></div>'
			. '<input type="hidden" name="mc_ts" value="' . esc_attr( (string) $ts ) . '"><input type="hidden" name="mc_sig" value="' . esc_attr( $sig ) . '">';
	}

	/** @return true|\WP_Error */
	public static function check( array $data, string $bucket = 'form', int $limit = 10 ) {
		$err = new \WP_Error( 'mc_spam', __( 'Submission rejected. Please try again.', 'magstack-coaches' ), array( 'status' => 400 ) );

		if ( ! empty( $data['mc_website'] ) ) {
			return $err;
		}
		$ts  = (int) ( $data['mc_ts'] ?? 0 );
		$sig = (string) ( $data['mc_sig'] ?? '' );
		if ( $ts && $sig ) {
			if ( ! hash_equals( wp_hash( 'mc_ts|' . $ts ), $sig ) ) {
				return $err;
			}
			if ( time() - $ts < (int) Settings::get( 'min_submit_seconds', 3 ) ) {
				return $err;
			}
		}

		$key   = 'mc_rl_' . $bucket . '_' . md5( Helpers::client_ip() );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return new \WP_Error( 'mc_rate', __( 'Too many attempts. Please wait a few minutes.', 'magstack-coaches' ), array( 'status' => 429 ) );
		}
		set_transient( $key, $count + 1, 10 * MINUTE_IN_SECONDS );

		$secret = (string) Settings::get( 'recaptcha_secret', '' );
		if ( $secret && Settings::get( 'recaptcha_site', '' ) ) {
			$token = (string) ( $data['mc_recaptcha'] ?? '' );
			$ok    = $token && self::verify_recaptcha( $token, $secret );
			if ( ! $ok ) {
				return $err;
			}
		}

		/**
		 * Extra spam checks (e.g. hCaptcha/Turnstile). Return WP_Error to reject.
		 *
		 * @param true|\WP_Error $result
		 * @param array          $data
		 */
		return apply_filters( 'magstack_spam_check', true, $data );
	}

	private static function verify_recaptcha( string $token, string $secret ): bool {
		$res = wp_remote_post(
			'https://www.google.com/recaptcha/api/siteverify',
			array(
				'timeout' => 8,
				'body'    => array(
					'secret'   => $secret,
					'response' => $token,
					'remoteip' => Helpers::client_ip(),
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return false;
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		return ! empty( $body['success'] ) && ( ! isset( $body['score'] ) || (float) $body['score'] >= 0.3 );
	}
}
