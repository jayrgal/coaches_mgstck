<?php
namespace Magstack\Coaches\Booking;

use Magstack\Coaches\Database;
use Magstack\Coaches\Helpers;

defined( 'ABSPATH' ) || exit;

/** Data access for bookings, packages and package purchases (all queries prepared). */
final class Repository {

	public const ACTIVE = array( 'pending', 'confirmed', 'completed' );

	public static function get( int $id ): ?array {
		global $wpdb;
		$t   = Database::bookings();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ?: null;
	}

	public static function by_reference( string $ref ): ?array {
		global $wpdb;
		$t   = Database::bookings();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE reference = %s", $ref ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ?: null;
	}

	public static function update( int $id, array $data ): bool {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql' );
		return false !== $wpdb->update( Database::bookings(), $data, array( 'id' => $id ) );
	}

	/**
	 * Active booked intervals per date: [date => [[startMin,endMin],...]]
	 */
	public static function booked_intervals( int $coach_id, string $from, string $to ): array {
		global $wpdb;
		$t    = Database::bookings();
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT booking_date, start_time, end_time FROM {$t} WHERE coach_id = %d AND booking_date BETWEEN %s AND %s AND status IN ('pending','confirmed','completed')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$coach_id,
				$from,
				$to
			),
			ARRAY_A
		);
		$out = array();
		foreach ( $rows as $r ) {
			$out[ $r['booking_date'] ][] = array( Helpers::to_minutes( $r['start_time'] ), Helpers::to_minutes( $r['end_time'] ) );
		}
		return $out;
	}

	/**
	 * Filtered list.
	 *
	 * @param array $a coach_id, status, payment_status, date_from, date_to, search, orderby, order, per_page, paged
	 * @return array{rows:array,total:int}
	 */
	public static function query( array $a = array() ): array {
		global $wpdb;
		$t     = Database::bookings();
		$where = array( '1=1' );
		$args  = array();
		if ( ! empty( $a['coach_id'] ) ) {
			$where[] = 'coach_id = %d';
			$args[]  = (int) $a['coach_id'];
		}
		if ( ! empty( $a['status'] ) && array_key_exists( $a['status'], Helpers::status_labels() ) ) {
			$where[] = 'status = %s';
			$args[]  = $a['status'];
		}
		if ( ! empty( $a['payment_status'] ) && in_array( $a['payment_status'], array( 'paid', 'unpaid' ), true ) ) {
			$where[] = 'payment_status = %s';
			$args[]  = $a['payment_status'];
		}
		if ( ! empty( $a['date_from'] ) && Helpers::valid_date( $a['date_from'] ) ) {
			$where[] = 'booking_date >= %s';
			$args[]  = $a['date_from'];
		}
		if ( ! empty( $a['date_to'] ) && Helpers::valid_date( $a['date_to'] ) ) {
			$where[] = 'booking_date <= %s';
			$args[]  = $a['date_to'];
		}
		if ( ! empty( $a['search'] ) ) {
			$like    = '%' . $wpdb->esc_like( (string) $a['search'] ) . '%';
			$where[] = '(reference LIKE %s OR player_name LIKE %s OR player_email LIKE %s OR player_mobile LIKE %s)';
			array_push( $args, $like, $like, $like, $like );
		}
		$whereSql = implode( ' AND ', $where );

		$cols    = array( 'id', 'booking_date', 'created_at', 'status', 'coach_id', 'total', 'player_name' );
		$orderby = in_array( $a['orderby'] ?? '', $cols, true ) ? $a['orderby'] : 'booking_date';
		$order   = 'ASC' === strtoupper( (string) ( $a['order'] ?? 'DESC' ) ) ? 'ASC' : 'DESC';
		$per     = isset( $a['per_page'] ) ? max( 1, min( 500, (int) $a['per_page'] ) ) : 20;
		$paged   = max( 1, (int) ( $a['paged'] ?? 1 ) );
		$offset  = ( $paged - 1 ) * $per;
		$extra   = 'booking_date' === $orderby ? ', start_time ' . $order : '';

		$count_sql = "SELECT COUNT(*) FROM {$t} WHERE {$whereSql}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total     = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) : $wpdb->get_var( $count_sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$sql  = "SELECT * FROM {$t} WHERE {$whereSql} ORDER BY {$orderby} {$order}{$extra}, id DESC LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $args, array( $per, $offset ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		return array(
			'rows'  => $rows ?: array(),
			'total' => $total,
		);
	}

	// ---- Packages ---------------------------------------------------------------------------

	public static function packages( int $coach_id, bool $only_active = true ): array {
		global $wpdb;
		$t   = Database::packages();
		$sql = "SELECT * FROM {$t} WHERE coach_id = %d" . ( $only_active ? ' AND active = 1' : '' ) . ' ORDER BY sessions ASC, id ASC';
		return $wpdb->get_results( $wpdb->prepare( $sql, $coach_id ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	public static function package( int $id ): ?array {
		global $wpdb;
		$t   = Database::packages();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ?: null;
	}

	public static function save_package( int $coach_id, array $d, int $id = 0 ): int {
		global $wpdb;
		$row = array(
			'coach_id'      => $coach_id,
			'name'          => mb_substr( sanitize_text_field( (string) ( $d['name'] ?? '' ) ), 0, 190 ),
			'sessions'      => max( 2, min( 100, (int) ( $d['sessions'] ?? 0 ) ) ),
			'price'         => max( 0, round( (float) ( $d['price'] ?? 0 ), 2 ) ),
			'validity_days' => max( 0, min( 730, (int) ( $d['validity_days'] ?? 0 ) ) ),
			'active'        => isset( $d['active'] ) ? (int) (bool) $d['active'] : 1,
		);
		if ( '' === $row['name'] ) {
			$row['name'] = sprintf( /* translators: %d: sessions */ __( '%d-session package', 'magstack-coaches' ), $row['sessions'] );
		}
		if ( $id ) {
			$existing = self::package( $id );
			if ( ! $existing || (int) $existing['coach_id'] !== $coach_id ) {
				return 0;
			}
			$wpdb->update( Database::packages(), $row, array( 'id' => $id ) );
			return $id;
		}
		$row['created_at'] = current_time( 'mysql' );
		$wpdb->insert( Database::packages(), $row );
		return (int) $wpdb->insert_id;
	}

	public static function delete_package( int $coach_id, int $id ): bool {
		global $wpdb;
		$existing = self::package( $id );
		if ( ! $existing || (int) $existing['coach_id'] !== $coach_id ) {
			return false;
		}
		// Keep history for purchases: deactivate instead of deleting when purchases exist.
		$pt = Database::purchases();
		$n  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$pt} WHERE package_id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $n ) {
			return false !== $wpdb->update( Database::packages(), array( 'active' => 0 ), array( 'id' => $id ) );
		}
		return false !== $wpdb->delete( Database::packages(), array( 'id' => $id ) );
	}

	// ---- Purchases --------------------------------------------------------------------------

	public static function purchase( int $id ): ?array {
		global $wpdb;
		$t   = Database::purchases();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::with_remaining( $row ) : null;
	}

	public static function purchase_by_code( string $code ): ?array {
		global $wpdb;
		$t   = Database::purchases();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE code = %s", strtoupper( trim( $code ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? self::with_remaining( $row ) : null;
	}

	private static function with_remaining( array $row ): array {
		$row['sessions_remaining'] = max( 0, (int) $row['sessions_total'] - (int) $row['sessions_used'] );
		return $row;
	}

	/** Recompute sessions_used from active bookings (pending/confirmed/completed). */
	public static function recalc_purchase( int $purchase_id ): void {
		global $wpdb;
		if ( $purchase_id < 1 ) {
			return;
		}
		$b    = Database::bookings();
		$used = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$b} WHERE purchase_id = %d AND status IN ('pending','confirmed','completed')", $purchase_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->update( Database::purchases(), array( 'sessions_used' => $used ), array( 'id' => $purchase_id ) );
	}

	public static function purchases_for_coach( int $coach_id ): array {
		global $wpdb;
		$t    = Database::purchases();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE coach_id = %d ORDER BY id DESC LIMIT 100", $coach_id ), ARRAY_A ) ?: array(); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map( array( self::class, 'with_remaining' ), $rows );
	}
}
