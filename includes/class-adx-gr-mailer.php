<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends the plugin's notification emails: PHP mail() first (via
 * wp_mail() as normal), and only if that fails, the same message again
 * through the SMTP server configured under Settings > SMTP fallback.
 *
 * The SMTP settings are applied to that one retry only, so they never
 * change how the rest of the site sends email.
 */
class ADX_GR_Mailer {

	/**
	 * @param bool $smtp_only Skip PHP mail() and go straight to SMTP
	 *                        (used by the "Test SMTP" button).
	 * @return bool Whether either method accepted the message.
	 */
	public static function send( $to, $subject, $message, array $headers = array(), $smtp_only = false ) {
		$errors = array();

		$capture_error = function ( $error ) use ( &$errors ) {
			$errors[] = $error->get_error_message();
		};
		add_action( 'wp_mail_failed', $capture_error );

		$sent   = false;
		$method = '';

		if ( ! $smtp_only ) {
			$sent   = wp_mail( $to, $subject, $message, $headers );
			$method = 'php';
		}

		if ( ! $sent ) {
			if ( self::smtp_configured() ) {
				$attempted = count( $errors );

				add_action( 'phpmailer_init', array( __CLASS__, 'configure_smtp' ), PHP_INT_MAX );
				$sent = wp_mail( $to, $subject, $message, $headers );
				remove_action( 'phpmailer_init', array( __CLASS__, 'configure_smtp' ), PHP_INT_MAX );

				$method = 'smtp';

				// Label each error by the method that produced it.
				foreach ( $errors as $i => $error ) {
					$errors[ $i ] = ( $i < $attempted ? 'PHP mail(): ' : 'SMTP: ' ) . $error;
				}
			} elseif ( $smtp_only ) {
				$errors[] = __( 'SMTP fallback is not configured.', 'adx-gated-resources' );
			}
		}

		remove_action( 'wp_mail_failed', $capture_error );

		if ( $sent ) {
			update_option( 'adx_gr_last_mail_sent', array( 'time' => time(), 'method' => $method ), false );
			delete_option( 'adx_gr_last_mail_error' );
		} else {
			update_option( 'adx_gr_last_mail_error', array( 'time' => time(), 'message' => implode( ' | ', $errors ) ), false );
		}

		return $sent;
	}

	public static function smtp_configured() {
		return '' !== trim( (string) get_option( 'adx_gr_smtp_host', '' ) );
	}

	/**
	 * Switches the PHPMailer instance wp_mail() is about to use over to
	 * SMTP. wp_mail() resets it to mail() on every call, so this only
	 * affects the retry it's hooked around.
	 */
	public static function configure_smtp( $phpmailer ) {
		$encryption = get_option( 'adx_gr_smtp_encryption', 'tls' );
		$username   = (string) get_option( 'adx_gr_smtp_username', '' );

		$phpmailer->isSMTP();
		$phpmailer->Host     = trim( (string) get_option( 'adx_gr_smtp_host', '' ) );
		$phpmailer->Port     = absint( get_option( 'adx_gr_smtp_port', 587 ) );
		$phpmailer->Timeout  = 15;
		$phpmailer->SMTPAuth = '' !== $username;

		if ( $phpmailer->SMTPAuth ) {
			$phpmailer->Username = $username;
			$phpmailer->Password = (string) get_option( 'adx_gr_smtp_password', '' );
		}

		if ( in_array( $encryption, array( 'tls', 'ssl' ), true ) ) {
			$phpmailer->SMTPSecure = $encryption;
		} else {
			$phpmailer->SMTPSecure  = '';
			$phpmailer->SMTPAutoTLS = false;
		}

		$from_email = get_option( 'adx_gr_smtp_from_email', '' );
		if ( $from_email && is_email( $from_email ) ) {
			$from_name = get_option( 'adx_gr_smtp_from_name', '' );
			$phpmailer->setFrom( $from_email, $from_name ? $from_name : get_bloginfo( 'name' ), false );
		}
	}
}
