<?php
/** @var array $f */
defined( 'ABSPATH' ) || exit;
$fee = $f['pay_at_venue'] ? __( 'Pay at venue', 'magstack-coaches' ) : ( null !== $f['court_fee'] ? \Magstack\Coaches\Helpers::money( $f['court_fee'] ) : '' );
?>
<div class="mc mc-facility">
	<?php if ( $f['partner'] ) : ?><p><span class="mc-badge mc-badge--partner"><?php esc_html_e( 'Partner facility', 'magstack-coaches' ); ?></span></p><?php endif; ?>
	<dl class="mc-dl">
		<?php if ( $f['address'] ) : ?><dt><?php esc_html_e( 'Address', 'magstack-coaches' ); ?></dt><dd><?php echo esc_html( $f['address'] ); ?></dd><?php endif; ?>
		<?php if ( $f['courts'] ) : ?><dt><?php esc_html_e( 'Courts', 'magstack-coaches' ); ?></dt><dd><?php echo (int) $f['courts']; ?></dd><?php endif; ?>
		<?php if ( $f['surface'] ) : ?><dt><?php esc_html_e( 'Surface', 'magstack-coaches' ); ?></dt><dd><?php echo esc_html( $f['surface'] ); ?></dd><?php endif; ?>
		<?php if ( $f['hours'] ) : ?><dt><?php esc_html_e( 'Hours', 'magstack-coaches' ); ?></dt><dd><?php echo esc_html( $f['hours'] ); ?></dd><?php endif; ?>
		<?php if ( $f['contact'] ) : ?><dt><?php esc_html_e( 'Contact', 'magstack-coaches' ); ?></dt><dd><?php echo esc_html( $f['contact'] ); ?></dd><?php endif; ?>
		<?php if ( $fee ) : ?><dt><?php esc_html_e( 'Court fee', 'magstack-coaches' ); ?></dt><dd><?php echo esc_html( $fee . ( $f['court_fee_note'] ? ' – ' . $f['court_fee_note'] : '' ) ); ?></dd><?php endif; ?>
	</dl>
	<?php if ( $f['magstack_url'] ) : ?><p><a href="<?php echo esc_url( $f['magstack_url'] ); ?>" rel="noopener"><?php esc_html_e( 'Reserve a court on Magstack', 'magstack-coaches' ); ?></a></p><?php endif; ?>
	<p><a class="mc-btn" href="<?php echo esc_url( $f['book_url'] ); ?>"><?php esc_html_e( 'Book a coach here', 'magstack-coaches' ); ?></a></p>
	<?php if ( null !== $f['lat'] && null !== $f['lng'] ) : ?>
		<?php echo do_shortcode( '[magstack_map height="320"]' ); // phpcs:ignore ?>
	<?php endif; ?>
</div>
