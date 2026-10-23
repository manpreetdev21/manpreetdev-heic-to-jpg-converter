<?php
/**
 * Plugin Name:       ManpreetDev HEIC to JPG Converter
 * Plugin URI:        https://github.com/manpreetdev21/manpreetdev-heic-to-jpg-converter
 * Description:       Automatically converts iPhone .heic/.heif photos to JPG or PNG inside the browser — when they are uploaded, and for HEIC images already in the Media Library. No ImageMagick or other server-side HEIC support needed.
 * Version:           1.2.0
 * Requires at least: 5.8
 * Requires PHP:      7.0
 * Author:            Manpreet Singh
 * Author URI:        https://github.com/manpreetdev21/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       manpreetdev-heic-to-jpg-converter
 * Domain Path:       /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'HIC_VERSION', '1.2.0' );
define( 'HIC_FILE', __FILE__ );
define( 'HIC_PATH', plugin_dir_path( __FILE__ ) );
define( 'HIC_URL', plugin_dir_url( __FILE__ ) );

/*
 * Translations: languages/manpreetdev-heic-to-jpg-converter-{locale}.mo bundled with the
 * plugin. Translations from translate.wordpress.org (wp-content/languages/plugins) take priority.
 */
add_action( 'init', function () {
	load_plugin_textdomain( 'manpreetdev-heic-to-jpg-converter', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
} );

/* -------------------------------------------------------------------------
 * Settings helpers
 * ---------------------------------------------------------------------- */

function hic_defaults() {
	return array(
		'format'          => 'jpg', // jpg | png
		'quality'         => 85,    // JPG quality 10–100
		'max_size'        => 4096,  // Longest side in px after conversion (0 = keep original).
		'convert_uploads' => 1,     // Convert new uploads before they are sent.
		'auto_existing'   => 1,     // Convert existing HEIC images in the background.
		'keep_original'   => 1,     // Keep the original .heic file on the server.
		'replace_urls'    => 1,     // Update .heic links in posts and Elementor data.
		'concurrency'     => 2,     // Photos converted at the same time in the browser (1–4).
		'server_convert'  => 0,     // Let the server convert when it supports HEIC.
	);
}

function hic_get_settings() {
	$saved = get_option( 'hic_settings', array() );
	return wp_parse_args( is_array( $saved ) ? $saved : array(), hic_defaults() );
}

function hic_heic_mimes() {
	return array( 'image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence' );
}

function hic_is_heic_mime( $mime ) {
	return in_array( strtolower( (string) $mime ), hic_heic_mimes(), true );
}

/* -------------------------------------------------------------------------
 * Allow HEIC uploads (used only when in-browser conversion fails, so the
 * original can still be stored and converted later from the Media Library).
 * ---------------------------------------------------------------------- */

add_filter( 'upload_mimes', function ( $mimes ) {
	if ( ! isset( $mimes['heic'] ) ) {
		$mimes['heic'] = 'image/heic';
	}
	if ( ! isset( $mimes['heif'] ) ) {
		$mimes['heif'] = 'image/heif';
	}
	return $mimes;
} );

/** Checks magic bytes so only genuine HEIC/HEIF files are accepted. */
function hic_is_heic_file( $path ) {
	$fh = @fopen( $path, 'rb' );
	if ( ! $fh ) {
		return false;
	}
	$head = fread( $fh, 12 );
	fclose( $fh );
	if ( strlen( $head ) < 12 || 'ftyp' !== substr( $head, 4, 4 ) ) {
		return false;
	}
	return in_array( substr( $head, 8, 4 ), array( 'heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'mif1', 'msf1', 'heif' ), true );
}

add_filter( 'wp_check_filetype_and_ext', function ( $data, $file, $filename ) {
	$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
	if ( in_array( $ext, array( 'heic', 'heif' ), true ) && empty( $data['type'] ) && hic_is_heic_file( $file ) ) {
		$data['ext']  = $ext;
		$data['type'] = ( 'heic' === $ext ) ? 'image/heic' : 'image/heif';
	}
	return $data;
}, 10, 3 );

/* -------------------------------------------------------------------------
 * Finding HEIC attachments
 * ---------------------------------------------------------------------- */

/**
 * IDs of HEIC attachments the current user may convert.
 *
 * @param int  $limit          Max items (-1 for all).
 * @param bool $include_failed Include items whose last conversion failed.
 */
/**
 * HEIC attachments whose file is still .heic/.heif. Since WordPress 6.7 a server
 * with HEIC support swaps the file for a JPG but keeps the image/heic MIME type;
 * those already display fine and are skipped.
 */
function hic_pending_query_args() {
	return array(
		'post_type'        => 'attachment',
		'post_status'      => 'inherit',
		'post_mime_type'   => hic_heic_mimes(),
		'suppress_filters' => true,
		'meta_query'       => array(
			array(
				'key'     => '_wp_attached_file',
				'value'   => '\\.(heic|heif)$',
				'compare' => 'REGEXP',
			),
		),
	);
}

function hic_get_pending_ids( $limit = -1, $include_failed = true ) {
	$args = array_merge( hic_pending_query_args(), array(
		'posts_per_page' => $limit,
		'fields'         => 'ids',
		'orderby'        => 'date',
		'order'          => 'DESC',
		'no_found_rows'  => true,
	) );
	if ( ! $include_failed ) {
		$args['meta_query'][] = array(
			'key'     => '_hic_failed',
			'compare' => 'NOT EXISTS',
		);
	}

	$ids = array_map( 'intval', get_posts( $args ) );
	return array_values( array_filter( $ids, function ( $id ) {
		return current_user_can( 'edit_post', $id );
	} ) );
}

function hic_pending_count() {
	$query = new WP_Query( array_merge( hic_pending_query_args(), array(
		'posts_per_page' => 1,
		'fields'         => 'ids',
	) ) );
	return (int) $query->found_posts;
}

/* -------------------------------------------------------------------------
 * Server-side conversion (WordPress 6.7+ with an image editor that reads HEIC)
 * ---------------------------------------------------------------------- */

function hic_server_supports_heic() {
	static $supported = null;
	if ( null === $supported ) {
		$supported = function_exists( 'wp_is_heic_image_mime_type' ) && wp_image_editor_supports( array( 'mime_type' => 'image/heic' ) );
	}
	return $supported;
}

/** Whether HEIC files should be converted by the server instead of the browser. */
function hic_use_server() {
	$s = hic_get_settings();
	return ! empty( $s['server_convert'] ) && hic_server_supports_heic();
}

// When the server converts new uploads, apply the plugin's format and size settings.
add_filter( 'image_editor_output_format', function ( $formats ) {
	if ( hic_use_server() && 'png' === hic_get_settings()['format'] ) {
		foreach ( hic_heic_mimes() as $mime ) {
			$formats[ $mime ] = 'image/png';
		}
	}
	return $formats;
} );

add_filter( 'big_image_size_threshold', function ( $threshold, $imagesize ) {
	if ( hic_use_server() && ! empty( $imagesize['mime'] ) && hic_is_heic_mime( $imagesize['mime'] ) ) {
		$max = (int) hic_get_settings()['max_size'];
		return $max > 0 ? $max : false;
	}
	return $threshold;
}, 10, 2 );

// Log uploads the server converted (WordPress stores the .heic as "original_image").
add_filter( 'wp_generate_attachment_metadata', function ( $meta, $id, $context ) {
	if ( 'create' === $context && ! empty( $meta['original_image'] ) && hic_is_heic_mime( get_post_mime_type( $id ) ) ) {
		$file = get_attached_file( $id );
		hic_log( array(
			'source'     => 'upload',
			'attachment' => $id,
			'file'       => $meta['original_image'],
			'output'     => $file ? wp_basename( $file ) : '',
			'out_size'   => isset( $meta['filesize'] ) ? $meta['filesize'] : 0,
			'decoder'    => 'server',
		) );
	}
	return $meta;
}, 10, 3 );

/** Attachments whose last conversion attempt failed. */
function hic_failed_count() {
	global $wpdb;
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s", '_hic_failed' ) );
}

function hic_item_data( $id ) {
	$file = get_attached_file( $id );
	return array(
		'id'     => (int) $id,
		'name'   => $file ? wp_basename( $file ) : 'image.heic',
		'url'    => wp_get_attachment_url( $id ),
		'failed' => (bool) get_post_meta( $id, '_hic_failed', true ),
	);
}

/* -------------------------------------------------------------------------
 * When an attachment is deleted, also delete the kept original .heic file.
 * ---------------------------------------------------------------------- */

add_action( 'delete_attachment', function ( $post_id ) {
	$relative = get_post_meta( $post_id, '_hic_original_file', true );
	if ( ! $relative ) {
		return;
	}
	$uploads = wp_get_upload_dir();
	$path    = path_join( $uploads['basedir'], $relative );
	if ( file_exists( $path ) ) {
		wp_delete_file( $path );
	}
} );

/* -------------------------------------------------------------------------
 * Activation + links
 * ---------------------------------------------------------------------- */

register_activation_hook( __FILE__, function () {
	add_option( 'hic_settings', hic_defaults() );
} );

/**
 * After an update, retry images that failed with an older version
 * (e.g. newer iPhone photos the previous converter could not read).
 */
add_action( 'admin_init', function () {
	if ( get_option( 'hic_version' ) !== HIC_VERSION ) {
		delete_post_meta_by_key( '_hic_failed' );
		update_option( 'hic_version', HIC_VERSION, false );
	}
} );

// Quick links on the Plugins screen.
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	$quick = array( 'hic-converter' => __( 'Tools', 'manpreetdev-heic-to-jpg-converter' ) );
	if ( current_user_can( 'manage_options' ) ) {
		$quick['hic-settings'] = __( 'Settings', 'manpreetdev-heic-to-jpg-converter' );
		$quick['hic-logs']     = __( 'Logs', 'manpreetdev-heic-to-jpg-converter' );
	}
	$quick['hic-help'] = __( 'Help', 'manpreetdev-heic-to-jpg-converter' );

	$html = array();
	foreach ( $quick as $page => $label ) {
		$html[] = '<a href="' . esc_url( admin_url( 'admin.php?page=' . $page ) ) . '">' . esc_html( $label ) . '</a>';
	}
	return array_merge( $html, $links );
} );

require_once HIC_PATH . 'includes/logs.php';
require_once HIC_PATH . 'includes/assets.php';
require_once HIC_PATH . 'includes/rest.php';
require_once HIC_PATH . 'includes/admin-page.php';
require_once HIC_PATH . 'includes/help-page.php';
