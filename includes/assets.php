<?php
/**
 * Script loading: the in-browser converter, upload hooks and background conversion.
 */

defined( 'ABSPATH' ) || exit;

/** Hook suffixes of the plugin's admin pages (set in admin-page.php). */
$GLOBALS['hic_page_hooks'] = array();
/** Whether the background converter runs on the current admin page. */
$GLOBALS['hic_bg_active'] = false;

function hic_register_core() {
	if ( wp_script_is( 'hic-core', 'registered' ) ) {
		return;
	}
	$s = hic_get_settings();

	wp_register_style( 'hic-admin', HIC_URL . 'assets/css/hic-admin.css', array( 'dashicons' ), HIC_VERSION );
	wp_register_script( 'hic-core', HIC_URL . 'assets/js/hic-core.js', array(), HIC_VERSION, true );
	wp_localize_script( 'hic-core', 'hicSettings', array(
		'libs'    => array(
			'heicTo'   => HIC_URL . 'assets/vendor/heic-to/heic-to.js?ver=' . HIC_VERSION,
			'heic2any' => HIC_URL . 'assets/vendor/heic2any/heic2any.min.js?ver=' . HIC_VERSION,
		),
		'format'  => $s['format'],
		'quality' => max( 0.1, min( 1, ( (int) $s['quality'] ) / 100 ) ),
		'maxSize' => (int) $s['max_size'],
		'concurrency' => max( 1, min( 4, (int) $s['concurrency'] ) ),
		'restUrl' => esc_url_raw( rest_url( 'hic/v1/' ) ),
		'nonce'   => wp_create_nonce( 'wp_rest' ),
		'i18n'    => array(
			'unsupported' => __( 'This HEIC photo could not be read by any of the converters.', 'manpreetdev-heic-to-jpg-converter' ),
			/* translators: %s: file name */
			'converting' => __( 'Converting %s…', 'manpreetdev-heic-to-jpg-converter' ),
			/* translators: %s: new file name */
			'converted'  => __( 'Converted to %s — uploading.', 'manpreetdev-heic-to-jpg-converter' ),
			/* translators: %s: file name */
			'failed'     => __( 'Could not convert %s in this browser. Uploading the original; it can be converted later from HEIC Converter → Tools.', 'manpreetdev-heic-to-jpg-converter' ),
		),
	) );
}

/** Whether new uploads should be converted in the browser (the server may do it instead). */
function hic_convert_uploads_in_browser() {
	$s = hic_get_settings();
	return ! empty( $s['convert_uploads'] ) && ! hic_use_server();
}

/**
 * Convert HEIC files in the media uploader (Media Library, media modal,
 * Elementor and any other screen that uses wp.media).
 */
function hic_enqueue_uploader_support( $extra_deps = array() ) {
	if ( ! is_user_logged_in() || ! current_user_can( 'upload_files' ) || ! hic_convert_uploads_in_browser() ) {
		return;
	}
	hic_register_core();
	wp_enqueue_style( 'hic-admin' );

	// Hooks fired without arguments (wp_enqueue_media, Elementor) pass '' here; an empty
	// dependency would stop WordPress from printing the script at all.
	$deps = array_merge( array( 'hic-core', 'jquery' ), array_filter( (array) $extra_deps ) );
	if ( wp_script_is( 'wp-plupload', 'registered' ) ) {
		$deps[] = 'wp-plupload';
	}
	wp_enqueue_script( 'hic-uploader', HIC_URL . 'assets/js/hic-uploader.js', array_unique( $deps ), HIC_VERSION, true );
}
add_action( 'wp_enqueue_media', 'hic_enqueue_uploader_support' );
add_action( 'elementor/editor/after_enqueue_scripts', 'hic_enqueue_uploader_support' );

/** Block editor: convert HEIC files dropped into image/gallery/cover blocks. */
add_action( 'enqueue_block_editor_assets', function () {
	if ( ! current_user_can( 'upload_files' ) || ! hic_convert_uploads_in_browser() ) {
		return;
	}
	hic_register_core();
	wp_enqueue_style( 'hic-admin' );
	wp_enqueue_script( 'hic-editor', HIC_URL . 'assets/js/hic-editor.js', array( 'hic-core', 'wp-api-fetch' ), HIC_VERSION, true );
} );

/** Data + script for converting existing HEIC attachments. */
function hic_enqueue_bulk( $mode, $ids, $auto_start ) {
	hic_register_core();
	wp_enqueue_style( 'hic-admin' );
	wp_enqueue_script( 'hic-bulk', HIC_URL . 'assets/js/hic-bulk.js', array( 'hic-core' ), HIC_VERSION, true );
	wp_localize_script( 'hic-bulk', 'hicBulk', array(
		'mode'      => $mode,
		'items'     => array_map( 'hic_item_data', $ids ),
		'autoStart' => (bool) $auto_start,
		'server'    => hic_use_server(),
		'i18n'      => array(
			'waiting'     => __( 'Waiting', 'manpreetdev-heic-to-jpg-converter' ),
			'serverConverting' => __( 'Converting on server…', 'manpreetdev-heic-to-jpg-converter' ),
			'downloading' => __( 'Downloading…', 'manpreetdev-heic-to-jpg-converter' ),
			'converting'  => __( 'Converting…', 'manpreetdev-heic-to-jpg-converter' ),
			'uploading'   => __( 'Saving…', 'manpreetdev-heic-to-jpg-converter' ),
			'done'        => __( 'Converted', 'manpreetdev-heic-to-jpg-converter' ),
			'elsewhere'   => __( 'Being converted in another tab', 'manpreetdev-heic-to-jpg-converter' ),
			'failed'      => __( 'Failed', 'manpreetdev-heic-to-jpg-converter' ),
			/* translators: 1: processed count, 2: total count */
			'progress'    => __( 'Converting HEIC images: %1$d of %2$d…', 'manpreetdev-heic-to-jpg-converter' ),
			/* translators: 1: converted count, 2: failed count */
			'finished'    => __( 'Finished. %1$d converted, %2$d failed.', 'manpreetdev-heic-to-jpg-converter' ),
			'leaveWarn'   => __( 'HEIC images are still being converted. Leave anyway?', 'manpreetdev-heic-to-jpg-converter' ),
		),
	) );
}

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	if ( wp_doing_ajax() || ! current_user_can( 'upload_files' ) ) {
		return;
	}

	// Classic "Add New Media File" screen uses its own uploader.
	if ( 'media-new.php' === $hook ) {
		hic_enqueue_uploader_support( array( 'plupload-handlers' ) );
	}

	$s = hic_get_settings();

	// The plugin's own pages: Tools runs the converter, Settings/Logs only need styles.
	if ( hic_is_plugin_screen( $hook ) ) {
		$ids = $hook === $GLOBALS['hic_page_hooks']['tools'] ? hic_get_pending_ids( 200, true ) : array();
		if ( $ids ) {
			hic_enqueue_bulk( 'page', $ids, ! empty( $s['auto_existing'] ) );
		} else {
			hic_register_core();
			wp_enqueue_style( 'hic-admin' );
		}
		return;
	}

	// Background conversion on other admin screens.
	if ( empty( $s['auto_existing'] ) ) {
		return;
	}
	$skip = array( 'post.php', 'post-new.php', 'site-editor.php', 'widgets.php', 'customize.php', 'update.php', 'update-core.php' );
	if ( in_array( $hook, $skip, true ) ) {
		return;
	}
	$ids = hic_get_pending_ids( 25, false );
	if ( ! $ids ) {
		return;
	}
	hic_enqueue_bulk( 'background', $ids, true );
	$GLOBALS['hic_bg_active'] = true;
} );
