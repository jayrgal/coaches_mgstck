<?php
defined( 'ABSPATH' ) || exit;
?>
<div class="mc mc-booking" id="mc-booking">
	<div id="mc-booking-app" aria-live="polite"><p><?php esc_html_e( 'Loading…', 'magstack-coaches' ); ?></p></div>
	<?php echo \Magstack\Coaches\Spam::fields(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
	<noscript><p><?php esc_html_e( 'Please enable JavaScript to book a coach.', 'magstack-coaches' ); ?></p></noscript>
</div>
