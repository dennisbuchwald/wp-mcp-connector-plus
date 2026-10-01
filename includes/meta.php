<?php
/**
 * Post meta: the fields an editorial review looks at, read and written
 * through allowlists.
 *
 * @package wp-mcp-connector-plus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Post meta worth showing an agent, by key.
 *
 * An allowlist rather than everything: post meta is where plugins keep
 * licence keys, tokens and internal state, and none of that belongs in a
 * model's context. These are the fields a person doing an editorial review
 * would look at.
 *
 * @return array<string, string> Meta key => readable label.
 */
function wpmcp_readable_meta_keys() {
	return apply_filters(
		'wpmcp_readable_meta_keys',
		array(
			// Rank Math.
			'rank_math_title'          => 'SEO title',
			'rank_math_description'    => 'Meta description',
			'rank_math_focus_keyword'  => 'Focus keyword',
			'rank_math_canonical_url'  => 'Canonical URL',
			'rank_math_robots'         => 'Robots',
			'rank_math_facebook_image' => 'Social image',
			'rank_math_schema_type'    => 'Schema type',
			// Yoast.
			'_yoast_wpseo_title'         => 'SEO title',
			'_yoast_wpseo_metadesc'      => 'Meta description',
			'_yoast_wpseo_focuskw'       => 'Focus keyword',
			'_yoast_wpseo_canonical'     => 'Canonical URL',
			'_yoast_wpseo_meta-robots-noindex' => 'Robots: noindex',
			'_yoast_wpseo_opengraph-image'     => 'Social image',
		)
	);
}

/**
 * Meta prefixes a post type's own plugin keeps its settings under.
 *
 * A GeneratePress element is an empty shell without its meta: where it
 * shows, what kind it is, under which conditions. Creating one through the
 * connector and leaving those unset produces an element that displays
 * nowhere. So for post types that are nothing but their settings, the
 * plugin's own prefix is readable and writable — on that post type only,
 * never on a page.
 *
 * @return array<string, string[]> Post type => meta key prefixes.
 */
function wpmcp_post_type_meta_prefixes() {
	return apply_filters(
		'wpmcp_post_type_meta_prefixes',
		array(
			'gp_elements' => array( '_generate_' ),
		)
	);
}

/**
 * Meta keys that are never writable, whatever prefix they match.
 *
 * Some plugins keep a switch in meta that makes the post's content run as
 * PHP. Reachable through a prefix, that would be code execution on the
 * server by way of a settings field.
 *
 * @param string $key Meta key.
 * @return bool
 */
function wpmcp_meta_key_forbidden( $key ) {
	return (bool) preg_match( '/(php|execute|eval|(^|_)code($|_))/i', (string) $key );
}

/**
 * May this plugin meta key be read and written on this post?
 *
 * @param \WP_Post $post Post.
 * @param string   $key  Meta key.
 * @return bool
 */
function wpmcp_plugin_meta_allowed( $post, $key ) {
	if ( wpmcp_meta_key_forbidden( $key ) ) {
		return false;
	}

	$type     = isset( $post->post_type ) ? (string) $post->post_type : '';
	$prefixes = wpmcp_post_type_meta_prefixes()[ $type ] ?? array();

	foreach ( $prefixes as $prefix ) {
		if ( '' !== $prefix && 0 === strpos( (string) $key, $prefix ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Does this post run its own content as code?
 *
 * A GeneratePress hook element with "Execute PHP" switched on evaluates
 * its content on every page view. The script guard looks for markup that
 * runs in a browser; a line of PHP is plain text to it. Writing that
 * content would be writing code onto the server, so such a post is not
 * writable through the connector at all.
 *
 * @param \WP_Post $post Post.
 * @return bool
 */
function wpmcp_runs_code( $post ) {
	$flag = get_post_meta( $post->ID, '_generate_hook_execute_php', true );

	return ! empty( $flag ) && 'false' !== $flag;
}

/**
 * Clean a structured plugin meta value, keeping its shape.
 *
 * @param mixed $value Value as sent.
 * @return mixed
 */
function wpmcp_clean_plugin_meta( $value ) {
	if ( is_array( $value ) ) {
		$clean = array();
		foreach ( $value as $k => $v ) {
			$clean[ is_int( $k ) ? $k : sanitize_text_field( (string) $k ) ] = wpmcp_clean_plugin_meta( $v );
		}
		return $clean;
	}

	if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
		return $value;
	}

	return sanitize_text_field( (string) $value );
}

/**
 * Plugin meta on a post, as far as its post type exposes any.
 *
 * Reading comes first for a reason: the keys and value shapes differ per
 * plugin and version, and guessing them produces an element that saves
 * cleanly and displays nowhere. An existing element is the reference.
 *
 * @param \WP_Post $post Post.
 * @return array<string, mixed>
 */
function wpmcp_read_plugin_meta( $post ) {
	$out = array();

	foreach ( (array) get_post_meta( $post->ID ) as $key => $values ) {
		if ( ! wpmcp_plugin_meta_allowed( $post, $key ) ) {
			continue;
		}
		$raw         = is_array( $values ) ? ( $values[0] ?? '' ) : $values;
		$out[ $key ] = function_exists( 'maybe_unserialize' ) ? maybe_unserialize( $raw ) : $raw;
	}

	ksort( $out );

	return $out;
}

/**
 * Meta fields the agent may change.
 *
 * A whitelist, not an open door to post meta. Everything here belongs to
 * an SEO plugin and is a plain string that a human would otherwise retype
 * in a settings screen. Anything a block, a page builder or a licence
 * check keeps in meta stays out of reach.
 *
 * @return array<string, string> Key => how the value is cleaned.
 */
function wpmcp_writable_meta_keys() {
	return apply_filters(
		'wpmcp_writable_meta_keys',
		array(
			'rank_math_title'         => 'text',
			'rank_math_description'   => 'text',
			'rank_math_focus_keyword' => 'text',
			'rank_math_canonical_url' => 'url',
			'_yoast_wpseo_title'      => 'text',
			'_yoast_wpseo_metadesc'   => 'text',
			'_yoast_wpseo_focuskw'    => 'text',
			'_yoast_wpseo_canonical'  => 'url',
		)
	);
}

/**
 * Work out what a meta change would do, before it does it.
 *
 * Meta is the one thing a write cannot take back: WordPress revisions
 * cover post content, not post meta, so the usual one-click rollback does
 * not apply here. The previous value is therefore reported in the dry run
 * and recorded in the log, which is what makes the change reversible at
 * all.
 *
 * @param \WP_Post $post Post.
 * @param array    $meta Requested key => value.
 * @return array { fields: array, errors: string[], changes: int }
 */
function wpmcp_meta_diff( $post, array $meta ) {
	$allowed = wpmcp_writable_meta_keys();
	$labels  = wpmcp_readable_meta_keys();

	$fields  = array();
	$errors  = array();
	$changes = 0;

	foreach ( $meta as $key => $value ) {
		// A post type's own plugin settings, on that post type only.
		if ( ! isset( $allowed[ $key ] ) && wpmcp_plugin_meta_allowed( $post, $key ) ) {
			$from = get_post_meta( $post->ID, $key, true );
			$to   = ( null === $value ) ? '' : wpmcp_clean_plugin_meta( $value );

			$fields[ $key ] = array(
				'label'   => $key,
				'from'    => $from,
				'to'      => $to,
				'changed' => ( wp_json_encode( $from ) !== wp_json_encode( $to ) ),
			);

			if ( $fields[ $key ]['changed'] ) {
				++$changes;
			}
			continue;
		}

		if ( ! isset( $allowed[ $key ] ) ) {
			$type     = isset( $post->post_type ) ? (string) $post->post_type : '';
			$prefixes = wpmcp_post_type_meta_prefixes()[ $type ] ?? array();

			$errors[] = wpmcp_meta_key_forbidden( $key )
				? sprintf( '"%s" is never written through the connector: a key like this can make a post run its content as code.', (string) $key )
				: sprintf(
					'"%s" is not a meta field this connector writes. Allowed: %s%s.',
					(string) $key,
					implode( ', ', array_keys( $allowed ) ),
					empty( $prefixes ) ? '' : ', and on this post type keys starting with ' . implode( ', ', $prefixes )
				);
			continue;
		}

		if ( null !== $value && ! is_scalar( $value ) ) {
			$errors[] = sprintf( '"%s" must be a string, or null to clear it.', (string) $key );
			continue;
		}

		$from = (string) get_post_meta( $post->ID, $key, true );
		$to   = ( null === $value ) ? '' : wpmcp_clean_meta_value( (string) $value, $allowed[ $key ] );

		if ( 'url' === $allowed[ $key ] && '' !== $to && ! wp_http_validate_url( $to ) ) {
			$errors[] = sprintf( '"%s" is not a usable URL: %s', (string) $key, (string) $value );
			continue;
		}

		$fields[ $key ] = array(
			'label'   => $labels[ $key ] ?? $key,
			'from'    => $from,
			'to'      => $to,
			'changed' => ( $from !== $to ),
		);

		if ( $from !== $to ) {
			++$changes;
		}
	}

	return array(
		'fields'  => $fields,
		'errors'  => $errors,
		'changes' => $changes,
	);
}

/**
 * A log line that carries the old value, because nothing else will.
 *
 * The revision system does not cover meta. If this line does not say what
 * the field used to hold, nobody can put it back.
 *
 * @param array $fields Result of wpmcp_meta_diff()['fields'].
 * @return string
 */
function wpmcp_meta_log_line( array $fields ) {
	$parts = array();

	foreach ( $fields as $key => $field ) {
		if ( empty( $field['changed'] ) ) {
			continue;
		}
		$parts[] = sprintf(
			'%s: "%s" -> "%s"',
			$key,
			wpmcp_shorten( is_scalar( $field['from'] ) ? (string) $field['from'] : wp_json_encode( $field['from'] ), 80 ),
			wpmcp_shorten( is_scalar( $field['to'] ) ? (string) $field['to'] : wp_json_encode( $field['to'] ), 80 )
		);
	}

	return 'Meta ' . implode( '; ', $parts );
}

/**
 * Clean a meta value for its kind.
 *
 * @param string $value Raw value.
 * @param string $kind  'text' or 'url'.
 * @return string
 */
function wpmcp_clean_meta_value( $value, $kind ) {
	return 'url' === $kind ? esc_url_raw( trim( $value ) ) : sanitize_text_field( $value );
}

/**
 * Write the meta fields a diff decided on.
 *
 * @param \WP_Post $post   Post.
 * @param array    $fields Result of wpmcp_meta_diff()['fields'].
 * @return string[] Keys actually written.
 */
function wpmcp_apply_meta( $post, array $fields ) {
	$written = array();

	foreach ( $fields as $key => $field ) {
		if ( empty( $field['changed'] ) ) {
			continue;
		}
		if ( '' === $field['to'] ) {
			delete_post_meta( $post->ID, $key );
		} else {
			// update_post_meta() unslashes what it is given, as every
			// WordPress write function does. Unslashed, a backslash in a
			// title or a display rule was silently gone.
			update_post_meta( $post->ID, $key, wp_slash( $field['to'] ) );
		}
		$written[] = $key;
	}

	return $written;
}

/**
 * The allowlisted meta of a post, plus the fields WordPress itself keeps.
 *
 * Reading only. Writing goes through wpmcp_meta_diff() and
 * wpmcp_apply_meta(), with its own allowlist.
 *
 * @param \WP_Post $post Post.
 * @return array
 */
function wpmcp_read_meta( $post ) {
	$fields = array();

	foreach ( wpmcp_readable_meta_keys() as $key => $label ) {
		$value = get_post_meta( $post->ID, $key, true );
		if ( '' === $value || null === $value || array() === $value ) {
			continue;
		}
		$fields[ $key ] = array(
			'label' => $label,
			'value' => is_scalar( $value ) ? $value : wp_json_encode( $value ),
		);
	}

	$thumbnail = get_post_thumbnail_id( $post->ID );

	$result = array(
		'seo'           => $fields,
		'featuredImage' => $thumbnail ? (int) $thumbnail : null,
		'excerpt'       => $post->post_excerpt,
		'template'      => get_page_template_slug( $post->ID ),
	);

	$plugin = wpmcp_read_plugin_meta( $post );
	if ( ! empty( $plugin ) ) {
		$result['plugin'] = $plugin;
	}

	return $result;
}
