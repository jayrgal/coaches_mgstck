<?php
/** @var string[] $errors @var array $v @var array $facilities @var string $site_key */
defined( 'ABSPATH' ) || exit;
$val = static fn( $k, $d = '' ) => isset( $v[ $k ] ) && is_string( $v[ $k ] ) ? $v[ $k ] : $d;
$sel_levels = (array) ( $v['levels'] ?? array() );
$sel_fac    = array_map( 'intval', (array) ( $v['venue_facilities'] ?? array() ) );
?>
<div class="mc mc-register">
	<?php if ( $errors ) : ?>
		<div class="mc-notice mc-notice--err" role="alert"><ul><?php foreach ( $errors as $e ) : ?><li><?php echo esc_html( $e ); ?></li><?php endforeach; ?></ul></div>
	<?php endif; ?>
	<form method="post" enctype="multipart/form-data" class="mc-form" id="mc-register-form">
		<?php wp_nonce_field( 'mc_register', '_mc_nonce' ); ?>
		<?php echo \Magstack\Coaches\Spam::fields(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
		<input type="hidden" name="mc_register" value="1">
		<input type="hidden" name="mc_recaptcha" value="">

		<fieldset class="mc-fieldset">
			<legend><?php esc_html_e( 'About you', 'magstack-coaches' ); ?></legend>
			<div class="mc-field"><label for="mc-name"><?php esc_html_e( 'Full name', 'magstack-coaches' ); ?> *</label><input id="mc-name" name="name" type="text" required autocomplete="name" value="<?php echo esc_attr( $val( 'name' ) ); ?>"></div>
			<div class="mc-row">
				<div class="mc-field"><label for="mc-email"><?php esc_html_e( 'Email', 'magstack-coaches' ); ?> *</label><input id="mc-email" name="email" type="email" required autocomplete="email" value="<?php echo esc_attr( $val( 'email' ) ); ?>"></div>
				<div class="mc-field"><label for="mc-mobile"><?php esc_html_e( 'Mobile (Viber/GCash)', 'magstack-coaches' ); ?> *</label><input id="mc-mobile" name="mobile" type="tel" required autocomplete="tel" inputmode="tel" placeholder="09XX XXX XXXX" value="<?php echo esc_attr( $val( 'mobile' ) ); ?>"></div>
			</div>
			<div class="mc-field"><label for="mc-photo"><?php esc_html_e( 'Photo', 'magstack-coaches' ); ?></label><input id="mc-photo" name="mc_photo" type="file" accept="image/jpeg,image/png,image/webp"></div>
			<div class="mc-field"><label for="mc-bio"><?php esc_html_e( 'Short bio', 'magstack-coaches' ); ?> *</label><textarea id="mc-bio" name="bio" rows="4" maxlength="1200" required><?php echo esc_textarea( $val( 'bio' ) ); ?></textarea></div>
		</fieldset>

		<fieldset class="mc-fieldset">
			<legend><?php esc_html_e( 'Credentials', 'magstack-coaches' ); ?></legend>
			<div class="mc-row">
				<div class="mc-field"><label for="mc-dupr"><?php esc_html_e( 'DUPR rating (optional)', 'magstack-coaches' ); ?></label><input id="mc-dupr" name="dupr" type="text" inputmode="decimal" placeholder="4.5" value="<?php echo esc_attr( $val( 'dupr' ) ); ?>"></div>
				<div class="mc-field"><label for="mc-years"><?php esc_html_e( 'Years of experience', 'magstack-coaches' ); ?></label><input id="mc-years" name="years" type="number" min="0" max="60" value="<?php echo esc_attr( $val( 'years' ) ); ?>"></div>
			</div>
			<div class="mc-field"><label for="mc-certs"><?php esc_html_e( 'Certifications', 'magstack-coaches' ); ?></label><textarea id="mc-certs" name="certifications" rows="2"><?php echo esc_textarea( $val( 'certifications' ) ); ?></textarea></div>
		</fieldset>

		<fieldset class="mc-fieldset">
			<legend><?php esc_html_e( 'Coaching', 'magstack-coaches' ); ?></legend>
			<div class="mc-field">
				<span class="mc-label" id="mc-levels-l"><?php esc_html_e( 'Coaching type', 'magstack-coaches' ); ?> *</span>
				<div class="mc-chips" role="group" aria-labelledby="mc-levels-l">
					<?php foreach ( \Magstack\Coaches\Helpers::level_labels() as $k => $label ) : ?>
						<label class="mc-chip"><input type="checkbox" name="levels[]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, $sel_levels, true ) ); ?>><span><?php echo esc_html( $label ); ?></span></label>
					<?php endforeach; ?>
				</div>
			</div>
			<div class="mc-row">
				<div class="mc-field"><label for="mc-rate"><?php echo esc_html( sprintf( /* translators: %s: currency */ __( 'Session rate (%s per hour)', 'magstack-coaches' ), \Magstack\Coaches\Settings::get( 'currency', 'PHP' ) ) ); ?> *</label><input id="mc-rate" name="rate" type="number" min="1" step="1" required inputmode="numeric" value="<?php echo esc_attr( $val( 'rate' ) ); ?>"></div>
				<div class="mc-field"><label for="mc-durations"><?php esc_html_e( 'Session durations (minutes, comma separated)', 'magstack-coaches' ); ?></label><input id="mc-durations" name="durations" type="text" placeholder="60, 90" value="<?php echo esc_attr( $val( 'durations', '60' ) ); ?>"></div>
			</div>
			<div class="mc-field"><label for="mc-city"><?php esc_html_e( 'Service area (city)', 'magstack-coaches' ); ?> *</label><input id="mc-city" name="city" type="text" required placeholder="Makati" value="<?php echo esc_attr( $val( 'city' ) ); ?>"></div>
		</fieldset>

		<fieldset class="mc-fieldset">
			<legend><?php esc_html_e( 'Preferred venues', 'magstack-coaches' ); ?></legend>
			<?php if ( $facilities ) : ?>
				<div class="mc-chips" role="group" aria-label="<?php esc_attr_e( 'Partner facilities', 'magstack-coaches' ); ?>">
					<?php foreach ( $facilities as $f ) : ?>
						<label class="mc-chip"><input type="checkbox" name="venue_facilities[]" value="<?php echo (int) $f['id']; ?>" <?php checked( in_array( (int) $f['id'], $sel_fac, true ) ); ?>><span><?php echo esc_html( $f['name'] ); ?></span></label>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<div class="mc-field"><label for="mc-venues"><?php esc_html_e( 'Other venues (one per line: Name | Address)', 'magstack-coaches' ); ?></label><textarea id="mc-venues" name="venues" rows="3"><?php echo esc_textarea( $val( 'venues' ) ); ?></textarea></div>
		</fieldset>

		<fieldset class="mc-fieldset">
			<legend><?php esc_html_e( 'Session packages (optional)', 'magstack-coaches' ); ?></legend>
			<p class="mc-muted"><?php esc_html_e( 'Offer a bundle, e.g. 5 sessions at a set price.', 'magstack-coaches' ); ?></p>
			<?php for ( $i = 0; $i < 2; $i++ ) : $row = $v['pkg'][ $i ] ?? array(); ?>
				<div class="mc-row mc-row--3">
					<div class="mc-field"><label for="mc-pkg-n-<?php echo (int) $i; ?>"><?php esc_html_e( 'Name', 'magstack-coaches' ); ?></label><input id="mc-pkg-n-<?php echo (int) $i; ?>" name="pkg[<?php echo (int) $i; ?>][name]" type="text" value="<?php echo esc_attr( $row['name'] ?? '' ); ?>"></div>
					<div class="mc-field"><label for="mc-pkg-s-<?php echo (int) $i; ?>"><?php esc_html_e( 'Sessions', 'magstack-coaches' ); ?></label><input id="mc-pkg-s-<?php echo (int) $i; ?>" name="pkg[<?php echo (int) $i; ?>][sessions]" type="number" min="2" value="<?php echo esc_attr( $row['sessions'] ?? '' ); ?>"></div>
					<div class="mc-field"><label for="mc-pkg-p-<?php echo (int) $i; ?>"><?php esc_html_e( 'Total price', 'magstack-coaches' ); ?></label><input id="mc-pkg-p-<?php echo (int) $i; ?>" name="pkg[<?php echo (int) $i; ?>][price]" type="number" min="1" value="<?php echo esc_attr( $row['price'] ?? '' ); ?>"></div>
				</div>
			<?php endfor; ?>
		</fieldset>

		<fieldset class="mc-fieldset">
			<legend><?php esc_html_e( 'Social links (optional)', 'magstack-coaches' ); ?></legend>
			<div class="mc-row">
				<?php foreach ( array( 'facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok', 'youtube' => 'YouTube', 'website' => __( 'Website', 'magstack-coaches' ) ) as $k => $label ) : ?>
					<div class="mc-field"><label for="mc-soc-<?php echo esc_attr( $k ); ?>"><?php echo esc_html( $label ); ?></label><input id="mc-soc-<?php echo esc_attr( $k ); ?>" name="social_<?php echo esc_attr( $k ); ?>" type="url" placeholder="https://" value="<?php echo esc_attr( $val( 'social_' . $k ) ); ?>"></div>
				<?php endforeach; ?>
			</div>
		</fieldset>

		<label class="mc-check"><input type="checkbox" name="terms" value="1" required <?php checked( ! empty( $v['terms'] ) ); ?>> <?php esc_html_e( 'I confirm my details are accurate and agree to the site terms.', 'magstack-coaches' ); ?></label>
		<button type="submit" class="mc-btn mc-btn--lg"><?php esc_html_e( 'Submit application', 'magstack-coaches' ); ?></button>
	</form>
	<?php if ( $site_key ) : ?>
		<script>
		(function(){var f=document.getElementById('mc-register-form');if(!f)return;var ok=false;f.addEventListener('submit',function(e){if(ok||typeof grecaptcha==='undefined')return;e.preventDefault();grecaptcha.ready(function(){grecaptcha.execute(<?php echo wp_json_encode( $site_key ); ?>,{action:'coach_register'}).then(function(t){f.mc_recaptcha.value=t;ok=true;f.submit();});});});})();
		</script>
	<?php endif; ?>
</div>
