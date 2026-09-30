<?php
namespace Magstack\Coaches;

defined( 'ABSPATH' ) || exit;

final class Database {

	public static function bookings(): string {
		global $wpdb;
		return $wpdb->prefix . 'mc_bookings';
	}

	public static function packages(): string {
		global $wpdb;
		return $wpdb->prefix . 'mc_packages';
	}

	public static function purchases(): string {
		global $wpdb;
		return $wpdb->prefix . 'mc_package_purchases';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$b       = self::bookings();
		$p       = self::packages();
		$pp      = self::purchases();

		// slot_key is UNIQUE and only populated for active (pending/confirmed/completed) bookings.
		// It is the last-line-of-defence against double booking; overlap checks run under a named lock.
		dbDelta(
			"CREATE TABLE {$b} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			reference VARCHAR(16) NOT NULL,
			token VARCHAR(40) NOT NULL,
			coach_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			player_name VARCHAR(190) NOT NULL,
			player_email VARCHAR(190) NOT NULL,
			player_mobile VARCHAR(40) NOT NULL DEFAULT '',
			player_level VARCHAR(20) NOT NULL DEFAULT '',
			notes TEXT NULL,
			booking_date DATE NOT NULL,
			start_time TIME NOT NULL,
			end_time TIME NOT NULL,
			duration SMALLINT UNSIGNED NOT NULL DEFAULT 60,
			court_mode VARCHAR(20) NOT NULL DEFAULT 'coach_picks',
			facility_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			venue_label VARCHAR(255) NOT NULL DEFAULT '',
			coach_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
			court_fee DECIMAL(10,2) NOT NULL DEFAULT 0,
			court_fee_note VARCHAR(255) NOT NULL DEFAULT '',
			total DECIMAL(10,2) NOT NULL DEFAULT 0,
			currency VARCHAR(8) NOT NULL DEFAULT 'PHP',
			commission_rate DECIMAL(5,2) NOT NULL DEFAULT 0,
			commission_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
			package_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			purchase_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid',
			gateway VARCHAR(30) NOT NULL DEFAULT 'manual',
			admin_note TEXT NULL,
			slot_key VARCHAR(64) NULL DEFAULT NULL,
			reminded_24 TINYINT(1) NOT NULL DEFAULT 0,
			reminded_2 TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY reference (reference),
			UNIQUE KEY slot_key (slot_key),
			KEY coach_date (coach_id,booking_date),
			KEY coach_id (coach_id),
			KEY booking_date (booking_date),
			KEY status (status),
			KEY player_email (player_email),
			KEY purchase_id (purchase_id)
		) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$p} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			coach_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(190) NOT NULL,
			sessions SMALLINT UNSIGNED NOT NULL DEFAULT 5,
			price DECIMAL(10,2) NOT NULL DEFAULT 0,
			validity_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY coach_id (coach_id)
		) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$pp} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			code VARCHAR(16) NOT NULL,
			package_id BIGINT UNSIGNED NOT NULL,
			coach_id BIGINT UNSIGNED NOT NULL,
			player_name VARCHAR(190) NOT NULL,
			player_email VARCHAR(190) NOT NULL,
			sessions_total SMALLINT UNSIGNED NOT NULL,
			sessions_used SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			price DECIMAL(10,2) NOT NULL DEFAULT 0,
			payment_status VARCHAR(20) NOT NULL DEFAULT 'unpaid',
			expires_on DATE NULL DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code),
			KEY coach_id (coach_id),
			KEY player_email (player_email)
		) {$charset};"
		);

		update_option( 'magstack_coaches_db_version', MAGSTACK_COACHES_DB_VERSION );
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'magstack_coaches_db_version' ) !== MAGSTACK_COACHES_DB_VERSION ) {
			self::install();
		}
	}

	public static function drop(): void {
		global $wpdb;
		foreach ( array( self::bookings(), self::packages(), self::purchases() ) as $t ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$t}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		delete_option( 'magstack_coaches_db_version' );
	}
}
