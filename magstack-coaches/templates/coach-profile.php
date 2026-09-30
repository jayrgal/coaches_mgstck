<?php
/** @var array $c @var array $packages @var string[] $venues @var array $socials @var array $weekly @var array $next @var array $reviews @var bool $listable */
defined( 'ABSPATH' ) || exit;
use Magstack\Coaches\Helpers;
$labels = Helpers::level_labels();
$days   = Helpers::day_labels();
?>
<div class="mc mc-profile">
	<header class="mc-profile__head">
		<?php if ( $c['photo'] ) : ?><img class="mc-profile__photo" src="<?php echo esc_url( $c['photo'] ); ?>" alt="<?php echo esc_attr( $c['name'] ); ?>"><?php endif; ?>
		<div>
			<p class="mc-profile__badges"><?php if ( $c['verified'] ) { echo Helpers::badge_verified(); } // phpcs:ignore ?> <?php echo Helpers::stars( $c['rating'], $c['rating_n'] ); // phpcs:ignore ?></p>
			<p class="mc-profile__meta"><?php echo esc_html( implode( ' · ', array_filter( array( $c['city'], $c['levels'] ? implode( ', ', array_map( static fn( $l ) => $labels[ $l ] ?? $l, $c['levels'] ) ) : '' ) ) ) ); ?></p>
			<p class="mc-card__price"><strong><?php echo esc_html( Helpers::money( $c['rate'] ) ); ?></strong> <small><?php esc_html_e( '/ hour', 'magstack-coaches' ); ?></small></p>
			<?php if ( $listable ) : ?><p><a class="mc-btn mc-btn--lg" href="<?php echo esc_url( $c['booking_url'] ); ?>"><?php esc_html_e( 'Book now', 'magstack-coaches' ); ?></a></p><?php endif; ?>
		</div>
	</header>

	<?php if ( $c['bio'] ) : ?><section><h2><?php esc_html_e( 'About', 'magstack-coaches' ); ?></h2><?php echo wp_kses_post( wpautop( esc_html( $c['bio'] ) ) ); ?></section><?php endif; ?>

	<section>
		<h2><?php esc_html_e( 'Credentials', 'magstack-coaches' ); ?></h2>
		<dl class="mc-dl">
			<?php if ( $c['dupr'] ) : ?><dt>DUPR</dt><dd><?php echo esc_html( $c['dupr'] ); ?></dd><?php endif; ?>
			<?php if ( $c['years'] ) : ?><dt><?php esc_html_e( 'Experience', 'magstack-coaches' ); ?></dt><dd><?php echo esc_html( sprintf( /* translators: %d: years */ _n( '%d year', '%d years', $c['years'], 'magstack-coaches' ), $c['years'] ) ); ?></dd><?php endif; ?>
			<?php if ( $c['certs'] ) : ?><dt><?php esc_html_e( 'Certifications', 'magstack-coaches' ); ?></dt><dd><?php echo nl2br( esc_html( $c['certs'] ) ); ?></dd><?php endif; ?>
			<dt><?php esc_html_e( 'Session lengths', 'magstack-coaches' ); ?></dt><dd><?php echo esc_html( implode( ', ', array_map( static fn( $m ) => $m . ' min', $c['durations'] ) ) ); ?></dd>
			<?php if ( $venues ) : ?><dt><?php esc_html_e( 'Preferred venues', 'magstack-coaches' ); ?></dt><dd><?php echo esc_html( implode( ', ', $venues ) ); ?></dd><?php endif; ?>
		</dl>
		<?php if ( $socials ) : ?><p class="mc-socials"><?php foreach ( $socials as $net => $url ) : ?><a href="<?php echo esc_url( $url ); ?>" rel="noopener nofollow" target="_blank"><?php echo esc_html( ucfirst( $net ) ); ?></a> <?php endforeach; ?></p><?php endif; ?>
		<p class="mc-muted"><?php esc_html_e( 'Coach contact details are shared once your booking is confirmed.', 'magstack-coaches' ); ?></p>
	</section>

	<?php if ( $packages ) : ?>
	<section>
		<h2><?php esc_html_e( 'Session packages', 'magstack-coaches' ); ?></h2>
		<ul class="mc-packages">
			<?php foreach ( $packages as $p ) : ?>
				<li><strong><?php echo esc_html( $p['name'] ); ?></strong> – <?php echo esc_html( sprintf( /* translators: %d: sessions */ _n( '%d session', '%d sessions', (int) $p['sessions'], 'magstack-coaches' ), (int) $p['sessions'] ) ); ?> · <?php echo esc_html( Helpers::money( $p['price'] ) ); ?></li>
			<?php endforeach; ?>
		</ul>
	</section>
	<?php endif; ?>

	<section>
		<h2><?php esc_html_e( 'Availability', 'magstack-coaches' ); ?></h2>
		<?php if ( $next ) : ?>
			<p><?php esc_html_e( 'Next available dates:', 'magstack-coaches' ); ?></p>
			<p class="mc-chips">
				<?php foreach ( $next as $date => $n ) : ?>
					<a class="mc-chip mc-chip--link" href="<?php echo esc_url( add_query_arg( 'date', $date, $c['booking_url'] ) ); ?>"><span><?php echo esc_html( wp_date( 'D, M j', Helpers::dt( $date )->getTimestamp() ) ); ?></span></a>
				<?php endforeach; ?>
			</p>
		<?php else : ?>
			<p class="mc-muted"><?php esc_html_e( 'No open slots in the next 3 weeks. Check back soon.', 'magstack-coaches' ); ?></p>
		<?php endif; ?>
		<table class="mc-table mc-table--week">
			<caption class="screen-reader-text"><?php esc_html_e( 'Weekly availability', 'magstack-coaches' ); ?></caption>
			<tbody>
			<?php foreach ( $days as $k => $label ) : $r = $weekly[ $k ] ?? array(); ?>
				<tr><th scope="row"><?php echo esc_html( $label ); ?></th><td><?php echo $r ? esc_html( implode( ', ', array_map( static fn( $x ) => Helpers::fmt_time( $x[0] ) . '–' . Helpers::fmt_time( $x[1] ), $r ) ) ) : '<span class="mc-muted">—</span>'; // phpcs:ignore ?></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</section>

	<section>
		<h2><?php esc_html_e( 'Reviews', 'magstack-coaches' ); ?></h2>
		<?php if ( $reviews ) : ?>
			<?php foreach ( $reviews as $rv ) : ?><blockquote><?php echo esc_html( $rv['text'] ?? '' ); ?><cite> – <?php echo esc_html( $rv['author'] ?? '' ); ?></cite></blockquote><?php endforeach; ?>
		<?php else : ?>
			<p class="mc-muted"><?php esc_html_e( 'No reviews yet.', 'magstack-coaches' ); ?></p>
		<?php endif; ?>
	</section>

	<?php if ( $listable ) : ?><p class="mc-sticky-cta"><a class="mc-btn mc-btn--lg" href="<?php echo esc_url( $c['booking_url'] ); ?>"><?php esc_html_e( 'Book now', 'magstack-coaches' ); ?></a></p><?php endif; ?>
</div>
