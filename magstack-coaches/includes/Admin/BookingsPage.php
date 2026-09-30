<?php
namespace Magstack\Coaches\Admin;

use Magstack\Coaches\Booking\Repository;
use Magstack\Coaches\Booking\Service;
use Magstack\Coaches\Helpers;
use Magstack\Coaches\Payments\Registry;
use Magstack\Coaches\PostTypes;

defined( 'ABSPATH' ) || exit;

final class BookingsPage {

	public static function hooks(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 20 );
		add_action( 'admin_post_magstack_booking_action', array( self::class, 'handle_action' ) );
		add_action( 'admin_post_magstack_booking_save', array( self::class, 'handle_save' ) );
		add_action( 'admin_post_magstack_export_bookings', array( self::class, 'export_csv' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'magstack-coaches', __( 'Bookings', 'magstack-coaches' ), __( 'Bookings', 'magstack-coaches' ), Menu::CAP, 'magstack-bookings', array( self::class, 'render' ) );
	}

	public static function action_url( int $id, string $action ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=magstack_booking_action&do=' . $action . '&booking=' . $id ), 'mc_booking_' . $action . '_' . $id );
	}

	public static function handle_action(): void {
		$id = (int) ( $_GET['booking'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$do = sanitize_key( $_GET['do'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'magstack-coaches' ), 403 );
		}
		check_admin_referer( 'mc_booking_' . $do . '_' . $id );
		$res = true;
		if ( 0 === strpos( $do, 'status_' ) ) {
			$res = Service::set_status( $id, substr( $do, 7 ) );
		} elseif ( in_array( $do, array( 'paid', 'unpaid' ), true ) ) {
			$b = Repository::get( $id );
			if ( $b && 'paid' === $do ) {
				( Registry::get( (string) $b['gateway'] ) ?? Registry::active() )->mark_paid( $id, array( 'by' => get_current_user_id() ) );
			} else {
				Service::set_payment_status( $id, 'unpaid' );
			}
		}
		$args = is_wp_error( $res ) ? array( 'mc_error' => rawurlencode( $res->get_error_message() ) ) : array( 'mc_notice' => rawurlencode( __( 'Booking updated.', 'magstack-coaches' ) ) );
		wp_safe_redirect( add_query_arg( $args, wp_get_referer() ?: admin_url( 'admin.php?page=magstack-bookings' ) ) );
		exit;
	}

	public static function handle_save(): void {
		$id = (int) ( $_POST['booking'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'magstack-coaches' ), 403 );
		}
		check_admin_referer( 'mc_booking_save_' . $id );
		$b = Repository::get( $id );
		if ( ! $b ) {
			wp_die( esc_html__( 'Booking not found.', 'magstack-coaches' ), 404 );
		}
		$p      = wp_unslash( $_POST );
		$fee    = max( 0, round( (float) ( $p['court_fee'] ?? 0 ), 2 ) );
		$note   = sanitize_text_field( $p['court_fee_note'] ?? '' );
		$coach  = max( 0, round( (float) ( $p['coach_fee'] ?? $b['coach_fee'] ), 2 ) );
		Repository::update(
			$id,
			array(
				'court_fee'      => $fee,
				'court_fee_note' => $note,
				'coach_fee'      => $coach,
				'total'          => round( $coach + $fee, 2 ),
				'admin_note'     => sanitize_textarea_field( $p['admin_note'] ?? '' ),
			)
		);
		$res = true;
		$new = sanitize_key( $p['status'] ?? $b['status'] );
		if ( $new !== $b['status'] ) {
			$res = Service::set_status( $id, $new, true ); // Admin may override the transition table.
		}
		$pay = sanitize_key( $p['payment_status'] ?? $b['payment_status'] );
		if ( $pay !== $b['payment_status'] ) {
			Service::set_payment_status( $id, $pay );
		}
		$args = is_wp_error( $res ) ? array( 'mc_error' => rawurlencode( $res->get_error_message() ) ) : array( 'mc_notice' => rawurlencode( __( 'Booking saved.', 'magstack-coaches' ) ) );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=magstack-bookings&booking=' . $id ) ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			return;
		}
		if ( ! empty( $_GET['booking'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			self::render_detail( (int) $_GET['booking'] ); // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$table = new BookingsTable();
		$table->prepare_items();
		$f = BookingsTable::filters();
		$coaches = get_posts( array( 'post_type' => PostTypes::COACH, 'post_status' => array( 'publish', 'pending', 'draft' ), 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		$export = wp_nonce_url( add_query_arg( array_filter( $f ) + array( 'action' => 'magstack_export_bookings' ), admin_url( 'admin-post.php' ) ), 'mc_export' );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Bookings', 'magstack-coaches' ); ?></h1>
			<a href="<?php echo esc_url( $export ); ?>" class="page-title-action"><?php esc_html_e( 'Export CSV', 'magstack-coaches' ); ?></a>
			<hr class="wp-header-end">
			<form method="get">
				<input type="hidden" name="page" value="magstack-bookings">
				<p class="search-box" style="float:none;margin:10px 0;display:flex;gap:6px;flex-wrap:wrap;align-items:center">
					<select name="status"><option value=""><?php esc_html_e( 'All statuses', 'magstack-coaches' ); ?></option>
						<?php foreach ( Helpers::status_labels() as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f['status'], $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select>
					<select name="payment_status"><option value=""><?php esc_html_e( 'Any payment', 'magstack-coaches' ); ?></option>
						<option value="paid" <?php selected( $f['payment_status'], 'paid' ); ?>><?php esc_html_e( 'Paid', 'magstack-coaches' ); ?></option>
						<option value="unpaid" <?php selected( $f['payment_status'], 'unpaid' ); ?>><?php esc_html_e( 'Unpaid', 'magstack-coaches' ); ?></option></select>
					<select name="coach_id"><option value="0"><?php esc_html_e( 'All coaches', 'magstack-coaches' ); ?></option>
						<?php foreach ( $coaches as $c ) : ?><option value="<?php echo (int) $c->ID; ?>" <?php selected( $f['coach_id'], $c->ID ); ?>><?php echo esc_html( $c->post_title ); ?></option><?php endforeach; ?></select>
					<label><?php esc_html_e( 'From', 'magstack-coaches' ); ?> <input type="date" name="date_from" value="<?php echo esc_attr( $f['date_from'] ); ?>"></label>
					<label><?php esc_html_e( 'To', 'magstack-coaches' ); ?> <input type="date" name="date_to" value="<?php echo esc_attr( $f['date_to'] ); ?>"></label>
					<input type="search" name="s" value="<?php echo esc_attr( $f['search'] ); ?>" placeholder="<?php esc_attr_e( 'Reference, name, email, mobile', 'magstack-coaches' ); ?>">
					<button class="button"><?php esc_html_e( 'Filter', 'magstack-coaches' ); ?></button>
					<a class="button-link" href="<?php echo esc_url( admin_url( 'admin.php?page=magstack-bookings' ) ); ?>"><?php esc_html_e( 'Reset', 'magstack-coaches' ); ?></a>
				</p>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	private static function render_detail( int $id ): void {
		$b = Repository::get( $id );
		if ( ! $b ) {
			echo '<div class="wrap"><p>' . esc_html__( 'Booking not found.', 'magstack-coaches' ) . '</p></div>';
			return;
		}
		$purchase = (int) $b['purchase_id'] ? Repository::purchase( (int) $b['purchase_id'] ) : null;
		?>
		<div class="wrap">
			<h1><?php echo esc_html( sprintf( /* translators: %s: reference */ __( 'Booking %s', 'magstack-coaches' ), $b['reference'] ) ); ?></h1>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=magstack-bookings' ) ); ?>">&larr; <?php esc_html_e( 'All bookings', 'magstack-coaches' ); ?></a></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="magstack_booking_save">
				<input type="hidden" name="booking" value="<?php echo (int) $id; ?>">
				<?php wp_nonce_field( 'mc_booking_save_' . $id ); ?>
				<table class="form-table" role="presentation">
					<tr><th><?php esc_html_e( 'Coach', 'magstack-coaches' ); ?></th><td><?php echo esc_html( get_the_title( (int) $b['coach_id'] ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Session', 'magstack-coaches' ); ?></th><td><?php echo esc_html( Helpers::fmt_date( $b['booking_date'] ) . ', ' . Helpers::fmt_time( $b['start_time'] ) . ' – ' . Helpers::fmt_time( $b['end_time'] ) . ' (' . $b['duration'] . ' min)' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Player', 'magstack-coaches' ); ?></th><td><?php echo esc_html( $b['player_name'] . ' · ' . $b['player_email'] . ' · ' . $b['player_mobile'] . ( $b['player_level'] ? ' · ' . $b['player_level'] : '' ) ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Player notes', 'magstack-coaches' ); ?></th><td><?php echo esc_html( (string) $b['notes'] ?: '—' ); ?></td></tr>
					<tr><th><?php esc_html_e( 'Court mode', 'magstack-coaches' ); ?></th><td><?php echo esc_html( $b['court_mode'] . ' – ' . $b['venue_label'] ); ?></td></tr>
					<?php if ( $purchase ) : ?><tr><th><?php esc_html_e( 'Package', 'magstack-coaches' ); ?></th><td><?php echo esc_html( sprintf( '%s: %d/%d sessions left', $purchase['code'], $purchase['sessions_remaining'], $purchase['sessions_total'] ) ); ?></td></tr><?php endif; ?>
					<tr><th><label for="coach_fee"><?php esc_html_e( 'Coach fee', 'magstack-coaches' ); ?></label></th><td><input type="number" step="0.01" min="0" id="coach_fee" name="coach_fee" value="<?php echo esc_attr( $b['coach_fee'] ); ?>"></td></tr>
					<tr><th><label for="court_fee"><?php esc_html_e( 'Court fee', 'magstack-coaches' ); ?></label></th><td><input type="number" step="0.01" min="0" id="court_fee" name="court_fee" value="<?php echo esc_attr( $b['court_fee'] ); ?>"> <input type="text" class="regular-text" name="court_fee_note" value="<?php echo esc_attr( $b['court_fee_note'] ); ?>" aria-label="<?php esc_attr_e( 'Court fee note', 'magstack-coaches' ); ?>"></td></tr>
					<tr><th><?php esc_html_e( 'Total', 'magstack-coaches' ); ?></th><td><strong><?php echo esc_html( Helpers::money( $b['total'] ) ); ?></strong> <span class="description"><?php esc_html_e( '(recalculated on save)', 'magstack-coaches' ); ?></span></td></tr>
					<tr><th><label for="status"><?php esc_html_e( 'Status', 'magstack-coaches' ); ?></label></th><td><select name="status" id="status"><?php foreach ( Helpers::status_labels() as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $b['status'], $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select></td></tr>
					<tr><th><label for="payment_status"><?php esc_html_e( 'Payment', 'magstack-coaches' ); ?></label></th><td><select name="payment_status" id="payment_status"><option value="unpaid" <?php selected( $b['payment_status'], 'unpaid' ); ?>><?php esc_html_e( 'Unpaid', 'magstack-coaches' ); ?></option><option value="paid" <?php selected( $b['payment_status'], 'paid' ); ?>><?php esc_html_e( 'Paid', 'magstack-coaches' ); ?></option></select></td></tr>
					<tr><th><label for="admin_note"><?php esc_html_e( 'Admin note', 'magstack-coaches' ); ?></label></th><td><textarea class="large-text" rows="3" id="admin_note" name="admin_note"><?php echo esc_textarea( (string) $b['admin_note'] ); ?></textarea></td></tr>
				</table>
				<?php submit_button( __( 'Save booking', 'magstack-coaches' ) ); ?>
			</form>
		</div>
		<?php
	}

	public static function export_csv(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'magstack-coaches' ), 403 );
		}
		check_admin_referer( 'mc_export' );
		$f   = BookingsTable::filters();
		$res = Repository::query( $f + array( 'per_page' => 500, 'paged' => 1 ) );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=magstack-bookings-' . gmdate( 'Ymd-His' ) . '.csv' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $out, array( 'Reference', 'Date', 'Start', 'End', 'Coach', 'Player', 'Email', 'Mobile', 'Level', 'Court mode', 'Court', 'Coach fee', 'Court fee', 'Court fee note', 'Total', 'Currency', 'Status', 'Payment', 'Package code', 'Created' ), ',', '"', '' );
		$page = 1;
		do {
			$rows = 1 === $page ? $res['rows'] : Repository::query( $f + array( 'per_page' => 500, 'paged' => $page ) )['rows'];
			foreach ( $rows as $b ) {
				$p = (int) $b['purchase_id'] ? Repository::purchase( (int) $b['purchase_id'] ) : null;
				fputcsv(
					$out,
					array_map(
						array( self::class, 'csv_safe' ),
						array( $b['reference'], $b['booking_date'], $b['start_time'], $b['end_time'], get_the_title( (int) $b['coach_id'] ), $b['player_name'], $b['player_email'], $b['player_mobile'], $b['player_level'], $b['court_mode'], $b['venue_label'], $b['coach_fee'], $b['court_fee'], $b['court_fee_note'], $b['total'], $b['currency'], $b['status'], $b['payment_status'], $p['code'] ?? '', $b['created_at'] )
					),
					',',
					'"',
					''
				);
			}
			++$page;
		} while ( count( $rows ) === 500 && $page < 100 );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/** Neutralise spreadsheet formula injection. */
	public static function csv_safe( $v ): string {
		$v = (string) $v;
		return ( '' !== $v && in_array( $v[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) ) ? "'" . $v : $v;
	}
}
