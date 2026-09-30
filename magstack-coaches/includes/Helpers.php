<?php
namespace Magstack\Coaches;

defined( 'ABSPATH' ) || exit;

final class Helpers {

	public const LEVELS = array( 'beginner', 'intermediate', 'advanced', 'kids', 'group' );
	public const DAYS   = array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' );

	public static function level_labels(): array {
		return array(
			'beginner'     => __( 'Beginner', 'magstack-coaches' ),
			'intermediate' => __( 'Intermediate', 'magstack-coaches' ),
			'advanced'     => __( 'Advanced', 'magstack-coaches' ),
			'kids'         => __( 'Kids', 'magstack-coaches' ),
			'group'        => __( 'Group', 'magstack-coaches' ),
		);
	}

	public static function day_labels(): array {
		return array(
			'mon' => __( 'Monday', 'magstack-coaches' ),
			'tue' => __( 'Tuesday', 'magstack-coaches' ),
			'wed' => __( 'Wednesday', 'magstack-coaches' ),
			'thu' => __( 'Thursday', 'magstack-coaches' ),
			'fri' => __( 'Friday', 'magstack-coaches' ),
			'sat' => __( 'Saturday', 'magstack-coaches' ),
			'sun' => __( 'Sunday', 'magstack-coaches' ),
		);
	}

	public static function status_labels(): array {
		return array(
			'pending'   => __( 'Pending', 'magstack-coaches' ),
			'confirmed' => __( 'Confirmed', 'magstack-coaches' ),
			'declined'  => __( 'Declined', 'magstack-coaches' ),
			'completed' => __( 'Completed', 'magstack-coaches' ),
			'cancelled' => __( 'Cancelled', 'magstack-coaches' ),
		);
	}

	public static function money( $amount ): string {
		$symbol = (string) Settings::get( 'currency_symbol', '₱' );
		return $symbol . number_format_i18n( (float) $amount, ( floor( (float) $amount ) == (float) $amount ) ? 0 : 2 );
	}

	public static function tz(): \DateTimeZone {
		return wp_timezone();
	}

	public static function now(): \DateTimeImmutable {
		return new \DateTimeImmutable( 'now', self::tz() );
	}

	public static function dt( string $date, string $time = '00:00' ): \DateTimeImmutable {
		return new \DateTimeImmutable( $date . ' ' . substr( $time, 0, 5 ), self::tz() );
	}

	public static function fmt_date( string $date ): string {
		return wp_date( get_option( 'date_format' ), self::dt( $date )->getTimestamp() );
	}

	public static function fmt_time( string $time ): string {
		return wp_date( get_option( 'time_format' ), self::dt( '2000-01-01', $time )->getTimestamp() );
	}

	public static function valid_date( string $date ): bool {
		$d = \DateTimeImmutable::createFromFormat( '!Y-m-d', $date );
		return $d && $d->format( 'Y-m-d' ) === $date;
	}

	public static function valid_time( string $time ): bool {
		return (bool) preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $time );
	}

	public static function to_minutes( string $time ): int {
		return (int) substr( $time, 0, 2 ) * 60 + (int) substr( $time, 3, 2 );
	}

	public static function from_minutes( int $m ): string {
		return sprintf( '%02d:%02d', intdiv( $m, 60 ), $m % 60 );
	}

	public static function slugify( string $s ): string {
		return sanitize_title( $s );
	}

	public static function client_ip(): string {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	/** Normalise a Philippine-style mobile number, keeping it lenient for others. */
	public static function clean_phone( string $phone ): string {
		return preg_replace( '/[^0-9+]/', '', $phone ) ?? '';
	}

	/**
	 * Load a template (theme override: {theme}/magstack-coaches/{name}.php).
	 */
	public static function template( string $name, array $vars = array() ): string {
		$file = locate_template( 'magstack-coaches/' . $name . '.php' );
		if ( ! $file ) {
			$file = MAGSTACK_COACHES_DIR . 'templates/' . $name . '.php';
		}
		if ( ! is_readable( $file ) ) {
			return '';
		}
		// phpcs:ignore WordPress.PHP.DontExtract.extract_extract
		extract( $vars, EXTR_SKIP );
		ob_start();
		include $file;
		return (string) ob_get_clean();
	}

	public static function badge_verified(): string {
		return '<span class="mc-badge mc-badge--verified" title="' . esc_attr__( 'Verified coach', 'magstack-coaches' ) . '"><svg aria-hidden="true" width="14" height="14" viewBox="0 0 20 20"><path fill="currentColor" d="M10 0l2.4 2 3.1-.2 1 3 2.7 1.6-.9 3 .9 3-2.7 1.6-1 3-3.1-.2L10 20l-2.4-2-3.1.2-1-3L.8 13.6l.9-3-.9-3L3.5 6l1-3 3.1.2z"/><path fill="#fff" d="M8.8 13.3L5.6 10l1.2-1.2 2 2 4.4-4.4 1.2 1.2z"/></svg> ' . esc_html__( 'Verified', 'magstack-coaches' ) . '</span>';
	}

	public static function stars( float $avg, int $count ): string {
		if ( $count < 1 ) {
			return '<span class="mc-rating mc-rating--new">' . esc_html__( 'New', 'magstack-coaches' ) . '</span>';
		}
		return '<span class="mc-rating" aria-label="' . esc_attr( sprintf( /* translators: %s: rating */ __( 'Rated %s out of 5', 'magstack-coaches' ), number_format_i18n( $avg, 1 ) ) ) . '">★ ' . esc_html( number_format_i18n( $avg, 1 ) ) . ' <small>(' . (int) $count . ')</small></span>';
	}
}
