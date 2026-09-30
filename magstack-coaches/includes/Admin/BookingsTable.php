<?php
namespace Magstack\Coaches\Admin;

use Magstack\Coaches\Booking\Repository;
use Magstack\Coaches\Helpers;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class BookingsTable extends \WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'booking',
				'plural'   => 'bookings',
				'ajax'     => false,
			)
		);
	}

	public static function filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification
		$g = wp_unslash( $_GET );
		// phpcs:enable
		return array(
			'status'         => sanitize_key( $g['status'] ?? '' ),
			'payment_status' => sanitize_key( $g['payment_status'] ?? '' ),
			'coach_id'       => (int) ( $g['coach_id'] ?? 0 ),
			'date_from'      => sanitize_text_field( $g['date_from'] ?? '' ),
			'date_to'        => sanitize_text_field( $g['date_to'] ?? '' ),
			'search'         => sanitize_text_field( $g['s'] ?? '' ),
			'orderby'        => sanitize_key( $g['orderby'] ?? 'booking_date' ),
			'order'          => sanitize_key( $g['order'] ?? 'desc' ),
		);
	}

	public function get_columns(): array {
		return array(
			'reference' => __( 'Reference', 'magstack-coaches' ),
			'when'      => __( 'Session', 'magstack-coaches' ),
			'coach'     => __( 'Coach', 'magstack-coaches' ),
			'player'    => __( 'Player', 'magstack-coaches' ),
			'court'     => __( 'Court', 'magstack-coaches' ),
			'coach_fee' => __( 'Coach fee', 'magstack-coaches' ),
			'court_fee' => __( 'Court fee', 'magstack-coaches' ),
			'total'     => __( 'Total', 'magstack-coaches' ),
			'status'    => __( 'Status', 'magstack-coaches' ),
			'payment'   => __( 'Payment', 'magstack-coaches' ),
		);
	}

	protected function get_sortable_columns(): array {
		return array(
			'when'   => array( 'booking_date', true ),
			'status' => array( 'status', false ),
			'total'  => array( 'total', false ),
			'coach'  => array( 'coach_id', false ),
		);
	}

	public function prepare_items(): void {
		$f     = self::filters();
		$per   = 20;
		$paged = $this->get_pagenum();
		$res   = Repository::query( $f + array( 'per_page' => $per, 'paged' => $paged ) );
		$this->items = $res['rows'];
		$this->set_pagination_args( array( 'total_items' => $res['total'], 'per_page' => $per ) );
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );
	}

	public function column_default( $item, $col ) {
		return '';
	}

	public function column_reference( $b ): string {
		$id      = (int) $b['id'];
		$view    = admin_url( 'admin.php?page=magstack-bookings&booking=' . $id );
		$actions = array( 'view' => '<a href="' . esc_url( $view ) . '">' . esc_html__( 'View / edit', 'magstack-coaches' ) . '</a>' );
		$verbs = array(
			'confirmed' => __( 'Confirm', 'magstack-coaches' ),
			'declined'  => __( 'Decline', 'magstack-coaches' ),
			'completed' => __( 'Complete', 'magstack-coaches' ),
			'cancelled' => __( 'Cancel', 'magstack-coaches' ),
		);
		foreach ( \Magstack\Coaches\Booking\Service::TRANSITIONS[ $b['status'] ] ?? array() as $to ) {
			$actions[ $to ] = '<a href="' . esc_url( BookingsPage::action_url( $id, 'status_' . $to ) ) . '">' . esc_html( $verbs[ $to ] ?? $to ) . '</a>';
		}
		$actions['pay'] = '<a href="' . esc_url( BookingsPage::action_url( $id, 'paid' === $b['payment_status'] ? 'unpaid' : 'paid' ) ) . '">' . esc_html( 'paid' === $b['payment_status'] ? __( 'Mark unpaid', 'magstack-coaches' ) : __( 'Mark paid', 'magstack-coaches' ) ) . '</a>';
		return '<strong><a href="' . esc_url( $view ) . '">' . esc_html( $b['reference'] ) . '</a></strong>' . $this->row_actions( $actions );
	}

	public function column_when( $b ): string {
		return esc_html( Helpers::fmt_date( $b['booking_date'] ) . ' ' . Helpers::fmt_time( $b['start_time'] ) . '–' . Helpers::fmt_time( $b['end_time'] ) );
	}

	public function column_coach( $b ): string {
		return esc_html( get_the_title( (int) $b['coach_id'] ) );
	}

	public function column_player( $b ): string {
		return esc_html( $b['player_name'] ) . '<br><small>' . esc_html( $b['player_email'] . ' · ' . $b['player_mobile'] ) . '</small>';
	}

	public function column_court( $b ): string {
		return esc_html( $b['venue_label'] );
	}

	public function column_coach_fee( $b ): string {
		return esc_html( Helpers::money( $b['coach_fee'] ) );
	}

	public function column_court_fee( $b ): string {
		return (float) $b['court_fee'] > 0 ? esc_html( Helpers::money( $b['court_fee'] ) ) : '<small>' . esc_html( $b['court_fee_note'] ?: '—' ) . '</small>';
	}

	public function column_total( $b ): string {
		return '<strong>' . esc_html( Helpers::money( $b['total'] ) ) . '</strong>';
	}

	public function column_status( $b ): string {
		return esc_html( Helpers::status_labels()[ $b['status'] ] ?? $b['status'] );
	}

	public function column_payment( $b ): string {
		return 'paid' === $b['payment_status'] ? '<span style="color:#166534">' . esc_html__( 'Paid', 'magstack-coaches' ) . '</span>' : '<span style="color:#b45309">' . esc_html__( 'Unpaid', 'magstack-coaches' ) . '</span>';
	}

	public function no_items(): void {
		esc_html_e( 'No bookings found.', 'magstack-coaches' );
	}
}
