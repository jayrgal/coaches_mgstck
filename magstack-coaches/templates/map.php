<?php
/** @var int $height @var array $facilities */
defined( 'ABSPATH' ) || exit;
wp_localize_script(
	'magstack-map',
	'magstackMap',
	array(
		'currency' => \Magstack\Coaches\Settings::get( 'currency_symbol', '₱' ),
		'i18n'     => array(
			'partner'    => __( 'Partner', 'magstack-coaches' ),
			'courts'     => __( 'courts', 'magstack-coaches' ),
			'payAtVenue' => __( 'Court fee: pay at venue', 'magstack-coaches' ),
			'courtFee'   => __( 'Court fee', 'magstack-coaches' ),
			'bookHere'   => __( 'Book a coach here', 'magstack-coaches' ),
			'locating'   => __( 'Finding your location…', 'magstack-coaches' ),
			'you'        => __( 'You are here', 'magstack-coaches' ),
			'nearest'    => __( 'Nearest court:', 'magstack-coaches' ),
			'locDenied'  => __( 'Could not get your location. Please allow location access.', 'magstack-coaches' ),
		),
	)
);
?>
<div class="mc mc-map" data-facilities="<?php echo esc_attr( wp_json_encode( $facilities ) ); ?>">
	<div class="mc-map__bar">
		<button type="button" class="mc-btn mc-btn--ghost" data-mc-near><?php esc_html_e( 'Near me', 'magstack-coaches' ); ?></button>
		<span class="mc-map__status" role="status" aria-live="polite"></span>
	</div>
	<div class="mc-map__layout">
		<div class="mc-map__canvas" style="height:<?php echo (int) $height; ?>px" role="application" aria-label="<?php esc_attr_e( 'Map of pickleball facilities', 'magstack-coaches' ); ?>"></div>
		<ul class="mc-map__list" aria-label="<?php esc_attr_e( 'Facilities', 'magstack-coaches' ); ?>"></ul>
	</div>
	<noscript>
		<ul>
			<?php foreach ( $facilities as $f ) : ?>
				<li><?php echo esc_html( $f['name'] . ( $f['address'] ? ' – ' . $f['address'] : '' ) ); ?></li>
			<?php endforeach; ?>
		</ul>
	</noscript>
</div>
