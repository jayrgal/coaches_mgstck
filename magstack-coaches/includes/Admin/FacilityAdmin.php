<?php
namespace Magstack\Coaches\Admin;

use Magstack\Coaches\Facility\Facility;
use Magstack\Coaches\Helpers;
use Magstack\Coaches\PostTypes;

defined( 'ABSPATH' ) || exit;

/** wp-admin: Facility CPT fields, columns and Partner toggle (admin curated). */
final class FacilityAdmin {

	public static function hooks(): void {
		$pt = PostTypes::FACILITY;
		add_action( 'add_meta_boxes', array( self::class, 'metaboxes' ) );
		add_action( "save_post_{$pt}", array( self::class, 'save' ), 10, 2 );
		add_filter( "manage_{$pt}_posts_columns", array( self::class, 'columns' ) );
		add_action( "manage_{$pt}_posts_custom_column", array( self::class, 'column' ), 10, 2 );
		add_action( 'admin_post_magstack_facility_partner', array( self::class, 'toggle_partner' ) );
	}

	public static function columns( array $cols ): array {
		return array(
			'cb'         => $cols['cb'] ?? '',
			'title'      => __( 'Facility', 'magstack-coaches' ),
			'mc_partner' => __( 'Partner', 'magstack-coaches' ),
			'mc_city'    => __( 'City', 'magstack-coaches' ),
			'mc_courts'  => __( 'Courts', 'magstack-coaches' ),
			'mc_fee'     => __( 'Court fee', 'magstack-coaches' ),
			'mc_geo'     => __( 'Map', 'magstack-coaches' ),
			'date'       => $cols['date'] ?? '',
		);
	}

	public static function column( string $col, int $id ): void {
		switch ( $col ) {
			case 'mc_partner':
				$p   = Facility::is_partner( $id );
				$url = wp_nonce_url( admin_url( 'admin-post.php?action=magstack_facility_partner&facility=' . $id ), 'mc_partner_' . $id );
				echo '<a href="' . esc_url( $url ) . '" title="' . esc_attr__( 'Toggle partner', 'magstack-coaches' ) . '" style="text-decoration:none;font-weight:600;color:' . ( $p ? '#b45309' : '#a7aaad' ) . '">' . ( $p ? '★ ' . esc_html__( 'Partner', 'magstack-coaches' ) : '☆' ) . '</a>';
				break;
			case 'mc_city':
				echo esc_html( (string) Facility::meta( $id, 'city', '—' ) );
				break;
			case 'mc_courts':
				echo (int) Facility::meta( $id, 'courts', 0 );
				break;
			case 'mc_fee':
				echo Facility::meta( $id, 'pay_at_venue', 0 ) ? esc_html__( 'Pay at venue', 'magstack-coaches' ) : ( '' !== Facility::meta( $id, 'court_fee', '' ) ? esc_html( Helpers::money( Facility::meta( $id, 'court_fee', 0 ) ) ) : '—' );
				break;
			case 'mc_geo':
				echo '' !== Facility::meta( $id, 'lat', '' ) ? '📍' : '<span style="color:#b32d2e">' . esc_html__( 'No coordinates', 'magstack-coaches' ) . '</span>';
				break;
		}
	}

	public static function toggle_partner(): void {
		$id = (int) ( $_GET['facility'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! current_user_can( Menu::CAP ) || PostTypes::FACILITY !== get_post_type( $id ) ) {
			wp_die( esc_html__( 'Not allowed.', 'magstack-coaches' ), 403 );
		}
		check_admin_referer( 'mc_partner_' . $id );
		update_post_meta( $id, '_mc_partner', Facility::is_partner( $id ) ? 0 : 1 );
		\Magstack\Coaches\Coach\Coach::clear_caches();
		wp_safe_redirect( add_query_arg( 'mc_notice', rawurlencode( __( 'Partner status updated.', 'magstack-coaches' ) ), wp_get_referer() ?: admin_url( 'edit.php?post_type=' . PostTypes::FACILITY ) ) );
		exit;
	}

	public static function metaboxes(): void {
		add_meta_box( 'mc_facility', __( 'Facility details', 'magstack-coaches' ), array( self::class, 'box' ), PostTypes::FACILITY, 'normal', 'high' );
	}

	public static function box( \WP_Post $post ): void {
		wp_nonce_field( 'mc_facility_save', '_mc_facility_nonce' );
		$id = $post->ID;
		$m  = static fn( $k, $d = '' ) => Facility::meta( $id, $k, $d );
		?>
		<table class="form-table" role="presentation">
			<tr><th><?php esc_html_e( 'Partner facility', 'magstack-coaches' ); ?></th><td><label><input type="checkbox" name="mc_partner" value="1" <?php checked( (bool) $m( 'partner', 0 ) ); ?>> <?php esc_html_e( 'Partner (badge on map, ranked first, selectable in booking step 3)', 'magstack-coaches' ); ?></label></td></tr>
			<tr><th><label for="mc_address"><?php esc_html_e( 'Address', 'magstack-coaches' ); ?></label></th><td><input class="large-text" type="text" id="mc_address" name="mc_address" value="<?php echo esc_attr( $m( 'address' ) ); ?>"></td></tr>
			<tr><th><label for="mc_city"><?php esc_html_e( 'City', 'magstack-coaches' ); ?></label></th><td><input class="regular-text" type="text" id="mc_city" name="mc_city" value="<?php echo esc_attr( $m( 'city' ) ); ?>"></td></tr>
			<tr><th><?php esc_html_e( 'Latitude / Longitude', 'magstack-coaches' ); ?></th><td>
				<input type="text" name="mc_lat" value="<?php echo esc_attr( (string) $m( 'lat' ) ); ?>" placeholder="14.5547" style="width:130px" aria-label="<?php esc_attr_e( 'Latitude', 'magstack-coaches' ); ?>">
				<input type="text" name="mc_lng" value="<?php echo esc_attr( (string) $m( 'lng' ) ); ?>" placeholder="121.0244" style="width:130px" aria-label="<?php esc_attr_e( 'Longitude', 'magstack-coaches' ); ?>">
				<p class="description"><?php esc_html_e( 'Tip: right-click a spot on openstreetmap.org → "Show address" to read coordinates.', 'magstack-coaches' ); ?></p></td></tr>
			<tr><th><label for="mc_courts"><?php esc_html_e( 'Number of courts', 'magstack-coaches' ); ?></label></th><td><input type="number" min="0" id="mc_courts" name="mc_courts" value="<?php echo esc_attr( (string) $m( 'courts', '' ) ); ?>"></td></tr>
			<tr><th><label for="mc_surface"><?php esc_html_e( 'Surface type', 'magstack-coaches' ); ?></label></th><td><input class="regular-text" type="text" id="mc_surface" name="mc_surface" value="<?php echo esc_attr( $m( 'surface' ) ); ?>" placeholder="Hard court, acrylic"></td></tr>
			<tr><th><label for="mc_hours"><?php esc_html_e( 'Opening hours', 'magstack-coaches' ); ?></label></th><td><input class="large-text" type="text" id="mc_hours" name="mc_hours" value="<?php echo esc_attr( $m( 'hours' ) ); ?>" placeholder="Mon–Sun 6am–10pm"></td></tr>
			<tr><th><label for="mc_contact"><?php esc_html_e( 'Contact', 'magstack-coaches' ); ?></label></th><td><input class="regular-text" type="text" id="mc_contact" name="mc_contact" value="<?php echo esc_attr( $m( 'contact' ) ); ?>"></td></tr>
			<tr><th><label for="mc_court_fee"><?php esc_html_e( 'Court fee (PHP per session)', 'magstack-coaches' ); ?></label></th><td>
				<input type="number" min="0" step="1" id="mc_court_fee" name="mc_court_fee" value="<?php echo esc_attr( (string) $m( 'court_fee', '' ) ); ?>">
				<label style="margin-left:12px"><input type="checkbox" name="mc_pay_at_venue" value="1" <?php checked( (bool) $m( 'pay_at_venue', 0 ) ); ?>> <?php esc_html_e( 'Pay at venue (no fixed fee)', 'magstack-coaches' ); ?></label>
				<p class="description"><?php esc_html_e( 'Informational in Phase 1: shown as a separate line item; not collected by the plugin.', 'magstack-coaches' ); ?></p></td></tr>
			<tr><th><label for="mc_court_fee_note"><?php esc_html_e( 'Court fee note', 'magstack-coaches' ); ?></label></th><td><input class="large-text" type="text" id="mc_court_fee_note" name="mc_court_fee_note" value="<?php echo esc_attr( $m( 'court_fee_note' ) ); ?>" placeholder="₱300/hr, peak rates apply after 5pm"></td></tr>
			<tr><th><label for="mc_url"><?php esc_html_e( 'Magstack link (optional)', 'magstack-coaches' ); ?></label></th><td><input class="large-text" type="url" id="mc_url" name="mc_url" value="<?php echo esc_attr( $m( 'url' ) ); ?>"><p class="description"><?php esc_html_e( 'Phase 3: enables the "live availability" flag for partner courts.', 'magstack-coaches' ); ?></p></td></tr>
		</table>
		<?php
	}

	public static function save( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST['_mc_facility_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_mc_facility_nonce'] ) ), 'mc_facility_save' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( Menu::CAP ) ) {
			return;
		}
		$p   = wp_unslash( $_POST );
		$num = static fn( $v ) => ( '' === trim( (string) $v ) || ! is_numeric( $v ) ) ? '' : (float) $v;
		$lat = $num( $p['mc_lat'] ?? '' );
		$lng = $num( $p['mc_lng'] ?? '' );
		if ( '' !== $lat && ( $lat < -90 || $lat > 90 ) ) {
			$lat = '';
		}
		if ( '' !== $lng && ( $lng < -180 || $lng > 180 ) ) {
			$lng = '';
		}
		$meta = array(
			'partner'        => empty( $p['mc_partner'] ) ? 0 : 1,
			'address'        => sanitize_text_field( $p['mc_address'] ?? '' ),
			'city'           => sanitize_text_field( $p['mc_city'] ?? '' ),
			'lat'            => $lat,
			'lng'            => $lng,
			'courts'         => max( 0, (int) ( $p['mc_courts'] ?? 0 ) ),
			'surface'        => sanitize_text_field( $p['mc_surface'] ?? '' ),
			'hours'          => sanitize_text_field( $p['mc_hours'] ?? '' ),
			'contact'        => sanitize_text_field( $p['mc_contact'] ?? '' ),
			'court_fee'      => $num( $p['mc_court_fee'] ?? '' ),
			'pay_at_venue'   => empty( $p['mc_pay_at_venue'] ) ? 0 : 1,
			'court_fee_note' => sanitize_text_field( $p['mc_court_fee_note'] ?? '' ),
			'url'            => esc_url_raw( trim( (string) ( $p['mc_url'] ?? '' ) ), array( 'http', 'https' ) ),
		);
		foreach ( $meta as $k => $v ) {
			update_post_meta( $post_id, '_mc_' . $k, $v );
		}
		\Magstack\Coaches\Coach\Coach::clear_caches();
	}
}
