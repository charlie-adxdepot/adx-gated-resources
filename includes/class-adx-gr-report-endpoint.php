<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The scheduler URL: a secret link that a hosting provider's scheduler
 * (cron job, uptime monitor, etc.) can call to trigger the daily report
 * when the site has no server cron of its own.
 *
 *   https://example.com/?adx_gr_cron=<key>              runs the report if it is due
 *   https://example.com/?adx_gr_cron=<key>&dry_run=1    says what would happen, sends nothing
 *
 * The scheduler doesn't have to be exact. It can call as often as it
 * likes (every few minutes is ideal): the plugin works out for itself
 * whether a report is due (the send time has passed, it's a send day,
 * and that day's report hasn't gone out), so repeat calls do nothing,
 * and a late call still sends the report once. All of that is in
 * ADX_GR_Daily_Report::run_due(), which the WP-Cron event shares, so the
 * two can never both send.
 *
 * The response is JSON. HTTP 200 means the call worked, including
 * "nothing due"; 500 means a report was due but couldn't be sent; 403
 * means the key is wrong.
 */
class ADX_GR_Report_Endpoint {

	const PARAM      = 'adx_gr_cron';
	const OPT_TOKEN  = 'adx_gr_report_cron_token';
	const OPT_LAST   = 'adx_gr_report_endpoint_last';
	const ACTION_NEW = 'adx_gr_regenerate_cron_key';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Early on init, ahead of themes, redirects and page gating.
		add_action( 'init', array( $this, 'maybe_handle' ), 1 );
		add_action( 'admin_post_' . self::ACTION_NEW, array( $this, 'regenerate_key' ) );
	}

	/* ------------------------------------------------------------------
	 * The key and URLs
	 * --------------------------------------------------------------- */

	/**
	 * The secret in the URL. Created the first time it's needed (the
	 * Settings page), never by a request to the endpoint.
	 */
	public static function get_token( $create = true ) {
		$token = (string) get_option( self::OPT_TOKEN, '' );

		if ( '' === $token && $create ) {
			$token = wp_generate_password( 40, false, false );
			update_option( self::OPT_TOKEN, $token, false );
		}

		return $token;
	}

	public static function url( $dry_run = false ) {
		$args = array( self::PARAM => self::get_token() );

		if ( $dry_run ) {
			$args['dry_run'] = '1';
		}

		return add_query_arg( $args, home_url( '/' ) );
	}

	public function regenerate_key() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'adx-gated-resources' ) );
		}

		check_admin_referer( self::ACTION_NEW );

		update_option( self::OPT_TOKEN, wp_generate_password( 40, false, false ), false );
		delete_option( self::OPT_LAST );

		wp_safe_redirect( add_query_arg( 'report', 'cron_key_reset', admin_url( 'admin.php?page=adx-gr-settings' ) ) );
		exit;
	}

	/* ------------------------------------------------------------------
	 * The request
	 * --------------------------------------------------------------- */

	public function maybe_handle() {
		if ( ! isset( $_GET[ self::PARAM ] ) ) {
			return;
		}

		// Never cache: a cached response would mean the call never ran.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );

		$given = sanitize_text_field( wp_unslash( $_GET[ self::PARAM ] ) );
		$token = self::get_token( false );

		if ( '' === $token || ! hash_equals( $token, $given ) ) {
			self::respond(
				array(
					'ok'      => false,
					'status'  => 'forbidden',
					'message' => 'The key in this URL is missing or no longer valid.',
				),
				403
			);
		}

		// A HEAD request (some monitors and link checkers send one) must
		// never trigger a send, so it's treated as a dry run.
		$method  = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		$dry_run = ! empty( $_GET['dry_run'] ) || 'HEAD' === $method;

		$result = ADX_GR_Daily_Report::run_due( 'scheduler URL', $dry_run );

		// What the Settings page shows as "last call".
		if ( ! $dry_run ) {
			update_option(
				self::OPT_LAST,
				array(
					'time'    => ADX_GR_Daily_Report::now(),
					'status'  => $result['status'],
					'message' => $result['message'],
				),
				false
			);
		}

		self::respond( $result, $result['ok'] || 'busy' === $result['status'] ? 200 : 500 );
	}

	private static function respond( array $data, $status ) {
		wp_send_json( $data, $status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/* ------------------------------------------------------------------
	 * Settings panel
	 * --------------------------------------------------------------- */

	/**
	 * The ready-to-send note for the hosting provider.
	 */
	private static function message_for_host( $url, $send_time, $timezone ) {
		return sprintf(
			"Please set up a scheduled task that requests this URL (a plain HTTPS GET, e.g. with curl):\n\n%1\$s\n\nSchedule: every 5 minutes, all day, every day. (15 minutes is fine too.)\n\nThe website works out for itself when a report is due: it sends once a day, at %2\$s %3\$s time, on the days it is set up to send. Calls at other times, or extra calls, do nothing, so there is no need to match the time exactly or to adjust for daylight saving. A report is only sent within 3 hours after %2\$s; if no call arrives in that time, that day's report is skipped and its downloads go into the next one.\n\nIf you can only schedule one call a day, make it %2\$s %3\$s time plus a few minutes (for example 5:05pm) and use the timezone name %3\$s, which follows daylight saving.\n\nThe reply is JSON.\n- HTTP 200: the call worked (it may say nothing is due yet; that is normal).\n- HTTP 500: a report was due but could not be sent. Please let us know.\n- HTTP 403: the URL is wrong or has been replaced.\n\nTo check the connection without sending any email, add &dry_run=1 to the end of the URL.\n\nPlease keep the URL private: anyone who has it can trigger the report.",
			$url,
			self::display_time( $send_time ),
			$timezone
		);
	}

	private static function display_time( $hhmm ) {
		list( $h, $m ) = array_map( 'intval', explode( ':', $hhmm ) );

		return gmdate( 'g:ia', mktime( $h, $m, 0, 1, 1, 2000 ) );
	}

	public static function render_panel() {
		$url       = self::url();
		$dry_url   = self::url( true );
		$timezone  = wp_timezone_string();
		$send_time = ADX_GR_Daily_Report::get_time();
		$last      = get_option( self::OPT_LAST );
		$format    = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$is_sa     = 'Australia/Adelaide' === $timezone;
		$message   = self::message_for_host( $url, $send_time, $timezone );
		?>
		<div style="margin:16px 0 12px;padding:14px 16px;border:1px solid #c3c4c7;border-left:4px solid #00a32a;background:#fff;max-width:720px;">
			<strong style="display:block;margin-bottom:4px;"><?php esc_html_e( 'Scheduler URL (for your hosting provider)', 'adx-gated-resources' ); ?></strong>
			<p class="description" style="margin:0 0 10px;">
				<?php esc_html_e( 'When there is no server cron job, your hosting provider can call this URL on a schedule to send the daily report. It only sends when a report is due: once a day, within 3 hours after the send time above, on the send days. Opening it at any other time does nothing, so calling it often is safe.', 'adx-gated-resources' ); ?>
			</p>

			<?php if ( ! $is_sa ) : ?>
				<div class="notice notice-warning inline" style="margin:0 0 10px;"><p>
					<?php
					printf(
						/* translators: 1: current timezone, 2: link to General Settings */
						esc_html__( 'The site timezone is %1$s. For the report to go out at the send time in South Australia, set the timezone to Adelaide under %2$s.', 'adx-gated-resources' ),
						'<strong>' . esc_html( $timezone ) . '</strong>',
						'<a href="' . esc_url( admin_url( 'options-general.php' ) ) . '">' . esc_html__( 'Settings > General', 'adx-gated-resources' ) . '</a>'
					);
					?>
				</p></div>
			<?php else : ?>
				<p class="description" style="margin:0 0 10px;">
					<?php
					/* translators: 1: send time, 2: timezone */
					echo esc_html( sprintf( __( 'Send time: %1$s, %2$s (South Australia, follows daylight saving).', 'adx-gated-resources' ), self::display_time( $send_time ), $timezone ) );
					?>
				</p>
			<?php endif; ?>

			<label for="adx-gr-cron-url" style="display:block;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'URL', 'adx-gated-resources' ); ?></label>
			<div style="display:flex;gap:6px;margin-bottom:6px;">
				<input type="text" id="adx-gr-cron-url" class="large-text code" readonly value="<?php echo esc_attr( $url ); ?>" onclick="this.select();" />
				<button type="button" class="button" data-adx-gr-copy="adx-gr-cron-url"><?php esc_html_e( 'Copy', 'adx-gated-resources' ); ?></button>
			</div>
			<p style="margin:0 0 12px;">
				<a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( $dry_url ); ?>"><?php esc_html_e( 'Test the connection (sends nothing)', 'adx-gated-resources' ); ?></a>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_NEW ), self::ACTION_NEW ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Generate a new URL? The current one stops working straight away, so your hosting provider will need the new one.', 'adx-gated-resources' ) ); ?>');"><?php esc_html_e( 'Generate a new URL', 'adx-gated-resources' ); ?></a>
			</p>

			<p style="margin:0 0 12px;">
				<?php if ( is_array( $last ) && ! empty( $last['time'] ) ) : ?>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: date/time, 2: result message */
							__( 'Last call from the scheduler: %1$s. %2$s', 'adx-gated-resources' ),
							wp_date( $format, (int) $last['time'] ),
							$last['message']
						)
					);
					?>
				<?php else : ?>
					<em><?php esc_html_e( 'No call received from the scheduler yet.', 'adx-gated-resources' ); ?></em>
				<?php endif; ?>
			</p>

			<label for="adx-gr-cron-message" style="display:block;font-weight:600;margin-bottom:4px;"><?php esc_html_e( 'Message to send to your hosting provider', 'adx-gated-resources' ); ?></label>
			<textarea id="adx-gr-cron-message" class="large-text" rows="14" readonly onclick="this.select();"><?php echo esc_textarea( $message ); ?></textarea>
			<p style="margin:6px 0 0;"><button type="button" class="button" data-adx-gr-copy="adx-gr-cron-message"><?php esc_html_e( 'Copy message', 'adx-gated-resources' ); ?></button></p>
			<p class="description" style="margin:8px 0 0;"><?php esc_html_e( 'Anyone with the URL can trigger the report, so share it only with your hosting provider. If it ever leaks, generate a new one.', 'adx-gated-resources' ); ?></p>
		</div>
		<script>
		( function () {
			document.querySelectorAll( '[data-adx-gr-copy]' ).forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					var field = document.getElementById( button.getAttribute( 'data-adx-gr-copy' ) );
					var label = button.textContent;
					field.select();
					var done = function () {
						button.textContent = <?php echo wp_json_encode( __( 'Copied', 'adx-gated-resources' ) ); ?>;
						setTimeout( function () { button.textContent = label; }, 1500 );
					};
					if ( navigator.clipboard && window.isSecureContext ) {
						navigator.clipboard.writeText( field.value ).then( done );
					} else {
						document.execCommand( 'copy' );
						done();
					}
				} );
			} );
		} )();
		</script>
		<?php
	}
}
