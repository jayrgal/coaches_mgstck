<?php
/** @var int $coach_id @var string $fee */
defined( 'ABSPATH' ) || exit;
?>
<div class="mc mc-dash" id="mc-dash">
	<header class="mc-dash__head">
		<h2><?php echo esc_html( get_the_title( $coach_id ) ); ?></h2>
		<?php if ( 'due' === $fee ) : ?>
			<p class="mc-notice mc-notice--err" role="alert"><?php esc_html_e( 'Your monthly listing fee is due. Please settle it with the admin to stay visible in the directory.', 'magstack-coaches' ); ?></p>
		<?php elseif ( 'founding' === $fee ) : ?>
			<p class="mc-notice mc-notice--ok"><?php esc_html_e( 'Founding Coach: your listing is free during the founding period.', 'magstack-coaches' ); ?></p>
		<?php endif; ?>
	</header>
	<div id="mc-dash-app" aria-live="polite"><p><?php esc_html_e( 'Loading…', 'magstack-coaches' ); ?></p></div>
	<noscript><p><?php esc_html_e( 'Please enable JavaScript to use the dashboard.', 'magstack-coaches' ); ?></p></noscript>
</div>
