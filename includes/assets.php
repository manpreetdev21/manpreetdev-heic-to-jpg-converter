<?php
/**
 * Script loading: the in-browser converter, upload hooks and background conversion.
 */

defined( 'ABSPATH' ) || exit;

/** Hook suffix of the Media → HEIC Converter page (set in admin-page.php). */
$GLOBALS['hic_page_hook'] = '';
/** Whether the background converter runs on the current admin page. */
$GLOBALS['hic_bg_active'] = false;

function hic_register_core() {
	if ( wp_script_is( 'hic-core', 'registered' ) ) {
		return;
	}
	$s = hic_get_settings();

	wp_register_style( 'hic-admin', HIC_URL . 'assets/css/hic-admin.css', array(), HIC_VERSION );
	wp_register_script( 'hic-core', HIC_URL . 'assets/js/hic-core.js', array(), HIC_VERSION, true );
	wp_localize_script( 'hic-core', 'hicSettings', array(
		'libs'    => array(
			'heicTo'   => HIC_URL . 'assets/vendor/heic-to/heic-to.js?ver=' . HIC_VERSION,
			'heic2any' => HIC_URL . 'assets/vendor/heic2any/heic2any.min.js?ver=' . HIC_VERSION,
		),
		'format'  => $s['format'],
		'quality' => max( 0.1, min( 1, ( (int) $s['quality'] ) / 100 ) ),
		'maxSize' => (int) $s['max_size'],
		'i18n'    => array(
			'unsupported' => __( 'This HEIC photo could not be read by any of the converters.', 'heic-to-jpg-converter' ),
			/* translators: %s: file name */
			'converting' => __( 'Converting %s…', 'heic-to-jpg-converter' ),
			/* translators: %s: new file name */
			'converted'  => __( 'Converted to %s — uploading.', 'heic-to-jpg-converter' ),
			/* translators: %s: file name */
			'failed'     => __( 'Could not convert %s in this browser. Uploading the original; it can be converted later from Media → HEIC Converter.', 'heic-to-jpg-converter' ),
		),
	) );
}

/**
 * Convert HEIC files in the media uploader (Media Library, media modal,
 * Elementor and any other screen that uses wp.media).
 */
function hic_enqueue_uploader_support( $extra_deps = array() ) {
	if ( ! is_user_logged_in() || ! current_user_can( 'upload_files' ) ) {
		return;
	}
	$s = hic_get_settings();
	if ( empty( $s['convert_uploads'] ) ) {
		return;
	}
	hic_register_core();
	wp_enqueue_style( 'hic-admin' );

	$deps = array_merge( array( 'hic-core', 'jquery' ), (array) $extra_deps );
	if ( wp_script_is( 'wp-plupload', 'registered' ) ) {
		$deps[] = 'wp-plupload';
	}
	wp_enqueue_script( 'hic-uploader', HIC_URL . 'assets/js/hic-uploader.js', array_unique( $deps ), HIC_VERSION, true );
}
add_action( 'wp_enqueue_media', 'hic_enqueue_uploader_support' );
add_action( 'elementor/editor/after_enqueue_scripts', 'hic_enqueue_uploader_support' );

/** Block editor: convert HEIC files dropped into image/gallery/cover blocks. */
add_action( 'enqueue_block_editor_assets', function () {
	if ( ! current_user_can( 'upload_files' ) ) {
		return;
	}
	$s = hic_get_settings();
	if ( empty( $s['convert_uploads'] ) ) {
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
		'restUrl'   => esc_url_raw( rest_url( 'hic/v1/' ) ),
		'nonce'     => wp_create_nonce( 'wp_rest' ),
		'autoStart' => (bool) $auto_start,
		'toolUrl'   => admin_url( 'upload.php?page=hic-converter' ),
		'i18n'      => array(
			'waiting'     => __( 'Waiting', 'heic-to-jpg-converter' ),
			'downloading' => __( 'Downloading…', 'heic-to-jpg-converter' ),
			'converting'  => __( 'Converting…', 'heic-to-jpg-converter' ),
			'uploading'   => __( 'Saving…', 'heic-to-jpg-converter' ),
			'done'        => __( 'Converted', 'heic-to-jpg-converter' ),
			'failed'      => __( 'Failed', 'heic-to-jpg-converter' ),
			/* translators: 1: processed count, 2: total count */
			'progress'    => __( 'Converting HEIC images: %1$d of %2$d…', 'heic-to-jpg-converter' ),
			/* translators: 1: converted count, 2: failed count */
			'finished'    => __( 'Finished. %1$d converted, %2$d failed.', 'heic-to-jpg-converter' ),
			'leaveWarn'   => __( 'HEIC images are still being converted. Leave anyway?', 'heic-to-jpg-converter' ),
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

	// Media → HEIC Converter page.
	if ( $GLOBALS['hic_page_hook'] && $hook === $GLOBALS['hic_page_hook'] ) {
		$ids = hic_get_pending_ids( 200, true );
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
