<?php
/**
 * REST endpoints that receive the JPG/PNG converted in the browser and swap it
 * into an existing HEIC attachment (same attachment ID, so Elementor widgets,
 * featured images and galleries keep working).
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', function () {
	$permission = function ( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		return current_user_can( 'upload_files' ) && current_user_can( 'edit_post', $id );
	};

	register_rest_route( 'hic/v1', '/convert/(?P<id>\d+)', array(
		'methods'             => 'POST',
		'callback'            => 'hic_rest_convert',
		'permission_callback' => $permission,
	) );

	register_rest_route( 'hic/v1', '/fail/(?P<id>\d+)', array(
		'methods'             => 'POST',
		'callback'            => 'hic_rest_fail',
		'permission_callback' => $permission,
	) );
} );

function hic_rest_fail( WP_REST_Request $request ) {
	$id      = (int) $request['id'];
	$message = sanitize_text_field( (string) $request->get_param( 'message' ) );
	update_post_meta( $id, '_hic_failed', $message ? substr( $message, 0, 500 ) : 'error' );
	return rest_ensure_response( array( 'id' => $id ) );
}

function hic_rest_convert( WP_REST_Request $request ) {
	$id   = (int) $request['id'];
	$post = get_post( $id );

	if ( ! $post || 'attachment' !== $post->post_type ) {
		return new WP_Error( 'hic_not_found', __( 'Attachment not found.', 'heic-to-jpg-converter' ), array( 'status' => 404 ) );
	}

	// Already converted (e.g. by another open tab).
	if ( ! hic_is_heic_mime( $post->post_mime_type ) ) {
		return rest_ensure_response( array(
			'id'      => $id,
			'skipped' => true,
			'url'     => wp_get_attachment_url( $id ),
		) );
	}

	$files = $request->get_file_params();
	if ( empty( $files['file'] ) || ! is_array( $files['file'] ) ) {
		return new WP_Error( 'hic_no_file', __( 'No converted file was received.', 'heic-to-jpg-converter' ), array( 'status' => 400 ) );
	}

	$lock = 'hic_lock_' . $id;
	if ( get_transient( $lock ) ) {
		return new WP_Error( 'hic_locked', __( 'This image is already being converted.', 'heic-to-jpg-converter' ), array( 'status' => 409 ) );
	}
	set_transient( $lock, 1, 5 * MINUTE_IN_SECONDS );
	ignore_user_abort( true );

	try {
		$result = hic_replace_attachment_file( $id, $files['file'] );
	} finally {
		delete_transient( $lock );
	}

	return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
}

/**
 * Stores the converted file next to the original and points the attachment at it.
 *
 * @return array|WP_Error
 */
function hic_replace_attachment_file( $id, $file ) {
	$settings = hic_get_settings();

	if ( ! isset( $file['error'], $file['tmp_name'], $file['name'] ) || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
		return new WP_Error( 'hic_upload', __( 'The converted file did not upload correctly.', 'heic-to-jpg-converter' ), array( 'status' => 400 ) );
	}

	// Only JPG or PNG are accepted, verified by real file contents.
	$check = wp_check_filetype_and_ext(
		$file['tmp_name'],
		$file['name'],
		array(
			'jpg|jpeg|jpe' => 'image/jpeg',
			'png'          => 'image/png',
		)
	);
	if ( empty( $check['type'] ) ) {
		return new WP_Error( 'hic_type', __( 'Only JPG or PNG files can replace a HEIC image.', 'heic-to-jpg-converter' ), array( 'status' => 415 ) );
	}

	$old_file = get_attached_file( $id );
	$uploads  = wp_get_upload_dir();
	$dir      = ( $old_file && is_dir( dirname( $old_file ) ) ) ? dirname( $old_file ) : $uploads['path'];

	if ( ! wp_is_writable( $dir ) ) {
		return new WP_Error( 'hic_dir', __( 'The uploads folder is not writable.', 'heic-to-jpg-converter' ), array( 'status' => 500 ) );
	}

	$ext  = ( 'image/png' === $check['type'] ) ? 'png' : 'jpg';
	$base = $old_file ? pathinfo( $old_file, PATHINFO_FILENAME ) : 'image';
	$name = wp_unique_filename( $dir, sanitize_file_name( $base . '.' . $ext ) );
	$dest = trailingslashit( $dir ) . $name;

	if ( ! @move_uploaded_file( $file['tmp_name'], $dest ) ) {
		return new WP_Error( 'hic_move', __( 'Could not save the converted file.', 'heic-to-jpg-converter' ), array( 'status' => 500 ) );
	}

	// Same permissions WordPress gives normal uploads.
	$stat = @stat( dirname( $dest ) );
	if ( $stat ) {
		@chmod( $dest, $stat['mode'] & 0000666 );
	}

	$size = function_exists( 'wp_getimagesize' ) ? wp_getimagesize( $dest ) : @getimagesize( $dest );
	if ( ! $size ) {
		wp_delete_file( $dest );
		return new WP_Error( 'hic_invalid', __( 'The converted file is not a valid image.', 'heic-to-jpg-converter' ), array( 'status' => 422 ) );
	}

	$old_url  = wp_get_attachment_url( $id );
	$old_meta = wp_get_attachment_metadata( $id );

	// Remove any thumbnails previously generated from the HEIC file.
	if ( $old_file && is_array( $old_meta ) && ! empty( $old_meta['sizes'] ) ) {
		foreach ( $old_meta['sizes'] as $sub ) {
			if ( ! empty( $sub['file'] ) ) {
				$path = path_join( dirname( $old_file ), $sub['file'] );
				if ( file_exists( $path ) ) {
					wp_delete_file( $path );
				}
			}
		}
	}

	// Point the attachment at the new file.
	update_attached_file( $id, $dest );
	wp_update_post( array(
		'ID'             => $id,
		'post_mime_type' => $check['type'],
	) );

	// Normal WordPress thumbnail generation (works with GD or Imagick on JPG/PNG).
	require_once ABSPATH . 'wp-admin/includes/image.php';
	$meta = wp_generate_attachment_metadata( $id, $dest );
	wp_update_attachment_metadata( $id, $meta );

	// Keep or remove the original HEIC.
	if ( $old_file && file_exists( $old_file ) ) {
		if ( ! empty( $settings['keep_original'] ) ) {
			update_post_meta( $id, '_hic_original_file', _wp_relative_upload_path( $old_file ) );
		} else {
			wp_delete_file( $old_file );
		}
	}

	delete_post_meta( $id, '_hic_failed' );
	clean_attachment_cache( $id );

	$new_url  = wp_get_attachment_url( $id );
	$replaced = ! empty( $settings['replace_urls'] ) ? hic_replace_urls( $old_url, $new_url ) : 0;

	do_action( 'hic_attachment_converted', $id, $dest, $old_file );

	return array(
		'id'       => $id,
		'url'      => $new_url,
		'old_url'  => $old_url,
		'replaced' => $replaced,
	);
}

/**
 * Replaces the old .heic URL with the new one in post content and Elementor data.
 *
 * @return int Number of posts updated.
 */
function hic_replace_urls( $old_url, $new_url ) {
	global $wpdb;

	if ( ! $old_url || ! $new_url || $old_url === $new_url ) {
		return 0;
	}

	$ids = array();

	// Post content (classic editor, blocks).
	$like = '%' . $wpdb->esc_like( $old_url ) . '%';
	$ids  = array_merge( $ids, $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_content LIKE %s", $like ) ) );
	$wpdb->query( $wpdb->prepare(
		"UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_content LIKE %s",
		$old_url,
		$new_url,
		$like
	) );

	// Elementor stores JSON with escaped slashes (https:\/\/...).
	$old_json = str_replace( '/', '\\/', $old_url );
	$new_json = str_replace( '/', '\\/', $new_url );
	foreach ( array( array( $old_url, $new_url ), array( $old_json, $new_json ) ) as $pair ) {
		$like_meta = '%' . $wpdb->esc_like( $pair[0] ) . '%';
		$ids       = array_merge( $ids, $wpdb->get_col( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND meta_value LIKE %s",
			$like_meta
		) ) );
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$wpdb->postmeta} SET meta_value = REPLACE(meta_value, %s, %s) WHERE meta_key = '_elementor_data' AND meta_value LIKE %s",
			$pair[0],
			$pair[1],
			$like_meta
		) );
	}

	$ids = array_unique( array_map( 'intval', $ids ) );
	foreach ( $ids as $post_id ) {
		clean_post_cache( $post_id );
	}

	// Let Elementor rebuild its CSS files with the new URLs.
	if ( $ids && class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
		\Elementor\Plugin::$instance->files_manager->clear_cache();
	}

	return count( $ids );
}
