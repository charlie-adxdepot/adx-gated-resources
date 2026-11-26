<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Front-end AJAX endpoints: request a download (email gate) and the
 * actual (token-based) file redirect.
 */
class ADX_GR_Ajax {

	const COOKIE_NAME     = 'adx_gr_verified';
	const URL_EMAIL_PARAM = 'adx_gr_email';
	const URL_KEY_PARAM   = 'adx_gr_key';

	private static $instance = null;

	/**
	 * Lead captured at gate time and carried in the download token,
	 * written to the log only once the file is actually sent.
	 */
	private $pending_lead = null;

	/**
	 * Expiry (timestamp) of the identity verified or issued in this
	 * request, so a re-issued link never outlives the original cookie.
	 */
	private static $verified_expires = 0;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'wp_ajax_adx_gr_request_download', array( $this, 'handle_request_download' ) );
		add_action( 'wp_ajax_nopriv_adx_gr_request_download', array( $this, 'handle_request_download' ) );

		add_action( 'wp_ajax_adx_gr_download', array( $this, 'handle_download' ) );
		add_action( 'wp_ajax_nopriv_adx_gr_download', array( $this, 'handle_download' ) );

		add_action( 'wp_ajax_adx_gr_verify_page', array( $this, 'handle_verify_page' ) );
		add_action( 'wp_ajax_nopriv_adx_gr_verify_page', array( $this, 'handle_verify_page' ) );

		add_action( 'wp_ajax_adx_gr_library_access', array( $this, 'handle_library_access' ) );
		add_action( 'wp_ajax_nopriv_adx_gr_library_access', array( $this, 'handle_library_access' ) );
	}

	/**
	 * Step 1: browser asks to download a gated resource (file or bundle).
	 * If a valid "remember me" cookie is present we skip straight to
	 * issuing download token(s); otherwise the email + ZeroBounce must
	 * be supplied.
	 */
	public function handle_request_download() {
		check_ajax_referer( 'adx_gr_frontend', 'nonce' );

		$resource_id = isset( $_POST['resource_id'] ) ? absint( $_POST['resource_id'] ) : 0;

		if ( ! $resource_id || ADX_GR_CPT::POST_TYPE !== get_post_type( $resource_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown resource.', 'adx-gated-resources' ) ) );
		}

		$gate_type = ADX_GR_CPT::get_gate_type( $resource_id );

		if ( 'bundle' === $gate_type ) {
			if ( empty( ADX_GR_CPT::get_bundle_files( $resource_id ) ) ) {
				wp_send_json_error( array( 'message' => __( 'This bundle has no files configured yet.', 'adx-gated-resources' ) ) );
			}
		} elseif ( empty( ADX_GR_CPT::get_resource_file_url( $resource_id ) ) ) {
			wp_send_json_error( array( 'message' => __( 'This resource has no file configured yet.', 'adx-gated-resources' ) ) );
		}

		$remembered_email = self::get_verified_email();

		if ( $remembered_email ) {
			$token = $this->mint_token( $resource_id, $gate_type, $this->build_lead( $remembered_email, $resource_id, array(), array(
				'zerobounce_status'     => 'cookie',
				'zerobounce_sub_status' => '',
				'via_cookie'             => 1,
			) ) );

			wp_send_json_success( $this->build_download_response( $gate_type, $token ) );
		}

		if ( $this->is_rate_limited() ) {
			wp_send_json_error( array( 'message' => __( 'Too many requests. Please try again in a few minutes.', 'adx-gated-resources' ) ) );
		}

		$email  = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$fields = $this->collect_and_validate_fields( $email );

		$this->bump_rate_limit();

		$zb_result = ADX_GR_ZeroBounce::validate( $email );

		if ( ! $zb_result['acceptable'] ) {
			wp_send_json_error(
				array(
					'message' => __( "We couldn't verify that email address. Please check it and try again, or use a different address.", 'adx-gated-resources' ),
				)
			);
		}

		$this->set_email_cookie( $email );

		$token = $this->mint_token(
			$resource_id,
			$gate_type,
			$this->build_lead(
				$email,
				$resource_id,
				$fields,
				array(
					'zerobounce_status'     => $zb_result['status'],
					'zerobounce_sub_status' => $zb_result['sub_status'],
					'via_cookie'             => 0,
				)
			)
		);

		wp_send_json_success( $this->build_download_response( $gate_type, $token ) );
	}

	/**
	 * Step 1 (page-gate variant): browser asks to unlock a whole gated
	 * page. Same email/ZeroBounce/cookie pipeline as
	 * handle_request_download(), but nothing is downloaded. Acts as the
	 * visitor's "login": their details are saved as a subscriber (not a
	 * download -- only actual file downloads go in the download log),
	 * and success sends the browser to the page with their identity in
	 * the URL (adx_gr_email + adx_gr_key), so every download clicked
	 * from there is attributed to them even if cookies don't stick.
	 */
	public function handle_verify_page() {
		check_ajax_referer( 'adx_gr_frontend', 'nonce' );

		$page_id = isset( $_POST['page_id'] ) ? absint( $_POST['page_id'] ) : 0;

		if ( ! $page_id || ! ADX_GR_Page_Gate::is_gated( $page_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown page.', 'adx-gated-resources' ) ) );
		}

		$remembered_email = self::get_verified_email();

		if ( $remembered_email ) {
			// A remembered visitor only lands here if they were shown a
			// cached lock screen, e.g. their cookie predates the cache
			// bypass cookie -- (re)issue it so the reload gets through.
			$this->set_cache_bypass_cookie();

			wp_send_json_success( $this->build_page_response( $page_id, $remembered_email ) );
		}

		if ( $this->is_rate_limited() ) {
			wp_send_json_error( array( 'message' => __( 'Too many requests. Please try again in a few minutes.', 'adx-gated-resources' ) ) );
		}

		$email  = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$fields = $this->collect_and_validate_fields( $email );

		$this->bump_rate_limit();

		$zb_result = ADX_GR_ZeroBounce::validate( $email );

		if ( ! $zb_result['acceptable'] ) {
			wp_send_json_error(
				array(
					'message' => __( "We couldn't verify that email address. Please check it and try again, or use a different address.", 'adx-gated-resources' ),
				)
			);
		}

		// Checked before saving, so it reflects whether they were already a subscriber.
		$is_new_subscriber = ! ADX_GR_DB::subscriber_exists( $email );

		ADX_GR_DB::upsert_subscriber( $email, $fields, $zb_result['status'], $zb_result['sub_status'], false );

		$this->set_email_cookie( $email );

		// Only a brand-new subscriber notifies. Someone already in the
		// list (e.g. back after their cookie expired) was reported when
		// they first signed up, and their downloads still show in the
		// daily report.
		if ( $is_new_subscriber ) {
			$referrer = isset( $_POST['referrer'] ) ? esc_url_raw( wp_unslash( $_POST['referrer'] ) ) : '';
			self::notify_page_unlock( $email, $fields, $page_id, $referrer );
		}

		wp_send_json_success( $this->build_page_response( $page_id, $email ) );
	}

	/**
	 * Step 1 (library-button variant): a visitor clicked the
	 * [adx_library_access] button on a product page. Same
	 * email/ZeroBounce/cookie pipeline as handle_verify_page(), then
	 * notifies Sales which product the visitor came from and sends the
	 * browser on to the library page (Settings > Library page) with
	 * their identity in the URL, so the library unlocks for them.
	 */
	public function handle_library_access() {
		check_ajax_referer( 'adx_gr_frontend', 'nonce' );

		$source_id  = isset( $_POST['source_id'] ) ? absint( $_POST['source_id'] ) : 0;
		$library_id = self::get_library_page_id();

		if ( ! $source_id || 'publish' !== get_post_status( $source_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown page.', 'adx-gated-resources' ) ) );
		}

		if ( ! $library_id ) {
			wp_send_json_error( array( 'message' => __( 'The library is not available right now.', 'adx-gated-resources' ) ) );
		}

		$product  = $this->get_posted_product( $source_id );
		$page_url = isset( $_POST['page_url'] ) ? esc_url_raw( wp_unslash( $_POST['page_url'] ) ) : '';

		$remembered_email = self::get_verified_email();

		if ( $remembered_email ) {
			// A remembered visitor is already a subscriber, so no email.
			$this->set_cache_bypass_cookie();

			wp_send_json_success( $this->build_page_response( $library_id, $remembered_email ) );
		}

		if ( $this->is_rate_limited() ) {
			wp_send_json_error( array( 'message' => __( 'Too many requests. Please try again in a few minutes.', 'adx-gated-resources' ) ) );
		}

		$email  = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$fields = $this->collect_and_validate_fields( $email );

		$this->bump_rate_limit();

		$zb_result = ADX_GR_ZeroBounce::validate( $email );

		if ( ! $zb_result['acceptable'] ) {
			wp_send_json_error(
				array(
					'message' => __( "We couldn't verify that email address. Please check it and try again, or use a different address.", 'adx-gated-resources' ),
				)
			);
		}

		$is_new_subscriber = ! ADX_GR_DB::subscriber_exists( $email );

		ADX_GR_DB::upsert_subscriber( $email, $fields, $zb_result['status'], $zb_result['sub_status'], false );

		$this->set_email_cookie( $email );

		if ( $is_new_subscriber ) {
			self::notify_library_access( $email, $fields, $product, $source_id, $page_url );
		}

		wp_send_json_success( $this->build_page_response( $library_id, $email ) );
	}

	public static function get_library_page_id() {
		$page_id = absint( get_option( 'adx_gr_library_page_id', 0 ) );
		return $page_id && 'publish' === get_post_status( $page_id ) ? $page_id : 0;
	}

	/**
	 * The product name an [adx_library_access] button was given via its
	 * "product" attribute, if it carries a valid signature for this
	 * page -- otherwise the page's own title.
	 */
	private function get_posted_product( $source_id ) {
		$product = isset( $_POST['product'] ) ? sanitize_text_field( wp_unslash( $_POST['product'] ) ) : '';
		$key     = isset( $_POST['product_key'] ) ? sanitize_text_field( wp_unslash( $_POST['product_key'] ) ) : '';

		if ( '' !== $product && hash_equals( self::sign_product( $source_id, $product ), $key ) ) {
			return $product;
		}

		return self::plain_title( $source_id );
	}

	/**
	 * A post title as plain text for the email (get_the_title() returns
	 * HTML entities such as &#8211;).
	 */
	private static function plain_title( $post_id ) {
		return html_entity_decode( wp_strip_all_tags( get_the_title( $post_id ) ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Signs a button's product name to its page, so the name that ends
	 * up in the notification email can't be swapped by the visitor.
	 */
	public static function sign_product( $source_id, $product ) {
		return hash_hmac( 'sha256', absint( $source_id ) . '|' . $product, wp_salt( 'adx_gr_product' ) );
	}

	private function build_page_response( $page_id, $email ) {
		return array(
			'gate_type' => 'page',
			'redirect'  => self::add_identity_to_url( get_permalink( $page_id ), $email ),
		);
	}

	/**
	 * Appends the signed identity params to a URL. The key is
	 * "{expiry}.{HMAC of email + expiry}", so the email can't be swapped
	 * for someone else's and the identity lapses together with the
	 * visitor's cookie -- after that they get the form again.
	 */
	public static function add_identity_to_url( $url, $email ) {
		$expires = self::$verified_expires ? self::$verified_expires : self::new_expiry();

		return add_query_arg(
			array(
				self::URL_EMAIL_PARAM => rawurlencode( $email ),
				self::URL_KEY_PARAM   => $expires . '.' . self::sign_identity( $email, $expires ),
			),
			$url
		);
	}

	/**
	 * Reads and validates the admin-configured gate-form fields (see
	 * ADX_GR_Form_Fields), used by both the download gate and the page
	 * gate. Sends a JSON error (and terminates) if the email or any
	 * required field is missing.
	 */
	private function collect_and_validate_fields( $email ) {
		if ( empty( $email ) || ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid email address.', 'adx-gated-resources' ) ) );
		}

		return ADX_GR_Form_Fields::validate_and_collect_submission();
	}

	/**
	 * Step 2: fulfils the actual download once a short-lived token
	 * (issued only after a successful gate check) is presented. A
	 * single-file resource is streamed through PHP as an attachment, so
	 * the visitor never sees (or can share) the file's real URL; a
	 * bundle is zipped on the fly so "Download all" is one instant
	 * download rather than a list of links to click one by one.
	 */
	public function handle_download() {
		$token   = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$payload = $token ? get_transient( 'adx_gr_dl_' . $token ) : false;

		if ( ! $token || false === $payload || ! is_array( $payload ) || ! isset( $payload['resource_id'] ) ) {
			wp_die( esc_html__( 'This download link has expired. Please go back and request the file again.', 'adx-gated-resources' ), 403 );
		}

		delete_transient( 'adx_gr_dl_' . $token );

		$resource_id        = absint( $payload['resource_id'] );
		$this->pending_lead = isset( $payload['lead'] ) && is_array( $payload['lead'] ) ? $payload['lead'] : null;

		if ( 'bundle' === $payload['gate_type'] ) {
			$this->stream_bundle_zip( $resource_id );
			return;
		}

		$file_url = ADX_GR_CPT::get_resource_file_url( $resource_id );

		if ( empty( $file_url ) ) {
			wp_die( esc_html__( 'File not found.', 'adx-gated-resources' ), 404 );
		}

		$this->stream_single_file(
			$file_url,
			absint( get_post_meta( $resource_id, '_adx_gr_attachment_id', true ) )
		);
	}

	/**
	 * Sends one file to the browser as a download (Content-Disposition:
	 * attachment) instead of redirecting to it. Files on this server
	 * are read straight off disk; anything hosted elsewhere is fetched
	 * to a temp file first and then served the same way.
	 */
	private function stream_single_file( $file_url, $attachment_id = 0 ) {
		$path      = $this->resolve_local_path( $file_url, $attachment_id );
		$temp_path = '';

		if ( ! $path ) {
			$temp_path = wp_tempnam( 'adx-gr-file' );
			$response  = wp_safe_remote_get(
				$file_url,
				array(
					'timeout'  => 60,
					'stream'   => true,
					'filename' => $temp_path,
				)
			);

			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				wp_delete_file( $temp_path );
				wp_die( esc_html__( 'The file could not be retrieved. Please try again later.', 'adx-gated-resources' ), 502 );
			}

			$path = $temp_path;
		}

		$this->send_file( $path, $this->download_filename( $file_url ) );

		if ( $temp_path ) {
			wp_delete_file( $temp_path );
		}
		exit;
	}

	/**
	 * Maps a gated file to its path on this server, if it lives here:
	 * via its Media Library attachment (only when the attachment still
	 * matches the configured URL -- an editor may have pasted a new URL
	 * after picking from the library), or via the URL sitting under
	 * wp-content. Returns false for files hosted elsewhere. Also used by
	 * the library page to read file sizes.
	 */
	public static function resolve_local_path( $file_url, $attachment_id = 0 ) {
		if ( $attachment_id && wp_get_attachment_url( $attachment_id ) === $file_url ) {
			$path = get_attached_file( $attachment_id );
			if ( $path && is_readable( $path ) ) {
				return $path;
			}
		}

		// Compare scheme-less so http/https mismatches still resolve.
		$strip_scheme = function ( $url ) {
			return preg_replace( '#^https?:#i', '', $url );
		};
		$content_url  = trailingslashit( $strip_scheme( content_url() ) );
		$file_no_sch  = $strip_scheme( strtok( $file_url, '?#' ) );

		if ( 0 !== strpos( $file_no_sch, $content_url ) ) {
			return false;
		}

		$relative = rawurldecode( substr( $file_no_sch, strlen( $content_url ) ) );
		$path     = realpath( WP_CONTENT_DIR . '/' . $relative );
		$root     = realpath( WP_CONTENT_DIR );

		// realpath() collapses any "../" so this also guards against
		// a URL that tries to climb out of wp-content.
		if ( ! $path || ! $root || 0 !== strpos( $path, $root . DIRECTORY_SEPARATOR ) || ! is_file( $path ) || ! is_readable( $path ) ) {
			return false;
		}

		return $path;
	}

	private function download_filename( $file_url ) {
		$url_path = wp_parse_url( $file_url, PHP_URL_PATH );
		$name     = $url_path ? sanitize_file_name( rawurldecode( wp_basename( $url_path ) ) ) : '';
		return $name ? $name : 'download';
	}

	/**
	 * Writes the download headers and the file body. Any buffered
	 * output (stray whitespace from a theme/plugin, etc.) is discarded
	 * first so it can't corrupt the file.
	 */
	private function send_file( $path, $download_name, $content_type = '' ) {
		if ( ! $content_type ) {
			$filetype     = wp_check_filetype( $download_name );
			$content_type = $filetype['type'] ? $filetype['type'] : 'application/octet-stream';
		}

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit
		}

		// The file is ready to go out -- this is the point a download
		// counts, so log it now (once per token).
		$this->log_pending_lead();

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: ' . $content_type );
		header( 'Content-Disposition: attachment; filename="' . $download_name . '"; filename*=UTF-8\'\'' . rawurlencode( $download_name ) );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'X-Content-Type-Options: nosniff' );

		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile
	}

	/**
	 * Builds a zip of every file in the bundle and streams it as one
	 * download. Local Media Library files are read straight off disk;
	 * anything hosted elsewhere is fetched over HTTP. A file that can't
	 * be retrieved is skipped rather than failing the whole bundle.
	 */
	private function stream_bundle_zip( $resource_id ) {
		$files = ADX_GR_CPT::get_bundle_files( $resource_id );

		if ( empty( $files ) ) {
			wp_die( esc_html__( 'This bundle has no files configured.', 'adx-gated-resources' ), 404 );
		}

		// One file that is already a .zip: send it as it is, rather than
		// zipping a zip (slow, and a very large one could run out of memory).
		if ( 1 === count( $files ) && 'ZIP' === ADX_GR_CPT::get_file_extension_label( $files[0]['url'] ) ) {
			$this->stream_single_file( $files[0]['url'], ! empty( $files[0]['attachment_id'] ) ? (int) $files[0]['attachment_id'] : 0 );
		}

		if ( ! class_exists( 'ZipArchive' ) ) {
			// No zip support on this host -- fall back to the first file
			// rather than failing outright.
			$this->stream_single_file( $files[0]['url'], ! empty( $files[0]['attachment_id'] ) ? (int) $files[0]['attachment_id'] : 0 );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit
		}

		$zip_path = wp_tempnam( 'adx-gr-bundle' );
		$zip      = new ZipArchive();

		if ( true !== $zip->open( $zip_path, ZipArchive::OVERWRITE ) ) {
			wp_die( esc_html__( 'Could not prepare the download. Please try again.', 'adx-gated-resources' ), 500 );
		}

		$used_names = array();
		$added      = 0;

		foreach ( $files as $file ) {
			$contents = $this->get_bundle_file_contents( $file );
			if ( false === $contents ) {
				continue;
			}

			$name         = $this->unique_zip_entry_name( $file, $used_names );
			$used_names[] = $name;
			$zip->addFromString( $name, $contents );
			$added++;
		}

		$zip->close();

		if ( ! $added ) {
			wp_delete_file( $zip_path );
			wp_die( esc_html__( 'None of the files in this bundle could be retrieved. Please try again later.', 'adx-gated-resources' ), 500 );
		}

		$title         = get_the_title( $resource_id );
		$download_name = sanitize_file_name( $title ? $title : 'download' ) . '.zip';

		$this->send_file( $zip_path, $download_name, 'application/zip' );
		wp_delete_file( $zip_path );
		exit;
	}

	private function get_bundle_file_contents( array $file ) {
		if ( empty( $file['url'] ) ) {
			return false;
		}

		$path = $this->resolve_local_path( $file['url'], ! empty( $file['attachment_id'] ) ? (int) $file['attachment_id'] : 0 );
		if ( $path ) {
			return file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents
		}

		$response = wp_safe_remote_get( $file['url'], array( 'timeout' => 20 ) );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		return wp_remote_retrieve_body( $response );
	}

	private function unique_zip_entry_name( array $file, array $used_names ) {
		$name = ! empty( $file['label'] ) ? sanitize_file_name( $file['label'] ) : '';

		if ( ! $name ) {
			$path = wp_parse_url( $file['url'], PHP_URL_PATH );
			$name = $path ? sanitize_file_name( wp_basename( $path ) ) : 'file';
		} elseif ( ! pathinfo( $name, PATHINFO_EXTENSION ) ) {
			// A label was used as the filename but carries no extension
			// of its own -- keep the real file's extension so the
			// downloaded file still opens correctly.
			$path = wp_parse_url( $file['url'], PHP_URL_PATH );
			$ext  = $path ? pathinfo( $path, PATHINFO_EXTENSION ) : '';
			if ( $ext ) {
				$name .= '.' . $ext;
			}
		}

		$base = pathinfo( $name, PATHINFO_FILENAME );
		$ext  = pathinfo( $name, PATHINFO_EXTENSION );
		$i    = 2;

		while ( in_array( $name, $used_names, true ) ) {
			$name = $base . '-' . $i . ( $ext ? '.' . $ext : '' );
			$i++;
		}

		return $name;
	}

	/**
	 * Mints one one-time, 60-second token for this gate pass, tying a
	 * resource to how it should be fulfilled (single-file redirect vs.
	 * bundle zip) once handle_download() redeems it. The lead rides
	 * along so it's only logged if the download actually happens.
	 */
	private function mint_token( $resource_id, $gate_type, array $lead ) {
		$token = wp_generate_password( 32, false, false );
		set_transient(
			'adx_gr_dl_' . $token,
			array(
				'resource_id' => $resource_id,
				'gate_type'   => $gate_type,
				'lead'        => $lead,
			),
			60
		);
		return $token;
	}

	private function build_download_response( $gate_type, $token ) {
		return array(
			'gate_type'    => $gate_type,
			'download_url' => $this->get_download_url( $token ),
		);
	}

	private function get_download_url( $token ) {
		return add_query_arg(
			array(
				'action' => 'adx_gr_download',
				'token'  => $token,
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	/**
	 * Snapshots everything the log needs at gate time -- the download
	 * itself is a plain GET, so page_url etc. aren't available then.
	 */
	private function build_lead( $email, $resource_id, array $fields, array $zb_meta ) {
		// A remembered visitor (URL identity or cookie) doesn't resubmit
		// the form -- reuse what they gave at the gate so the log entry
		// still shows who they are.
		if ( empty( $fields ) ) {
			$fields = ADX_GR_DB::get_subscriber_fields( $email );
		}

		$page_url = isset( $_POST['page_url'] ) ? remove_query_arg( self::URL_KEY_PARAM, wp_unslash( $_POST['page_url'] ) ) : '';

		return array_merge(
			array(
				'email'          => $email,
				'fields'         => $fields,
				'resource_id'    => $resource_id,
				'resource_title' => get_the_title( $resource_id ),
				'ip_address'     => $this->get_client_ip(),
				'user_agent'     => isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '',
				'page_url'       => $page_url,
			),
			$zb_meta
		);
	}

	private function log_pending_lead() {
		if ( empty( $this->pending_lead['email'] ) ) {
			return;
		}

		$lead               = $this->pending_lead;
		$this->pending_lead = null;
		$fields             = isset( $lead['fields'] ) && is_array( $lead['fields'] ) ? $lead['fields'] : array();

		ADX_GR_DB::insert_lead( $lead );

		ADX_GR_DB::upsert_subscriber(
			$lead['email'],
			$fields,
			isset( $lead['zerobounce_status'] ) ? $lead['zerobounce_status'] : '',
			isset( $lead['zerobounce_sub_status'] ) ? $lead['zerobounce_sub_status'] : ''
		);

		// Downloads are only logged here -- the notification email goes
		// out from the product-page library button instead (see
		// handle_library_access()).
	}

	/**
	 * Emails whoever is configured under Settings > Notify by email
	 * when a visitor uses an [adx_library_access] button, naming the
	 * product/page the button sits on. Also used by the admin "Send
	 * test email" action.
	 */
	public static function notify_library_access( $email, array $fields, $product, $source_id = 0, $page_url = '', $smtp_only = false ) {
		$page_title = $source_id ? self::plain_title( $source_id ) : '';

		$lines = array(
			sprintf( __( 'Product: %s', 'adx-gated-resources' ), $product ),
		);
		if ( '' !== $page_title && $page_title !== $product ) {
			$lines[] = sprintf( __( 'Page: %s', 'adx-gated-resources' ), $page_title );
		}
		if ( $page_url ) {
			$lines[] = sprintf( __( 'Page URL: %s', 'adx-gated-resources' ), self::strip_identity( $page_url ) );
		}

		return self::send_notification(
			$email,
			$fields,
			/* translators: 1: person's name or email, 2: product name */
			__( 'Library access: %1$s via %2$s', 'adx-gated-resources' ),
			$product,
			$lines,
			$smtp_only
		);
	}

	/**
	 * Emails the same recipients when a visitor fills in the form on a
	 * gated page's lock screen (e.g. the library, reached directly or
	 * after their cookie expired), naming the page they were on just
	 * before landing there.
	 */
	public static function notify_page_unlock( $email, array $fields, $page_id, $referrer = '' ) {
		$page_title = self::plain_title( $page_id );
		$referrer   = self::strip_identity( $referrer );

		// Reloading the lock screen makes it its own referrer.
		if ( $referrer && untrailingslashit( strtok( $referrer, '?#' ) ) === untrailingslashit( get_permalink( $page_id ) ) ) {
			$referrer = '';
		}

		$lines = array(
			sprintf( __( 'Unlocked page: %s', 'adx-gated-resources' ), $page_title ),
		);

		if ( $referrer ) {
			$referrer_id = url_to_postid( $referrer );
			$lines[]     = $referrer_id
				? sprintf( __( 'Previous page: %1$s (%2$s)', 'adx-gated-resources' ), self::plain_title( $referrer_id ), $referrer )
				: sprintf( __( 'Previous page: %s', 'adx-gated-resources' ), $referrer );
		} else {
			$lines[] = __( 'Previous page: none (direct visit, bookmark or link that hides its origin)', 'adx-gated-resources' );
		}

		return self::send_notification(
			$email,
			$fields,
			/* translators: 1: person's name or email, 2: page title */
			__( 'Page unlocked: %1$s unlocked %2$s', 'adx-gated-resources' ),
			$page_title,
			$lines,
			false
		);
	}

	private static function strip_identity( $url ) {
		return $url ? remove_query_arg( array( self::URL_EMAIL_PARAM, self::URL_KEY_PARAM ), $url ) : '';
	}

	/**
	 * Shared body of every notification: the context lines given, then
	 * the visitor's details, then the time.
	 */
	private static function send_notification( $email, array $fields, $subject_format, $subject_item, array $lines, $smtp_only ) {
		$recipients = self::get_notification_recipients();

		if ( empty( $recipients ) ) {
			return false;
		}

		$full_name = trim( ( isset( $fields['first_name'] ) ? $fields['first_name'] : '' ) . ' ' . ( isset( $fields['last_name'] ) ? $fields['last_name'] : '' ) );

		$lines[] = '';
		$lines[] = sprintf( __( 'Full name: %s', 'adx-gated-resources' ), '' !== $full_name ? $full_name : '-' );
		$lines[] = sprintf( __( 'Email: %s', 'adx-gated-resources' ), $email );

		// Company, Role, State, Project name and any other configured
		// field, in the order set under Form Fields. First/last name are
		// already shown above as the full name.
		foreach ( ADX_GR_Form_Fields::get_fields() as $field ) {
			if ( in_array( $field['key'], array( 'first_name', 'last_name' ), true ) ) {
				continue;
			}
			$value = isset( $fields[ $field['key'] ] ) ? (string) $fields[ $field['key'] ] : '';
			if ( 'checkbox' === $field['type'] ) {
				$value = '1' === $value ? __( 'Yes', 'adx-gated-resources' ) : __( 'No', 'adx-gated-resources' );
			}
			$lines[] = sprintf( '%s: %s', $field['label'], '' !== $value ? $value : '-' );
		}

		$lines[] = '';
		$lines[] = sprintf( __( 'Submitted at: %s', 'adx-gated-resources' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) );

		$subject = sprintf( $subject_format, '' !== $full_name ? $full_name : $email, $subject_item );

		// Replying goes straight to the visitor.
		$headers = array( 'Reply-To: ' . $email );

		return ADX_GR_Mailer::send( $recipients, $subject, implode( "\n", $lines ), $headers, $smtp_only );
	}

	public static function get_notification_recipients() {
		$raw = get_option( 'adx_gr_notification_emails', '' );

		if ( empty( $raw ) ) {
			return array();
		}

		$candidates = preg_split( '/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );

		return array_values( array_filter( $candidates, 'is_email' ) );
	}

	private function set_email_cookie( $email ) {
		$expiry = self::new_expiry();
		$value  = $email . '|' . $expiry . '|' . self::sign_identity( $email, $expiry );

		// The URL identity handed out with this response shares the
		// cookie's expiry.
		self::$verified_expires = $expiry;

		setcookie( self::COOKIE_NAME, $value, $expiry, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
		$this->set_cache_bypass_cookie( $expiry );
	}

	private static function new_expiry() {
		return time() + ( max( 1, absint( get_option( 'adx_gr_cookie_days', 30 ) ) ) * DAY_IN_SECONDS );
	}

	/**
	 * Full-page caches (Nginx FastCGI cache, Varnish, most WP caching
	 * plugins) don't know about our cookie, so without this a verified
	 * visitor keeps getting the cached lock screen of a gated page --
	 * WordPress never runs to see they're verified. "wordpress_no_cache"
	 * is the conventional cookie those caches already skip for; set it
	 * alongside ours so verified visitors always reach WordPress.
	 * Filterable for hosts whose bypass rule looks for something else.
	 */
	private function set_cache_bypass_cookie( $expiry = 0 ) {
		$name = apply_filters( 'adx_gr_cache_bypass_cookie', 'wordpress_no_cache' );

		if ( ! $name ) {
			return;
		}

		if ( ! $expiry ) {
			$expiry = self::$verified_expires ? self::$verified_expires : self::new_expiry();
		}

		setcookie( $name, '1', $expiry, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
	}

	/**
	 * Public + static so other parts of the plugin (e.g. the page-gate
	 * template_redirect check) can ask "is this visitor already
	 * verified?" without duplicating the cookie/HMAC logic.
	 */
	public static function get_verified_email() {
		// The signed identity in the URL (or forwarded by the JS on an
		// AJAX call) wins over the cookie: it's what the gate form hands
		// out and doesn't depend on the browser/host keeping cookies.
		if ( ! empty( $_REQUEST[ self::URL_EMAIL_PARAM ] ) && ! empty( $_REQUEST[ self::URL_KEY_PARAM ] ) ) {
			$key   = explode( '.', sanitize_text_field( wp_unslash( $_REQUEST[ self::URL_KEY_PARAM ] ) ), 2 );
			$email = 2 === count( $key ) ? self::check_signed_identity( wp_unslash( $_REQUEST[ self::URL_EMAIL_PARAM ] ), $key[0], $key[1] ) : false;
			if ( $email ) {
				return $email;
			}
		}

		if ( empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return false;
		}

		$raw   = sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
		$parts = explode( '|', $raw );

		if ( 3 === count( $parts ) ) {
			return self::check_signed_identity( $parts[0], $parts[1], $parts[2] );
		}

		// Cookie from before expiries were signed in: the browser still
		// drops it on time, but its end date is unknown here, so links
		// handed out from it get a fresh one.
		if ( 2 === count( $parts ) ) {
			$email = sanitize_email( $parts[0] );
			if ( $email && is_email( $email ) && hash_equals( hash_hmac( 'sha256', strtolower( $email ), wp_salt( 'adx_gr_cookie' ) ), $parts[1] ) ) {
				return $email;
			}
		}

		return false;
	}

	/**
	 * Valid only while unexpired. On success, remembers the expiry so
	 * any identity re-issued in this request keeps the same end date.
	 */
	private static function check_signed_identity( $email, $expires, $signature ) {
		$email   = sanitize_email( $email );
		$expires = absint( $expires );

		if ( ! $email || ! is_email( $email ) || $expires < time() ) {
			return false;
		}

		if ( ! hash_equals( self::sign_identity( $email, $expires ), $signature ) ) {
			return false;
		}

		self::$verified_expires = $expires;

		return $email;
	}

	private static function sign_identity( $email, $expires ) {
		return hash_hmac( 'sha256', strtolower( $email ) . '|' . absint( $expires ), wp_salt( 'adx_gr_cookie' ) );
	}

	private function get_client_ip() {
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$forwarded = explode( ',', wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			return sanitize_text_field( trim( $forwarded[0] ) );
		}

		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}

	private function rate_limit_key() {
		return 'adx_gr_rl_' . md5( $this->get_client_ip() );
	}

	private function is_rate_limited() {
		$count = (int) get_transient( $this->rate_limit_key() );
		$max   = (int) apply_filters( 'adx_gr_rate_limit_max', 20 );
		return $count >= $max;
	}

	private function bump_rate_limit() {
		$key   = $this->rate_limit_key();
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
	}
}
