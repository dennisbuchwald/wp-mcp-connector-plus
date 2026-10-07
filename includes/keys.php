<?php
/**
 * Keys nobody asked for: refused with the ones that exist, never ignored.
 *
 * On staging.maxport.ch a core/shortcode was sent with "attributes"
 * instead of "attrs". The connector read "attrs", found nothing, and
 * saved an empty block after a dry run that said ok. Every structure an
 * agent sends (a tree node, an operation, an Elementor element, a batch
 * item, the arguments of a tool) used to drop what it did not know in the
 * same silence. These helpers turn that into an answer: which key, where,
 * what is accepted there, and the nearest accepted name.
 *
 * @package wp-mcp-connector-plus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The accepted name a key was most likely meant to be.
 *
 * First the known mix-ups ($hints), then the same name in another
 * spelling (postId, post-id, POST_ID), then a typo within a few letters.
 *
 * @param string   $key     Key as sent.
 * @param string[] $allowed Accepted keys.
 * @param array    $hints   Known mix-ups: sent => meant.
 * @return string Empty when nothing is close.
 */
function wpmcp_did_you_mean( $key, array $allowed, array $hints = array() ) {
	$key = (string) $key;
	if ( isset( $hints[ $key ] ) && in_array( $hints[ $key ], $allowed, true ) ) {
		return $hints[ $key ];
	}

	$norm = function ( $name ) {
		return strtolower( str_replace( array( '_', '-', ' ' ), '', (string) $name ) );
	};

	$wanted = $norm( $key );
	foreach ( $allowed as $name ) {
		if ( $norm( $name ) === $wanted ) {
			return $name;
		}
	}

	$best     = '';
	$distance = PHP_INT_MAX;
	$limit    = max( 1, min( 3, (int) floor( strlen( $wanted ) / 3 ) ) );
	foreach ( $allowed as $name ) {
		$d = levenshtein( $wanted, $norm( $name ) );
		if ( $d < $distance ) {
			$distance = $d;
			$best     = $name;
		}
	}

	return $distance <= $limit ? $best : '';
}

/**
 * The keys of a structure that are not accepted there.
 *
 * @param array    $given   Structure as sent.
 * @param string[] $allowed Accepted keys.
 * @return string[]
 */
function wpmcp_unknown_keys( array $given, array $allowed ) {
	$unknown = array();
	foreach ( array_keys( $given ) as $key ) {
		if ( ! in_array( (string) $key, $allowed, true ) ) {
			$unknown[] = (string) $key;
		}
	}
	return $unknown;
}

/**
 * The sentence that refuses them.
 *
 * @param string   $where   Where, e.g. "2.1" or "Operation 0 (insert)".
 * @param string[] $unknown Keys not accepted.
 * @param string[] $allowed Accepted keys, as listed to the agent.
 * @param array    $hints   Known mix-ups, see wpmcp_did_you_mean().
 * @param string   $noun    "key" or "argument".
 * @return string
 */
function wpmcp_unknown_keys_text( $where, array $unknown, array $allowed, array $hints = array(), $noun = 'key' ) {
	$parts = array();
	foreach ( $unknown as $key ) {
		$near    = wpmcp_did_you_mean( $key, $allowed, $hints );
		$parts[] = sprintf( 'unknown %s "%s"%s', $noun, $key, '' === $near ? '' : sprintf( ' (did you mean "%s"?)', $near ) );
	}

	return sprintf(
		'%s: %s. It would have been ignored, so nothing was done. %s',
		$where,
		implode( ', ', $parts ),
		empty( $allowed ) ? sprintf( 'This takes no %ss.', $noun ) : 'Accepted: ' . implode( ', ', $allowed ) . '.'
	);
}

/**
 * Take "attributes" as another name for "attrs".
 *
 * blocks-describe answers with a field called "attributes", so an agent
 * sends that name as naturally as "attrs". Both at once are accepted
 * when they say the same; when they differ there is no telling which
 * was meant.
 *
 * @param array  $entry Node or operation.
 * @param string $where For the message.
 * @return array{0: array, 1: string} The entry with "attrs" only, and an error or "".
 */
function wpmcp_attrs_alias( array $entry, $where ) {
	if ( ! array_key_exists( 'attributes', $entry ) ) {
		return array( $entry, '' );
	}

	$alias = $entry['attributes'];
	unset( $entry['attributes'] );

	if ( ! array_key_exists( 'attrs', $entry ) ) {
		$entry['attrs'] = $alias;
		return array( $entry, '' );
	}

	$same = wp_json_encode( wpmcp_plain_value( $entry['attrs'] ) ) === wp_json_encode( wpmcp_plain_value( $alias ) );
	if ( $same ) {
		return array( $entry, '' );
	}

	return array(
		$entry,
		sprintf( '%s: "attrs" and "attributes" are the same field under two names, and they differ here. Send one of them.', $where ),
	);
}

/**
 * Objects as arrays, all the way down, for comparing two decoded values.
 *
 * @param mixed $value Value.
 * @return mixed
 */
function wpmcp_plain_value( $value ) {
	if ( is_object( $value ) ) {
		$value = (array) $value;
	}
	if ( is_array( $value ) ) {
		foreach ( $value as $key => $item ) {
			$value[ $key ] = wpmcp_plain_value( $item );
		}
	}
	return $value;
}
