<?php
/**
 * HEIC Converter → Help: how the plugin works, FAQ and a system check.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Rows for the system check: label, value, ok (true/false/null for neutral).
 */
function hic_system_status() {
	$upload = wp_max_upload_size();
	$memory = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
	$editor = function_exists( '_wp_image_editor_choose' ) ? _wp_image_editor_choose( array( 'mime_type' => 'image/jpeg' ) ) : false;
	$dirs   = wp_get_upload_dir();
	$yes    = __( 'Yes', 'manpreetdev-heic-to-jpg-converter' );
	$no     = __( 'No', 'manpreetdev-heic-to-jpg-converter' );

	return array(
		array( __( 'Plugin version', 'manpreetdev-heic-to-jpg-converter' ), HIC_VERSION, null ),
		array( __( 'WordPress version', 'manpreetdev-heic-to-jpg-converter' ), get_bloginfo( 'version' ), null ),
		array( __( 'PHP version', 'manpreetdev-heic-to-jpg-converter' ), PHP_VERSION, null ),
		array( __( 'Image editor (thumbnails)', 'manpreetdev-heic-to-jpg-converter' ), $editor ? str_replace( 'WP_Image_Editor_', '', $editor ) : __( 'None found', 'manpreetdev-heic-to-jpg-converter' ), (bool) $editor ),
		array( __( 'Server can read HEIC', 'manpreetdev-heic-to-jpg-converter' ), hic_server_supports_heic() ? $yes : __( 'No (the browser converts)', 'manpreetdev-heic-to-jpg-converter' ), null ),
		array( __( 'Converted by', 'manpreetdev-heic-to-jpg-converter' ), hic_use_server() ? __( 'Server', 'manpreetdev-heic-to-jpg-converter' ) : __( 'Browser', 'manpreetdev-heic-to-jpg-converter' ), null ),
		array( __( 'Maximum upload size', 'manpreetdev-heic-to-jpg-converter' ), size_format( $upload ), $upload >= 8 * MB_IN_BYTES ),
		array( __( 'PHP memory limit', 'manpreetdev-heic-to-jpg-converter' ), $memory > 0 ? size_format( $memory ) : __( 'Unlimited', 'manpreetdev-heic-to-jpg-converter' ), $memory <= 0 || $memory >= 128 * MB_IN_BYTES ),
		array( __( 'Uploads folder writable', 'manpreetdev-heic-to-jpg-converter' ), wp_is_writable( $dirs['basedir'] ) ? $yes : $no, wp_is_writable( $dirs['basedir'] ) ),
		array( __( 'Elementor active', 'manpreetdev-heic-to-jpg-converter' ), defined( 'ELEMENTOR_VERSION' ) ? $yes . ' (' . ELEMENTOR_VERSION . ')' : $no, null ),
		array( __( 'HEIC images waiting', 'manpreetdev-heic-to-jpg-converter' ), number_format_i18n( hic_pending_count() ), null ),
	);
}

function hic_render_help_page() {
	$faq = array(
		array(
			__( 'Does the server need ImageMagick or libheif?', 'manpreetdev-heic-to-jpg-converter' ),
			__( 'No. By default the photo is converted in the browser of the person uploading, and the server only receives a normal JPG or PNG. If your server can read HEIC (WordPress 6.7+ with Imagick), you can let it do the work instead under Settings.', 'manpreetdev-heic-to-jpg-converter' ),
		),
		array(
			__( 'Which iPhone photos are supported?', 'manpreetdev-heic-to-jpg-converter' ),
			__( 'Photos from all iPhones, including iOS 18 HDR photos. The plugin tries the browser\'s own HEIC support first (Safari), then heic-to (libheif 1.23), then heic2any as a last resort. The Logs page shows which one converted each photo.', 'manpreetdev-heic-to-jpg-converter' ),
		),
		array(
			__( 'Do I have to keep the Tools page open?', 'manpreetdev-heic-to-jpg-converter' ),
			__( 'Yes, while existing images are being converted in the browser. Background conversion also runs on other dashboard pages, up to 25 images per page load. With server conversion the work still starts from the page, but your device does not do the heavy lifting.', 'manpreetdev-heic-to-jpg-converter' ),
		),
		array(
			__( 'Will my pages and Elementor layouts break?', 'manpreetdev-heic-to-jpg-converter' ),
			__( 'No. The attachment keeps its Media Library ID, so featured images, galleries and Elementor widgets point at the new file automatically. Links typed into content (including resized copies such as image-300x200.heic) are updated when "Update old .heic links" is on.', 'manpreetdev-heic-to-jpg-converter' ),
		),
		array(
			__( 'Is the camera data (EXIF) kept?', 'manpreetdev-heic-to-jpg-converter' ),
			__( 'No. Browser conversion removes the camera model, date taken and GPS location from the new image, which also stops visitors from seeing where a photo was taken. Keep the original .heic file if you need that information.', 'manpreetdev-heic-to-jpg-converter' ),
		),
		array(
			__( 'An image failed to convert. What now?', 'manpreetdev-heic-to-jpg-converter' ),
			__( 'Open Logs to see the reason, then use "Retry failed" on the Tools page. If your media is served from another domain (a CDN or offload plugin), that domain must allow cross-origin requests from your admin area. Very large photos may need "Photos converted at the same time" set to 1.', 'manpreetdev-heic-to-jpg-converter' ),
		),
		array(
			__( 'Does it work for uploads from the front end (forms)?', 'manpreetdev-heic-to-jpg-converter' ),
			__( 'Not while uploading: conversion runs in the dashboard and editors. If a form plugin saves HEIC photos to the Media Library, they are listed under Tools and converted from there or in the background.', 'manpreetdev-heic-to-jpg-converter' ),
		),
	);
	?>
	<div class="hic-grid">
		<div>
			<section class="hic-card">
				<h2><?php esc_html_e( 'How it works', 'manpreetdev-heic-to-jpg-converter' ); ?></h2>
				<ol class="hic-steps">
					<li>
						<strong><?php esc_html_e( 'New uploads', 'manpreetdev-heic-to-jpg-converter' ); ?></strong>
						<span><?php esc_html_e( 'Drop a .heic photo into the Media Library, the block editor, Elementor or Media → Add New. It is converted to JPG or PNG before it is uploaded.', 'manpreetdev-heic-to-jpg-converter' ); ?></span>
					</li>
					<li>
						<strong><?php esc_html_e( 'Existing images', 'manpreetdev-heic-to-jpg-converter' ); ?></strong>
						<span><?php esc_html_e( 'HEIC images already in the Media Library are listed under Tools. Convert them all at once, or let them convert in the background while you work in the dashboard.', 'manpreetdev-heic-to-jpg-converter' ); ?></span>
					</li>
					<li>
						<strong><?php esc_html_e( 'Same attachment, new file', 'manpreetdev-heic-to-jpg-converter' ); ?></strong>
						<span><?php esc_html_e( 'The converted image replaces the HEIC file inside the same attachment, thumbnails are rebuilt and old links are updated.', 'manpreetdev-heic-to-jpg-converter' ); ?></span>
					</li>
					<li>
						<strong><?php esc_html_e( 'Everything is logged', 'manpreetdev-heic-to-jpg-converter' ); ?></strong>
						<span><?php esc_html_e( 'Logs shows every conversion with sizes, time taken, the converter used and any error.', 'manpreetdev-heic-to-jpg-converter' ); ?></span>
					</li>
				</ol>
			</section>

			<section class="hic-card">
				<h2><?php esc_html_e( 'Frequently asked questions', 'manpreetdev-heic-to-jpg-converter' ); ?></h2>
				<div class="hic-faq">
					<?php foreach ( $faq as $item ) : ?>
						<details>
							<summary><?php echo esc_html( $item[0] ); ?></summary>
							<p><?php echo esc_html( $item[1] ); ?></p>
						</details>
					<?php endforeach; ?>
				</div>
			</section>
		</div>

		<aside>
			<?php if ( current_user_can( 'manage_options' ) ) : ?>
				<section class="hic-card hic-summary">
					<h2><?php esc_html_e( 'System check', 'manpreetdev-heic-to-jpg-converter' ); ?></h2>
					<dl>
						<?php foreach ( hic_system_status() as $row ) : ?>
							<dt><?php echo esc_html( $row[0] ); ?></dt>
							<dd class="<?php echo false === $row[2] ? 'is-bad' : ''; ?>">
								<?php if ( null !== $row[2] ) : ?>
									<span class="dashicons <?php echo $row[2] ? 'dashicons-yes' : 'dashicons-warning'; ?>" aria-hidden="true"></span>
								<?php endif; ?>
								<?php echo esc_html( $row[1] ); ?>
							</dd>
						<?php endforeach; ?>
					</dl>
				</section>
			<?php endif; ?>

			<section class="hic-card">
				<h2><?php esc_html_e( 'Quick links', 'manpreetdev-heic-to-jpg-converter' ); ?></h2>
				<ul class="hic-links">
					<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=hic-converter' ) ); ?>"><span class="dashicons dashicons-update" aria-hidden="true"></span><?php esc_html_e( 'Convert existing images', 'manpreetdev-heic-to-jpg-converter' ); ?></a></li>
					<?php if ( current_user_can( 'manage_options' ) ) : ?>
						<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=hic-settings' ) ); ?>"><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span><?php esc_html_e( 'Settings', 'manpreetdev-heic-to-jpg-converter' ); ?></a></li>
						<li><a href="<?php echo esc_url( admin_url( 'admin.php?page=hic-logs' ) ); ?>"><span class="dashicons dashicons-list-view" aria-hidden="true"></span><?php esc_html_e( 'Conversion logs', 'manpreetdev-heic-to-jpg-converter' ); ?></a></li>
					<?php endif; ?>
					<li><a href="<?php echo esc_url( admin_url( 'upload.php' ) ); ?>"><span class="dashicons dashicons-admin-media" aria-hidden="true"></span><?php esc_html_e( 'Media Library', 'manpreetdev-heic-to-jpg-converter' ); ?></a></li>
					<li><a href="https://github.com/manpreetdev21/manpreetdev-heic-to-jpg-converter" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span><?php esc_html_e( 'Plugin website', 'manpreetdev-heic-to-jpg-converter' ); ?></a></li>
					<li><a href="https://github.com/manpreetdev21/manpreetdev-heic-to-jpg-converter/issues" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-sos" aria-hidden="true"></span><?php esc_html_e( 'Report an issue', 'manpreetdev-heic-to-jpg-converter' ); ?></a></li>
				</ul>
			</section>
		</aside>
	</div>
	<?php
}
