<?php
/** @var array $f public facility data */
defined( 'ABSPATH' ) || exit;
$fee = $f['pay_at_venue'] ? __( 'Pay at venue', 'magstack-coaches' ) : ( null !== $f['court_fee'] ? \Magstack\Coaches\Helpers::money( $f['court_fee'] ) : '' );
?>
<article class="mc-card">
	<a class="mc-card__photo" href="<?php echo esc_url( $f['url'] ); ?>" tabindex="-1" aria-hidden="true">
		<?php if ( $f['photo'] ) : ?><img src="<?php echo esc_url( $f['photo'] ); ?>" alt="" loading="lazy"><?php else : ?><span class="mc-card__ph">🏓</span><?php endif; ?>
	</a>
	<div class="mc-card__body">
		<h3 class="mc-card__name"><a href="<?php echo esc_url( $f['url'] ); ?>"><?php echo esc_html( $f['name'] ); ?></a> <?php if ( $f['partner'] ) : ?><span class="mc-badge mc-badge--partner"><?php esc_html_e( 'Partner', 'magstack-coaches' ); ?></span><?php endif; ?></h3>
		<p class="mc-card__meta"><?php echo esc_html( implode( ' · ', array_filter( array( $f['address'], $f['courts'] ? $f['courts'] . ' ' . __( 'courts', 'magstack-coaches' ) : '', $f['surface'] ) ) ) ); ?></p>
		<?php if ( $f['hours'] ) : ?><p class="mc-card__levels"><?php echo esc_html( $f['hours'] ); ?></p><?php endif; ?>
		<?php if ( $fee ) : ?><p class="mc-card__price"><small><?php esc_html_e( 'Court fee:', 'magstack-coaches' ); ?></small> <strong><?php echo esc_html( $fee ); ?></strong></p><?php endif; ?>
		<p class="mc-card__cta"><a class="mc-btn mc-btn--sm" href="<?php echo esc_url( $f['book_url'] ); ?>"><?php esc_html_e( 'Book a coach here', 'magstack-coaches' ); ?></a></p>
	</div>
</article>
