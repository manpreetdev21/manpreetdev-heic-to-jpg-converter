<?php
/**
 * Conversion log: the latest events, kept in one (non-autoloaded) option,
 * plus running totals for the dashboard cards.
 */

defined( 'ABSPATH' ) || exit;

define( 'HIC_LOG_LIMIT', 500 );

/**
 * Adds an entry to the log.
 *
 * @param array $data level (success|error), source (upload|library), attachment, file, output,
 *                    in_size, out_size, ms, decoder, message.
 */
function hic_log( $data ) {
	$data  = wp_parse_args( (array) $data, array(
		'level'      => 'success',
		'source'     => 'library',
		'attachment' => 0,
		'file'       => '',
		'output'     => '',
		'in_size'    => 0,
		'out_size'   => 0,
		'ms'         => 0,
		'decoder'    => '',
		'message'    => '',
	) );
	$entry = array(
		'time'       => time(),
		'user'       => get_current_user_id(),
		'level'      => 'error' === $data['level'] ? 'error' : 'success',
		'source'     => 'upload' === $data['source'] ? 'upload' : 'library',
		'attachment' => absint( $data['attachment'] ),
		'file'       => substr( sanitize_text_field( (string) $data['file'] ), 0, 200 ),
		'output'     => substr( sanitize_text_field( (string) $data['output'] ), 0, 200 ),
		'in_size'    => absint( $data['in_size'] ),
		'out_size'   => absint( $data['out_size'] ),
		'ms'         => absint( $data['ms'] ),
		'decoder'    => sanitize_key( (string) $data['decoder'] ),
		'message'    => substr( sanitize_text_field( (string) $data['message'] ), 0, 500 ),
	);

	// ponytail: one capped option; two simultaneous writes can drop an entry. Use a custom table if an exact audit trail is needed.
	$logs = get_option( 'hic_logs', array() );
	$logs = is_array( $logs ) ? $logs : array();
	array_unshift( $logs, $entry );
	update_option( 'hic_logs', array_slice( $logs, 0, HIC_LOG_LIMIT ), false );

	if ( 'success' === $entry['level'] ) {
		$stats = hic_get_stats();
		$stats[ $entry['source'] ]++;
		update_option( 'hic_stats', $stats, false );
	}
}

function hic_get_logs() {
	$logs = get_option( 'hic_logs', array() );
	return is_array( $logs ) ? $logs : array();
}

/** Totals of successful conversions: library (existing images) and upload (new files). */
function hic_get_stats() {
	$stats = get_option( 'hic_stats', array() );
	$stats = is_array( $stats ) ? $stats : array();
	return array(
		'library' => isset( $stats['library'] ) ? (int) $stats['library'] : 0,
		'upload'  => isset( $stats['upload'] ) ? (int) $stats['upload'] : 0,
	);
}

add_action( 'admin_post_hic_clear_logs', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'manpreetdev-heic-to-jpg-converter' ), 403 );
	}
	check_admin_referer( 'hic_clear_logs' );
	delete_option( 'hic_logs' );
	wp_safe_redirect( admin_url( 'admin.php?page=hic-logs&cleared=1' ) );
	exit;
} );

add_action( 'admin_post_hic_export_logs', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to do this.', 'manpreetdev-heic-to-jpg-converter' ), 403 );
	}
	check_admin_referer( 'hic_export_logs' );

	// Cells starting with = + - @ would run as formulas in Excel/Sheets.
	$cell = function ( $value ) {
		$value = (string) $value;
		return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
	};

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="heic-converter-log-' . gmdate( 'Y-m-d' ) . '.csv"' );

	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, array( 'time_utc', 'result', 'source', 'attachment_id', 'file', 'output', 'original_bytes', 'converted_bytes', 'ms', 'converter', 'user', 'message' ), ',', '"', '\\' );
	foreach ( hic_get_logs() as $entry ) {
		$user = $entry['user'] ? get_userdata( $entry['user'] ) : false;
		fputcsv( $out, array_map( $cell, array(
			gmdate( 'Y-m-d H:i:s', $entry['time'] ),
			$entry['level'],
			$entry['source'],
			$entry['attachment'],
			$entry['file'],
			$entry['output'],
			$entry['in_size'],
			$entry['out_size'],
			$entry['ms'],
			$entry['decoder'],
			$user ? $user->user_login : '',
			$entry['message'],
		) ), ',', '"', '\\' );
	}
	fclose( $out );
	exit;
} );

/** Details column:"a.heic → a.jpg · 3.1 MB → 1.2 MB · 2.4 s · heic-to · message". */
function hic_log_details( $entry ) {
	$parts = array();
	if ( $entry['output'] ) {
		$parts[] = '→ ' . $entry['output'];
	}
	if ( $entry['in_size'] && $entry['out_size'] ) {
		$parts[] = size_format( $entry['in_size'], 1 ) . ' → ' . size_format( $entry['out_size'], 1 );
	} elseif ( $entry['in_size'] ) {
		$parts[] = size_format( $entry['in_size'], 1 );
	}
	if ( $entry['ms'] ) {
		/* translators: %s: seconds */
		$parts[] = sprintf( __( '%s s', 'manpreetdev-heic-to-jpg-converter' ), number_format_i18n( $entry['ms'] / 1000, 1 ) );
	}
	if ( $entry['decoder'] ) {
		$parts[] = $entry['decoder'];
	}
	if ( $entry['message'] ) {
		$parts[] = $entry['message'];
	}
	return implode( ' · ', $parts );
}

function hic_render_logs_page() {
	$all    = hic_get_logs();
	$counts = array( 'all' => count( $all ), 'success' => 0, 'error' => 0 );
	foreach ( $all as $entry ) {
		$counts[ $entry['level'] ]++;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
	$level = isset( $_GET['level'] ) ? sanitize_key( $_GET['level'] ) : 'all';
	$level = isset( $counts[ $level ] ) ? $level : 'all';
	$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
	$cleared = ! empty( $_GET['cleared'] );
	// phpcs:enable

	$logs = 'all' === $level ? $all : array_values( wp_list_filter( $all, array( 'level' => $level ) ) );

	$per_page = 50;
	$pages    = max( 1, (int) ceil( count( $logs ) / $per_page ) );
	$paged    = min( $paged, $pages );
	$rows     = array_slice( $logs, ( $paged - 1 ) * $per_page, $per_page );
	$base     = admin_url( 'admin.php?page=hic-logs' );

	$filters = array(
		'all'     => __( 'All', 'manpreetdev-heic-to-jpg-converter' ),
		'success' => __( 'Converted', 'manpreetdev-heic-to-jpg-converter' ),
		'error'   => __( 'Errors', 'manpreetdev-heic-to-jpg-converter' ),
	);
	?>
	<?php if ( $cleared ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'The log was cleared.', 'manpreetdev-heic-to-jpg-converter' ); ?></p></div>
	<?php endif; ?>

	<section class="hic-card">
		<div class="hic-card__head">
			<div>
				<h2><?php esc_html_e( 'Activity log', 'manpreetdev-heic-to-jpg-converter' ); ?></h2>
				<p class="hic-muted">
					<?php
					/* translators: %d: maximum number of log entries */
					echo esc_html( sprintf( __( 'Every conversion on upload and in the Media Library. The latest %d events are kept.', 'manpreetdev-heic-to-jpg-converter' ), HIC_LOG_LIMIT ) );
					?>
				</p>
			</div>
			<?php if ( $all ) : ?>
				<div class="hic-buttons">
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=hic_export_logs' ), 'hic_export_logs' ) ); ?>"><span class="dashicons dashicons-download" aria-hidden="true"></span><?php esc_html_e( 'Download CSV', 'manpreetdev-heic-to-jpg-converter' ); ?></a>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Clear the whole log?', 'manpreetdev-heic-to-jpg-converter' ) ) ); ?>);">
						<input type="hidden" name="action" value="hic_clear_logs">
						<?php wp_nonce_field( 'hic_clear_logs' ); ?>
						<button type="submit" class="button hic-button-danger"><span class="dashicons dashicons-trash" aria-hidden="true"></span><?php esc_html_e( 'Clear log', 'manpreetdev-heic-to-jpg-converter' ); ?></button>
					</form>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( ! $all ) : ?>
			<div class="hic-empty">
				<span class="dashicons dashicons-list-view is-muted" aria-hidden="true"></span>
				<h3><?php esc_html_e( 'Nothing logged yet', 'manpreetdev-heic-to-jpg-converter' ); ?></h3>
				<p><?php esc_html_e( 'Conversions will appear here as soon as a HEIC photo is uploaded or converted.', 'manpreetdev-heic-to-jpg-converter' ); ?></p>
			</div>
		<?php else : ?>
			<nav class="hic-segmented" aria-label="<?php esc_attr_e( 'Filter log', 'manpreetdev-heic-to-jpg-converter' ); ?>">
				<?php foreach ( $filters as $key => $text ) : ?>
					<a href="<?php echo esc_url( 'all' === $key ? $base : add_query_arg( 'level', $key, $base ) ); ?>" class="<?php echo $key === $level ? 'is-active' : ''; ?>"<?php echo $key === $level ? ' aria-current="page"' : ''; ?>>
						<?php echo esc_html( $text ); ?> <span class="hic-count"><?php echo esc_html( number_format_i18n( $counts[ $key ] ) ); ?></span>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php if ( ! $rows ) : ?>
				<p class="hic-muted hic-pad"><?php esc_html_e( 'No entries match this filter.', 'manpreetdev-heic-to-jpg-converter' ); ?></p>
			<?php else : ?>
				<div class="hic-table-wrap">
					<table class="hic-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'When', 'manpreetdev-heic-to-jpg-converter' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Result', 'manpreetdev-heic-to-jpg-converter' ); ?></th>
								<th scope="col"><?php esc_html_e( 'File', 'manpreetdev-heic-to-jpg-converter' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Details', 'manpreetdev-heic-to-jpg-converter' ); ?></th>
								<th scope="col"><?php esc_html_e( 'User', 'manpreetdev-heic-to-jpg-converter' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $entry ) : ?>
								<?php
								$user = $entry['user'] ? get_userdata( $entry['user'] ) : false;
								$link = $entry['attachment'] ? get_edit_post_link( $entry['attachment'] ) : '';
								?>
								<tr>
									<td class="hic-nowrap">
										<time datetime="<?php echo esc_attr( gmdate( 'c', $entry['time'] ) ); ?>" title="<?php echo esc_attr( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $entry['time'] ) ); ?>">
											<?php
											/* translators: %s: human-readable time difference */
											echo esc_html( sprintf( __( '%s ago', 'manpreetdev-heic-to-jpg-converter' ), human_time_diff( $entry['time'] ) ) );
											?>
										</time>
									</td>
									<td class="hic-nowrap">
										<span class="hic-pill is-<?php echo 'error' === $entry['level'] ? 'error' : 'done'; ?>">
											<?php echo 'error' === $entry['level'] ? esc_html__( 'Failed', 'manpreetdev-heic-to-jpg-converter' ) : esc_html__( 'Converted', 'manpreetdev-heic-to-jpg-converter' ); ?>
										</span>
										<span class="hic-source"><?php echo 'upload' === $entry['source'] ? esc_html__( 'Upload', 'manpreetdev-heic-to-jpg-converter' ) : esc_html__( 'Library', 'manpreetdev-heic-to-jpg-converter' ); ?></span>
									</td>
									<td class="hic-file">
										<?php if ( $link ) : ?>
											<a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $entry['file'] ? $entry['file'] : '#' . $entry['attachment'] ); ?></a>
										<?php else : ?>
											<?php echo esc_html( $entry['file'] ? $entry['file'] : '—' ); ?>
										<?php endif; ?>
									</td>
									<td class="hic-details"><?php echo esc_html( hic_log_details( $entry ) ); ?></td>
									<td class="hic-nowrap"><?php echo esc_html( $user ? $user->display_name : '—' ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<?php if ( $pages > 1 ) : ?>
					<div class="hic-pagination">
						<?php
						echo wp_kses_post( paginate_links( array(
							'base'    => add_query_arg( 'paged', '%#%', 'all' === $level ? $base : add_query_arg( 'level', $level, $base ) ),
							'format'  => '',
							'current' => $paged,
							'total'   => $pages,
						) ) );
						?>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		<?php endif; ?>
	</section>
	<?php
}
