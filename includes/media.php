<?php
/**
 * The media library, as far as an editor needs it.
 *
 * Alt text usually sits on the attachment, not on the block that shows the
 * image. Without a way to see the library, an agent auditing a site for
 * missing alt text can count the images and say nothing useful about any
 * of them: it cannot tell an empty alt attribute in a block from an alt
 * attribute the theme fills in from the attachment.
 *
 * What this deliberately does not do: upload, delete, replace files, or
 * touch anything but the two text fields a human would otherwise retype.
 * The agent role has no upload_files capability and this does not work
 * around that.
 *
 * @package wp-mcp-connector-plus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * List attachments with their alt text and where they are used.
 *
 * `usedIn` is the point of the tool. An image with no alt text and no
 * pages behind it is a cleanup job; the same image on eleven pages is a
 * different decision entirely, and until now telling them apart meant
 * opening the media library by hand.
 *
 * @param array $args { search, mime_type, missing_alt, per_page, page }.
 * @return array
 */
function wpmcp_media_list( array $args ) {
	$per_page = min( 100, max( 1, (int) ( $args['per_page'] ?? 25 ) ) );
	$page     = max( 1, (int) ( $args['page'] ?? 1 ) );

	$query_args = array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => $per_page,
		'paged'          => $page,
		'orderby'        => 'date',
		'order'          => 'DESC',
	);

	if ( ! empty( $args['search'] ) ) {
		$query_args['s'] = (string) $args['search'];
	}

	if ( ! empty( $args['mime_type'] ) ) {
		$query_args['post_mime_type'] = (string) $args['mime_type'];
	}

	if ( ! empty( $args['missing_alt'] ) ) {
		$query_args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
			'relation' => 'OR',
			array(
				'key'     => '_wp_attachment_image_alt',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => '_wp_attachment_image_alt',
				'value'   => '',
				'compare' => '=',
			),
		);
	}

	$query = new \WP_Query( $query_args );
	$items = array();
	$ids   = wp_list_pluck( $query->posts, 'ID' );
	$usage = wpmcp_media_usage( $ids );

	foreach ( $query->posts as $attachment ) {
		$items[] = wpmcp_media_shape( $attachment, $usage[ $attachment->ID ] ?? array() );
	}

	return array(
		'items'   => $items,
		'total'   => (int) $query->found_posts,
		'pages'   => (int) $query->max_num_pages,
		'page'    => $page,
		'source'  => 'the media library, as stored',
	);
}

/**
 * Read one attachment.
 *
 * @param int $id Attachment ID.
 * @return array|\WP_Error
 */
function wpmcp_media_read( $id ) {
	$attachment = wpmcp_get_attachment( $id );
	if ( is_wp_error( $attachment ) ) {
		return $attachment;
	}

	$usage = wpmcp_media_usage( array( $attachment->ID ) );

	return wpmcp_media_shape( $attachment, $usage[ $attachment->ID ] ?? array() );
}

/**
 * Change an attachment's alt text or title.
 *
 * @param array $args { id, alt, title, dry_run }.
 * @return array|\WP_Error
 */
function wpmcp_media_update( array $args ) {
	$attachment = wpmcp_get_attachment( (int) ( $args['id'] ?? 0 ) );
	if ( is_wp_error( $attachment ) ) {
		return $attachment;
	}

	if ( ! current_user_can( 'edit_post', $attachment->ID ) ) {
		return new \WP_Error(
			'wpmcp_forbidden',
			sprintf( 'No permission to edit attachment %d.', $attachment->ID )
		);
	}

	$dry_run = ! isset( $args['dry_run'] ) || (bool) $args['dry_run'];
	$changes = array();

	if ( array_key_exists( 'alt', $args ) ) {
		$from = (string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true );
		$to   = sanitize_text_field( (string) $args['alt'] );
		$changes['alt'] = array(
			'from'    => $from,
			'to'      => $to,
			'changed' => ( $from !== $to ),
		);
	}

	if ( array_key_exists( 'title', $args ) ) {
		$from = (string) $attachment->post_title;
		$to   = sanitize_text_field( (string) $args['title'] );
		$changes['title'] = array(
			'from'    => $from,
			'to'      => $to,
			'changed' => ( $from !== $to ),
		);
	}

	if ( empty( $changes ) ) {
		return new \WP_Error( 'wpmcp_bad_request', 'Provide "alt" or "title" to change.' );
	}

	$response = array(
		'ok'      => true,
		'dryRun'  => $dry_run,
		'id'      => $attachment->ID,
		'changes' => $changes,
		// Neither field is covered by the revision system.
		'note'    => 'Attachment fields have no revisions. The previous values are listed above and in the activity log.',
	);

	if ( $dry_run ) {
		$response['message'] = 'Dry run only — nothing was saved. Call again with dry_run: false to write.';
		return $response;
	}

	if ( ! empty( $changes['alt']['changed'] ) ) {
		update_post_meta( $attachment->ID, '_wp_attachment_image_alt', $changes['alt']['to'] );
	}

	if ( ! empty( $changes['title']['changed'] ) ) {
		wp_update_post(
			array(
				'ID'         => $attachment->ID,
				'post_title' => wp_slash( $changes['title']['to'] ),
			)
		);
	}

	wpmcp_log(
		'wpmcp/media-update',
		array(
			'post_id' => $attachment->ID,
			'summary' => wpmcp_media_log_line( $changes ),
		)
	);

	$response['message'] = 'Saved.';

	return $response;
}

/**
 * Resolve an attachment, or say why not.
 *
 * @param int $id Attachment ID.
 * @return \WP_Post|\WP_Error
 */
function wpmcp_get_attachment( $id ) {
	$attachment = get_post( (int) $id );

	if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
		return new \WP_Error( 'wpmcp_not_found', sprintf( 'No attachment with ID %d.', (int) $id ) );
	}

	return $attachment;
}

/**
 * The shape one attachment takes in a response.
 *
 * @param \WP_Post $attachment Attachment.
 * @param int[]    $used_in    Post IDs embedding it.
 * @return array
 */
function wpmcp_media_shape( $attachment, array $used_in ) {
	$file = get_attached_file( $attachment->ID );

	return array(
		'id'       => (int) $attachment->ID,
		'filename' => $file ? basename( $file ) : '',
		'url'      => wp_get_attachment_url( $attachment->ID ),
		'mime'     => $attachment->post_mime_type,
		'alt'      => (string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true ),
		'title'    => $attachment->post_title,
		'caption'  => $attachment->post_excerpt,
		'usedIn'   => $used_in,
	);
}

/**
 * Which posts embed each of these attachments.
 *
 * Two ways an image reaches a page, and both count: the editor writes a
 * wp-image-{id} class into the markup, and a featured image is a meta
 * value with no trace in the content at all. Looking at only one of them
 * would report images as unused that are on the front page.
 *
 * One query for the batch rather than one per attachment — a media
 * library is long and a LIKE over post content is not cheap.
 *
 * @param int[] $ids Attachment IDs.
 * @return array<int, int[]> Attachment ID => post IDs.
 */
function wpmcp_media_usage( array $ids ) {
	global $wpdb;

	$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
	if ( empty( $ids ) ) {
		return array();
	}

	$usage = array_fill_keys( $ids, array() );

	// In the markup: the class the editor writes, and the file URL for
	// anything hand-written or built by a page builder.
	$clauses = array();
	$values  = array();
	foreach ( $ids as $id ) {
		$clauses[] = 'post_content LIKE %s';
		$values[]  = '%' . $wpdb->esc_like( 'wp-image-' . $id ) . '%';

		$url = wp_get_attachment_url( $id );
		if ( $url ) {
			$clauses[] = 'post_content LIKE %s';
			$values[]  = '%' . $wpdb->esc_like( wpmcp_url_path( $url ) ) . '%';
		}
	}

	$sql = "SELECT ID, post_content FROM {$wpdb->posts}
			WHERE post_type NOT IN ('revision', 'attachment')
			  AND post_status NOT IN ('trash', 'auto-draft')
			  AND (" . implode( ' OR ', $clauses ) . ')';

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	$rows = $wpdb->get_results( $wpdb->prepare( $sql, $values ) );

	foreach ( $rows as $row ) {
		foreach ( $ids as $id ) {
			$url  = wp_get_attachment_url( $id );
			$path = $url ? wpmcp_url_path( $url ) : '';
			$hit  = false !== strpos( $row->post_content, 'wp-image-' . $id )
				|| ( '' !== $path && false !== strpos( $row->post_content, $path ) );

			if ( $hit && ! in_array( (int) $row->ID, $usage[ $id ], true ) ) {
				$usage[ $id ][] = (int) $row->ID;
			}
		}
	}

	// As a featured image, where nothing appears in the content.
	$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
	$thumbs = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta}
			 WHERE meta_key = '_thumbnail_id' AND meta_value IN ({$placeholders})",
			$ids
		)
	);

	foreach ( $thumbs as $row ) {
		$id = (int) $row->meta_value;
		if ( isset( $usage[ $id ] ) && ! in_array( (int) $row->post_id, $usage[ $id ], true ) ) {
			$usage[ $id ][] = (int) $row->post_id;
		}
	}

	return $usage;
}

/**
 * The path part of an upload URL.
 *
 * Matching on the whole URL misses every page written while the site ran
 * on another domain, or over http.
 *
 * @param string $url Attachment URL.
 * @return string
 */
function wpmcp_url_path( $url ) {
	$path = wp_parse_url( $url, PHP_URL_PATH );

	return is_string( $path ) ? $path : $url;
}

/**
 * A log line carrying the previous values.
 *
 * @param array $changes Change set from wpmcp_media_update().
 * @return string
 */
function wpmcp_media_log_line( array $changes ) {
	$parts = array();

	foreach ( $changes as $field => $change ) {
		if ( empty( $change['changed'] ) ) {
			continue;
		}
		$parts[] = sprintf(
			'%s: "%s" -> "%s"',
			$field,
			wpmcp_shorten( $change['from'], 80 ),
			wpmcp_shorten( $change['to'], 80 )
		);
	}

	return 'Media ' . implode( '; ', $parts );
}

/**
 * The largest upload accepted, in bytes.
 *
 * @return int
 */
function wpmcp_max_upload_bytes() {
	return (int) apply_filters( 'wpmcp_max_upload_bytes', 8 * 1024 * 1024 );
}

/**
 * Look at an upload before anything touches the disk.
 *
 * What a file is gets decided by its bytes, never by its name: a PNG
 * called shell.php is stored as shell.png, and a "photo.jpg" that is
 * really an SVG is refused. SVG is refused outright because it can carry
 * script, and so is anything with a PHP opening tag in it, which is the
 * shape of an image that doubles as a program.
 *
 * @param string $filename Name as sent.
 * @param string $data     Base64, optionally as a data: URL.
 * @return array|\WP_Error { bytes, mime, filename, width, height, size }
 */
function wpmcp_inspect_upload( $filename, $data ) {
	$data = is_string( $data ) ? $data : '';

	if ( preg_match( '#^data:[^;,]+;base64,#i', $data ) ) {
		$data = substr( $data, strpos( $data, ',' ) + 1 );
	}

	$bytes = base64_decode( preg_replace( '/\s+/', '', $data ), true );
	if ( false === $bytes || '' === $bytes ) {
		return new \WP_Error( 'wpmcp_bad_upload', '"data" is not valid base64. Send the file contents base64-encoded, or as a data: URL.' );
	}

	$size = strlen( $bytes );
	$max  = wpmcp_max_upload_bytes();
	if ( $size > $max ) {
		return new \WP_Error(
			'wpmcp_upload_too_large',
			sprintf( 'The file is %.1f MB; the limit is %.1f MB. Resize it before uploading — a web image rarely needs more.', $size / 1048576, $max / 1048576 )
		);
	}

	if ( false !== stripos( $bytes, '<?php' ) ) {
		return new \WP_Error( 'wpmcp_upload_refused', 'The file contains a PHP opening tag. An image does not; a file that is both an image and a program is refused.' );
	}

	$types = array(
		IMAGETYPE_JPEG => array( 'image/jpeg', 'jpg' ),
		IMAGETYPE_PNG  => array( 'image/png', 'png' ),
		IMAGETYPE_WEBP => array( 'image/webp', 'webp' ),
	);

	$info = @getimagesizefromstring( $bytes ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	if ( ! $info || ! isset( $types[ $info[2] ] ) ) {
		return new \WP_Error(
			'wpmcp_upload_type',
			'Only JPEG, PNG and WebP images are accepted, judged by the file contents rather than its name. SVG is refused because it can carry script.'
		);
	}

	list( $mime, $ext ) = $types[ $info[2] ];

	$base = pathinfo( (string) $filename, PATHINFO_FILENAME );
	$base = function_exists( 'sanitize_file_name' )
		? sanitize_file_name( $base )
		: preg_replace( '/[^A-Za-z0-9._-]+/', '-', $base );
	$base = trim( str_replace( '.', '-', (string) $base ), '-' );

	return array(
		'bytes'    => $bytes,
		'mime'     => $mime,
		'filename' => ( '' === $base ? 'image' : $base ) . '.' . $ext,
		'width'    => (int) $info[0],
		'height'   => (int) $info[1],
		'size'     => $size,
	);
}

/**
 * Upload an image into the media library, during a work session only.
 *
 * The agent role has no upload_files, and still does not: the capability
 * is granted for this one call while a session is open. Alt text is
 * required, because an upload without one is a job left for later — a
 * purely decorative image says so with decorative: true instead.
 *
 * @param array $args { filename, data, alt, decorative, title, dry_run }.
 * @return array|\WP_Error
 */
function wpmcp_media_upload( array $args ) {
	if ( ! function_exists( 'wpmcp_work_session_active' ) || ! wpmcp_work_session_active() ) {
		return new \WP_Error(
			'wpmcp_upload_needs_session',
			'Uploading is only possible while the site owner has a work session open under Tools > MCP Connector.'
		);
	}

	$decorative = ! empty( $args['decorative'] );
	$alt        = sanitize_text_field( (string) ( $args['alt'] ?? '' ) );

	if ( '' === $alt && ! $decorative ) {
		return new \WP_Error(
			'wpmcp_upload_needs_alt',
			'Give the image an "alt" describing what it shows. For a purely decorative image pass decorative: true, which stores an empty alt on purpose.'
		);
	}

	$file = wpmcp_inspect_upload( (string) ( $args['filename'] ?? '' ), $args['data'] ?? '' );
	if ( is_wp_error( $file ) ) {
		return $file;
	}

	$title   = sanitize_text_field( (string) ( $args['title'] ?? '' ) );
	$title   = '' !== $title ? $title : pathinfo( $file['filename'], PATHINFO_FILENAME );
	$dry_run = ! isset( $args['dry_run'] ) || (bool) $args['dry_run'];

	$summary = array(
		'filename' => $file['filename'],
		'mime'     => $file['mime'],
		'width'    => $file['width'],
		'height'   => $file['height'],
		'bytes'    => $file['size'],
		'alt'      => $alt,
		'title'    => $title,
	);

	if ( $dry_run ) {
		return array_merge(
			array(
				'ok'      => true,
				'dryRun'  => true,
				'message' => 'Dry run only — nothing was uploaded. Call again with dry_run: false.',
			),
			$summary
		);
	}

	require_once ABSPATH . 'wp-admin/includes/image.php';

	$release = wpmcp_grant_caps_for_request( array( 'upload_files' ) );

	try {
		$upload = wp_upload_bits( $file['filename'], null, $file['bytes'] );
		if ( ! empty( $upload['error'] ) ) {
			return new \WP_Error( 'wpmcp_upload_failed', 'WordPress could not store the file: ' . $upload['error'] );
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $file['mime'],
				'post_title'     => $title,
				'post_status'    => 'inherit',
				'post_author'    => get_current_user_id(),
			),
			$upload['file'],
			0,
			true
		);

		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $upload['file'] );
			return $attachment_id;
		}

		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
	} finally {
		$release();
	}

	wpmcp_log(
		'wpmcp/media-upload',
		array(
			'post_id' => (int) $attachment_id,
			'summary' => sprintf( 'Uploaded %s (%dx%d, %d KB) during a work session.', $file['filename'], $file['width'], $file['height'], (int) ( $file['size'] / 1024 ) ),
		)
	);

	return array_merge(
		array(
			'ok'      => true,
			'dryRun'  => false,
			'id'      => (int) $attachment_id,
			'url'     => wp_get_attachment_url( $attachment_id ),
			'message' => 'Uploaded. Use this id and url in the image block.',
		),
		$summary
	);
}
