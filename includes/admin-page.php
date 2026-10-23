<?php
/**
 * HEIC Converter admin menu: Tools (convert existing images), Settings and Logs.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
	$title = __( 'HEIC Converter', 'manpreetdev-heic-to-jpg-converter' );

	add_menu_page( $title, $title, 'upload_files', 'hic-converter', 'hic_render_page', 'dashicons-format-image', 11 );

	$GLOBALS['hic_page_hooks'] = array(
		'tools'    => add_submenu_page( 'hic-converter', $title, __( 'Tools', 'manpreetdev-heic-to-jpg-converter' ), 'upload_files', 'hic-converter', 'hic_render_page' ),
		'settings' => add_submenu_page( 'hic-converter', __( 'Settings', 'manpreetdev-heic-to-jpg-converter' ), __( 'Settings', 'manpreetdev-heic-to-jpg-converter' ), 'manage_options', 'hic-settings', 'hic_render_page' ),
		'logs'     => add_submenu_page( 'hic-converter', __( 'Logs', 'manpreetdev-heic-to-jpg-converter' ), __( 'Logs', 'manpreetdev-heic-to-jpg-converter' ), 'manage_options', 'hic-logs', 'hic_render_page' ),
		'help'     => add_submenu_page( 'hic-converter', __( 'Help', 'manpreetdev-heic-to-jpg-converter' ), __( 'Help', 'manpreetdev-heic-to-jpg-converter' ), 'upload_files', 'hic-help', 'hic_render_page' ),
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
		'concurrency'     => isset( $input['concurrency'] ) ? max( 1, min( 4, (int) $input['concurrency'] ) ) : 2,
		'server_convert'  => empty( $input['server_convert'] ) ? 0 : 1,
	);
}

/** Whether a hook suffix / screen ID belongs to one of this plugin's pages. */
function hic_is_plugin_screen( $hook ) {
	return $hook && in_array( $hook, $GLOBALS['hic_page_hooks'], true );
}

/** Admin notices: background progress, or a prompt when auto-conversion is off. */
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'upload_files' ) ) {
		return;
	}
	$screen = get_current_screen();
	if ( $screen && hic_is_plugin_screen( $screen->id ) ) {
		return;
	}

	if ( ! empty( $GLOBALS['hic_bg_active'] ) ) {
		printf(
			'<div class="notice notice-info hic-bg-notice"><p><strong>%s</strong> <span id="hic-progress">%s</span> <a href="%s">%s</a></p></div>',
			esc_html__( 'HEIC Converter:', 'manpreetdev-heic-to-jpg-converter' ),
			esc_html__( 'Preparing…', 'manpreetdev-heic-to-jpg-converter' ),
			esc_url( admin_url( 'admin.php?page=hic-converter' ) ),
			esc_html__( 'Details', 'manpreetdev-heic-to-jpg-converter' )
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
				_n( '%d HEIC image cannot be displayed in most browsers.', '%d HEIC images cannot be displayed in most browsers.', $count, 'manpreetdev-heic-to-jpg-converter' ),
				$count
			) ),
			esc_url( admin_url( 'admin.php?page=hic-converter' ) ),
			esc_html__( 'Convert now', 'manpreetdev-heic-to-jpg-converter' )
		);
	}
} );

/** Shared page shell: header + navigation, then the current page's content. */
function hic_render_page() {
	if ( ! current_user_can( 'upload_files' ) ) {
		return;
	}

	$pages = array(
		'hic-converter' => array( __( 'Tools', 'manpreetdev-heic-to-jpg-converter' ), 'dashicons-update', 'hic_render_tools_page' ),
	);
	if ( current_user_can( 'manage_options' ) ) {
		$pages['hic-settings'] = array( __( 'Settings', 'manpreetdev-heic-to-jpg-converter' ), 'dashicons-admin-generic', 'hic_render_settings_page' );
		$pages['hic-logs']     = array( __( 'Logs', 'manpreetdev-heic-to-jpg-converter' ), 'dashicons-list-view', 'hic_render_logs_page' );
	}
	$pages['hic-help'] = array( __( 'Help', 'manpreetdev-heic-to-jpg-converter' ), 'dashicons-editor-help', 'hic_render_help_page' );
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- page routing only.
	$current = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
	$current = isset( $pages[ $current ] ) ? $current : 'hic-converter';
	?>
	<div class="wrap hic-wrap">
		<header class="hic-header">
			<span class="hic-logo" aria-hidden="true"><span class="dashicons dashicons-format-image"></span></span>
			<div class="hic-header__text">
				<h1>
					<?php esc_html_e( 'ManpreetDev HEIC to JPG Converter', 'manpreetdev-heic-to-jpg-converter' ); ?>
					<span class="hic-badge">v<?php echo esc_html( HIC_VERSION ); ?></span>
				</h1>
				<p><?php esc_html_e( 'iPhone HEIC/HEIF photos are converted to JPG or PNG inside your browser. The server does not need ImageMagick or any HEIC support.', 'manpreetdev-heic-to-jpg-converter' ); ?></p>
			</div>
		</header>

		<nav class="hic-nav" aria-label="<?php esc_attr_e( 'HEIC Converter pages', 'manpreetdev-heic-to-jpg-converter' ); ?>">
			<?php foreach ( $pages as $slug => $page ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . $slug ) ); ?>" class="<?php echo $slug === $current ? 'is-active' : ''; ?>"<?php echo $slug === $current ? ' aria-current="page"' : ''; ?>>
					<span class="dashicons <?php echo esc_attr( $page[1] ); ?>" aria-hidden="true"></span><?php echo esc_html( $page[0] ); ?>
				</a>
			<?php endforeach; ?>
		</nav>

		<hr class="wp-header-end">

		<?php call_user_func( $pages[ $current ][2] ); ?>
	</div>
	<?php
}

/** Stat card; $key and data-value let hic-bulk.js update the number live. */
function hic_stat_card( $key, $icon, $value, $label, $kind = '' ) {
	printf(
		'<div class="hic-stat%s" data-hic-stat="%s" data-value="%d"><span class="hic-stat__icon dashicons %s" aria-hidden="true"></span><div><strong>%s</strong><span>%s</span></div></div>',
		$kind ? ' is-' . esc_attr( $kind ) : '',
		esc_attr( $key ),
		(int) $value,
		esc_attr( $icon ),
		esc_html( number_format_i18n( $value ) ),
		esc_html( $label )
	);
}

function hic_render_tools_page() {
	$s      = hic_get_settings();
	$ids    = hic_get_pending_ids( 200, true );
	$total  = hic_pending_count();
	$failed = hic_failed_count();
	$stats  = hic_get_stats();
	$label  = 'png' === $s['format'] ? 'PNG' : 'JPG';
	$retry  = count( array_filter( $ids, function ( $id ) {
		return (bool) get_post_meta( $id, '_hic_failed', true );
	} ) );
	$yes    = __( 'On', 'manpreetdev-heic-to-jpg-converter' );
	$no     = __( 'Off', 'manpreetdev-heic-to-jpg-converter' );
	?>
	<div class="hic-stats">
		<?php
		hic_stat_card( 'waiting', 'dashicons-clock', $total, __( 'Waiting to convert', 'manpreetdev-heic-to-jpg-converter' ), $total ? 'warn' : '' );
		hic_stat_card( 'library', 'dashicons-images-alt2', $stats['library'], __( 'Converted in library', 'manpreetdev-heic-to-jpg-converter' ), 'ok' );
		hic_stat_card( 'upload', 'dashicons-upload', $stats['upload'], __( 'Converted on upload', 'manpreetdev-heic-to-jpg-converter' ), 'ok' );
		hic_stat_card( 'failed', 'dashicons-warning', $failed, __( 'Failed', 'manpreetdev-heic-to-jpg-converter' ), $failed ? 'error' : '' );
		?>
	</div>

	<div class="hic-grid">
		<section class="hic-card">
			<div class="hic-card__head">
				<div>
					<h2><?php esc_html_e( 'HEIC images in the Media Library', 'manpreetdev-heic-to-jpg-converter' ); ?></h2>
					<p class="hic-muted"><?php esc_html_e( 'Each image keeps its Media Library ID, so pages, featured images and Elementor widgets update automatically.', 'manpreetdev-heic-to-jpg-converter' ); ?></p>
				</div>
				<?php if ( $ids ) : ?>
					<div class="hic-buttons">
					<?php if ( $retry ) : ?>
						<button type="button" id="hic-retry" class="button hic-button-lg">
							<span class="dashicons dashicons-image-rotate" aria-hidden="true"></span>
							<?php
							/* translators: %d: number of images */
							echo esc_html( sprintf( _n( 'Retry %d failed', 'Retry %d failed', $retry, 'manpreetdev-heic-to-jpg-converter' ), $retry ) );
							?>
						</button>
					<?php endif; ?>
					<button type="button" id="hic-start" class="button button-primary hic-button-lg">
						<span class="dashicons dashicons-controls-play" aria-hidden="true"></span>
						<?php
						echo esc_html( sprintf(
							/* translators: 1: number of images, 2: JPG or PNG */
							_n( 'Convert %1$d image to %2$s', 'Convert %1$d images to %2$s', count( $ids ), 'manpreetdev-heic-to-jpg-converter' ),
							count( $ids ),
							$label
						) );
						?>
					</button>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( ! $ids ) : ?>
				<div class="hic-empty">
					<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
					<h3><?php esc_html_e( 'All caught up', 'manpreetdev-heic-to-jpg-converter' ); ?></h3>
					<p><?php esc_html_e( 'There are no HEIC images left to convert.', 'manpreetdev-heic-to-jpg-converter' ); ?></p>
				</div>
			<?php else : ?>
				<div class="hic-run" id="hic-run" hidden>
					<div class="hic-bar" id="hic-bar" role="progressbar" aria-valuemin="0" aria-valuemax="<?php echo esc_attr( count( $ids ) ); ?>" aria-valuenow="0" aria-labelledby="hic-progress"><span></span></div>
					<p id="hic-progress" class="hic-progress" aria-live="polite"></p>
				</div>
				<?php if ( $total > count( $ids ) ) : ?>
					<p class="hic-note"><?php esc_html_e( 'Showing the newest 200. Reload this page after they finish to continue with the rest.', 'manpreetdev-heic-to-jpg-converter' ); ?></p>
				<?php endif; ?>

				<div class="hic-table-wrap">
					<table class="hic-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'File', 'manpreetdev-heic-to-jpg-converter' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Uploaded', 'manpreetdev-heic-to-jpg-converter' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Status', 'manpreetdev-heic-to-jpg-converter' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $ids as $id ) : ?>
								<?php
								$file  = get_attached_file( $id );
								$error = get_post_meta( $id, '_hic_failed', true );
								?>
								<tr>
									<td class="hic-file">
										<span class="hic-thumb" data-hic-thumb="<?php echo esc_attr( $id ); ?>"><span class="dashicons dashicons-format-image" aria-hidden="true"></span></span>
										<a href="<?php echo esc_url( get_edit_post_link( $id ) ); ?>"><?php echo esc_html( $file ? wp_basename( $file ) : '#' . $id ); ?></a>
									</td>
									<td class="hic-nowrap"><?php echo esc_html( get_the_date( '', $id ) ); ?></td>
									<td>
										<span class="hic-pill<?php echo $error ? ' is-error' : ''; ?>" data-hic-status="<?php echo esc_attr( $id ); ?>"<?php echo $error ? ' title="' . esc_attr( $error ) . '"' : ''; ?>>
											<?php echo $error ? esc_html__( 'Last attempt failed', 'manpreetdev-heic-to-jpg-converter' ) : esc_html__( 'Waiting', 'manpreetdev-heic-to-jpg-converter' ); ?>
										</span>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<p class="hic-note"><span class="dashicons dashicons-info-outline" aria-hidden="true"></span><?php esc_html_e( 'Keep this page open while converting: the work happens in your browser.', 'manpreetdev-heic-to-jpg-converter' ); ?></p>
			<?php endif; ?>
		</section>

		<aside class="hic-card hic-summary">
			<h2><?php esc_html_e( 'Current setup', 'manpreetdev-heic-to-jpg-converter' ); ?></h2>
			<dl>
				<dt><?php esc_html_e( 'Output', 'manpreetdev-heic-to-jpg-converter' ); ?></dt>
				<dd><?php echo esc_html( 'png' === $s['format'] ? 'PNG' : sprintf( 'JPG · %d%%', $s['quality'] ) ); ?></dd>
				<dt><?php esc_html_e( 'Maximum size', 'manpreetdev-heic-to-jpg-converter' ); ?></dt>
				<dd><?php echo $s['max_size'] ? esc_html( number_format_i18n( $s['max_size'] ) . ' px' ) : esc_html__( 'Original', 'manpreetdev-heic-to-jpg-converter' ); ?></dd>
				<dt><?php esc_html_e( 'Converted by', 'manpreetdev-heic-to-jpg-converter' ); ?></dt>
				<dd><?php echo hic_use_server() ? esc_html__( 'Server', 'manpreetdev-heic-to-jpg-converter' ) : esc_html__( 'Browser', 'manpreetdev-heic-to-jpg-converter' ); ?></dd>
				<dt><?php esc_html_e( 'At the same time', 'manpreetdev-heic-to-jpg-converter' ); ?></dt>
				<dd><?php echo esc_html( number_format_i18n( $s['concurrency'] ) ); ?></dd>
				<dt><?php esc_html_e( 'Convert on upload', 'manpreetdev-heic-to-jpg-converter' ); ?></dt>
				<dd><?php echo esc_html( $s['convert_uploads'] ? $yes : $no ); ?></dd>
				<dt><?php esc_html_e( 'Background conversion', 'manpreetdev-heic-to-jpg-converter' ); ?></dt>
				<dd><?php echo esc_html( $s['auto_existing'] ? $yes : $no ); ?></dd>
				<dt><?php esc_html_e( 'Keep original .heic', 'manpreetdev-heic-to-jpg-converter' ); ?></dt>
				<dd><?php echo esc_html( $s['keep_original'] ? $yes : $no ); ?></dd>
				<dt><?php esc_html_e( 'Update old links', 'manpreetdev-heic-to-jpg-converter' ); ?></dt>
				<dd><?php echo esc_html( $s['replace_urls'] ? $yes : $no ); ?></dd>
			</dl>
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=hic-settings' ) ); ?>"><?php esc_html_e( 'Change settings', 'manpreetdev-heic-to-jpg-converter' ); ?></a>
			<?php endif; ?>
		</aside>
	</div>
	<?php
}

/** A checkbox styled as a switch; the real input stays focusable for keyboards and screen readers. */
function hic_switch( $key, $title, $description, $settings ) {
	?>
	<label class="hic-switch">
		<input type="checkbox" name="hic_settings[<?php echo esc_attr( $key ); ?>]" value="1" <?php checked( $settings[ $key ], 1 ); ?>>
		<span class="hic-switch__track" aria-hidden="true"></span>
		<span class="hic-switch__text"><strong><?php echo esc_html( $title ); ?></strong><span><?php echo esc_html( $description ); ?></span></span>
	</label>
	<?php
}

function hic_render_settings_page() {
	$s = hic_get_settings();
	settings_errors();
	?>
	<form method="post" action="options.php" class="hic-settings">
		<?php settings_fields( 'hic_settings_group' ); ?>

		<section class="hic-card">
			<h2><?php esc_html_e( 'Output', 'manpreetdev-heic-to-jpg-converter' ); ?></h2>

			<fieldset class="hic-field">
				<legend><?php esc_html_e( 'Convert to', 'manpreetdev-heic-to-jpg-converter' ); ?></legend>
				<div class="hic-choices">
					<label class="hic-choice">
						<input type="radio" name="hic_settings[format]" value="jpg" <?php checked( $s['format'], 'jpg' ); ?>>
						<span><strong>JPG</strong><?php esc_html_e( 'Recommended for photos. Much smaller files.', 'manpreetdev-heic-to-jpg-converter' ); ?></span>
					</label>
					<label class="hic-choice">
						<input type="radio" name="hic_settings[format]" value="png" <?php checked( $s['format'], 'png' ); ?>>
						<span><strong>PNG</strong><?php esc_html_e( 'Lossless, but files are several times larger.', 'manpreetdev-heic-to-jpg-converter' ); ?></span>
					</label>
				</div>
			</fieldset>

			<div class="hic-field">
				<label for="hic-quality"><?php esc_html_e( 'JPG quality', 'manpreetdev-heic-to-jpg-converter' ); ?></label>
				<div class="hic-range">
					<input id="hic-quality" type="range" min="10" max="100" step="1" name="hic_settings[quality]" value="<?php echo esc_attr( $s['quality'] ); ?>" oninput="this.nextElementSibling.value = this.value + '%'">
					<output for="hic-quality"><?php echo esc_html( $s['quality'] ); ?>%</output>
				</div>
				<p class="hic-muted"><?php esc_html_e( '85 gives a good balance of quality and file size. Ignored for PNG.', 'manpreetdev-heic-to-jpg-converter' ); ?></p>
			</div>

			<div class="hic-field">
				<label for="hic-max-size"><?php esc_html_e( 'Maximum size', 'manpreetdev-heic-to-jpg-converter' ); ?></label>
				<div class="hic-input-suffix">
					<input id="hic-max-size" type="number" min="0" max="20000" step="1" name="hic_settings[max_size]" value="<?php echo esc_attr( $s['max_size'] ); ?>">
					<span>px</span>
				</div>
				<p class="hic-muted"><?php esc_html_e( 'Longest side after conversion. Large iPhone photos (up to 48 MP) are scaled down to this so they upload quickly and stay under server upload limits. 0 keeps the original size (browsers still cap very large photos at about 16 megapixels).', 'manpreetdev-heic-to-jpg-converter' ); ?></p>
			</div>

			<div class="hic-field">
				<label for="hic-concurrency"><?php esc_html_e( 'Photos converted at the same time', 'manpreetdev-heic-to-jpg-converter' ); ?></label>
				<select id="hic-concurrency" name="hic_settings[concurrency]">
					<?php foreach ( array( 1, 2, 3, 4 ) as $n ) : ?>
						<option value="<?php echo esc_attr( $n ); ?>" <?php selected( (int) $s['concurrency'], $n ); ?>><?php echo esc_html( number_format_i18n( $n ) ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="hic-muted"><?php esc_html_e( 'How many photos the browser converts in parallel; the rest wait their turn. Each large iPhone photo needs several hundred MB of memory while converting, so use 1 or 2 on phones and older computers.', 'manpreetdev-heic-to-jpg-converter' ); ?></p>
			</div>

			<p class="hic-callout">
				<span class="dashicons dashicons-privacy" aria-hidden="true"></span>
				<?php esc_html_e( 'Photos converted in the browser do not keep their camera details (EXIF), such as the camera model, date taken or GPS location. This protects visitors from seeing where a photo was taken. Keep the original .heic file if you need that information.', 'manpreetdev-heic-to-jpg-converter' ); ?>
			</p>
		</section>

		<section class="hic-card">
			<h2><?php esc_html_e( 'Automatic conversion', 'manpreetdev-heic-to-jpg-converter' ); ?></h2>
			<?php
			hic_switch( 'convert_uploads', __( 'Convert while uploading', 'manpreetdev-heic-to-jpg-converter' ), __( 'HEIC files are converted before they are sent: Media Library, Elementor, block editor and Media → Add New.', 'manpreetdev-heic-to-jpg-converter' ), $s );
			hic_switch( 'auto_existing', __( 'Convert existing images in the background', 'manpreetdev-heic-to-jpg-converter' ), __( 'HEIC images already in the Media Library are converted while an editor is logged in to the dashboard (up to 25 per page load).', 'manpreetdev-heic-to-jpg-converter' ), $s );
			hic_switch(
				'server_convert',
				__( 'Let the server convert when it can', 'manpreetdev-heic-to-jpg-converter' ),
				hic_server_supports_heic()
					? __( 'Your server can read HEIC. New uploads and existing images are converted by the server (no browser work, keeps working on slow devices). New uploads use WordPress\'s own JPG quality; size and format follow the settings above. If the server fails on a file, the browser converts it instead.', 'manpreetdev-heic-to-jpg-converter' )
					: __( 'Not available: this server cannot read HEIC (needs WordPress 6.7+ and Imagick with HEIC support). The browser is used instead.', 'manpreetdev-heic-to-jpg-converter' ),
				$s
			);
			?>
		</section>

		<section class="hic-card">
			<h2><?php esc_html_e( 'After converting', 'manpreetdev-heic-to-jpg-converter' ); ?></h2>
			<?php
			hic_switch( 'keep_original', __( 'Keep the original .heic file', 'manpreetdev-heic-to-jpg-converter' ), __( 'Stays on the server as a backup and is deleted together with the attachment.', 'manpreetdev-heic-to-jpg-converter' ), $s );
			hic_switch( 'replace_urls', __( 'Update old .heic links', 'manpreetdev-heic-to-jpg-converter' ), __( 'Replaces links to the old file in posts, pages and Elementor layouts, then clears Elementor\'s CSS cache.', 'manpreetdev-heic-to-jpg-converter' ), $s );
			?>
		</section>

		<div class="hic-actions"><?php submit_button( null, 'primary hic-button-lg', 'submit', false ); ?></div>
	</form>
	<?php
}
