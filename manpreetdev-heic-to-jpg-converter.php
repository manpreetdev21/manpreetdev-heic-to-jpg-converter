<?php
/**
 * Plugin Name:       HEIC to JPG Converter
 * Description:       Automatically converts iPhone .heic/.heif photos to JPG or PNG inside the browser — when they are uploaded, and for HEIC images already in the Media Library. No ImageMagick or other server-side HEIC support needed.
 * Version:           1.1.0
 * Requires at least: 5.8
 * Requires PHP:      7.0
 * Author:            Manpreet Singh
 * Author URI:        https://github.com/manpreetdev21/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       heic-to-jpg-converter
 */

defined( 'ABSPATH' ) || exit;

define( 'HIC_VERSION', '1.1.0' );
define( 'HIC_FILE', __FILE__ );
define( 'HIC_PATH', plugin_dir_path( __FILE__ ) );
define( 'HIC_URL', plugin_dir_url( __FILE__ ) );

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
function hic_get_pending_ids( $limit = -1, $include_failed = true ) {
	$args = array(
		'post_type'        => 'attachment',
		'post_status'      => 'inherit',
		'post_mime_type'   => hic_heic_mimes(),
		'posts_per_page'   => $limit,
		'fields'           => 'ids',
		'orderby'          => 'date',
		'order'            => 'DESC',
		'no_found_rows'    => true,
		'suppress_filters' => true,
	);
	if ( ! $include_failed ) {
		$args['meta_query'] = array(
			array(
				'key'     => '_hic_failed',
				'compare' => 'NOT EXISTS',
			),
		);
	}

	$ids = array_map( 'intval', get_posts( $args ) );
	return array_values( array_filter( $ids, function ( $id ) {
		return current_user_can( 'edit_post', $id );
	} ) );
}

function hic_pending_count() {
	$counts = wp_count_attachments();
	$total  = 0;
	foreach ( hic_heic_mimes() as $mime ) {
		if ( isset( $counts->$mime ) ) {
			$total += (int) $counts->$mime;
		}
	}
	return $total;
}

function hic_item_data( $id ) {
	$file = get_attached_file( $id );
	return array(
		'id'   => (int) $id,
		'name' => $file ? wp_basename( $file ) : 'image.heic',
		'url'  => wp_get_attachment_url( $id ),
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

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
	$url = admin_url( 'upload.php?page=hic-converter' );
	array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'heic-to-jpg-converter' ) . '</a>' );
	return $links;
} );

require_once HIC_PATH . 'includes/assets.php';
require_once HIC_PATH . 'includes/rest.php';
require_once HIC_PATH . 'includes/admin-page.php';
