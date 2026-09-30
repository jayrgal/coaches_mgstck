<?php
namespace Magstack\Coaches\Admin;

use Magstack\Coaches\Coach\Availability;
use Magstack\Coaches\Coach\Coach;
use Magstack\Coaches\Coach\Registration;
use Magstack\Coaches\Helpers;
use Magstack\Coaches\PostTypes;
use Magstack\Coaches\Rest\CoachController;
use Magstack\Coaches\Settings;

defined( 'ABSPATH' ) || exit;

/** wp-admin: Coaches list (status / tier / fee / verified / founding end) + coach editor. */
final class CoachAdmin {

	private static bool $saving = false;

	public static function hooks(): void {
		$pt = PostTypes::COACH;
		add_filter( "manage_{$pt}_posts_columns", array( self::class, 'columns' ) );
		add_action( "manage_{$pt}_posts_custom_column", array( self::class, 'column' ), 10, 2 );
		add_filter( "views_edit-{$pt}", array( self::class, 'views' ) );
		add_action( 'pre_get_posts', array( self::class, 'filter_query' ) );
		add_filter( 'post_row_actions', array( self::class, 'row_actions' ), 10, 2 );
		add_action( 'add_meta_boxes', array( self::class, 'metaboxes' ) );
		add_action( "save_post_{$pt}", array( self::class, 'save' ), 10, 2 );
		add_action( 'admin_post_magstack_coach_action', array( self::class, 'handle_action' ) );
	}

	// ---- List table -------------------------------------------------------------------------

	public static function columns( array $cols ): array {
		return array(
			'cb'            => $cols['cb'] ?? '',
			'title'         => __( 'Coach', 'magstack-coaches' ),
			'mc_status'     => __( 'Status', 'magstack-coaches' ),
			'mc_tier'       => __( 'Tier', 'magstack-coaches' ),
			'mc_fee'        => __( 'Listing fee', 'magstack-coaches' ),
			'mc_verified'   => __( 'Verified', 'magstack-coaches' ),
			'mc_founding'   => __( 'Founding ends', 'magstack-coaches' ),
			'mc_city'       => __( 'Location', 'magstack-coaches' ),
			'date'          => $cols['date'] ?? __( 'Date', 'magstack-coaches' ),
		);
	}

	public static function action_url( int $id, string $action ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=magstack_coach_action&do=' . $action . '&coach=' . $id ), 'mc_coach_' . $action . '_' . $id );
	}

	public static function column( string $col, int $id ): void {
		switch ( $col ) {
			case 'mc_status':
				$s = Coach::status( $id );
				$labels = array(
					'pending'  => __( 'Pending', 'magstack-coaches' ),
					'approved' => __( 'Approved', 'magstack-coaches' ),
					'rejected' => __( 'Rejected', 'magstack-coaches' ),
				);
				$color = array( 'pending' => '#b45309', 'approved' => '#166534', 'rejected' => '#991b1b' )[ $s ] ?? '#444';
				echo '<strong style="color:' . esc_attr( $color ) . '">' . esc_html( $labels[ $s ] ?? $s ) . '</strong>';
				break;
			case 'mc_tier':
				echo esc_html( ucfirst( Coach::tier( $id ) ) );
				break;
			case 'mc_fee':
				$f = Coach::fee_status( $id );
				$color = array( 'due' => '#991b1b', 'paid' => '#166534', 'founding' => '#1d4ed8' )[ $f ] ?? '#666';
				echo '<span style="color:' . esc_attr( $color ) . ';font-weight:600">' . esc_html( Coach::fee_status_label( $f ) ) . '</span>';
				$until = (string) Coach::meta( $id, 'fee_paid_until', '' );
				if ( $until ) {
					echo '<br><small>' . esc_html( sprintf( /* translators: %s: date */ __( 'Paid until %s', 'magstack-coaches' ), Helpers::fmt_date( $until ) ) ) . '</small>';
				}
				break;
			case 'mc_verified':
				$v = Coach::is_verified( $id );
				echo '<a href="' . esc_url( self::action_url( $id, 'verify' ) ) . '" title="' . esc_attr__( 'Toggle verified', 'magstack-coaches' ) . '" style="text-decoration:none;font-size:20px;color:' . ( $v ? '#166534' : '#a7aaad' ) . '">' . ( $v ? '✔' : '○' ) . '</a>';
				break;
			case 'mc_founding':
				$e = Coach::founding_end( $id );
				echo $e ? esc_html( Helpers::fmt_date( $e ) ) : '—';
				break;
			case 'mc_city':
				echo esc_html( (string) Coach::meta( $id, 'city', '—' ) );
				break;
		}
	}

	public static function row_actions( array $actions, \WP_Post $post ): array {
		if ( PostTypes::COACH !== $post->post_type ) {
			return $actions;
		}
		$id = $post->ID;
		unset( $actions['inline hide-if-no-js'] );
		$s = Coach::status( $id );
		if ( 'approved' !== $s ) {
			$actions['mc_approve'] = '<a href="' . esc_url( self::action_url( $id, 'approve' ) ) . '">' . esc_html__( 'Approve', 'magstack-coaches' ) . '</a>';
		}
		if ( 'rejected' !== $s ) {
			$actions['mc_reject'] = '<a href="' . esc_url( self::action_url( $id, 'reject' ) ) . '" onclick="return confirm(\'' . esc_js( __( 'Reject this application and email the coach?', 'magstack-coaches' ) ) . '\')">' . esc_html__( 'Reject', 'magstack-coaches' ) . '</a>';
		}
		if ( 'approved' === $s ) {
			$actions['mc_feepaid'] = '<a href="' . esc_url( self::action_url( $id, 'fee_paid' ) ) . '">' . esc_html__( 'Mark fee paid (+1 month)', 'magstack-coaches' ) . '</a>';
		}
		return $actions;
	}

	public static function handle_action(): void {
		$id = (int) ( $_GET['coach'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$do = sanitize_key( $_GET['do'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! current_user_can( Menu::CAP ) || ! Coach::is_coach( $id ) ) {
			wp_die( esc_html__( 'Not allowed.', 'magstack-coaches' ), 403 );
		}
		check_admin_referer( 'mc_coach_' . $do . '_' . $id );
		$msg = '';
		switch ( $do ) {
			case 'approve':
				Coach::approve( $id );
				$msg = __( 'Coach approved and notified.', 'magstack-coaches' );
				break;
			case 'reject':
				Coach::reject( $id );
				$msg = __( 'Coach rejected and notified.', 'magstack-coaches' );
				break;
			case 'verify':
				update_post_meta( $id, '_mc_verified', Coach::is_verified( $id ) ? 0 : 1 );
				$msg = __( 'Verified badge updated.', 'magstack-coaches' );
				break;
			case 'fee_paid':
				Coach::mark_fee_paid( $id );
				$msg = __( 'Listing fee marked as paid.', 'magstack-coaches' );
				break;
		}
		Coach::clear_caches();
		wp_safe_redirect( add_query_arg( 'mc_notice', rawurlencode( $msg ), wp_get_referer() ?: admin_url( 'edit.php?post_type=' . PostTypes::COACH ) ) );
		exit;
	}

	/** @return int[] */
	public static function ids_by_fee( string $fee ): array {
		$ids = get_posts(
			array(
				'post_type'      => PostTypes::COACH,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		return array_values( array_filter( $ids, static fn( $id ) => Coach::fee_status( (int) $id ) === $fee ) );
	}

	public static function views( array $views ): array {
		$base = admin_url( 'edit.php?post_type=' . PostTypes::COACH );
		$cur  = sanitize_key( $_GET['mc_status'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$fee  = sanitize_key( $_GET['mc_fee'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification
		foreach ( array( 'pending' => __( 'Pending', 'magstack-coaches' ), 'approved' => __( 'Approved', 'magstack-coaches' ), 'rejected' => __( 'Rejected', 'magstack-coaches' ) ) as $k => $label ) {
			$views[ 'mc_' . $k ] = '<a href="' . esc_url( add_query_arg( 'mc_status', $k, $base ) ) . '"' . ( $cur === $k ? ' class="current"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		$views['mc_due'] = '<a href="' . esc_url( add_query_arg( 'mc_fee', 'due', $base ) ) . '"' . ( 'due' === $fee ? ' class="current"' : '' ) . '>' . esc_html__( 'Fee due', 'magstack-coaches' ) . ' (' . count( self::ids_by_fee( 'due' ) ) . ')</a>';
		return $views;
	}

	public static function filter_query( \WP_Query $q ): void {
		if ( ! is_admin() || ! $q->is_main_query() || PostTypes::COACH !== $q->get( 'post_type' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification
		if ( ! empty( $_GET['mc_status'] ) ) {
			$q->set(
				'meta_query',
				array(
					array(
						'key'   => '_mc_status',
						'value' => sanitize_key( $_GET['mc_status'] ),
					),
				)
			);
		}
		if ( ! empty( $_GET['mc_fee'] ) ) {
			$ids = self::ids_by_fee( sanitize_key( $_GET['mc_fee'] ) );
			$q->set( 'post__in', $ids ?: array( 0 ) );
		}
		// phpcs:enable
	}

	// ---- Editor -----------------------------------------------------------------------------

	public static function metaboxes(): void {
		add_meta_box( 'mc_coach', __( 'Coach details', 'magstack-coaches' ), array( self::class, 'box' ), PostTypes::COACH, 'normal', 'high' );
		add_meta_box( 'mc_coach_admin', __( 'Moderation & listing', 'magstack-coaches' ), array( self::class, 'box_admin' ), PostTypes::COACH, 'side', 'high' );
	}

	public static function box_admin( \WP_Post $post ): void {
		wp_nonce_field( 'mc_coach_save', '_mc_coach_nonce' );
		$id     = $post->ID;
		$status = Coach::meta( $id, 'status', 'approved' ); // Admin-created coaches default to Approved.
		$tiers  = Settings::get( 'tiers', array() );
		?>
		<p><label for="mc_status"><strong><?php esc_html_e( 'Application status', 'magstack-coaches' ); ?></strong></label><br>
			<select name="mc_status" id="mc_status" class="widefat">
				<?php foreach ( array( 'pending' => __( 'Pending', 'magstack-coaches' ), 'approved' => __( 'Approved', 'magstack-coaches' ), 'rejected' => __( 'Rejected', 'magstack-coaches' ) ) as $k => $l ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $status, $k ); ?>><?php echo esc_html( $l ); ?></option>
				<?php endforeach; ?>
			</select>
			<span class="description"><?php esc_html_e( 'Changing to Approved/Rejected emails the coach.', 'magstack-coaches' ); ?></span></p>
		<p><label><input type="checkbox" name="mc_verified" value="1" <?php checked( Coach::is_verified( $id ) ); ?>> <strong><?php esc_html_e( 'Verified badge', 'magstack-coaches' ); ?></strong></label></p>
		<p><label for="mc_tier"><strong><?php esc_html_e( 'Tier', 'magstack-coaches' ); ?></strong></label><br>
			<select name="mc_tier" id="mc_tier" class="widefat">
				<option value="free" <?php selected( Coach::tier( $id ), 'free' ); ?>><?php echo esc_html( sprintf( 'Free (%s / %s%%)', Helpers::money( $tiers['free']['listing_fee'] ?? 0 ), $tiers['free']['commission'] ?? 0 ) ); ?></option>
				<option value="pro" <?php selected( Coach::tier( $id ), 'pro' ); ?>><?php echo esc_html( sprintf( 'Pro (%s / %s%%)', Helpers::money( $tiers['pro']['listing_fee'] ?? 0 ), $tiers['pro']['commission'] ?? 0 ) ); ?></option>
			</select></p>
		<p><label for="mc_founding_ends"><strong><?php esc_html_e( 'Founding period ends', 'magstack-coaches' ); ?></strong></label><br>
			<input type="date" name="mc_founding_ends" id="mc_founding_ends" class="widefat" value="<?php echo esc_attr( Coach::founding_end( $id ) ); ?>"></p>
		<p><label for="mc_fee_paid_until"><strong><?php esc_html_e( 'Fee paid until', 'magstack-coaches' ); ?></strong></label><br>
			<input type="date" name="mc_fee_paid_until" id="mc_fee_paid_until" class="widefat" value="<?php echo esc_attr( (string) Coach::meta( $id, 'fee_paid_until', '' ) ); ?>"></p>
		<p><strong><?php esc_html_e( 'Fee status:', 'magstack-coaches' ); ?></strong> <?php echo esc_html( Coach::fee_status_label( Coach::fee_status( $id ) ) ); ?></p>
		<p class="description"><?php esc_html_e( 'Rating fields are Phase 2-ready (reviews).', 'magstack-coaches' ); ?></p>
		<p><label for="mc_rating_avg"><?php esc_html_e( 'Rating avg', 'magstack-coaches' ); ?></label> <input type="number" step="0.1" min="0" max="5" name="mc_rating_avg" id="mc_rating_avg" style="width:70px" value="<?php echo esc_attr( (string) Coach::meta( $id, 'rating_avg', 0 ) ); ?>">
			<label for="mc_rating_count"><?php esc_html_e( 'Count', 'magstack-coaches' ); ?></label> <input type="number" min="0" name="mc_rating_count" id="mc_rating_count" style="width:70px" value="<?php echo esc_attr( (string) Coach::meta( $id, 'rating_count', 0 ) ); ?>"></p>
		<?php
	}

	public static function box( \WP_Post $post ): void {
		$id      = $post->ID;
		$m       = static fn( $k, $d = '' ) => Coach::meta( $id, $k, $d );
		$levels  = Coach::levels( $id );
		$socials = Coach::socials( $id );
		$venues  = implode( "\n", array_map( static fn( $v ) => trim( $v['name'] . ( ! empty( $v['address'] ) ? ' | ' . $v['address'] : '' ) ), array_filter( Coach::venues( $id ), static fn( $v ) => empty( $v['facility_id'] ) ) ) );
		$facs    = get_posts( array( 'post_type' => PostTypes::FACILITY, 'post_status' => 'publish', 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		$sel     = array_map( 'intval', (array) $m( 'venue_facilities', array() ) );
		?>
		<table class="form-table" role="presentation">
			<tr><th><label for="mc_email"><?php esc_html_e( 'Email (private)', 'magstack-coaches' ); ?></label></th><td><input class="regular-text" type="email" id="mc_email" name="mc_email" value="<?php echo esc_attr( $m( 'email' ) ); ?>"></td></tr>
			<tr><th><label for="mc_mobile"><?php esc_html_e( 'Mobile (private)', 'magstack-coaches' ); ?></label></th><td><input class="regular-text" type="text" id="mc_mobile" name="mc_mobile" value="<?php echo esc_attr( $m( 'mobile' ) ); ?>"></td></tr>
			<tr><th><label for="mc_city"><?php esc_html_e( 'Service area (city)', 'magstack-coaches' ); ?></label></th><td><input class="regular-text" type="text" id="mc_city" name="mc_city" value="<?php echo esc_attr( $m( 'city' ) ); ?>"></td></tr>
			<tr><th><label for="mc_bio"><?php esc_html_e( 'Short bio', 'magstack-coaches' ); ?></label></th><td><textarea class="large-text" rows="4" id="mc_bio" name="mc_bio"><?php echo esc_textarea( $m( 'bio' ) ); ?></textarea></td></tr>
			<tr><th><?php esc_html_e( 'Coaching types', 'magstack-coaches' ); ?></th><td><?php foreach ( Helpers::level_labels() as $k => $l ) : ?><label style="margin-right:12px"><input type="checkbox" name="mc_levels[]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, $levels, true ) ); ?>> <?php echo esc_html( $l ); ?></label><?php endforeach; ?></td></tr>
			<tr><th><label for="mc_rate"><?php esc_html_e( 'Rate per hour (PHP)', 'magstack-coaches' ); ?></label></th><td><input type="number" min="0" step="1" id="mc_rate" name="mc_rate" value="<?php echo esc_attr( (string) $m( 'rate', 0 ) ); ?>"></td></tr>
			<tr><th><label for="mc_durations"><?php esc_html_e( 'Durations (minutes)', 'magstack-coaches' ); ?></label></th><td><input class="regular-text" type="text" id="mc_durations" name="mc_durations" value="<?php echo esc_attr( implode( ', ', Coach::durations( $id ) ) ); ?>"></td></tr>
			<tr><th><label for="mc_dupr">DUPR</label></th><td><input type="text" id="mc_dupr" name="mc_dupr" value="<?php echo esc_attr( $m( 'dupr' ) ); ?>" style="width:80px"> <label for="mc_years"><?php esc_html_e( 'Years', 'magstack-coaches' ); ?></label> <input type="number" min="0" id="mc_years" name="mc_years" value="<?php echo esc_attr( (string) $m( 'years', 0 ) ); ?>" style="width:70px"></td></tr>
			<tr><th><label for="mc_certs"><?php esc_html_e( 'Certifications', 'magstack-coaches' ); ?></label></th><td><textarea class="large-text" rows="2" id="mc_certs" name="mc_certs"><?php echo esc_textarea( $m( 'certifications' ) ); ?></textarea></td></tr>
			<tr><th><?php esc_html_e( 'Partner venues', 'magstack-coaches' ); ?></th><td><?php foreach ( $facs as $f ) : ?><label style="display:block"><input type="checkbox" name="mc_venue_facilities[]" value="<?php echo (int) $f->ID; ?>" <?php checked( in_array( $f->ID, $sel, true ) ); ?>> <?php echo esc_html( $f->post_title ); ?></label><?php endforeach; ?></td></tr>
			<tr><th><label for="mc_venues"><?php esc_html_e( 'Other venues', 'magstack-coaches' ); ?></label></th><td><textarea class="large-text" rows="3" id="mc_venues" name="mc_venues" placeholder="Name | Address"><?php echo esc_textarea( $venues ); ?></textarea></td></tr>
			<?php foreach ( array( 'facebook', 'instagram', 'tiktok', 'youtube', 'website' ) as $net ) : ?>
				<tr><th><label for="mc_soc_<?php echo esc_attr( $net ); ?>"><?php echo esc_html( ucfirst( $net ) ); ?></label></th><td><input class="regular-text" type="url" id="mc_soc_<?php echo esc_attr( $net ); ?>" name="mc_social[<?php echo esc_attr( $net ); ?>]" value="<?php echo esc_attr( $socials[ $net ] ?? '' ); ?>"></td></tr>
			<?php endforeach; ?>
			<tr><th><?php esc_html_e( 'Booking link', 'magstack-coaches' ); ?></th><td><code><?php echo esc_html( Coach::booking_link( $id ) ); ?></code></td></tr>
		</table>
		<?php
	}

	public static function save( int $post_id, \WP_Post $post ): void {
		if ( self::$saving || ! isset( $_POST['_mc_coach_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_mc_coach_nonce'] ) ), 'mc_coach_save' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE || ! current_user_can( Menu::CAP ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		self::$saving = true;
		$p            = wp_unslash( $_POST );
		$old_status   = Coach::meta( $post_id, 'status', '' );

		$fac_ids = array();
		foreach ( (array) ( $p['mc_venue_facilities'] ?? array() ) as $fid ) {
			$fac_ids[] = (int) $fid;
		}
		$socials = array();
		foreach ( (array) ( $p['mc_social'] ?? array() ) as $net => $u ) {
			$u = esc_url_raw( trim( (string) $u ), array( 'http', 'https' ) );
			if ( $u && in_array( $net, array( 'facebook', 'instagram', 'tiktok', 'youtube', 'website' ), true ) ) {
				$socials[ $net ] = $u;
			}
		}
		$date = static fn( $v ) => Helpers::valid_date( (string) $v ) ? (string) $v : '';
		$meta = array(
			'email'            => sanitize_email( $p['mc_email'] ?? '' ),
			'mobile'           => Helpers::clean_phone( $p['mc_mobile'] ?? '' ),
			'city'             => sanitize_text_field( $p['mc_city'] ?? '' ),
			'bio'              => sanitize_textarea_field( $p['mc_bio'] ?? '' ),
			'levels'           => array_values( array_intersect( (array) ( $p['mc_levels'] ?? array() ), Helpers::LEVELS ) ),
			'rate'             => max( 0, (float) ( $p['mc_rate'] ?? 0 ) ),
			'durations'        => CoachController::parse_durations( $p['mc_durations'] ?? '60' ),
			'dupr'             => sanitize_text_field( $p['mc_dupr'] ?? '' ),
			'years'            => max( 0, (int) ( $p['mc_years'] ?? 0 ) ),
			'certifications'   => sanitize_textarea_field( $p['mc_certs'] ?? '' ),
			'venue_facilities' => $fac_ids,
			'venues'           => Registration::parse_venues( (string) ( $p['mc_venues'] ?? '' ), $fac_ids ),
			'socials'          => $socials,
			'verified'         => empty( $p['mc_verified'] ) ? 0 : 1,
			'tier'             => in_array( $p['mc_tier'] ?? '', array( 'free', 'pro' ), true ) ? $p['mc_tier'] : 'free',
			'founding_ends'    => $date( $p['mc_founding_ends'] ?? '' ),
			'fee_paid_until'   => $date( $p['mc_fee_paid_until'] ?? '' ),
			'rating_avg'       => min( 5, max( 0, (float) ( $p['mc_rating_avg'] ?? 0 ) ) ),
			'rating_count'     => max( 0, (int) ( $p['mc_rating_count'] ?? 0 ) ),
		);
		foreach ( $meta as $k => $v ) {
			update_post_meta( $post_id, '_mc_' . $k, $v );
		}

		$new = in_array( $p['mc_status'] ?? '', array( 'pending', 'approved', 'rejected' ), true ) ? $p['mc_status'] : 'pending';
		if ( $new !== $old_status ) {
			if ( 'approved' === $new ) {
				Coach::approve( $post_id );
			} elseif ( 'rejected' === $new ) {
				Coach::reject( $post_id );
			} else {
				update_post_meta( $post_id, '_mc_status', 'pending' );
				if ( 'publish' === get_post_status( $post_id ) ) {
					wp_update_post( array( 'ID' => $post_id, 'post_status' => 'pending' ) );
				}
			}
		}
		// Keep the post status consistent with the application status.
		if ( 'approved' === Coach::status( $post_id ) && 'publish' !== get_post_status( $post_id ) ) {
			wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
		}
		Coach::clear_caches();
		self::$saving = false;
	}
}
