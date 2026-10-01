<?php
/**
 * The shape of every answer: error codes in the message, a code on every
 * refusal, and phase timings under WP_DEBUG.
 *
 * @package wp-mcp-connector-plus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Are timings and memory added to write and preview responses?
 *
 * On with WP_DEBUG, so a slow write on a development site can be taken
 * apart without a profiler: which phase took the time, and how much
 * memory the request peaked at. Filter wpmcp_debug_timings to switch it
 * on elsewhere or off.
 *
 * @return bool
 */
function wpmcp_debug_timings_enabled() {
	return (bool) apply_filters( 'wpmcp_debug_timings', defined( 'WP_DEBUG' ) && WP_DEBUG );
}

/**
 * Start measuring, or null when measuring is off.
 *
 * @return array|null
 */
function wpmcp_debug_timer() {
	if ( ! wpmcp_debug_timings_enabled() ) {
		return null;
	}
	return array(
		'last'   => microtime( true ),
		'phases' => array(),
	);
}

/**
 * Close a phase: milliseconds since the previous mark.
 *
 * @param array|null $timer Timer (by reference).
 * @param string     $phase Name of the phase that just ended.
 */
function wpmcp_debug_mark( &$timer, $phase ) {
	if ( null === $timer ) {
		return;
	}
	$now                       = microtime( true );
	$timer['phases'][ $phase ] = round( ( $now - $timer['last'] ) * 1000, 1 );
	$timer['last']             = $now;
}

/**
 * Add the measurements to a response, when measuring is on.
 *
 * @param array      $response Response.
 * @param array|null $timer    Timer.
 * @return array
 */
function wpmcp_debug_attach( array $response, $timer ) {
	if ( null === $timer ) {
		return $response;
	}
	$response['debug'] = array(
		'timings'    => $timer['phases'],
		'peakMemory' => memory_get_peak_usage( true ),
	);
	return $response;
}

/**
 * A WP_Error as one line that still carries its code: "[code] message".
 *
 * The MCP adapter hands a WP_Error to the client as its message alone, so
 * the code, the one part an agent can branch on reliably, never arrived.
 * Prefixed exactly once: an error passed through two layers keeps one
 * prefix. Only the connector's own codes (wpmcp_*) are prefixed; a code
 * from WordPress or another plugin means nothing documented here.
 *
 * @param \WP_Error $error Error.
 * @return string
 */
function wpmcp_error_text( $error ) {
	$code    = (string) $error->get_error_code();
	$message = (string) $error->get_error_message();

	if ( 0 !== strpos( $code, 'wpmcp_' ) ) {
		return $message;
	}

	$prefix = '[' . $code . '] ';
	return 0 === strpos( $message, $prefix ) ? $message : $prefix . $message;
}

/**
 * Bring an ability's answer into the shape the contract promises.
 *
 * Called once, at the boundary every ability passes (see
 * wpmcp_register_ability), so no tool can forget it:
 *
 * - A WP_Error with a wpmcp_* code gets its message prefixed with the
 *   code, see wpmcp_error_text().
 * - An answer with "ok": false gets a top-level "code" if it has none,
 *   wpmcp_validation_failed: the request was understood and refused for
 *   the reasons in "errors". That way both kinds of failure carry a code,
 *   and an agent never has to tell them apart by reading prose.
 *
 * @param mixed $result Whatever the execute callback returned.
 * @return mixed
 */
function wpmcp_contract_result( $result ) {
	if ( is_wp_error( $result ) ) {
		$text = wpmcp_error_text( $result );
		if ( $text === $result->get_error_message() ) {
			return $result;
		}
		return new \WP_Error( $result->get_error_code(), $text, $result->get_error_data() );
	}

	if ( is_array( $result ) && array_key_exists( 'ok', $result ) && false === $result['ok'] && empty( $result['code'] ) ) {
		// Right after "ok", where a reader looks.
		$shaped = array();
		foreach ( $result as $key => $value ) {
			$shaped[ $key ] = $value;
			if ( 'ok' === $key ) {
				$shaped['code'] = 'wpmcp_validation_failed';
			}
		}
		return $shaped;
	}

	return $result;
}
