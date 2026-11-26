<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Thin wrapper around the ZeroBounce real-time email validation API.
 * https://www.zerobounce.net/docs/email-validation-api-quickstart/
 */
class ADX_GR_ZeroBounce {

	const API_ENDPOINT = 'https://api.zerobounce.net/v2/validate';

	/**
	 * Validate an email address against ZeroBounce.
	 *
	 * @param string $email Email to validate.
	 * @return array {
	 *     @type bool   $success    Whether the API call itself succeeded.
	 *     @type bool   $acceptable Whether the resulting status is treated as a pass.
	 *     @type string $status     Raw ZeroBounce status (valid, invalid, catch-all, etc.).
	 *     @type string $sub_status Raw ZeroBounce sub-status.
	 *     @type string $message    Human readable message on failure.
	 * }
	 */
	public static function validate( $email ) {
		$api_key = trim( (string) get_option( 'adx_gr_zerobounce_api_key', '' ) );

		$result = array(
			'success'    => false,
			'acceptable' => false,
			'status'     => '',
			'sub_status' => '',
			'message'    => '',
		);

		if ( empty( $api_key ) ) {
			$result['message'] = __( 'ZeroBounce API key is not configured.', 'adx-gated-resources' );
			return self::apply_fallback_policy( $result );
		}

		$url = add_query_arg(
			array(
				'api_key' => $api_key,
				'email'   => rawurlencode( $email ),
			),
			self::API_ENDPOINT
		);

		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 8,
			)
		);

		if ( is_wp_error( $response ) ) {
			$result['message'] = $response->get_error_message();
			return self::apply_fallback_policy( $result );
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== (int) $code || empty( $body ) || ! is_array( $body ) ) {
			$result['message'] = __( 'Unexpected response from ZeroBounce.', 'adx-gated-resources' );
			return self::apply_fallback_policy( $result );
		}

		if ( ! empty( $body['error'] ) ) {
			$result['message'] = sanitize_text_field( $body['error'] );
			return self::apply_fallback_policy( $result );
		}

		$status     = isset( $body['status'] ) ? sanitize_text_field( $body['status'] ) : '';
		$sub_status = isset( $body['sub_status'] ) ? sanitize_text_field( $body['sub_status'] ) : '';

		$result['success']    = true;
		$result['status']     = $status;
		$result['sub_status'] = $sub_status;
		$result['acceptable'] = self::is_status_acceptable( $status );

		return $result;
	}

	private static function is_status_acceptable( $status ) {
		$acceptable_statuses = get_option(
			'adx_gr_acceptable_statuses',
			array( 'valid', 'catch-all' )
		);

		if ( ! is_array( $acceptable_statuses ) ) {
			$acceptable_statuses = array( 'valid' );
		}

		return in_array( $status, $acceptable_statuses, true );
	}

	/**
	 * If the ZeroBounce call itself fails (bad key, network error, no
	 * credits), decide whether to let the lead through anyway so a
	 * broken integration never blocks Sales from getting the download
	 * request, or to block until it's fixed. Controlled by the
	 * "fail open" setting.
	 */
	private static function apply_fallback_policy( array $result ) {
		$fail_open = get_option( 'adx_gr_fail_open', '1' );

		$result['acceptable'] = ( '1' === $fail_open );

		return $result;
	}
}
