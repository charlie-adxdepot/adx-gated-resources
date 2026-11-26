<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Single source of truth for the configurable gate-form fields (every
 * field except email, which is structurally required by the gate logic
 * itself and is therefore always shown and never editable here).
 *
 * Used by: the admin "Form Fields" builder, the front-end modal
 * renderer, and the AJAX handler that validates a submission -- so all
 * three always agree on what fields exist, their type, and whether
 * they're required.
 */
class ADX_GR_Form_Fields {

	const OPTION_NAME = 'adx_gr_form_fields';

	const ALLOWED_TYPES = array( 'text', 'textarea', 'select', 'checkbox' );

	/**
	 * Param names already used by the AJAX endpoints; a custom field
	 * can never take one of these keys.
	 */
	const RESERVED_KEYS = array( 'email', 'action', 'nonce', 'resource_id', 'page_id', 'page_url' );

	/**
	 * Returns the configured field list, seeding sensible defaults (and
	 * pulling in the pre-1.3 Role/State option lists if present) the
	 * first time this is ever called on a site.
	 */
	public static function get_fields() {
		$fields = get_option( self::OPTION_NAME, null );

		if ( is_array( $fields ) && ! empty( $fields ) ) {
			return $fields;
		}

		$fields = self::default_fields();
		update_option( self::OPTION_NAME, $fields );

		return $fields;
	}

	private static function default_fields() {
		$role_options  = self::legacy_options( 'adx_gr_role_options', array( 'Architect', 'Interior designer', 'BIM manager', 'Builder' ) );
		$state_options = self::legacy_options( 'adx_gr_state_options', array( 'NSW', 'QLD', 'SA', 'TAS', 'VIC', 'WA', 'Outside Australia' ) );

		return array(
			array(
				'key'      => 'first_name',
				'label'    => __( 'First Name', 'adx-gated-resources' ),
				'type'     => 'text',
				'required' => true,
				'options'  => array(),
			),
			array(
				'key'      => 'last_name',
				'label'    => __( 'Last Name', 'adx-gated-resources' ),
				'type'     => 'text',
				'required' => true,
				'options'  => array(),
			),
			array(
				'key'      => 'company',
				'label'    => __( 'Company', 'adx-gated-resources' ),
				'type'     => 'text',
				'required' => true,
				'options'  => array(),
			),
			array(
				'key'      => 'role',
				'label'    => __( 'Role / Discipline', 'adx-gated-resources' ),
				'type'     => 'select',
				'required' => true,
				'options'  => $role_options,
			),
			array(
				'key'      => 'state',
				'label'    => __( 'State', 'adx-gated-resources' ),
				'type'     => 'select',
				'required' => true,
				'options'  => $state_options,
			),
			array(
				'key'      => 'project_name',
				'label'    => __( 'Project Name', 'adx-gated-resources' ),
				'type'     => 'text',
				'required' => false,
				'options'  => array(),
			),
		);
	}

	private static function legacy_options( $option_name, array $default_lines ) {
		$raw = get_option( $option_name, '' );
		if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
			return $default_lines;
		}
		return array_values( array_filter( array_map( 'trim', explode( "\n", $raw ) ) ) );
	}

	/**
	 * Turns the Form Fields builder's raw $_POST arrays into a
	 * normalized, validated field list ready to store in the option.
	 * Keeps existing keys stable across edits (via a hidden per-row
	 * "field_key" input) so historical submissions stay attributed to
	 * the right label; only generates a new key for brand-new rows.
	 */
	public static function sanitize_fields_from_request( array $post ) {
		$labels    = isset( $post['adx_gr_field_label'] ) ? (array) wp_unslash( $post['adx_gr_field_label'] ) : array();
		$keys      = isset( $post['adx_gr_field_key'] ) ? (array) wp_unslash( $post['adx_gr_field_key'] ) : array();
		$types     = isset( $post['adx_gr_field_type'] ) ? (array) wp_unslash( $post['adx_gr_field_type'] ) : array();
		$required  = isset( $post['adx_gr_field_required'] ) ? (array) wp_unslash( $post['adx_gr_field_required'] ) : array();
		$options   = isset( $post['adx_gr_field_options'] ) ? (array) wp_unslash( $post['adx_gr_field_options'] ) : array();

		$existing_keys = array();
		$fields        = array();

		foreach ( $labels as $i => $raw_label ) {
			$label = sanitize_text_field( $raw_label );
			if ( '' === $label ) {
				continue;
			}

			$type = isset( $types[ $i ] ) && in_array( $types[ $i ], self::ALLOWED_TYPES, true ) ? $types[ $i ] : 'text';

			$key = isset( $keys[ $i ] ) ? sanitize_key( $keys[ $i ] ) : '';
			if ( '' === $key || in_array( $key, self::RESERVED_KEYS, true ) ) {
				$key = self::unique_key_from_label( $label, $existing_keys );
			} elseif ( in_array( $key, $existing_keys, true ) ) {
				$key = self::unique_key_from_label( $key, $existing_keys );
			}
			$existing_keys[] = $key;

			$field_options = array();
			if ( 'select' === $type && isset( $options[ $i ] ) ) {
				$field_options = array_values( array_filter( array_map( 'trim', explode( "\n", (string) $options[ $i ] ) ) ) );
			}

			$fields[] = array(
				'key'      => $key,
				'label'    => $label,
				'type'     => $type,
				'required' => ! empty( $required[ $i ] ),
				'options'  => $field_options,
			);
		}

		return $fields;
	}

	private static function unique_key_from_label( $label, array $existing_keys ) {
		$base = sanitize_key( $label );
		if ( '' === $base ) {
			$base = 'field';
		}
		if ( in_array( $base, self::RESERVED_KEYS, true ) ) {
			$base .= '_field';
		}

		$key = $base;
		$i   = 2;
		while ( in_array( $key, $existing_keys, true ) ) {
			$key = $base . '_' . $i;
			$i++;
		}

		return $key;
	}

	/**
	 * Reads and validates $_POST against the configured field list.
	 * Sends a JSON error (terminating the request, same as the rest of
	 * the AJAX handler) if a required field is missing; otherwise
	 * returns key => sanitized value for every configured field.
	 */
	public static function validate_and_collect_submission() {
		$values = array();

		foreach ( self::get_fields() as $field ) {
			$key   = $field['key'];
			$value = self::sanitize_submitted_value( $field, isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

			if ( $field['required'] && self::is_empty_value( $field, $value ) ) {
				wp_send_json_error( array( 'message' => __( 'Please complete all required fields.', 'adx-gated-resources' ) ) );
			}

			$values[ $key ] = $value;
		}

		return $values;
	}

	private static function sanitize_submitted_value( array $field, $raw ) {
		switch ( $field['type'] ) {
			case 'textarea':
				return sanitize_textarea_field( $raw );
			case 'checkbox':
				return $raw ? '1' : '0';
			case 'select':
				$raw = sanitize_text_field( $raw );
				return in_array( $raw, $field['options'], true ) ? $raw : '';
			case 'text':
			default:
				return sanitize_text_field( $raw );
		}
	}

	private static function is_empty_value( array $field, $value ) {
		if ( 'checkbox' === $field['type'] ) {
			return '1' !== $value;
		}
		return '' === trim( (string) $value );
	}
}
