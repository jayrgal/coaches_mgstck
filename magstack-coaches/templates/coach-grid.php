<?php
/** @var array $coaches */
defined( 'ABSPATH' ) || exit;
?>
<div class="mc mc-grid">
	<?php
	foreach ( $coaches as $c ) {
		echo \Magstack\Coaches\Helpers::template( 'coach-card', array( 'c' => $c ) ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
	?>
</div>
