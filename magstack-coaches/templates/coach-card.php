<?php
/** @var array $c public coach data */
defined( 'ABSPATH' ) || exit;
$labels = \Magstack\Coaches\Helpers::level_labels();
?>
<article class="mc-card">
	<a class="mc-card__photo" href="<?php echo esc_url( $c['url'] ); ?>" tabindex="-1" aria-hidden="true">
		<?php if ( $c['photo'] ) : ?><img src="<?php echo esc_url( $c['photo'] ); ?>" alt="" loading="lazy"><?php else : ?><span class="mc-card__ph"><?php echo esc_html( mb_substr( $c['name'], 0, 1 ) ); ?></span><?php endif; ?>
	</a>
	<div class="mc-card__body">
		<h3 class="mc-card__name"><a href="<?php echo esc_url( $c['url'] ); ?>"><?php echo esc_html( $c['name'] ); ?></a> <?php if ( $c['verified'] ) { echo \Magstack\Coaches\Helpers::badge_verified(); } // phpcs:ignore ?></h3>
		<p class="mc-card__meta"><?php echo \Magstack\Coaches\Helpers::stars( $c['rating'], $c['rating_n'] ); // phpcs:ignore ?><?php if ( $c['city'] ) : ?> · <span><?php echo esc_html( $c['city'] ); ?></span><?php endif; ?></p>
		<?php if ( $c['levels'] ) : ?>
			<p class="mc-card__levels"><?php echo esc_html( implode( ' · ', array_map( static fn( $l ) => $labels[ $l ] ?? $l, $c['levels'] ) ) ); ?></p>
		<?php endif; ?>
		<p class="mc-card__price"><strong><?php echo esc_html( \Magstack\Coaches\Helpers::money( $c['rate'] ) ); ?></strong> <small><?php esc_html_e( '/ hour', 'magstack-coaches' ); ?></small></p>
		<p class="mc-card__cta"><a class="mc-btn mc-btn--sm" href="<?php echo esc_url( $c['booking_url'] ); ?>"><?php esc_html_e( 'Book now', 'magstack-coaches' ); ?></a> <a class="mc-link" href="<?php echo esc_url( $c['url'] ); ?>"><?php esc_html_e( 'View profile', 'magstack-coaches' ); ?></a></p>
	</div>
</article>
