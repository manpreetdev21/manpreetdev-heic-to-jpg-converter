<?php
/**
 * Media → HEIC Converter: settings and conversion of existing images.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
	$GLOBALS['hic_page_hook'] = add_media_page(
		__( 'HEIC Converter', 'heic-to-jpg-converter' ),
		__( 'HEIC Converter', 'heic-to-jpg-converter' ),
		'upload_files',
		'hic-converter',
		'hic_render_page'
	);
} );

add_action( 'admin_init', function () {
	register_setting( 'hic_settings_group', 'hic_settings', array(
		'type'              => 'array',
		'sanitize_callback' => 'hic_sanitize_settings',
		'default'           => hic_defaults(),
	) );
} );

function hic_sanitize_settings( $input ) {
	$input = is_array( $input ) ? $input : array();
	return array(
		'format'          => ( isset( $input['format'] ) && 'png' === $input['format'] ) ? 'png' : 'jpg',
		'quality'         => isset( $input['quality'] ) ? max( 10, min( 100, (int) $input['quality'] ) ) : 85,
		'max_size'        => isset( $input['max_size'] ) ? max( 0, min( 20000, (int) $input['max_size'] ) ) : 4096,
		'convert_uploads' => empty( $input['convert_uploads'] ) ? 0 : 1,
		'auto_existing'   => empty( $input['auto_existing'] ) ? 0 : 1,
		'keep_original'   => empty( $input['keep_original'] ) ? 0 : 1,
		'replace_urls'    => empty( $input['replace_urls'] ) ? 0 : 1,
	);
}

/** Admin notices: background progress, or a prompt when auto-conversion is off. */
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'upload_files' ) ) {
		return;
	}
	$screen = get_current_screen();
	if ( $screen && $GLOBALS['hic_page_hook'] && $screen->id === $GLOBALS['hic_page_hook'] ) {
		return;
	}

	if ( ! empty( $GLOBALS['hic_bg_active'] ) ) {
		printf(
			'<div class="notice notice-info hic-bg-notice"><p><strong>%s</strong> <span id="hic-progress">%s</span> <a href="%s">%s</a></p></div>',
			esc_html__( 'HEIC Converter:', 'heic-to-jpg-converter' ),
			esc_html__( 'Preparing…', 'heic-to-jpg-converter' ),
			esc_url( admin_url( 'upload.php?page=hic-converter' ) ),
			esc_html__( 'Details', 'heic-to-jpg-converter' )
		);
		return;
	}

	$s = hic_get_settings();
	if ( ! empty( $s['auto_existing'] ) || ! $screen || 'upload' !== $screen->id ) {
		return;
	}
	$count = hic_pending_count();
	if ( $count ) {
		printf(
			'<div class="notice notice-warning"><p>%s <a class="button" href="%s">%s</a></p></div>',
			esc_html( sprintf(
				/* translators: %d: number of images */
				_n( '%d HEIC image cannot be displayed in most browsers.', '%d HEIC images cannot be displayed in most browsers.', $count, 'heic-to-jpg-converter' ),
				$count
			) ),
			esc_url( admin_url( 'upload.php?page=hic-converter' ) ),
			esc_html__( 'Convert now', 'heic-to-jpg-converter' )
		);
	}
} );

function hic_render_page() {
	if ( ! current_user_can( 'upload_files' ) ) {
		return;
	}
	$s     = hic_get_settings();
	$ids   = hic_get_pending_ids( 200, true );
	$total = hic_pending_count();
	$label = 'png' === $s['format'] ? 'PNG' : 'JPG';
	?>
	<div class="wrap hic-wrap">
		<h1><?php esc_html_e( 'HEIC Converter', 'heic-to-jpg-converter' ); ?></h1>
		<p class="description">
			<?php esc_html_e( 'iPhone HEIC/HEIF photos are converted to JPG or PNG inside your browser. The server does not need ImageMagick or any HEIC support.', 'heic-to-jpg-converter' ); ?>
		</p>

		<?php if ( current_user_can( 'manage_options' ) ) : ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'hic_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Convert to', 'heic-to-jpg-converter' ); ?></th>
						<td>
							<label><input type="radio" name="hic_settings[format]" value="jpg" <?php checked( $s['format'], 'jpg' ); ?>> JPG</label>
							&nbsp;&nbsp;
							<label><input type="radio" name="hic_settings[format]" value="png" <?php checked( $s['format'], 'png' ); ?>> PNG</label>
							<p class="description"><?php esc_html_e( 'JPG is recommended for photos: files are much smaller than PNG.', 'heic-to-jpg-converter' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hic-quality"><?php esc_html_e( 'JPG quality', 'heic-to-jpg-converter' ); ?></label></th>
						<td>
							<input id="hic-quality" type="number" min="10" max="100" step="1" name="hic_settings[quality]" value="<?php echo esc_attr( $s['quality'] ); ?>" class="small-text"> %
							<p class="description"><?php esc_html_e( '85 gives a good balance of quality and file size.', 'heic-to-jpg-converter' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="hic-max-size"><?php esc_html_e( 'Maximum size', 'heic-to-jpg-converter' ); ?></label></th>
						<td>
							<input id="hic-max-size" type="number" min="0" max="20000" step="1" name="hic_settings[max_size]" value="<?php echo esc_attr( $s['max_size'] ); ?>" class="small-text"> px
							<p class="description"><?php esc_html_e( 'Longest side after conversion. Large iPhone photos (up to 48 MP) are scaled down to this so they upload quickly and stay under server upload limits. 0 keeps the original size (browsers still cap very large photos at about 16 megapixels).', 'heic-to-jpg-converter' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Automatic conversion', 'heic-to-jpg-converter' ); ?></th>
						<td>
							<fieldset>
								<label><input type="checkbox" name="hic_settings[convert_uploads]" value="1" <?php checked( $s['convert_uploads'], 1 ); ?>>
									<?php esc_html_e( 'Convert HEIC files while they are being uploaded (Media Library, Elementor, block editor)', 'heic-to-jpg-converter' ); ?></label><br>
								<label><input type="checkbox" name="hic_settings[auto_existing]" value="1" <?php checked( $s['auto_existing'], 1 ); ?>>
									<?php esc_html_e( 'Convert HEIC images already in the Media Library automatically, in the background, while an editor is logged in to the dashboard', 'heic-to-jpg-converter' ); ?></label>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'After converting', 'heic-to-jpg-converter' ); ?></th>
						<td>
							<fieldset>
								<label><input type="checkbox" name="hic_settings[keep_original]" value="1" <?php checked( $s['keep_original'], 1 ); ?>>
									<?php esc_html_e( 'Keep the original .heic file on the server', 'heic-to-jpg-converter' ); ?></label><br>
								<label><input type="checkbox" name="hic_settings[replace_urls]" value="1" <?php checked( $s['replace_urls'], 1 ); ?>>
									<?php esc_html_e( 'Update old .heic image links in posts, pages and Elementor layouts', 'heic-to-jpg-converter' ); ?></label>
							</fieldset>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		<?php endif; ?>

		<h2><?php esc_html_e( 'HEIC images in the Media Library', 'heic-to-jpg-converter' ); ?></h2>

		<?php if ( ! $ids ) : ?>
			<p><?php esc_html_e( 'There are no HEIC images left to convert.', 'heic-to-jpg-converter' ); ?></p>
		<?php else : ?>
			<p>
				<button type="button" id="hic-start" class="button button-primary">
					<?php
					echo esc_html( sprintf(
						/* translators: 1: number of images, 2: JPG or PNG */
						_n( 'Convert %1$d image to %2$s', 'Convert %1$d images to %2$s', count( $ids ), 'heic-to-jpg-converter' ),
						count( $ids ),
						$label
					) );
					?>
				</button>
				<span id="hic-progress" class="hic-progress" aria-live="polite"></span>
			</p>
			<?php if ( $total > count( $ids ) ) : ?>
				<p class="description"><?php esc_html_e( 'Showing the newest 200. Reload this page after they finish to continue with the rest.', 'heic-to-jpg-converter' ); ?></p>
			<?php endif; ?>
			<p class="description"><?php esc_html_e( 'Keep this page open while converting. Each image keeps its Media Library ID, so pages and Elementor widgets that use it update automatically.', 'heic-to-jpg-converter' ); ?></p>

			<table class="widefat striped hic-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'File', 'heic-to-jpg-converter' ); ?></th>
						<th><?php esc_html_e( 'Uploaded', 'heic-to-jpg-converter' ); ?></th>
						<th><?php esc_html_e( 'Status', 'heic-to-jpg-converter' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $ids as $id ) : ?>
						<?php
						$file   = get_attached_file( $id );
						$failed = get_post_meta( $id, '_hic_failed', true );
						?>
						<tr>
							<td><a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>"><?php echo esc_html( $file ? wp_basename( $file ) : '#' . $id ); ?></a></td>
							<td><?php echo esc_html( get_the_date( '', $id ) ); ?></td>
							<td>
								<span class="hic-status<?php echo $failed ? ' is-error' : ''; ?>" data-hic-status="<?php echo esc_attr( $id ); ?>">
									<?php
									echo $failed
										/* translators: %s: error message */
										? esc_html( sprintf( __( 'Last attempt failed: %s', 'heic-to-jpg-converter' ), $failed ) )
										: esc_html__( 'Waiting', 'heic-to-jpg-converter' );
									?>
								</span>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
	<?php
}
