<?php
namespace Magstack\Coaches\Admin;

use Magstack\Coaches\Notifications\Templates;
use Magstack\Coaches\Payments\Registry;
use Magstack\Coaches\Seo\LocationPages;
use Magstack\Coaches\Settings;

defined( 'ABSPATH' ) || exit;

final class SettingsPage {

	private const TABS = array(
		'general'      => 'General',
		'booking'      => 'Booking',
		'monetization' => 'Monetization',
		'payments'     => 'Payments',
		'emails'       => 'Emails',
		'seo'          => 'SEO pages',
		'spam'         => 'Spam & channels',
	);

	/** Checkbox keys per tab (absent from POST = off). */
	private const CHECKS = array(
		'general'      => array( 'uninstall_cleanup' ),
		'booking'      => array( 'ics_attach' ),
		'monetization' => array( 'hide_unpaid' ),
		'seo'          => array( 'seo_enabled' ),
		'spam'         => array( 'channel_sms', 'channel_viber' ),
	);

	public static function hooks(): void {
		add_action( 'admin_menu', array( self::class, 'menu' ), 20 );
		add_action( 'admin_init', array( self::class, 'register' ) );
		add_action( 'admin_post_magstack_regen_seo', array( self::class, 'regenerate' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'magstack-coaches', __( 'Settings', 'magstack-coaches' ), __( 'Settings', 'magstack-coaches' ), Menu::CAP, 'magstack-settings', array( self::class, 'render' ) );
	}

	public static function register(): void {
		register_setting(
			'magstack_coaches',
			Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => Settings::defaults(),
			)
		);
	}

	public static function assets( string $hook ): void {
		if ( false === strpos( $hook, 'magstack-settings' ) ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_add_inline_script(
			'wp-color-picker',
			"jQuery(function($){\$('.mc-color').wpColorPicker();\$('#mc-qr-pick').on('click',function(e){e.preventDefault();var f=wp.media({multiple:false,library:{type:'image'}});f.on('select',function(){var a=f.state().get('selection').first().toJSON();\$('#mc-qr-id').val(a.id);\$('#mc-qr-preview').attr('src',a.url).show();});f.open();});\$('#mc-qr-clear').on('click',function(e){e.preventDefault();\$('#mc-qr-id').val(0);\$('#mc-qr-preview').hide();});});"
		);
	}

	public static function sanitize( $in ): array {
		$in      = is_array( $in ) ? $in : array();
		$tab     = sanitize_key( $in['__tab'] ?? '' );
		$current = get_option( Settings::OPTION, array() );
		$current = is_array( $current ) ? $current : array();
		$out     = $current;
		if ( ! isset( self::TABS[ $tab ] ) ) {
			return $out;
		}
		foreach ( self::CHECKS[ $tab ] ?? array() as $k ) {
			$out[ $k ] = empty( $in[ $k ] ) ? 0 : 1;
		}
		$int   = static fn( $v, $min, $max ) => max( $min, min( $max, (int) $v ) );
		$float = static fn( $v, $min, $max ) => max( $min, min( $max, round( (float) $v, 2 ) ) );

		switch ( $tab ) {
			case 'general':
				$out['currency']        = strtoupper( substr( sanitize_text_field( $in['currency'] ?? 'PHP' ), 0, 3 ) ) ?: 'PHP';
				$out['currency_symbol'] = mb_substr( sanitize_text_field( $in['currency_symbol'] ?? '₱' ), 0, 4 ) ?: '₱';
				$out['brand_color']     = sanitize_hex_color( $in['brand_color'] ?? '' ) ?: '#0f766e';
				$out['admin_email']     = sanitize_email( $in['admin_email'] ?? '' );
				$pages                  = array();
				foreach ( array( 'coaches', 'booking', 'register', 'dashboard', 'map' ) as $k ) {
					$pages[ $k ] = (int) ( $in['pages'][ $k ] ?? 0 );
				}
				$out['pages'] = $pages;
				break;
			case 'booking':
				$out['lead_time_hours']     = $int( $in['lead_time_hours'] ?? 12, 0, 720 );
				$out['max_advance_days']    = $int( $in['max_advance_days'] ?? 60, 1, 365 );
				$out['cancel_window_hours'] = $int( $in['cancel_window_hours'] ?? 24, 0, 720 );
				$out['slot_step']           = in_array( (int) ( $in['slot_step'] ?? 30 ), array( 15, 30, 60 ), true ) ? (int) $in['slot_step'] : 30;
				break;
			case 'monetization':
				$out['fee_mode']      = 'commission' === ( $in['fee_mode'] ?? '' ) ? 'commission' : 'listing';
				$out['founding_days'] = $int( $in['founding_days'] ?? 90, 0, 3650 );
				$tiers                = array();
				foreach ( array( 'free', 'pro' ) as $t ) {
					$tiers[ $t ] = array(
						'listing_fee' => $float( $in['tiers'][ $t ]['listing_fee'] ?? 0, 0, 1000000 ),
						'commission'  => $float( $in['tiers'][ $t ]['commission'] ?? 0, 0, 100 ),
					);
				}
				$out['tiers'] = $tiers;
				break;
			case 'payments':
				$gw                         = sanitize_key( $in['payment_gateway'] ?? 'manual' );
				$out['payment_gateway']     = Registry::get( $gw ) ? $gw : 'manual';
				$out['payment_instructions'] = sanitize_textarea_field( $in['payment_instructions'] ?? '' );
				$out['payment_qr_id']       = (int) ( $in['payment_qr_id'] ?? 0 );
				break;
			case 'emails':
				$emails = array();
				foreach ( array_keys( Templates::definitions() ) as $k ) {
					$emails[ $k ] = array(
						'subject' => sanitize_text_field( $in['emails'][ $k ]['subject'] ?? '' ),
						'body'    => wp_kses_post( $in['emails'][ $k ]['body'] ?? '' ),
					);
				}
				$out['emails'] = $emails;
				break;
			case 'seo':
				$out['seo_intro_extra'] = wp_kses_post( $in['seo_intro_extra'] ?? '' );
				add_action( 'shutdown', array( LocationPages::class, 'flush' ) );
				break;
			case 'spam':
				$out['recaptcha_site']     = sanitize_text_field( $in['recaptcha_site'] ?? '' );
				$out['recaptcha_secret']   = sanitize_text_field( $in['recaptcha_secret'] ?? '' );
				$out['min_submit_seconds'] = $int( $in['min_submit_seconds'] ?? 3, 0, 60 );
				break;
		}
		return $out;
	}

	public static function regenerate(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			wp_die( esc_html__( 'Not allowed.', 'magstack-coaches' ), 403 );
		}
		check_admin_referer( 'mc_regen_seo' );
		LocationPages::flush();
		wp_safe_redirect( add_query_arg( 'mc_notice', rawurlencode( __( 'SEO location pages regenerated.', 'magstack-coaches' ) ), admin_url( 'admin.php?page=magstack-settings&tab=seo' ) ) );
		exit;
	}

	private static function field( string $label, string $html, string $desc = '' ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $html . ( $desc ? '<p class="description">' . wp_kses_post( $desc ) . '</p>' : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private static function input( string $name, $value, string $type = 'text', array $attrs = array() ): string {
		$class = $attrs['class'] ?? 'regular-text';
		unset( $attrs['class'] );
		$a = '';
		foreach ( $attrs as $k => $v ) {
			$a .= ' ' . esc_attr( $k ) . '="' . esc_attr( (string) $v ) . '"';
		}
		return '<input type="' . esc_attr( $type ) . '" name="' . esc_attr( Settings::OPTION . '[' . $name . ']' ) . '" value="' . esc_attr( (string) $value ) . '" class="' . esc_attr( $class ) . '"' . $a . '>';
	}

	private static function check( string $name, bool $on, string $label ): string {
		return '<label><input type="checkbox" name="' . esc_attr( Settings::OPTION . '[' . $name . ']' ) . '" value="1"' . checked( $on, true, false ) . '> ' . esc_html( $label ) . '</label>';
	}

	public static function render(): void {
		if ( ! current_user_can( Menu::CAP ) ) {
			return;
		}
		$tab = sanitize_key( $_GET['tab'] ?? 'general' ); // phpcs:ignore WordPress.Security.NonceVerification
		$tab = isset( self::TABS[ $tab ] ) ? $tab : 'general';
		$s   = Settings::all();
		$O   = Settings::OPTION;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Magstack Coaches – Settings', 'magstack-coaches' ); ?></h1>
			<?php settings_errors(); ?>
			<nav class="nav-tab-wrapper">
				<?php foreach ( self::TABS as $k => $l ) : ?>
					<a class="nav-tab <?php echo $k === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=magstack-settings&tab=' . $k ) ); ?>"><?php echo esc_html( $l ); ?></a>
				<?php endforeach; ?>
			</nav>
			<form method="post" action="options.php">
				<?php settings_fields( 'magstack_coaches' ); ?>
				<input type="hidden" name="<?php echo esc_attr( $O ); ?>[__tab]" value="<?php echo esc_attr( $tab ); ?>">
				<table class="form-table" role="presentation">
				<?php
				switch ( $tab ) {
					case 'general':
						self::field( __( 'Currency code', 'magstack-coaches' ), self::input( 'currency', $s['currency'], 'text', array( 'maxlength' => 3, 'style' => 'width:80px' ) ) );
						self::field( __( 'Currency symbol', 'magstack-coaches' ), self::input( 'currency_symbol', $s['currency_symbol'], 'text', array( 'maxlength' => 4, 'style' => 'width:80px' ) ), __( 'Default: PHP / ₱', 'magstack-coaches' ) );
						self::field( __( 'Brand color', 'magstack-coaches' ), self::input( 'brand_color', $s['brand_color'], 'text', array( 'class' => 'mc-color' ) ) );
						self::field( __( 'Admin notification email', 'magstack-coaches' ), self::input( 'admin_email', $s['admin_email'], 'email' ), __( 'Leave blank to use the site admin email.', 'magstack-coaches' ) );
						foreach ( array( 'coaches' => __( 'Coach directory page', 'magstack-coaches' ), 'booking' => __( 'Booking page', 'magstack-coaches' ), 'register' => __( 'Coach registration page', 'magstack-coaches' ), 'dashboard' => __( 'Coach dashboard page', 'magstack-coaches' ), 'map' => __( 'Facility map page', 'magstack-coaches' ) ) as $k => $l ) {
							self::field(
								$l,
								wp_dropdown_pages( array( 'name' => $O . '[pages][' . $k . ']', 'selected' => (int) ( $s['pages'][ $k ] ?? 0 ), 'show_option_none' => __( '— Select —', 'magstack-coaches' ), 'option_none_value' => 0, 'echo' => 0 ) ) // phpcs:ignore WordPress.Security.EscapeOutput
							);
						}
						self::field( __( 'Uninstall', 'magstack-coaches' ), self::check( 'uninstall_cleanup', (bool) $s['uninstall_cleanup'], __( 'Delete ALL plugin data (tables, coaches, facilities, settings) when the plugin is deleted', 'magstack-coaches' ) ) );
						break;

					case 'booking':
						self::field( __( 'Booking lead time (hours)', 'magstack-coaches' ), self::input( 'lead_time_hours', $s['lead_time_hours'], 'number', array( 'min' => 0, 'style' => 'width:90px' ) ), __( 'Players cannot book sooner than this before the session.', 'magstack-coaches' ) );
						self::field( __( 'Max days in advance', 'magstack-coaches' ), self::input( 'max_advance_days', $s['max_advance_days'], 'number', array( 'min' => 1, 'style' => 'width:90px' ) ) );
						self::field( __( 'Cancellation window (hours)', 'magstack-coaches' ), self::input( 'cancel_window_hours', $s['cancel_window_hours'], 'number', array( 'min' => 0, 'style' => 'width:90px' ) ), __( 'Players can self-cancel until this many hours before the session.', 'magstack-coaches' ) );
						self::field( __( 'Time slot step (minutes)', 'magstack-coaches' ), '<select name="' . esc_attr( $O ) . '[slot_step]">' . implode( '', array_map( static fn( $m ) => '<option value="' . $m . '"' . selected( (int) $s['slot_step'], $m, false ) . '>' . $m . '</option>', array( 15, 30, 60 ) ) ) . '</select>' );
						self::field( __( 'Calendar file', 'magstack-coaches' ), self::check( 'ics_attach', (bool) $s['ics_attach'], __( 'Attach an .ics calendar file to confirmation emails', 'magstack-coaches' ) ) );
						break;

					case 'monetization':
						self::field(
							__( 'Model', 'magstack-coaches' ),
							'<select name="' . esc_attr( $O ) . '[fee_mode]"><option value="listing"' . selected( $s['fee_mode'], 'listing', false ) . '>' . esc_html__( 'Flat monthly listing fee (Phase 1, enforced)', 'magstack-coaches' ) . '</option><option value="commission"' . selected( $s['fee_mode'], 'commission', false ) . '>' . esc_html__( 'Commission % (Phase 2 – stored, NOT enforced yet)', 'magstack-coaches' ) . '</option></select>'
						);
						self::field( __( 'Founding Coach free period (days)', 'magstack-coaches' ), self::input( 'founding_days', $s['founding_days'], 'number', array( 'min' => 0, 'style' => 'width:90px' ) ), __( 'Counted from the approval date. After it ends the coach shows "Fee due" until you mark the fee paid.', 'magstack-coaches' ) );
						self::field( __( 'Hide unpaid coaches', 'magstack-coaches' ), self::check( 'hide_unpaid', (bool) $s['hide_unpaid'], __( 'Automatically hide coaches with "Fee due" from the directory and booking', 'magstack-coaches' ) ) );
						foreach ( array( 'free' => 'Free', 'pro' => 'Pro' ) as $t => $l ) {
							self::field(
								sprintf( /* translators: %s: tier */ __( '%s tier', 'magstack-coaches' ), $l ),
								__( 'Monthly listing fee:', 'magstack-coaches' ) . ' ' . self::input( "tiers][{$t}][listing_fee", $s['tiers'][ $t ]['listing_fee'], 'number', array( 'min' => 0, 'step' => '0.01', 'style' => 'width:110px' ) ) // phpcs:ignore
								. ' &nbsp; ' . __( 'Commission %:', 'magstack-coaches' ) . ' ' . self::input( "tiers][{$t}][commission", $s['tiers'][ $t ]['commission'], 'number', array( 'min' => 0, 'max' => 100, 'step' => '0.01', 'style' => 'width:90px' ) )
							);
						}
						break;

					case 'payments':
						$opts = '';
						foreach ( Registry::all() as $g ) {
							$opts .= '<option value="' . esc_attr( $g->id() ) . '"' . selected( $s['payment_gateway'], $g->id(), false ) . '>' . esc_html( $g->title() ) . '</option>';
						}
						self::field( __( 'Payment gateway', 'magstack-coaches' ), '<select name="' . esc_attr( $O ) . '[payment_gateway]">' . $opts . '</select>', __( 'Additional gateways (PayMongo, WooCommerce) plug in via the <code>magstack_payment_gateways</code> filter.', 'magstack-coaches' ) );
						self::field( __( 'Payment instructions (GCash / bank)', 'magstack-coaches' ), '<textarea class="large-text" rows="5" name="' . esc_attr( $O ) . '[payment_instructions]">' . esc_textarea( $s['payment_instructions'] ) . '</textarea>' );
						$qr  = (int) $s['payment_qr_id'];
						$src = $qr ? wp_get_attachment_image_url( $qr, 'medium' ) : '';
						self::field(
							__( 'GCash / payment QR image', 'magstack-coaches' ),
							'<img id="mc-qr-preview" src="' . esc_url( (string) $src ) . '" style="max-width:200px;display:' . ( $src ? 'block' : 'none' ) . '" alt=""><input type="hidden" id="mc-qr-id" name="' . esc_attr( $O ) . '[payment_qr_id]" value="' . esc_attr( (string) $qr ) . '"><button class="button" id="mc-qr-pick">' . esc_html__( 'Choose image', 'magstack-coaches' ) . '</button> <button class="button-link" id="mc-qr-clear">' . esc_html__( 'Remove', 'magstack-coaches' ) . '</button>'
						);
						break;

					case 'emails':
						$vars = '{site_name} {player_name} {coach_name} {reference} {date} {time} {status_label} {court} {coach_fee} {court_fee} {total} {booking_details} {payment_instructions} {coach_contact} {manage_url} {directory_url} {dashboard_url} {when} {recipient_name} {player_email} {player_mobile} {player_level} {notes} {profile_url} {booking_link} {password_url} {founding_note} {coach_city} {admin_url}';
						echo '<tr><td colspan="2"><p class="description">' . esc_html__( 'Leave a field blank to use the built-in default. Basic HTML is allowed. Placeholders:', 'magstack-coaches' ) . ' <code>' . esc_html( $vars ) . '</code><br>' . esc_html__( '{booking_details} always lists the coach fee and court fee as separate line items. Coach contact details ({coach_contact}) only appear after a booking is Confirmed.', 'magstack-coaches' ) . '</p></td></tr>';
						foreach ( Templates::definitions() as $k => $def ) {
							$saved = $s['emails'][ $k ] ?? array();
							self::field(
								$def[0],
								'<input type="text" class="large-text" name="' . esc_attr( $O ) . '[emails][' . esc_attr( $k ) . '][subject]" value="' . esc_attr( $saved['subject'] ?? '' ) . '" placeholder="' . esc_attr( $def[1] ) . '" aria-label="' . esc_attr__( 'Subject', 'magstack-coaches' ) . '"><br><textarea class="large-text" rows="6" name="' . esc_attr( $O ) . '[emails][' . esc_attr( $k ) . '][body]" placeholder="' . esc_attr( $def[2] ) . '" aria-label="' . esc_attr__( 'Body', 'magstack-coaches' ) . '">' . esc_textarea( $saved['body'] ?? '' ) . '</textarea>'
							);
						}
						break;

					case 'seo':
						self::field( __( 'Location pages', 'magstack-coaches' ), self::check( 'seo_enabled', (bool) $s['seo_enabled'], __( 'Enable auto-generated "Pickleball Coaches in {City}" and "Pickleball Courts in {City}" pages', 'magstack-coaches' ) ) );
						self::field( __( 'Extra intro text', 'magstack-coaches' ), '<textarea class="large-text" rows="4" name="' . esc_attr( $O ) . '[seo_intro_extra]">' . esc_textarea( $s['seo_intro_extra'] ) . '</textarea>', __( 'Appended to every location page. Use {city} as a placeholder.', 'magstack-coaches' ) );
						$cities = \Magstack\Coaches\Coach\Coach::cities();
						$html   = '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=magstack_regen_seo' ), 'mc_regen_seo' ) ) . '">' . esc_html__( 'Regenerate pages now', 'magstack-coaches' ) . '</a>';
						$html  .= '<p class="description">' . esc_html( sprintf( /* translators: 1: coach cities 2: facility cities */ __( 'Currently %1$d coach city page(s) and %2$d court city page(s).', 'magstack-coaches' ), count( $cities['coaches'] ), count( $cities['facilities'] ) ) ) . '</p>';
						$html  .= '<p><a href="' . esc_url( LocationPages::index_url( 'coaches' ) ) . '" target="_blank" rel="noopener">' . esc_html( LocationPages::index_url( 'coaches' ) ) . '</a><br><a href="' . esc_url( LocationPages::index_url( 'courts' ) ) . '" target="_blank" rel="noopener">' . esc_html( LocationPages::index_url( 'courts' ) ) . '</a></p>';
						self::field( __( 'Regenerate', 'magstack-coaches' ), $html, __( 'Rebuilds the city list and flushes rewrite rules.', 'magstack-coaches' ) );
						break;

					case 'spam':
						self::field( __( 'reCAPTCHA v3 site key', 'magstack-coaches' ), self::input( 'recaptcha_site', $s['recaptcha_site'] ), __( 'Optional. Honeypot, timing and rate limiting always run.', 'magstack-coaches' ) );
						self::field( __( 'reCAPTCHA v3 secret key', 'magstack-coaches' ), self::input( 'recaptcha_secret', $s['recaptcha_secret'], 'password', array( 'autocomplete' => 'off' ) ) );
						self::field( __( 'Minimum seconds to submit a form', 'magstack-coaches' ), self::input( 'min_submit_seconds', $s['min_submit_seconds'], 'number', array( 'min' => 0, 'style' => 'width:90px' ) ) );
						self::field( __( 'SMS channel', 'magstack-coaches' ), self::check( 'channel_sms', (bool) $s['channel_sms'], __( 'Enable (requires a provider hooked into the magstack_channel_sms_send filter)', 'magstack-coaches' ) ) );
						self::field( __( 'Viber channel', 'magstack-coaches' ), self::check( 'channel_viber', (bool) $s['channel_viber'], __( 'Enable (requires a provider hooked into the magstack_channel_viber_send filter)', 'magstack-coaches' ) ) );
						break;
				}
				?>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
