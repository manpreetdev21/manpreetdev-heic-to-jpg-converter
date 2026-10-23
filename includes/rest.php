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

	// Log entries for files converted in the browser while uploading.
	register_rest_route( 'hic/v1', '/log', array(
		'methods'             => 'POST',
		'callback'            => 'hic_rest_log',
		'permission_callback' => function () {
			return current_user_can( 'upload_files' );
		},
	) );
} );

function hic_rest_log( WP_REST_Request $request ) {
	hic_log( array(
		'level'    => $request->get_param( 'level' ),
		'source'   => 'upload',
		'file'     => $request->get_param( 'name' ),
		'output'   => $request->get_param( 'output' ),
		'in_size'  => $request->get_param( 'in_size' ),
		'out_size' => $request->get_param( 'out_size' ),
		'ms'       => $request->get_param( 'ms' ),
		'decoder'  => $request->get_param( 'decoder' ),
		'message'  => $request->get_param( 'message' ),
	) );
	return rest_ensure_response( array( 'logged' => true ) );
}

function hic_rest_fail( WP_REST_Request $request ) {
	$id      = (int) $request['id'];
	$message = sanitize_text_field( (string) $request->get_param( 'message' ) );
	update_post_meta( $id, '_hic_failed', $message ? substr( $message, 0, 500 ) : 'error' );

	$file = get_attached_file( $id );
	hic_log( array(
		'level'      => 'error',
		'attachment' => $id,
		'file'       => $file ? wp_basename( $file ) : '',
		'in_size'    => $file && file_exists( $file ) ? filesize( $file ) : 0,
		'message'    => $message,
	) );
	return rest_ensure_response( array( 'id' => $id ) );
}

/**
 * Swaps a HEIC attachment for a JPG/PNG. The file comes from the browser (multipart "file"),
 * or with server=1 the server converts it itself.
 */
function hic_rest_convert( WP_REST_Request $request ) {
	$id     = (int) $request['id'];
	$post   = get_post( $id );
	$server = (bool) $request->get_param( 'server' );

	if ( ! $post || 'attachment' !== $post->post_type ) {
		return new WP_Error( 'hic_not_found', __( 'Attachment not found.', 'manpreetdev-heic-to-jpg-converter' ), array( 'status' => 404 ) );
	}

	$files = $request->get_file_params();
	if ( ! $server && ( empty( $files['file'] ) || ! is_array( $files['file'] ) ) ) {
		return new WP_Error( 'hic_no_file', __( 'No converted file was received.', 'manpreetdev-heic-to-jpg-converter' ), array( 'status' => 400 ) );
	}

	// INSERT IGNORE is atomic, so two tabs can never both take the lock.
	// A lock older than 5 minutes is left over from a crashed request.
	global $wpdb;
	$lock = 'hic_lock_' . $id;
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value < %d", $lock, time() - 5 * MINUTE_IN_SECONDS ) );
	if ( ! $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %d, 'no')", $lock, time() ) ) ) {
		return new WP_Error( 'hic_locked', __( 'This image is already being converted.', 'manpreetdev-heic-to-jpg-converter' ), array( 'status' => 409 ) );
	}
	ignore_user_abort( true );
	$started = microtime( true );

	try {
		// Checked inside the lock: another tab may have just finished this one.
		clean_post_cache( $id );
		if ( ! hic_is_heic_mime( get_post_mime_type( $id ) ) ) {
			return rest_ensure_response( array(
				'id'      => $id,
				'skipped' => true,
				'url'     => wp_get_attachment_url( $id ),
			) );
		}
		$result = $server ? hic_convert_on_server( $id ) : hic_replace_attachment_file( $id, $files['file'] );
	} finally {
		$wpdb->delete( $wpdb->options, array( 'option_name' => $lock ) );
	}

	if ( is_wp_error( $result ) ) {
		return $result;
	}

	$message = '';
	if ( $result['replaced'] ) {
		/* translators: %d: number of posts */
		$message = sprintf( _n( 'Links updated in %d post.', 'Links updated in %d posts.', $result['replaced'], 'manpreetdev-heic-to-jpg-converter' ), $result['replaced'] );
	}
	hic_log( array(
		'attachment' => $id,
		'file'       => $result['file'],
		'output'     => $result['output'],
		'in_size'    => $result['in_size'],
		'out_size'   => $result['out_size'],
		'ms'         => $server ? round( ( microtime( true ) - $started ) * 1000 ) : $request->get_param( 'ms' ),
		'decoder'    => $server ? 'server' : $request->get_param( 'decoder' ),
		'message'    => $message,
	) );

	return rest_ensure_response( $result );
}

/**
 * Where the converted file goes: next to the original, same base name, new extension.
 *
 * @return string|WP_Error Absolute path.
 */
function hic_target_path( $id, $ext ) {
	$old_file = get_attached_file( $id );
	$uploads  = wp_get_upload_dir();
	$dir      = ( $old_file && is_dir( dirname( $old_file ) ) ) ? dirname( $old_file ) : $uploads['path'];

	if ( ! wp_is_writable( $dir ) ) {
		return new WP_Error( 'hic_dir', __( 'The uploads folder is not writable.', 'manpreetdev-heic-to-jpg-converter' ), array( 'status' => 500 ) );
	}
	$base = $old_file ? pathinfo( $old_file, PATHINFO_FILENAME ) : 'image';
	return trailingslashit( $dir ) . wp_unique_filename( $dir, sanitize_file_name( $base . '.' . $ext ) );
}

/**
 * Stores the JPG/PNG converted in the browser and points the attachment at it.
 *
 * @return array|WP_Error
 */
function hic_replace_attachment_file( $id, $file ) {
	if ( ! isset( $file['error'], $file['tmp_name'], $file['name'] ) || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
		return new WP_Error( 'hic_upload', __( 'The converted file did not upload correctly.', 'manpreetdev-heic-to-jpg-converter' ), array( 'status' => 400 ) );
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
		return new WP_Error( 'hic_type', __( 'Only JPG or PNG files can replace a HEIC image.', 'manpreetdev-heic-to-jpg-converter' ), array( 'status' => 415 ) );
	}

	$dest = hic_target_path( $id, 'image/png' === $check['type'] ? 'png' : 'jpg' );
	if ( is_wp_error( $dest ) ) {
		return $dest;
	}
	if ( ! @move_uploaded_file( $file['tmp_name'], $dest ) ) {
		return new WP_Error( 'hic_move', __( 'Could not save the converted file.', 'manpreetdev-heic-to-jpg-converter' ), array( 'status' => 500 ) );
	}

	// Same permissions WordPress gives normal uploads.
	$stat = @stat( dirname( $dest ) );
	if ( $stat ) {
		@chmod( $dest, $stat['mode'] & 0000666 );
	}

	return hic_swap_attachment_file( $id, $dest, $check['type'] );
}

/**
 * Converts the attachment's HEIC file with the server's image editor (Imagick with HEIC support),
 * using the plugin's format, quality and size settings.
 *
 * @return array|WP_Error
 */
function hic_convert_on_server( $id ) {
	if ( ! hic_server_supports_heic() ) {
		return new WP_Error( 'hic_server', __( 'This server cannot read HEIC images.', 'manpreetdev-heic-to-jpg-converter' ), array( 'status' => 501 ) );
	}
	$settings = hic_get_settings();
	$editor   = wp_get_image_editor( get_attached_file( $id ) );
	if ( is_wp_error( $editor ) ) {
		return $editor;
	}

	$size = $editor->get_size();
	$max  = (int) $settings['max_size'];
	if ( $max > 0 && max( $size['width'], $size['height'] ) > $max ) {
		$resized = $editor->resize( $max, $max );
		if ( is_wp_error( $resized ) ) {
			return $resized;
		}
	}
	$editor->maybe_exif_rotate();

	$mime = 'png' === $settings['format'] ? 'image/png' : 'image/jpeg';
	if ( 'image/jpeg' === $mime ) {
		$editor->set_quality( (int) $settings['quality'] );
	}

	$dest = hic_target_path( $id, 'image/png' === $mime ? 'png' : 'jpg' );
	if ( is_wp_error( $dest ) ) {
		return $dest;
	}
	$saved = $editor->save( $dest, $mime );
	if ( is_wp_error( $saved ) ) {
		return $saved;
	}

	return hic_swap_attachment_file( $id, $saved['path'], $mime );
}

/**
 * Points the attachment at a converted file already in the uploads folder, rebuilds
 * thumbnails and updates old links.
 *
 * @return array|WP_Error
 */
function hic_swap_attachment_file( $id, $dest, $mime ) {
	$settings = hic_get_settings();

	$size = function_exists( 'wp_getimagesize' ) ? wp_getimagesize( $dest ) : @getimagesize( $dest );
	if ( ! $size ) {
		wp_delete_file( $dest );
		return new WP_Error( 'hic_invalid', __( 'The converted file is not a valid image.', 'manpreetdev-heic-to-jpg-converter' ), array( 'status' => 422 ) );
	}

	$old_file = get_attached_file( $id );
	$old_url  = wp_get_attachment_url( $id );
	$old_meta = wp_get_attachment_metadata( $id );
	$old_size = $old_file && file_exists( $old_file ) ? filesize( $old_file ) : 0;
	$old_subs = array(); // Size name => old URL.

	// Remove any thumbnails previously generated from the HEIC file.
	if ( $old_file && is_array( $old_meta ) && ! empty( $old_meta['sizes'] ) ) {
		foreach ( $old_meta['sizes'] as $size_name => $sub ) {
			if ( ! empty( $sub['file'] ) ) {
				$old_subs[ $size_name ] = dirname( $old_url ) . '/' . $sub['file'];
				$path                   = path_join( dirname( $old_file ), $sub['file'] );
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
		'post_mime_type' => $mime,
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
	$replaced = 0;
	if ( ! empty( $settings['replace_urls'] ) ) {
		// Old resized copies map to the same size of the new image (the full image if that size no longer exists).
		$pairs = array( array( $old_url, $new_url ) );
		foreach ( $old_subs as $size_name => $old_sub_url ) {
			$new_sub = wp_get_attachment_image_url( $id, $size_name );
			$pairs[] = array( $old_sub_url, $new_sub ? $new_sub : $new_url );
		}
		$replaced = hic_replace_urls( $pairs );
	}

	do_action( 'hic_attachment_converted', $id, $dest, $old_file );

	return array(
		'id'       => $id,
		'url'      => $new_url,
		'old_url'  => $old_url,
		'thumb'    => wp_get_attachment_image_url( $id, 'thumbnail' ),
		'file'     => $old_file ? wp_basename( $old_file ) : '',
		'output'   => wp_basename( $dest ),
		'in_size'  => $old_size,
		'out_size' => filesize( $dest ),
		'replaced' => $replaced,
	);
}

/**
 * Replaces old URLs with new ones in post content and Elementor data.
 *
 * @param array $pairs List of array( old_url, new_url ).
 * @return int Number of posts updated.
 */
function hic_replace_urls( $pairs ) {
	global $wpdb;

	$ids = array();
	foreach ( $pairs as $pair ) {
		list( $old_url, $new_url ) = $pair;
		if ( ! $old_url || ! $new_url || $old_url === $new_url ) {
			continue;
		}

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
		foreach ( array( array( $old_url, $new_url ), array( $old_json, $new_json ) ) as $variant ) {
			$like_meta = '%' . $wpdb->esc_like( $variant[0] ) . '%';
			$ids       = array_merge( $ids, $wpdb->get_col( $wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_elementor_data' AND meta_value LIKE %s",
				$like_meta
			) ) );
			$wpdb->query( $wpdb->prepare(
				"UPDATE {$wpdb->postmeta} SET meta_value = REPLACE(meta_value, %s, %s) WHERE meta_key = '_elementor_data' AND meta_value LIKE %s",
				$variant[0],
				$variant[1],
				$like_meta
			) );
		}
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
