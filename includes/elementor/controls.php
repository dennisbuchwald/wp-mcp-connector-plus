<?php
/**
 * Element types and settings, checked against what Elementor registered.
 *
 * Elementor itself does not refuse anything here: a setting nobody
 * registered is stored and ignored, a select value outside its options
 * renders as nothing, and an element whose type is not registered (the
 * plugin that provides it is off) is silently dropped by the save. So
 * the checks happen before saving, against the registered controls, and
 * only for what the change touched: a setting an older Elementor version
 * left in an untouched element is not the agent's to answer for.
 *
 * @package wp-mcp-connector-plus
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor's registered type for an element, or null.
 *
 * @param array $element Element.
 * @return object|null Widget or element type instance.
 */
function wpmcp_elementor_type_of( array $element ) {
	$plugin = \Elementor\Plugin::$instance;

	if ( 'widget' === ( $element['elType'] ?? '' ) ) {
		$type = $plugin->widgets_manager->get_widget_types( (string) ( $element['widgetType'] ?? '' ) );
	} else {
		$type = $plugin->elements_manager->get_element_types( (string) ( $element['elType'] ?? '' ) );
	}

	return is_object( $type ) ? $type : null;
}

/**
 * Elements of a list by id, all levels.
 *
 * @param array $elements Element list.
 * @return array<string, array>
 */
function wpmcp_elementor_by_id( array $elements ) {
	$out = array();
	foreach ( $elements as $element ) {
		if ( ! is_array( $element ) ) {
			continue;
		}
		$out[ (string) ( $element['id'] ?? '' ) ] = $element;
		if ( ! empty( $element['elements'] ) && is_array( $element['elements'] ) ) {
			$out += wpmcp_elementor_by_id( $element['elements'] );
		}
	}
	return $out;
}

/**
 * Check every element and every changed setting.
 *
 * @param array $before Elements as stored.
 * @param array $after  Elements after the change.
 * @return array{errors: string[], warnings: string[]}
 */
function wpmcp_elementor_check_elements( array $before, array $after ) {
	$errors     = array();
	$warnings   = array();
	$stored     = wpmcp_elementor_by_id( $before );
	$unknown    = array();
	$can_manage = current_user_can( 'manage_options' );

	foreach ( wpmcp_elementor_by_id( $after ) as $id => $element ) {
		$type = wpmcp_elementor_type_of( $element );
		$name = 'widget' === ( $element['elType'] ?? '' ) ? 'widget "' . ( $element['widgetType'] ?? '' ) . '"' : (string) ( $element['elType'] ?? '' );

		if ( null === $type ) {
			$unknown[] = sprintf( '%s (%s%s)', $id, $name, isset( $stored[ $id ] ) ? '' : ', new' );
			continue;
		}

		$settings = isset( $element['settings'] ) && is_array( $element['settings'] ) ? $element['settings'] : array();
		$previous = isset( $stored[ $id ]['settings'] ) && is_array( $stored[ $id ]['settings'] ) ? $stored[ $id ]['settings'] : array();

		$changed = array();
		foreach ( $settings as $key => $value ) {
			if ( ! array_key_exists( $key, $previous ) || $previous[ $key ] !== $value ) {
				$changed[ (string) $key ] = $value;
			}
		}
		if ( empty( $changed ) ) {
			continue;
		}

		$controls = $type->get_controls();
		foreach ( $changed as $key => $value ) {
			$problem = wpmcp_elementor_check_setting( $key, $value, $controls );
			if ( null !== $problem ) {
				$errors[] = sprintf( 'Element %s (%s): %s', $id, $name, $problem );
			}
		}

		// Elementor rewrites a heading's title for every account without
		// manage_options (modules/content-sanitizer): wp_kses with no
		// attributes at all and no images. Said before saving, not found
		// afterwards.
		if ( ! $can_manage && 'heading' === ( $element['widgetType'] ?? '' ) && isset( $changed['title'] ) && is_string( $changed['title'] ) && method_exists( $type, 'sanitize' ) ) {
			$stored_as = (string) $type->sanitize( $changed['title'] );
			if ( $stored_as !== $changed['title'] ) {
				$warnings[] = sprintf(
					'Element %s (heading): Elementor itself strips attributes and images from a heading title saved by an account that cannot manage the site, so the title will be stored as: %s',
					$id,
					wpmcp_shorten( $stored_as, 160 )
				);
			}
		}
	}

	if ( ! empty( $unknown ) ) {
		$errors[] = sprintf(
			'Elementor does not know these element types on this site: %s. Its save drops every element it cannot load, so saving would delete them. Activate the plugin that provides them (Elementor Pro, an addon) or use a type this site has.',
			implode( ', ', $unknown )
		);
	}

	return array(
		'errors'   => $errors,
		'warnings' => $warnings,
	);
}

/**
 * Is this value right for this setting? Null when it is.
 *
 * @param string $key      Setting name.
 * @param mixed  $value    Value.
 * @param array  $controls Registered controls of the element type.
 * @return string|null What is wrong.
 */
function wpmcp_elementor_check_setting( $key, $value, array $controls ) {
	if ( '__globals__' === $key ) {
		return is_array( $value ) ? null : '"__globals__" must be an object of setting => global reference.';
	}
	if ( '__dynamic__' === $key ) {
		// Refused with its reason by the guard.
		return null;
	}

	if ( ! isset( $controls[ $key ] ) ) {
		$known = array();
		foreach ( $controls as $name => $control ) {
			if ( '_' !== substr( (string) $name, 0, 1 ) && in_array( $control['type'] ?? '', array( 'text', 'textarea', 'code', 'wysiwyg', 'url', 'media', 'select', 'switcher', 'number', 'repeater', 'icons' ), true ) ) {
				$known[] = $name;
			}
		}
		return sprintf(
			'there is no setting "%s"%s.',
			$key,
			empty( $known ) ? '' : '; its content settings are: ' . implode( ', ', array_slice( $known, 0, 25 ) )
		);
	}

	$control = $controls[ $key ];
	$type    = (string) ( $control['type'] ?? '' );

	$strings = array( 'text', 'textarea', 'code', 'wysiwyg', 'hidden', 'color', 'font', 'date_time', 'select', 'choose', 'switcher', 'popover_toggle', 'animation', 'hover_animation', 'exit_animation' );
	$arrays  = array( 'url', 'media', 'icons', 'slider', 'dimensions', 'image_dimensions', 'box_shadow', 'text_shadow', 'gallery', 'repeater', 'gaps' );

	if ( in_array( $type, $strings, true ) && ! is_string( $value ) ) {
		return sprintf( '"%s" is a %s setting and takes text, not %s.', $key, $type, gettype( $value ) );
	}
	if ( in_array( $type, $arrays, true ) && ! is_array( $value ) ) {
		return sprintf( '"%s" is a %s setting and takes an object%s, not %s.', $key, $type, 'url' === $type ? ' like {"url":"https://...","is_external":"","nofollow":""}' : '', gettype( $value ) );
	}
	if ( 'number' === $type && ! ( is_numeric( $value ) || '' === $value ) ) {
		return sprintf( '"%s" takes a number.', $key );
	}
	if ( 'repeater' === $type ) {
		foreach ( $value as $i => $item ) {
			if ( ! is_array( $item ) ) {
				return sprintf( '"%s" is a list of items (objects); item %s is not one.', $key, $i );
			}
		}
	}
	if ( in_array( $type, array( 'select', 'choose' ), true ) && ! empty( $control['options'] ) && is_array( $control['options'] ) && '' !== $value ) {
		$options = array_map( 'strval', array_keys( $control['options'] ) );
		if ( ! in_array( (string) $value, $options, true ) ) {
			return sprintf( '"%s" must be one of: %s (got "%s").', $key, implode( ', ', $options ), $value );
		}
	}

	return null;
}
