<?php
/** @var array $coaches @var array $f @var bool $show_filters @var array $cities @var string $action */
defined( 'ABSPATH' ) || exit;
?>
<div class="mc mc-directory">
	<?php if ( $show_filters ) : ?>
	<form class="mc-filters" method="get" action="<?php echo esc_url( $action ); ?>" role="search" aria-label="<?php esc_attr_e( 'Filter coaches', 'magstack-coaches' ); ?>">
		<div class="mc-field"><label for="mc-f-city"><?php esc_html_e( 'Location', 'magstack-coaches' ); ?></label>
			<select id="mc-f-city" name="mc_city"><option value=""><?php esc_html_e( 'Any location', 'magstack-coaches' ); ?></option>
				<?php foreach ( $cities as $label ) : ?><option value="<?php echo esc_attr( $label ); ?>" <?php selected( 0 === strcasecmp( $f['city'], $label ) ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
			</select></div>
		<div class="mc-field"><label for="mc-f-level"><?php esc_html_e( 'Skill level', 'magstack-coaches' ); ?></label>
			<select id="mc-f-level" name="mc_level"><option value=""><?php esc_html_e( 'Any level', 'magstack-coaches' ); ?></option>
				<?php foreach ( \Magstack\Coaches\Helpers::level_labels() as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $f['level'], $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?>
			</select></div>
		<div class="mc-field"><label for="mc-f-min"><?php esc_html_e( 'Min price', 'magstack-coaches' ); ?></label><input id="mc-f-min" type="number" name="mc_min" min="0" inputmode="numeric" value="<?php echo esc_attr( (string) $f['min_price'] ); ?>"></div>
		<div class="mc-field"><label for="mc-f-max"><?php esc_html_e( 'Max price', 'magstack-coaches' ); ?></label><input id="mc-f-max" type="number" name="mc_max" min="0" inputmode="numeric" value="<?php echo esc_attr( (string) $f['max_price'] ); ?>"></div>
		<div class="mc-field"><label for="mc-f-date"><?php esc_html_e( 'Available on', 'magstack-coaches' ); ?></label><input id="mc-f-date" type="date" name="mc_date" min="<?php echo esc_attr( \Magstack\Coaches\Helpers::now()->format( 'Y-m-d' ) ); ?>" value="<?php echo esc_attr( $f['date'] ); ?>"></div>
		<div class="mc-field mc-field--actions"><button class="mc-btn" type="submit"><?php esc_html_e( 'Search', 'magstack-coaches' ); ?></button> <a class="mc-link" href="<?php echo esc_url( $action ); ?>"><?php esc_html_e( 'Reset', 'magstack-coaches' ); ?></a></div>
	</form>
	<?php endif; ?>
	<p class="mc-count" role="status"><?php echo esc_html( sprintf( /* translators: %d: coach count */ _n( '%d coach found', '%d coaches found', count( $coaches ), 'magstack-coaches' ), count( $coaches ) ) ); ?></p>
	<?php if ( $coaches ) : ?>
		<div class="mc-grid">
			<?php
	foreach ( $coaches as $c ) {
		echo \Magstack\Coaches\Helpers::template( 'coach-card', array( 'c' => $c ) ); // phpcs:ignore WordPress.Security.EscapeOutput
	}
	?>
		</div>
	<?php else : ?>
		<p class="mc-empty"><?php esc_html_e( 'No coaches match your filters yet. Try widening your search.', 'magstack-coaches' ); ?></p>
	<?php endif; ?>
</div>
