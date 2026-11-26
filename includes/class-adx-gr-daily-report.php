<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * End-of-day download report. On each send day (5pm site time by default)
 * emails the "Notify by email" addresses a summary of every download
 * logged since the previous report, with a link to the full report
 * page, which in turn offers the same data as a CSV.
 *
 * The report page needs no login, so recipients can open it straight
 * from the email on any device and filter it by date. Every link
 * carries an unguessable access key (an HMAC of a stored secret), and
 * "Reset report links" under Settings changes the secret, voiding
 * every link sent so far. Only the download log is shown -- never any
 * gated files.
 */
class ADX_GR_Daily_Report {

	const CRON_HOOK    = 'adx_gr_daily_report';
	const QUERY_PARAM  = 'adx_gr_report';
	const CSV_PARAM    = 'adx_gr_csv';
	const SEARCH_PARAM = 'adx_gr_q';
	const FROM_PARAM   = 'adx_gr_from';
	const TO_PARAM     = 'adx_gr_to';
	const SINCE_PARAM  = 'adx_gr_since';
	const UNTIL_PARAM  = 'adx_gr_until';
	const DEFAULT_TIME = '17:00';

	const MODE_SCHEDULED = 'scheduled';
	const MODE_DAILY     = 'daily';
	const MODE_TEST      = 'test';
	const MODE_RESEND    = 'resend';

	/**
	 * How many people the email lists before pointing to the full report.
	 */
	const EMAIL_PEOPLE_LIMIT = 25;

	private static $instance = null;

	/**
	 * Download count of the most recent send_report() call, for the
	 * admin confirmation message.
	 */
	private static $last_downloads = 0;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( self::CRON_HOOK, array( $this, 'run' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );
		add_action( 'init', array( $this, 'maybe_render_report' ), 99 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_adx_gr_send_report_now', array( $this, 'send_report_now' ) );
		add_action( 'admin_post_adx_gr_resend_report', array( $this, 'resend_last_report' ) );
		add_action( 'admin_post_adx_gr_send_daily_report', array( $this, 'send_daily_report' ) );
		add_action( 'admin_post_adx_gr_reset_report_links', array( $this, 'reset_report_links' ) );

		// A new send time (or switching the report on/off) takes effect
		// straight away instead of after the already-booked run.
		foreach ( array( 'adx_gr_report_enabled', 'adx_gr_report_time', 'adx_gr_report_days' ) as $option ) {
			add_action( 'add_option_' . $option, array( __CLASS__, 'reschedule' ) );
			add_action( 'update_option_' . $option, array( __CLASS__, 'reschedule' ) );
		}
	}

	/* ------------------------------------------------------------------
	 * Settings
	 * --------------------------------------------------------------- */

	public function register_settings() {
		register_setting( 'adx_gr_settings', 'adx_gr_report_enabled', array( 'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ) ) );
		register_setting( 'adx_gr_settings', 'adx_gr_report_time', array( 'sanitize_callback' => array( __CLASS__, 'sanitize_time' ) ) );
		register_setting( 'adx_gr_settings', 'adx_gr_report_skip_empty', array( 'sanitize_callback' => array( __CLASS__, 'sanitize_checkbox' ) ) );
		register_setting( 'adx_gr_settings', 'adx_gr_report_days', array( 'sanitize_callback' => array( __CLASS__, 'sanitize_days' ) ) );
	}

	/**
	 * Days the report goes out, as PHP 'w' numbers (0 = Sunday). Every day
	 * but Saturday by default: weekdays at close of business, plus one
	 * weekend report on Sunday covering Friday 5pm to Sunday 5pm.
	 */
	public static function default_days() {
		return array( 0, 1, 2, 3, 4, 5 );
	}

	public static function sanitize_days( $value ) {
		$days = array_values( array_unique( array_intersect( array_map( 'intval', (array) $value ), range( 0, 6 ) ) ) );
		sort( $days );

		// No day ticked would silently stop the report; the on/off checkbox is for that.
		return $days ? $days : self::default_days();
	}

	private static function get_days() {
		return self::sanitize_days( get_option( 'adx_gr_report_days', self::default_days() ) );
	}

	public static function is_send_day( DateTimeImmutable $date ) {
		return in_array( (int) $date->format( 'w' ), self::get_days(), true );
	}

	/**
	 * The current time, as a Unix timestamp. Everything that decides
	 * "is the report due?" reads the clock here, so it can be set from one
	 * place (filter adx_gr_report_now), e.g. to test a whole week.
	 */
	public static function now() {
		return (int) apply_filters( 'adx_gr_report_now', time() );
	}

	private static function now_local() {
		return ( new DateTimeImmutable( '@' . self::now() ) )->setTimezone( wp_timezone() );
	}

	public static function sanitize_checkbox( $value ) {
		return '1' === $value ? '1' : '0';
	}

	public static function sanitize_time( $value ) {
		$value = trim( (string) $value );
		return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', $value ) ? $value : self::DEFAULT_TIME;
	}

	public static function is_enabled() {
		return '1' === get_option( 'adx_gr_report_enabled', '1' );
	}

	public static function get_time() {
		return self::sanitize_time( get_option( 'adx_gr_report_time', self::DEFAULT_TIME ) );
	}

	/* ------------------------------------------------------------------
	 * Scheduling
	 * --------------------------------------------------------------- */

	/**
	 * The next occurrence of the send time, on a send day, in the site's
	 * timezone. Worked out afresh for every run (rather than a fixed
	 * 24-hour repeat) so the report stays at 5pm across daylight-saving
	 * changes.
	 */
	public static function next_run_timestamp() {
		list( $hour, $minute ) = array_map( 'intval', explode( ':', self::get_time() ) );

		$now  = self::now_local();
		$next = $now->setTime( $hour, $minute );

		if ( $next <= $now ) {
			$next = $next->modify( '+1 day' )->setTime( $hour, $minute );
		}

		for ( $i = 0; $i < 7 && ! self::is_send_day( $next ); $i++ ) {
			$next = $next->modify( '+1 day' )->setTime( $hour, $minute );
		}

		return $next->getTimestamp();
	}

	/**
	 * The latest send time at or before now. WP-Cron only runs on a site
	 * visit, so Friday's 5pm run can fire after midnight; judging the
	 * day by this slot rather than by "now" keeps it a Friday report.
	 */
	public static function last_due_slot() {
		list( $hour, $minute ) = array_map( 'intval', explode( ':', self::get_time() ) );

		$now  = self::now_local();
		$slot = $now->setTime( $hour, $minute );

		return $slot > $now ? $slot->modify( '-1 day' )->setTime( $hour, $minute ) : $slot;
	}

	public static function maybe_schedule() {
		$scheduled = wp_next_scheduled( self::CRON_HOOK );

		if ( self::is_enabled() && ! $scheduled ) {
			wp_schedule_single_event( self::next_run_timestamp(), self::CRON_HOOK );
		} elseif ( ! self::is_enabled() && $scheduled ) {
			self::unschedule();
		}
	}

	public static function reschedule() {
		self::unschedule();
		self::maybe_schedule();
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Cron callback. Books the next run before sending, so a failure
	 * mid-send can't break the daily cycle.
	 */
	public function run() {
		self::maybe_schedule();
		self::run_due( 'wp-cron' );
	}

	/** After this many failed attempts at one report, stop retrying it. */
	const MAX_ATTEMPTS = 3;

	/** How long after the send time a report can still go out (hours). */
	const SEND_WINDOW_HOURS = 3;

	/**
	 * How long after the send time (in seconds) the report may still be sent,
	 * e.g. when the scheduler is a little late or briefly down. Filterable.
	 */
	public static function send_window() {
		return max( HOUR_IN_SECONDS, (int) apply_filters( 'adx_gr_report_send_window', self::SEND_WINDOW_HOURS * HOUR_IN_SECONDS ) );
	}

	/**
	 * Has the report for this send time already gone out? Judged from the
	 * slot marker, or else from the last recorded run (so installs from
	 * before the marker existed aren't treated as having missed it).
	 */
	private static function slot_fulfilled( $slot_ts ) {
		if ( absint( get_option( 'adx_gr_report_last_slot', 0 ) ) >= $slot_ts ) {
			return true;
		}

		$last = get_option( 'adx_gr_report_last_run' );

		return is_array( $last ) && in_array( $last['status'], array( 'sent', 'empty' ), true ) && (int) $last['time'] >= $slot_ts;
	}

	/**
	 * Takes a short-lived lock so two overlapping calls (a scheduler retry,
	 * or WP-Cron and the scheduler URL at the same moment) can't both send.
	 * add_option() is atomic: only one caller can create the row.
	 */
	private static function acquire_run_lock() {
		$key = 'adx_gr_report_run_lock';

		if ( add_option( $key, time(), '', 'no' ) ) {
			return true;
		}

		// A lock left behind by a request that died mid-send expires.
		if ( time() - absint( get_option( $key ) ) > 5 * MINUTE_IN_SECONDS ) {
			delete_option( $key );
			return (bool) add_option( $key, time(), '', 'no' );
		}

		return false;
	}

	private static function release_run_lock() {
		delete_option( 'adx_gr_report_run_lock' );
	}

	/**
	 * Runs the "is the report due?" routine (run_due_core) and adds how many
	 * downloads are waiting for the next report to whatever it returns.
	 *
	 * @param string $source  Who asked (for the response only).
	 * @param bool   $dry_run Say what would happen without sending or changing anything.
	 * @return array See run_due_core(), plus pending_downloads, pending_people
	 *               and pending_since.
	 */
	public static function run_due( $source = 'wp-cron', $dry_run = false ) {
		$result = self::run_due_core( $source, $dry_run );

		// Counted after the run, so a report that was just sent shows 0 waiting.
		list( $from, $to ) = self::scheduled_window();

		// A report that ended this very second leaves nothing waiting
		// (scheduled_window() would fall back to the last 24 hours).
		$bookmark = absint( get_option( 'adx_gr_report_last_to', 0 ) );
		if ( $bookmark && $bookmark >= $to ) {
			$from = $to;
		}

		list( $downloads, $people ) = $from < $to
			? ADX_GR_DB::count_leads_between( wp_date( 'Y-m-d H:i:s', $from ), wp_date( 'Y-m-d H:i:s', $to ) )
			: array( 0, 0 );

		$result['pending_downloads'] = $downloads;
		$result['pending_people']    = $people;
		$result['pending_since']     = wp_date( 'D j M Y, g:i a T', $from );

		// Say it in the message too, where nothing was sent. These are the
		// downloads so far; more can arrive before the report goes out.
		if ( in_array( $result['status'], array( 'disabled', 'not_send_day', 'not_due', 'missed', 'initialised', 'gave_up', 'busy' ), true ) ) {
			$result['message'] .= sprintf(
				' Waiting for the next report so far: %d download(s) by %d person(s), since %s.',
				$downloads,
				$people,
				wp_date( 'D j M, g:i a', $from )
			);
		}

		return $result;
	}

	/**
	 * Sends the scheduled report if one is due, at most once per send time.
	 * The only thing that sends the automatic report: the WP-Cron event and
	 * the scheduler URL (ADX_GR_Report_Endpoint) both come through here, so
	 * between them they can't send it twice.
	 *
	 * Due means: the report is switched on, the latest send time (e.g. 5pm)
	 * has passed, but not by more than the send window (a few hours), it
	 * falls on a send day, and that send time hasn't been served yet. A
	 * report that isn't triggered within the window is skipped, not sent
	 * late. A send time that passes on an off day (e.g. Saturday)
	 * is skipped without moving the bookmark, so those downloads roll into
	 * the next report, which is how Sunday's weekend report picks up
	 * everything after Friday's.
	 *
	 * @param string $source  Who asked (for the response only).
	 * @param bool   $dry_run Say what would happen without sending or changing anything.
	 * @return array status, message and details; status is one of: sent, empty,
	 *               failed, no_recipients, gave_up, busy, disabled, not_send_day,
	 *               not_due, missed, initialised, due (dry run only).
	 */
	private static function run_due_core( $source, $dry_run ) {
		$now     = self::now();
		$slot    = self::last_due_slot();
		$slot_ts = $slot->getTimestamp();
		$fmt     = 'D j M Y, g:i a T';

		$result = array(
			'ok'              => true,
			'status'          => '',
			'message'         => '',
			'dry_run'         => (bool) $dry_run,
			'source'          => $source,
			'timezone'        => wp_timezone_string(),
			'site_time'       => wp_date( $fmt, $now ),
			'send_time'       => self::get_time(),
			'next_report_due' => wp_date( $fmt, self::next_run_timestamp() ),
		);

		if ( ! self::is_enabled() ) {
			$result['status']  = 'disabled';
			$result['message'] = 'The daily report is switched off in the plugin settings, so nothing was sent.';
			return $result;
		}

		// A brand-new setup with no report ever sent: on the very first call,
		// connect quietly rather than send something unscheduled during a
		// test. The marker this sets means it only ever happens once, so the
		// first real send time goes out normally.
		if ( false === get_option( 'adx_gr_report_last_slot', false ) && ! get_option( 'adx_gr_report_last_to' ) && ! get_option( 'adx_gr_report_last_run' ) ) {
			if ( ! $dry_run ) {
				update_option( 'adx_gr_report_last_slot', $slot_ts, false );
			}
			$result['status']  = 'initialised';
			$result['message'] = sprintf( 'Connected. No report has been sent yet; the first one goes out at the next send time, %s.', $result['next_report_due'] );
			return $result;
		}

		if ( ! self::is_send_day( $slot ) ) {
			$result['status']  = 'not_send_day';
			$result['message'] = sprintf(
				"No report to send: %s isn't one of the send days. Its downloads roll into the next report, due %s.",
				wp_date( 'l', $slot_ts ),
				$result['next_report_due']
			);
			return $result;
		}

		if ( self::slot_fulfilled( $slot_ts ) ) {
			$result['status']  = 'not_due';
			$result['message'] = sprintf(
				'Nothing due. The %s report has already been handled. Next report due %s.',
				wp_date( 'D j M, g:i a', $slot_ts ),
				$result['next_report_due']
			);
			return $result;
		}

		// Reports go out at the send time, not whenever the link is opened:
		// once this long after the send time has passed, that report is
		// skipped (its downloads roll into the next one) rather than sent late.
		$window = self::send_window();

		if ( $now - $slot_ts > $window ) {
			$result['status']  = 'missed';
			$result['message'] = sprintf(
				'No report sent: the %s report wasn\'t triggered within %s of the send time, so it was skipped. Its downloads roll into the next report, due %s.',
				wp_date( 'D j M, g:i a', $slot_ts ),
				human_time_diff( 0, $window ),
				$result['next_report_due']
			);
			return $result;
		}

		$attempts = get_option( 'adx_gr_report_slot_attempts' );
		$tries    = ( is_array( $attempts ) && (int) $attempts['slot'] === $slot_ts ) ? (int) $attempts['count'] : 0;

		if ( $tries >= self::MAX_ATTEMPTS ) {
			$result['ok']      = false;
			$result['status']  = 'gave_up';
			$result['message'] = sprintf(
				'The %s report failed %d times and is no longer being retried. Check the email settings, then use "Send today\'s report" in the plugin settings.',
				wp_date( 'D j M, g:i a', $slot_ts ),
				self::MAX_ATTEMPTS
			);
			return $result;
		}

		$recipients = ADX_GR_Ajax::get_notification_recipients();
		list( $from, $to ) = self::scheduled_window( $now );

		if ( $dry_run ) {
			$preview = self::build_report( $from, $to );

			$result['status']      = 'due';
			$result['message']     = sprintf(
				'A report is due now and would be sent to %d recipient(s): %d download(s) by %d person(s) since %s.%s',
				count( $recipients ),
				$preview['downloads'],
				count( $preview['people'] ),
				wp_date( $fmt, $from ),
				( ! $preview['downloads'] && '1' === get_option( 'adx_gr_report_skip_empty', '1' ) ) ? ' With no downloads it would be skipped instead.' : ''
			);
			$result['recipients']  = count( $recipients );
			$result['downloads']   = $preview['downloads'];
			$result['people']      = count( $preview['people'] );
			$result['period_from'] = wp_date( $fmt, $from );
			$result['period_to']   = wp_date( $fmt, $to );
			return $result;
		}

		if ( ! self::acquire_run_lock() ) {
			$result['status']  = 'busy';
			$result['message'] = 'Another request is sending the report right now.';
			return $result;
		}

		// Release the lock even if the request is cut off part-way.
		ignore_user_abort( true );

		try {
			// Re-check under the lock: a request that overlapped this one may have just sent it.
			if ( self::slot_fulfilled( $slot_ts ) ) {
				$result['status']  = 'not_due';
				$result['message'] = 'Nothing due. The report was just sent by another request.';
				return $result;
			}

			$status = self::send_report( self::MODE_SCHEDULED );
		} finally {
			self::release_run_lock();
		}

		$run = get_option( 'adx_gr_report_last_run' );

		if ( in_array( $status, array( 'sent', 'empty' ), true ) ) {
			update_option( 'adx_gr_report_last_slot', $slot_ts, false );
			delete_option( 'adx_gr_report_slot_attempts' );
		} elseif ( 'failed' === $status ) {
			update_option( 'adx_gr_report_slot_attempts', array( 'slot' => $slot_ts, 'count' => $tries + 1 ), false );
		}

		$result['status'] = $status;

		if ( is_array( $run ) && ! empty( $run['from'] ) ) {
			$result['downloads']   = (int) $run['downloads'];
			$result['people']      = (int) $run['people'];
			$result['period_from'] = wp_date( $fmt, (int) $run['from'] );
			$result['period_to']   = wp_date( $fmt, (int) $run['to'] );
			$result['report_type'] = self::is_weekend_report( array( 'from' => (int) $run['from'], 'to' => (int) $run['to'] ) ) ? 'weekend' : 'daily';
		}
		$result['recipients'] = count( $recipients );

		switch ( $status ) {
			case 'sent':
				$result['message'] = sprintf( 'Report sent to %d recipient(s): %d download(s) by %d person(s).', count( $recipients ), $result['downloads'], $result['people'] );
				break;
			case 'empty':
				$result['message'] = 'No downloads since the last report, so no email was sent.';
				break;
			case 'no_recipients':
				$result['message'] = 'Nothing sent: no addresses are set under "Notify by email" in the plugin settings.';
				break;
			default:
				$result['ok']      = false;
				$result['message'] = sprintf( 'The report could not be sent (attempt %d of %d). It will be retried on the next call.', $tries + 1, self::MAX_ATTEMPTS );
		}

		return $result;
	}

	/**
	 * [from, to] of the next scheduled report: from the end of the last
	 * report (or the last 24 hours the first time) up to now.
	 */
	public static function scheduled_window( $to = null ) {
		$to   = $to ? $to : self::now();
		$from = absint( get_option( 'adx_gr_report_last_to', 0 ) );

		if ( ! $from || $from >= $to ) {
			$from = $to - DAY_IN_SECONDS;
		}

		return array( $from, $to );
	}

	/* ------------------------------------------------------------------
	 * Sending
	 * --------------------------------------------------------------- */

	/**
	 * Start of the period a manual "Send today's report" covers: midnight
	 * today, or the end of the last report if that's earlier, so evening
	 * downloads after yesterday's report aren't lost and a second click
	 * on the same day still covers the whole day.
	 */
	public static function daily_window_start( $to = null ) {
		$to       = $to ? $to : time();
		$midnight = ( new DateTimeImmutable( 'today', wp_timezone() ) )->getTimestamp();
		$bookmark = absint( get_option( 'adx_gr_report_last_to', 0 ) );

		return ( $bookmark && $bookmark < $to ) ? min( $bookmark, $midnight ) : $midnight;
	}

	/**
	 * Emails a report. Four modes:
	 *
	 * - scheduled (the daily cron): everything since the last report was
	 *   sent (or the last 24 hours, the first time). Moves the bookmark
	 *   forward, only once the email goes out, so a failed or late run is
	 *   caught up by the next one.
	 * - daily ("Send today's report", clicked by hand at the end of the
	 *   day): see daily_window_start(). A real report to the receivers;
	 *   moves the bookmark like a scheduled one and goes out even when
	 *   there are no downloads.
	 * - test ("Send test report"): the last 24 hours, or back to the
	 *   bookmark if that reaches further, so recent test downloads always
	 *   show up and repeat clicks send the same logs again. The bookmark
	 *   stays put, so the scheduled report is unaffected.
	 * - resend ("Resend last report"): the last scheduled report's exact
	 *   window again, e.g. for someone who missed it. Bookmark untouched.
	 *
	 * Daily, test and resend go out even when there are no downloads.
	 *
	 * @return string sent|failed|empty|no_recipients|no_previous
	 */
	public static function send_report( $mode = self::MODE_SCHEDULED ) {
		if ( self::MODE_RESEND === $mode ) {
			$window = self::last_sent_window();
			if ( ! $window ) {
				return 'no_previous';
			}
			list( $from, $to ) = $window;
		} elseif ( self::MODE_DAILY === $mode ) {
			$to   = time();
			$from = self::daily_window_start( $to );
		} else {
			list( $from, $to ) = self::scheduled_window();

			// Each 5pm run (even a skipped one) moves the bookmark on, which
			// would leave a test right after it empty.
			if ( self::MODE_TEST === $mode ) {
				$from = min( $from, $to - DAY_IN_SECONDS );
			}
		}

		$scheduled = self::MODE_SCHEDULED === $mode;
		// Real reports (scheduled or the manual end-of-day one) share the
		// bookmark, so neither repeats what the other already covered.
		$is_report  = $scheduled || self::MODE_DAILY === $mode;
		$recipients = ADX_GR_Ajax::get_notification_recipients();

		if ( empty( $recipients ) ) {
			if ( $is_report ) {
				self::record_run( 'no_recipients', $from, $to );
			}
			return 'no_recipients';
		}

		$report = self::build_report( $from, $to );

		self::$last_downloads = $report['downloads'];

		if ( $scheduled && ! $report['downloads'] && '1' === get_option( 'adx_gr_report_skip_empty', '1' ) ) {
			update_option( 'adx_gr_report_last_to', $to, false );
			self::record_run( 'empty', $from, $to );
			return 'empty';
		}

		if ( self::MODE_TEST === $mode ) {
			$report['note'] = __( 'Test send: downloads from the last 24 hours (or since the last report, if longer). Real reports are not affected.', 'adx-gated-resources' );
		} elseif ( self::MODE_RESEND === $mode ) {
			$report['note'] = __( 'Resent copy of an earlier report.', 'adx-gated-resources' );
		}

		$sent = ADX_GR_Mailer::send(
			$recipients,
			self::email_subject( $report, $mode ),
			self::render_email( $report ),
			array( 'Content-Type: text/html; charset=UTF-8' )
		);

		if ( $is_report ) {
			if ( $sent ) {
				update_option( 'adx_gr_report_last_to', $to, false );
				update_option( 'adx_gr_report_last_window', array( $from, $to ), false );
			}
			self::record_run( $sent ? 'sent' : 'failed', $from, $to, $report, $mode );
		}

		return $sent ? 'sent' : 'failed';
	}

	private static function email_subject( array $report, $mode ) {
		$subject = sprintf(
			/* translators: 1: report title, 2: date, 3: number of downloads */
			_n( '%1$s %2$s: %3$d download', '%1$s %2$s: %3$d downloads', $report['downloads'], 'adx-gated-resources' ),
			self::report_title( $report ),
			self::is_weekend_report( $report )
				/* translators: 1: start date, 2: end date */
				? sprintf( __( '%1$s – %2$s', 'adx-gated-resources' ), wp_date( 'D j M', $report['from'] ), wp_date( 'D j M', $report['to'] ) )
				: wp_date( 'D j M', $report['to'] ),
			$report['downloads']
		);
		if ( $report['downloads'] ) {
			$subject .= sprintf(
				/* translators: %d: number of people */
				_n( ' by %d person', ' by %d people', count( $report['people'] ), 'adx-gated-resources' ),
				count( $report['people'] )
			);
		}

		if ( self::MODE_TEST === $mode ) {
			$subject = __( '[Test] ', 'adx-gated-resources' ) . $subject;
		} elseif ( self::MODE_RESEND === $mode ) {
			$subject = __( '[Resent] ', 'adx-gated-resources' ) . $subject;
		}

		return $subject;
	}

	/**
	 * [from, to] of the last scheduled report that was actually sent, or
	 * null. Sites that sent reports before this was stored fall back to
	 * the last recorded run, if it was a successful send.
	 */
	public static function last_sent_window() {
		$window = get_option( 'adx_gr_report_last_window' );

		if ( is_array( $window ) && 2 === count( $window ) ) {
			return array_map( 'absint', array_values( $window ) );
		}

		$last_run = get_option( 'adx_gr_report_last_run' );

		if ( is_array( $last_run ) && 'sent' === $last_run['status'] && ! empty( $last_run['from'] ) && ! empty( $last_run['to'] ) ) {
			return array( absint( $last_run['from'] ), absint( $last_run['to'] ) );
		}

		return null;
	}

	private static function record_run( $status, $from, $to, $report = null, $mode = self::MODE_SCHEDULED ) {
		update_option(
			'adx_gr_report_last_run',
			array(
				'time'      => self::now(),
				'status'    => $status,
				'from'      => $from,
				'to'        => $to,
				'downloads' => $report ? $report['downloads'] : 0,
				'people'    => $report ? count( $report['people'] ) : 0,
				'manual'    => self::MODE_DAILY === $mode,
			),
			false
		);
	}

	public function send_daily_report() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'adx-gated-resources' ) );
		}

		check_admin_referer( 'adx_gr_send_daily_report' );

		$status = self::send_report( self::MODE_DAILY );

		wp_safe_redirect(
			add_query_arg(
				array(
					'report'    => 'sent' === $status ? 'daily_sent' : $status,
					'downloads' => self::$last_downloads,
				),
				admin_url( 'admin.php?page=adx-gr-settings' )
			)
		);
		exit;
	}

	public function send_report_now() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'adx-gated-resources' ) );
		}

		check_admin_referer( 'adx_gr_send_report_now' );

		$status = self::send_report( self::MODE_TEST );

		wp_safe_redirect(
			add_query_arg(
				array(
					'report'    => 'sent' === $status ? 'test_sent' : $status,
					'downloads' => self::$last_downloads,
				),
				admin_url( 'admin.php?page=adx-gr-settings' )
			)
		);
		exit;
	}

	public function resend_last_report() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'adx-gated-resources' ) );
		}

		check_admin_referer( 'adx_gr_resend_report' );

		$status = self::send_report( self::MODE_RESEND );

		wp_safe_redirect( add_query_arg( 'report', 'sent' === $status ? 'resent' : $status, admin_url( 'admin.php?page=adx-gr-settings' ) ) );
		exit;
	}

	public function reset_report_links() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'adx-gated-resources' ) );
		}

		check_admin_referer( 'adx_gr_reset_report_links' );

		update_option( 'adx_gr_report_secret', wp_generate_password( 32, false, false ), false );

		wp_safe_redirect( add_query_arg( 'report', 'reset', admin_url( 'admin.php?page=adx-gr-settings' ) ) );
		exit;
	}

	/* ------------------------------------------------------------------
	 * Signed links
	 * --------------------------------------------------------------- */

	private static function get_secret() {
		$secret = (string) get_option( 'adx_gr_report_secret', '' );

		if ( '' === $secret ) {
			$secret = wp_generate_password( 32, false, false );
			update_option( 'adx_gr_report_secret', $secret, false );
		}

		return $secret;
	}

	/**
	 * The key every report link carries. It unlocks the report page for
	 * any dates (receivers filter freely), so it's the same for every
	 * link until "Reset report links" changes the secret behind it.
	 */
	private static function access_key() {
		return substr( hash_hmac( 'sha256', 'report-access', wp_salt( 'adx_gr_report' ) . self::get_secret() ), 0, 32 );
	}

	private static function base_args() {
		return array( self::QUERY_PARAM => self::access_key() );
	}

	/**
	 * Link to an exact window (e.g. 5pm to 5pm), as used in the email.
	 */
	public static function report_url( $from, $to, $csv = false, $query = '' ) {
		$args = self::base_args() + array(
			self::SINCE_PARAM => absint( $from ),
			self::UNTIL_PARAM => absint( $to ),
		);

		if ( $csv ) {
			$args[ self::CSV_PARAM ] = '1';
		}

		if ( '' !== $query ) {
			$args[ self::SEARCH_PARAM ] = $query;
		}

		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * Link to whole days, $from_date to $to_date inclusive (Y-m-d).
	 */
	private static function dates_url( $from_date, $to_date, $csv = false, $query = '' ) {
		$args = self::base_args() + array(
			self::FROM_PARAM => $from_date,
			self::TO_PARAM   => $to_date,
		);

		if ( $csv ) {
			$args[ self::CSV_PARAM ] = '1';
		}

		if ( '' !== $query ) {
			$args[ self::SEARCH_PARAM ] = $query;
		}

		return add_query_arg( $args, home_url( '/' ) );
	}

	/**
	 * A Y-m-d string as midnight that day in the site's timezone, or
	 * null if it isn't a real date.
	 */
	private static function parse_date( $value ) {
		$value = (string) $value;
		$date  = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );

		return ( $date && $date->format( 'Y-m-d' ) === $value ) ? $date : null;
	}

	/**
	 * Works out which window the request asks for. Picked dates (the
	 * filter on the page) win; otherwise the exact window from an email
	 * link; otherwise today so far.
	 *
	 * @return array from, to (timestamps, to exclusive), from_date and
	 *               to_date (Y-m-d, inclusive) for the date pickers, and
	 *               whether the window is whole days.
	 */
	private static function requested_window() {
		$tz    = wp_timezone();
		$today = new DateTimeImmutable( 'today', $tz );

		$from_date = isset( $_GET[ self::FROM_PARAM ] ) ? self::parse_date( sanitize_text_field( wp_unslash( $_GET[ self::FROM_PARAM ] ) ) ) : null;
		$to_date   = isset( $_GET[ self::TO_PARAM ] ) ? self::parse_date( sanitize_text_field( wp_unslash( $_GET[ self::TO_PARAM ] ) ) ) : null;

		if ( $from_date || $to_date ) {
			$from_date = $from_date ? $from_date : $to_date;
			$to_date   = $to_date ? $to_date : $from_date;

			if ( $from_date > $to_date ) {
				list( $from_date, $to_date ) = array( $to_date, $from_date );
			}

			return array(
				'from'       => $from_date->getTimestamp(),
				'to'         => $to_date->modify( '+1 day' )->getTimestamp(),
				'from_date'  => $from_date->format( 'Y-m-d' ),
				'to_date'    => $to_date->format( 'Y-m-d' ),
				'whole_days' => true,
			);
		}

		$since = isset( $_GET[ self::SINCE_PARAM ] ) ? absint( $_GET[ self::SINCE_PARAM ] ) : 0;
		$until = isset( $_GET[ self::UNTIL_PARAM ] ) ? absint( $_GET[ self::UNTIL_PARAM ] ) : 0;

		if ( $since && $until > $since ) {
			return array(
				'from'       => $since,
				'to'         => $until,
				'from_date'  => wp_date( 'Y-m-d', $since ),
				'to_date'    => wp_date( 'Y-m-d', $until - 1 ),
				'whole_days' => false,
			);
		}

		return array(
			'from'       => $today->getTimestamp(),
			'to'         => $today->modify( '+1 day' )->getTimestamp(),
			'from_date'  => $today->format( 'Y-m-d' ),
			'to_date'    => $today->format( 'Y-m-d' ),
			'whole_days' => true,
		);
	}

	/**
	 * Serves the report page (or its CSV) for a link with a valid key.
	 * Runs on init, ahead of the theme, page gating and redirects.
	 */
	public function maybe_render_report() {
		if ( empty( $_GET[ self::QUERY_PARAM ] ) ) {
			return;
		}

		$key = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_PARAM ] ) );

		if ( ! hash_equals( self::access_key(), $key ) ) {
			self::deny();
		}

		$window = self::requested_window();

		// Personal details: never cache, index, or leak the link onward.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );

		$query  = self::requested_query();
		$report = self::build_report( $window['from'], $window['to'], $query ) + array(
			'window' => $window,
			'query'  => $query,
		);

		if ( ! empty( $_GET[ self::CSV_PARAM ] ) ) {
			self::output_csv( $report );
		} else {
			self::render_page( $report );
		}
		exit;
	}

	/**
	 * The search text from the link, trimmed and capped at 100 characters.
	 */
	private static function requested_query() {
		if ( empty( $_GET[ self::SEARCH_PARAM ] ) ) {
			return '';
		}

		return trim( mb_substr( sanitize_text_field( wp_unslash( $_GET[ self::SEARCH_PARAM ] ) ), 0, 100 ) );
	}

	private static function deny() {
		wp_die(
			esc_html__( 'This report link is invalid or has been reset. Please use the link from the latest report email.', 'adx-gated-resources' ),
			esc_html__( 'Report unavailable', 'adx-gated-resources' ),
			array( 'response' => 403 )
		);
	}

	/* ------------------------------------------------------------------
	 * Report data
	 * --------------------------------------------------------------- */

	/**
	 * Gathers every download in [$from, $to) and groups it by person,
	 * since the report exists for following each one up. Each person
	 * gets their latest details, the resources they downloaded (repeat
	 * downloads of one resource folded together), and whether they're
	 * new -- first seen during this report's window.
	 */
	public static function build_report( $from, $to, $query = '' ) {
		$from_mysql = wp_date( 'Y-m-d H:i:s', $from );
		$rows       = ADX_GR_DB::get_leads_between( $from_mysql, wp_date( 'Y-m-d H:i:s', $to ) );
		$fields     = ADX_GR_Form_Fields::get_fields();
		$people     = array();

		// Load every downloaded resource's categories in one go (for the series).
		update_object_term_cache( array_filter( array_map( 'absint', wp_list_pluck( $rows, 'resource_id' ) ) ), ADX_GR_CPT::POST_TYPE );

		foreach ( $rows as $row ) {
			$key = strtolower( $row->email );

			if ( ! isset( $people[ $key ] ) ) {
				$people[ $key ] = array(
					'email'  => $row->email,
					'fields' => array(),
					'items'  => array(),
					'count'  => 0,
					'last'   => '',
					'is_new' => false,
					'total'  => 0,
				);
			}

			$person = &$people[ $key ];

			foreach ( $fields as $field ) {
				$value = self::plain_text( ADX_GR_DB::get_row_field_value( $row, $field['key'] ) );
				if ( '' !== $value ) {
					$person['fields'][ $field['key'] ] = $value;
				}
			}

			$item_key = self::item_key( $row );
			if ( ! isset( $person['items'][ $item_key ] ) ) {
				$person['items'][ $item_key ] = array(
					'title'    => self::plain_text( $row->resource_title ),
					'series'   => self::series_label( $row->resource_id ),
					'count'    => 0,
					'last'     => '',
					'page_url' => '',
				);
			}
			$person['items'][ $item_key ]['count']++;
			$person['items'][ $item_key ]['last'] = $row->created_at;
			if ( $row->page_url ) {
				$person['items'][ $item_key ]['page_url'] = $row->page_url;
			}

			$person['count']++;
			$person['last'] = $row->created_at;
			unset( $person );
		}

		$subscribers = ADX_GR_DB::get_subscribers_by_emails( wp_list_pluck( $people, 'email' ) );
		$new_count   = 0;

		foreach ( $people as $key => &$person ) {
			if ( isset( $subscribers[ $key ] ) ) {
				$subscriber        = $subscribers[ $key ];
				$person['total']  = (int) $subscriber->downloads_count;
				$person['is_new'] = $subscriber->first_seen >= $from_mysql;

				// Fill any gaps (e.g. a remembered visitor's download
				// logged without details) from their subscriber record.
				$saved = $subscriber->field_data ? json_decode( $subscriber->field_data, true ) : array();
				foreach ( (array) $saved as $field_key => $value ) {
					$value = self::plain_text( $value );
					if ( empty( $person['fields'][ $field_key ] ) && '' !== $value ) {
						$person['fields'][ $field_key ] = $value;
					}
				}
			}

			$person['name'] = trim(
				( isset( $person['fields']['first_name'] ) ? $person['fields']['first_name'] : '' ) . ' ' .
				( isset( $person['fields']['last_name'] ) ? $person['fields']['last_name'] : '' )
			);

			$new_count += $person['is_new'] ? 1 : 0;
		}
		unset( $person );

		// Most recent activity first.
		uasort(
			$people,
			function ( $a, $b ) {
				return strcmp( $b['last'], $a['last'] );
			}
		);

		$terms = self::search_terms( $query );

		if ( $terms ) {
			list( $people, $rows ) = self::apply_search( $people, $rows, $terms );

			$new_count = 0;
			foreach ( $people as $person ) {
				$new_count += $person['is_new'] ? 1 : 0;
			}
		}

		return array(
			'from'      => $from,
			'to'        => $to,
			'rows'      => $rows,
			'people'    => $people,
			'fields'    => $fields,
			'downloads' => count( $rows ),
			'new'       => $new_count,
		);
	}

	/**
	 * Identifies one downloaded resource within a person's list: its ID,
	 * or its title when the resource has since been deleted.
	 */
	private static function item_key( $row ) {
		return $row->resource_id ? (int) $row->resource_id : 't:' . $row->resource_title;
	}

	private static function lower( $text ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $text, 'UTF-8' ) : strtolower( (string) $text );
	}

	/**
	 * The words of a search, lowercased. All of them must match.
	 */
	private static function search_terms( $query ) {
		$terms = preg_split( '/\s+/', self::lower( trim( (string) $query ) ), -1, PREG_SPLIT_NO_EMPTY );

		return array_slice( is_array( $terms ) ? $terms : array(), 0, 8 );
	}

	private static function search_text( array $parts ) {
		return self::lower( implode( ' ', array_filter( $parts ) ) );
	}

	private static function matches_terms( $haystack, array $terms ) {
		foreach ( $terms as $term ) {
			if ( false === strpos( $haystack, $term ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Narrows people and rows to a search. A person matches on their
	 * name, email and company; a download matches on its resource title
	 * and series. Every word has to be found, either all on the person
	 * (then all of their downloads are kept) or spread across the person
	 * and a download (then only the matching downloads are kept, e.g.
	 * "ann window" finds Ann's window downloads).
	 *
	 * @return array [ people, rows ]
	 */
	private static function apply_search( array $people, array $rows, array $terms ) {
		$keep = array();

		foreach ( $people as $key => $person ) {
			$person_text = self::search_text(
				array(
					$person['name'],
					$person['email'],
					isset( $person['fields']['company'] ) ? $person['fields']['company'] : '',
				)
			);

			if ( self::matches_terms( $person_text, $terms ) ) {
				$keep[ $key ] = true;
				continue;
			}

			$matching = array();
			foreach ( $person['items'] as $item_key => $item ) {
				if ( self::matches_terms( $person_text . ' ' . self::search_text( array( $item['title'], $item['series'] ) ), $terms ) ) {
					$matching[ $item_key ] = true;
				}
			}

			if ( $matching ) {
				$keep[ $key ] = $matching;
			}
		}

		$kept_people = array();

		foreach ( $people as $key => $person ) {
			if ( ! isset( $keep[ $key ] ) ) {
				continue;
			}

			if ( true !== $keep[ $key ] ) {
				$person['items'] = array_intersect_key( $person['items'], $keep[ $key ] );
				$person['count'] = array_sum( wp_list_pluck( $person['items'], 'count' ) );
				$person['last']  = max( wp_list_pluck( $person['items'], 'last' ) );
			}

			$kept_people[ $key ] = $person;
		}

		$kept_rows = array();

		foreach ( $rows as $row ) {
			$key = strtolower( $row->email );

			if ( isset( $keep[ $key ] ) && ( true === $keep[ $key ] || isset( $keep[ $key ][ self::item_key( $row ) ] ) ) ) {
				$kept_rows[] = $row;
			}
		}

		return array( $kept_people, $kept_rows );
	}

	/* ------------------------------------------------------------------
	 * Output helpers
	 * --------------------------------------------------------------- */

	/**
	 * Form values and titles as plain text: entities decoded first so
	 * an encoded tag (&lt;b&gt;) is caught too, then every tag removed.
	 * Output is still escaped as usual; this just keeps markup someone
	 * typed into the public form from showing up in the report.
	 */
	private static function plain_text( $value ) {
		return trim( wp_strip_all_tags( html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' ) ) );
	}

	private static function local_timestamp( $mysql ) {
		$date = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $mysql, wp_timezone() );
		return $date ? $date->getTimestamp() : 0;
	}

	private static function format_time( $mysql, $format = 'D j M, g:ia' ) {
		$ts = self::local_timestamp( $mysql );
		return $ts ? wp_date( $format, $ts ) : $mysql;
	}

	/**
	 * Sunday's catch-up report (Friday 5pm to Sunday 5pm, with Saturday
	 * off): any report ending on a Sunday that covers more than a day.
	 */
	private static function is_weekend_report( array $report ) {
		return '0' === wp_date( 'w', $report['to'] - 1 ) && ( $report['to'] - $report['from'] ) > 30 * HOUR_IN_SECONDS;
	}

	private static function report_title( array $report ) {
		return self::is_weekend_report( $report )
			? __( 'Weekend downloads report', 'adx-gated-resources' )
			: __( 'Downloads report', 'adx-gated-resources' );
	}

	private static function period_label( array $report ) {
		if ( ! empty( $report['window']['whole_days'] ) ) {
			$from = wp_date( 'D j M Y', $report['from'] );
			$to   = wp_date( 'D j M Y', $report['to'] - 1 );

			/* translators: 1: start date, 2: end date */
			return $from === $to ? $from : sprintf( __( '%1$s to %2$s', 'adx-gated-resources' ), $from, $to );
		}

		return sprintf(
			/* translators: 1: start date/time, 2: end date/time */
			__( '%1$s to %2$s', 'adx-gated-resources' ),
			wp_date( 'D j M Y, g:ia', $report['from'] ),
			wp_date( 'D j M Y, g:ia T', $report['to'] )
		);
	}

	private static function display_value( array $field, $value ) {
		if ( 'checkbox' === $field['type'] ) {
			return '1' === (string) $value ? __( 'Yes', 'adx-gated-resources' ) : __( 'No', 'adx-gated-resources' );
		}
		return (string) $value;
	}

	/**
	 * Every configured field except first/last name (shown as the
	 * person's name), as label => value, skipping blanks.
	 */
	private static function person_details( array $person, array $fields ) {
		$details = array();

		foreach ( $fields as $field ) {
			if ( in_array( $field['key'], array( 'first_name', 'last_name' ), true ) || ! isset( $person['fields'][ $field['key'] ] ) ) {
				continue;
			}
			$details[ $field['label'] ] = self::display_value( $field, $person['fields'][ $field['key'] ] );
		}

		return $details;
	}

	private static function mailto( array $person ) {
		$first   = reset( $person['items'] );
		$subject = $first
			? sprintf( /* translators: %s: resource title */ __( 'Your %s download', 'adx-gated-resources' ), $first['title'] )
			: __( 'Following up on your download', 'adx-gated-resources' );

		return 'mailto:' . $person['email'] . '?subject=' . rawurlencode( $subject );
	}

	private static function page_path( $url ) {
		$path = wp_parse_url( $url, PHP_URL_PATH );
		return $path ? $path : $url;
	}

	private static function summary_line( array $report ) {
		$people = count( $report['people'] );

		return sprintf(
			/* translators: 1: downloads, 2: people, 3: new subscribers */
			__( '%1$s · %2$s · %3$s', 'adx-gated-resources' ),
			sprintf( _n( '%d download', '%d downloads', $report['downloads'], 'adx-gated-resources' ), $report['downloads'] ),
			sprintf( _n( '%d person', '%d people', $people, 'adx-gated-resources' ), $people ),
			sprintf( _n( '%d new subscriber', '%d new subscribers', $report['new'], 'adx-gated-resources' ), $report['new'] )
		);
	}

	/* ------------------------------------------------------------------
	 * CSV
	 * --------------------------------------------------------------- */

	/**
	 * One row per download. Starts with a UTF-8 BOM so Excel shows
	 * accented names correctly, and neutralises values that Excel would
	 * otherwise run as formulas (all of this text came from a public form).
	 */
	private static function output_csv( array $report ) {
		header( 'Content-Type: text/csv; charset=utf-8' );
		$from_date = wp_date( 'Y-m-d', $report['from'] );
		$to_date   = wp_date( 'Y-m-d', $report['to'] - 1 );
		$filename  = 'adx-downloads-' . $from_date . ( $from_date === $to_date ? '' : '-to-' . $to_date ) . '.csv';
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" );

		$headers = array( __( 'Date', 'adx-gated-resources' ), __( 'Email', 'adx-gated-resources' ) );
		foreach ( $report['fields'] as $field ) {
			$headers[] = $field['label'];
		}
		$headers = array_merge(
			$headers,
			array(
				__( 'Resource', 'adx-gated-resources' ),
				__( 'Series', 'adx-gated-resources' ),
				__( 'Downloaded from', 'adx-gated-resources' ),
				__( 'New subscriber', 'adx-gated-resources' ),
			)
		);
		fputcsv( $out, $headers );

		foreach ( $report['rows'] as $row ) {
			$key    = strtolower( $row->email );
			$person = isset( $report['people'][ $key ] ) ? $report['people'][ $key ] : null;

			$line = array( self::format_time( $row->created_at, 'Y-m-d H:i' ), $row->email );
			foreach ( $report['fields'] as $field ) {
				$value = self::plain_text( ADX_GR_DB::get_row_field_value( $row, $field['key'] ) );
				if ( '' === $value && $person && isset( $person['fields'][ $field['key'] ] ) ) {
					$value = $person['fields'][ $field['key'] ];
				}
				$line[] = self::display_value( $field, $value );
			}
			$line = array_merge(
				$line,
				array(
					self::plain_text( $row->resource_title ),
					self::series_label( $row->resource_id ),
					$row->page_url,
					$person && $person['is_new'] ? __( 'Yes', 'adx-gated-resources' ) : __( 'No', 'adx-gated-resources' ),
				)
			);

			fputcsv( $out, array_map( array( __CLASS__, 'csv_cell' ), $line ) );
		}

		fclose( $out );
	}

	private static function csv_cell( $value ) {
		$value = (string) $value;
		return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
	}

	/* ------------------------------------------------------------------
	 * Email
	 * --------------------------------------------------------------- */

	private static function render_email( array $report ) {
		$url    = self::report_url( $report['from'], $report['to'] );
		$people = array_slice( $report['people'], 0, self::EMAIL_PEOPLE_LIMIT );
		$more   = count( $report['people'] ) - count( $people );
		$font   = '-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Arial,sans-serif';

		ob_start();
		?>
<!DOCTYPE html>
<html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>
<body style="margin:0;padding:0;background:#f4f4f2;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f2;">
<tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;background:#ffffff;border-radius:8px;font-family:<?php echo esc_attr( $font ); ?>;color:#1a1a1a;">
	<tr><td style="padding:28px 28px 8px;">
		<p style="margin:0 0 4px;font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#6b6b6b;"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
		<h1 style="margin:0 0 6px;font-size:22px;line-height:1.3;"><?php echo esc_html( self::report_title( $report ) ); ?></h1>
		<p style="margin:0 0 16px;font-size:14px;color:#6b6b6b;"><?php echo esc_html( self::period_label( $report ) ); ?></p>
		<?php if ( ! empty( $report['note'] ) ) : ?>
		<p style="margin:0 0 16px;padding:10px 12px;background:#fff8e1;border-radius:6px;font-size:13px;color:#6b5300;"><?php echo esc_html( $report['note'] ); ?></p>
		<?php endif; ?>
		<p style="margin:0 0 20px;font-size:15px;font-weight:600;"><?php echo esc_html( $report['downloads'] ? self::summary_line( $report ) : __( 'No downloads in this period.', 'adx-gated-resources' ) ); ?></p>
		<?php if ( $report['downloads'] ) : ?>
		<a href="<?php echo esc_url( $url ); ?>" style="display:inline-block;background:#1a1a1a;color:#ffffff;text-decoration:none;font-size:15px;font-weight:600;padding:12px 22px;border-radius:6px;"><?php esc_html_e( 'View full report & download CSV', 'adx-gated-resources' ); ?></a>
		<?php endif; ?>
	</td></tr>
	<?php if ( $people ) : ?>
	<tr><td style="padding:16px 28px 8px;">
		<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;">
			<?php foreach ( $people as $person ) : ?>
			<tr><td style="padding:12px 0;border-top:1px solid #e6e6e3;">
				<strong><?php echo esc_html( '' !== $person['name'] ? $person['name'] : $person['email'] ); ?></strong>
				<?php if ( $person['is_new'] ) : ?>
					<span style="display:inline-block;margin-left:6px;padding:1px 7px;border-radius:10px;background:#e3f1e6;color:#1e6b34;font-size:11px;font-weight:600;"><?php esc_html_e( 'NEW', 'adx-gated-resources' ); ?></span>
				<?php endif; ?>
				<?php if ( ! empty( $person['fields']['company'] ) ) : ?>
					<span style="color:#6b6b6b;"> · <?php echo esc_html( $person['fields']['company'] ); ?></span>
				<?php endif; ?>
				<br><a href="<?php echo esc_url( self::mailto( $person ) ); ?>" style="color:#1a1a1a;"><?php echo esc_html( $person['email'] ); ?></a>
				<br><span style="color:#6b6b6b;"><?php echo esc_html( implode( ', ', array_map( array( __CLASS__, 'item_label' ), $person['items'] ) ) ); ?></span>
			</td></tr>
			<?php endforeach; ?>
		</table>
		<?php if ( $more > 0 ) : ?>
			<p style="margin:8px 0 0;font-size:14px;"><a href="<?php echo esc_url( $url ); ?>" style="color:#1a1a1a;"><?php echo esc_html( sprintf( _n( '+ %d more person in the full report', '+ %d more people in the full report', $more, 'adx-gated-resources' ), $more ) ); ?></a></p>
		<?php endif; ?>
	</td></tr>
	<?php endif; ?>
	<tr><td style="padding:20px 28px 28px;font-size:12px;line-height:1.5;color:#8a8a8a;">
		<?php esc_html_e( 'The report link opens without logging in and shows the full download history, so please keep it within the team.', 'adx-gated-resources' ); ?>
		<?php esc_html_e( 'Sent by ADX Gated Resources.', 'adx-gated-resources' ); ?>
	</td></tr>
</table>
</td></tr>
</table>
</body></html>
		<?php
		return ob_get_clean();
	}

	/**
	 * "Door Frame with Window (1000 series) ×2", for the email's list.
	 */
	private static function item_label( array $item ) {
		$label = '' !== $item['series'] ? $item['title'] . ' (' . $item['series'] . ')' : $item['title'];

		return $item['count'] > 1 ? $label . ' ×' . $item['count'] : $label;
	}

	/**
	 * The series of a downloaded resource as plain text ("1000 series", or
	 * several comma-separated), or an empty string if it has none or has
	 * since been deleted.
	 */
	private static function series_label( $resource_id ) {
		return self::plain_text( implode( ', ', ADX_GR_CPT::get_series_names( $resource_id ) ) );
	}

	/* ------------------------------------------------------------------
	 * Report page
	 * --------------------------------------------------------------- */

	/**
	 * Quick date ranges for the filter, as label => [from, to] (Y-m-d,
	 * inclusive), relative to today in the site's timezone.
	 */
	private static function date_presets() {
		$today = new DateTimeImmutable( 'today', wp_timezone() );
		$ymd   = function ( DateTimeImmutable $d ) {
			return $d->format( 'Y-m-d' );
		};

		return array(
			__( 'Today', 'adx-gated-resources' )        => array( $ymd( $today ), $ymd( $today ) ),
			__( 'Yesterday', 'adx-gated-resources' )    => array( $ymd( $today->modify( '-1 day' ) ), $ymd( $today->modify( '-1 day' ) ) ),
			__( 'Last 7 days', 'adx-gated-resources' )  => array( $ymd( $today->modify( '-6 days' ) ), $ymd( $today ) ),
			__( 'Last 30 days', 'adx-gated-resources' ) => array( $ymd( $today->modify( '-29 days' ) ), $ymd( $today ) ),
			__( 'This month', 'adx-gated-resources' )   => array( $ymd( $today->modify( 'first day of this month' ) ), $ymd( $today ) ),
			__( 'Last month', 'adx-gated-resources' )   => array( $ymd( $today->modify( 'first day of last month' ) ), $ymd( $today->modify( 'last day of last month' ) ) ),
		);
	}

	private static function render_page( array $report ) {
		$window  = $report['window'];
		$query   = $report['query'];
		$csv_url = $window['whole_days']
			? self::dates_url( $window['from_date'], $window['to_date'], true, $query )
			: self::report_url( $report['from'], $report['to'], true, $query );
		// The same period without the search, for "Clear search".
		$clear_url = $window['whole_days']
			? self::dates_url( $window['from_date'], $window['to_date'] )
			: self::report_url( $report['from'], $report['to'] );
		$today   = wp_date( 'Y-m-d' );
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<meta name="referrer" content="no-referrer">
<title><?php echo esc_html( sprintf( /* translators: %s: date range */ __( 'Downloads report – %s', 'adx-gated-resources' ), self::period_label( $report ) ) ); ?></title>
<style>
	:root{--ink:#1a1a1a;--muted:#6b6b6b;--line:#e6e6e3;--bg:#f4f4f2;--card:#fff;--new-bg:#e3f1e6;--new-ink:#1e6b34}
	*{box-sizing:border-box}
	body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif}
	.wrap{max-width:960px;margin:0 auto;padding:32px 16px 48px}
	.eyebrow{margin:0;font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:var(--muted)}
	h1{margin:4px 0 4px;font-size:26px;line-height:1.25}
	.period{margin:0;color:var(--muted)}
	.bar{display:flex;flex-wrap:wrap;gap:12px 24px;align-items:center;justify-content:space-between;margin:24px 0;padding:16px 20px;background:var(--card);border-radius:8px}
	.stats{display:flex;flex-wrap:wrap;gap:8px 28px}
	.stat b{display:block;font-size:24px;line-height:1.1}
	.stat span{font-size:13px;color:var(--muted)}
	.btn{display:inline-block;background:var(--ink);color:#fff;text-decoration:none;font-weight:600;padding:11px 20px;border-radius:6px}
	.btn:hover{opacity:.88}
	.person{background:var(--card);border-radius:8px;padding:20px;margin:0 0 12px}
	.person-head{display:flex;flex-wrap:wrap;gap:4px 12px;align-items:baseline;justify-content:space-between}
	.name{margin:0;font-size:18px}
	.badge{display:inline-block;margin-left:6px;padding:1px 8px;border-radius:10px;background:var(--new-bg);color:var(--new-ink);font-size:11px;font-weight:700;vertical-align:middle;letter-spacing:.04em}
	.lifetime{font-size:13px;color:var(--muted)}
	.email{display:inline-block;margin-top:2px;color:var(--ink);word-break:break-all}
	.details{display:flex;flex-wrap:wrap;gap:4px 20px;margin:10px 0 0;padding:0;list-style:none;font-size:14px}
	.details span{color:var(--muted)}
	.items{margin:14px 0 0;padding:0;list-style:none;border-top:1px solid var(--line)}
	.items li{display:flex;flex-wrap:wrap;gap:2px 16px;justify-content:space-between;padding:9px 0;border-bottom:1px solid var(--line);font-size:14px}
	.items .meta{color:var(--muted);font-size:13px}
	.items a{color:var(--muted)}
	.series-tag{display:inline-block;margin-left:6px;padding:1px 9px;border-radius:999px;background:var(--new-bg);color:var(--new-ink);font-size:12px;font-weight:600;vertical-align:1px}
	.item-count{margin-left:4px;color:var(--muted);font-size:13px}
	.reply{display:inline-block;margin-top:12px;font-size:14px;font-weight:600;color:var(--ink)}
	.empty{background:var(--card);border-radius:8px;padding:40px 20px;text-align:center;color:var(--muted)}
	.foot{margin-top:28px;font-size:12px;color:var(--muted)}
	.filter{margin:24px 0 0;padding:16px 20px;background:var(--card);border-radius:8px}
	.presets{display:flex;flex-wrap:wrap;gap:8px;margin:0 0 14px;padding:0;list-style:none}
	.presets a{display:inline-block;padding:6px 12px;border:1px solid var(--line);border-radius:999px;color:var(--ink);text-decoration:none;font-size:14px}
	.presets a:hover{border-color:var(--ink)}
	.presets a[aria-current]{background:var(--ink);border-color:var(--ink);color:#fff}
	.range{display:flex;flex-wrap:wrap;gap:10px 14px;align-items:flex-end;margin:0}
	.range label{display:flex;flex-direction:column;gap:4px;font-size:13px;color:var(--muted)}
	.range input{font:inherit;color:var(--ink);padding:8px 10px;border:1px solid #cfcfcb;border-radius:6px;background:#fff;min-width:0}
	.range button{font:inherit;font-weight:600;padding:9px 18px;border:1px solid var(--ink);border-radius:6px;background:#fff;color:var(--ink);cursor:pointer}
	.range button:hover{background:var(--ink);color:#fff}
	.bar{margin-top:12px}
	.search{display:flex;gap:10px;margin:0 0 16px}
	.search-field{position:relative;flex:1;min-width:0}
	.search input[type=search]{display:block;width:100%;font:inherit;color:var(--ink);padding:10px 40px 10px 14px;border:1px solid #cfcfcb;border-radius:6px;background:#fff;-webkit-appearance:none;appearance:none}
	.search input[type=search]:focus{outline:2px solid var(--ink);outline-offset:-1px;border-color:var(--ink)}
	.search input[type=search]::-webkit-search-cancel-button,.search input[type=search]::-webkit-search-decoration{-webkit-appearance:none;display:none}
	.search-clear{position:absolute;top:50%;right:8px;transform:translateY(-50%);display:flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:50%;background:var(--line);color:var(--muted);text-decoration:none;font-size:16px;line-height:1}
	.search-clear:hover{background:var(--ink);color:#fff}
	.search button{font:inherit;font-weight:600;padding:10px 20px;border:0;border-radius:6px;background:var(--ink);color:#fff;cursor:pointer}
	.search button:hover{opacity:.88}
	.searching{margin:12px 0 0;font-size:14px;color:var(--muted)}
	.searching a{color:var(--ink)}
</style>
</head>
<body>
<div class="wrap">
	<p class="eyebrow"><?php echo esc_html( get_bloginfo( 'name' ) ); ?></p>
	<h1><?php esc_html_e( 'Downloads report', 'adx-gated-resources' ); ?></h1>
	<p class="period"><?php echo esc_html( self::period_label( $report ) ); ?></p>

	<div class="filter">
		<form class="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>" role="search">
			<input type="hidden" name="<?php echo esc_attr( self::QUERY_PARAM ); ?>" value="<?php echo esc_attr( self::access_key() ); ?>" />
			<?php // Searching keeps the period being viewed, including an email link's exact 5pm-to-5pm window. ?>
			<?php if ( $window['whole_days'] ) : ?>
				<input type="hidden" name="<?php echo esc_attr( self::FROM_PARAM ); ?>" value="<?php echo esc_attr( $window['from_date'] ); ?>" />
				<input type="hidden" name="<?php echo esc_attr( self::TO_PARAM ); ?>" value="<?php echo esc_attr( $window['to_date'] ); ?>" />
			<?php else : ?>
				<input type="hidden" name="<?php echo esc_attr( self::SINCE_PARAM ); ?>" value="<?php echo (int) $report['from']; ?>" />
				<input type="hidden" name="<?php echo esc_attr( self::UNTIL_PARAM ); ?>" value="<?php echo (int) $report['to']; ?>" />
			<?php endif; ?>
			<span class="search-field">
				<input type="search" name="<?php echo esc_attr( self::SEARCH_PARAM ); ?>" value="<?php echo esc_attr( $query ); ?>" maxlength="100" placeholder="<?php esc_attr_e( 'Search by name, email, company or resource…', 'adx-gated-resources' ); ?>" aria-label="<?php esc_attr_e( 'Search the report', 'adx-gated-resources' ); ?>" />
				<?php if ( '' !== $query ) : ?>
					<a class="search-clear" href="<?php echo esc_url( $clear_url ); ?>" aria-label="<?php esc_attr_e( 'Clear search', 'adx-gated-resources' ); ?>" title="<?php esc_attr_e( 'Clear search', 'adx-gated-resources' ); ?>">&times;</a>
				<?php endif; ?>
			</span>
			<button type="submit"><?php esc_html_e( 'Search', 'adx-gated-resources' ); ?></button>
		</form>
		<ul class="presets">
			<?php foreach ( self::date_presets() as $label => $range ) : ?>
				<?php $current = $window['whole_days'] && $range[0] === $window['from_date'] && $range[1] === $window['to_date']; ?>
				<li><a href="<?php echo esc_url( self::dates_url( $range[0], $range[1], false, $query ) ); ?>"<?php echo $current ? ' aria-current="true"' : ''; ?>><?php echo esc_html( $label ); ?></a></li>
			<?php endforeach; ?>
		</ul>
		<form class="range" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
			<input type="hidden" name="<?php echo esc_attr( self::QUERY_PARAM ); ?>" value="<?php echo esc_attr( self::access_key() ); ?>" />
			<?php if ( '' !== $query ) : ?>
				<input type="hidden" name="<?php echo esc_attr( self::SEARCH_PARAM ); ?>" value="<?php echo esc_attr( $query ); ?>" />
			<?php endif; ?>
			<label><?php esc_html_e( 'From', 'adx-gated-resources' ); ?>
				<input type="date" name="<?php echo esc_attr( self::FROM_PARAM ); ?>" value="<?php echo esc_attr( $window['from_date'] ); ?>" max="<?php echo esc_attr( $today ); ?>" required />
			</label>
			<label><?php esc_html_e( 'To', 'adx-gated-resources' ); ?>
				<input type="date" name="<?php echo esc_attr( self::TO_PARAM ); ?>" value="<?php echo esc_attr( $window['to_date'] ); ?>" max="<?php echo esc_attr( $today ); ?>" required />
			</label>
			<button type="submit"><?php esc_html_e( 'Show', 'adx-gated-resources' ); ?></button>
		</form>
		<?php if ( '' !== $query ) : ?>
			<p class="searching">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: the search text */
						__( 'Showing results for “%s”.', 'adx-gated-resources' ),
						$query
					)
				);
				?>
				<a href="<?php echo esc_url( $clear_url ); ?>"><?php esc_html_e( 'Clear search', 'adx-gated-resources' ); ?></a>
			</p>
		<?php endif; ?>
	</div>

	<div class="bar">
		<div class="stats">
			<div class="stat"><b><?php echo (int) $report['downloads']; ?></b><span><?php esc_html_e( 'Downloads', 'adx-gated-resources' ); ?></span></div>
			<div class="stat"><b><?php echo (int) count( $report['people'] ); ?></b><span><?php esc_html_e( 'People', 'adx-gated-resources' ); ?></span></div>
			<div class="stat"><b><?php echo (int) $report['new']; ?></b><span><?php esc_html_e( 'New subscribers', 'adx-gated-resources' ); ?></span></div>
		</div>
		<?php if ( $report['downloads'] ) : ?>
			<a class="btn" href="<?php echo esc_url( $csv_url ); ?>"><?php esc_html_e( 'Download CSV', 'adx-gated-resources' ); ?></a>
		<?php endif; ?>
	</div>

	<?php if ( ! $report['people'] ) : ?>
		<div class="empty">
			<?php
			echo '' !== $query
				? esc_html__( 'No downloads match your search in this period.', 'adx-gated-resources' )
				: esc_html__( 'No downloads in this period.', 'adx-gated-resources' );
			?>
		</div>
	<?php endif; ?>

	<?php foreach ( $report['people'] as $person ) : ?>
		<section class="person">
			<div class="person-head">
				<h2 class="name">
					<?php echo esc_html( '' !== $person['name'] ? $person['name'] : $person['email'] ); ?>
					<?php if ( $person['is_new'] ) : ?><span class="badge"><?php esc_html_e( 'NEW', 'adx-gated-resources' ); ?></span><?php endif; ?>
				</h2>
				<?php if ( $person['total'] ) : ?>
					<span class="lifetime"><?php echo esc_html( sprintf( _n( '%d download all-time', '%d downloads all-time', $person['total'], 'adx-gated-resources' ), $person['total'] ) ); ?></span>
				<?php endif; ?>
			</div>
			<a class="email" href="<?php echo esc_url( self::mailto( $person ) ); ?>"><?php echo esc_html( $person['email'] ); ?></a>

			<?php $details = self::person_details( $person, $report['fields'] ); ?>
			<?php if ( $details ) : ?>
				<ul class="details">
					<?php foreach ( $details as $label => $value ) : ?>
						<li><span><?php echo esc_html( $label ); ?>:</span> <?php echo esc_html( $value ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<ul class="items">
				<?php foreach ( $person['items'] as $item ) : ?>
					<li>
						<span>
							<?php echo esc_html( $item['title'] ); ?>
							<?php if ( '' !== $item['series'] ) : ?>
								<span class="series-tag"><?php echo esc_html( $item['series'] ); ?></span>
							<?php endif; ?>
							<?php if ( $item['count'] > 1 ) : ?>
								<span class="item-count">×<?php echo (int) $item['count']; ?></span>
							<?php endif; ?>
						</span>
						<span class="meta">
							<?php echo esc_html( self::format_time( $item['last'] ) ); ?>
							<?php if ( $item['page_url'] ) : ?>
								· <?php esc_html_e( 'from', 'adx-gated-resources' ); ?> <a href="<?php echo esc_url( $item['page_url'] ); ?>" rel="noreferrer" target="_blank"><?php echo esc_html( self::page_path( $item['page_url'] ) ); ?></a>
							<?php endif; ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>

			<a class="reply" href="<?php echo esc_url( self::mailto( $person ) ); ?>"><?php esc_html_e( 'Email this person →', 'adx-gated-resources' ); ?></a>
		</section>
	<?php endforeach; ?>

	<p class="foot"><?php esc_html_e( 'Anyone with this link can view the full download history without logging in, so please keep it within the team. Times are shown in the website\'s timezone.', 'adx-gated-resources' ); ?></p>
</div>
</body>
</html>
		<?php
	}

	/* ------------------------------------------------------------------
	 * Admin (rendered inside the Settings screen)
	 * --------------------------------------------------------------- */

	public static function render_admin_notice() {
		if ( empty( $_GET['report'] ) ) {
			return;
		}

		$downloads = isset( $_GET['downloads'] ) ? absint( $_GET['downloads'] ) : 0;
		$messages  = array(
			'test_sent'     => array(
				$downloads ? 'success' : 'warning',
				$downloads
					? sprintf(
						/* translators: %d: number of downloads */
						_n( 'Test report sent to the "Notify by email" addresses with %d download from the last 24 hours. Real reports are not affected.', 'Test report sent to the "Notify by email" addresses with %d downloads from the last 24 hours. Real reports are not affected.', $downloads, 'adx-gated-resources' ),
						$downloads
					)
					: __( 'Test report sent, but there were no downloads in the last 24 hours, so it is empty. Download a resource from the library first, then send the test again.', 'adx-gated-resources' ),
			),
			'daily_sent'    => array(
				'success',
				sprintf(
					/* translators: %d: number of downloads */
					_n( "Today's report was sent to the \"Notify by email\" addresses with %d download.", "Today's report was sent to the \"Notify by email\" addresses with %d downloads.", $downloads, 'adx-gated-resources' ),
					$downloads
				),
			),
			'resent'        => array( 'success', __( 'Last report resent to the "Notify by email" addresses.', 'adx-gated-resources' ) ),
			'no_previous'   => array( 'error', __( 'Nothing to resend yet: no report has been sent.', 'adx-gated-resources' ) ),
			'failed'        => array( 'error', __( 'The report could not be sent. See the mail error under "Notify by email" below.', 'adx-gated-resources' ) ),
			'no_recipients' => array( 'error', __( 'No report sent: add at least one address under "Notify by email" first.', 'adx-gated-resources' ) ),
			'reset'         => array( 'success', __( 'Report links reset. Links in earlier report emails no longer work.', 'adx-gated-resources' ) ),
			'cron_key_reset' => array( 'success', __( 'New scheduler URL generated. The old one no longer works, so send the new one to your hosting provider.', 'adx-gated-resources' ) ),
		);
		$key = sanitize_key( wp_unslash( $_GET['report'] ) );

		if ( isset( $messages[ $key ] ) ) {
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( $messages[ $key ][0] ), esc_html( $messages[ $key ][1] ) );
		}
	}

	public static function render_settings_row() {
		$enabled    = self::is_enabled();
		$skip_empty = get_option( 'adx_gr_report_skip_empty', '1' );
		$next       = wp_next_scheduled( self::CRON_HOOK );
		$last_run   = get_option( 'adx_gr_report_last_run' );
		$last_sent  = self::last_sent_window();
		$tz_string  = get_option( 'timezone_string' );
		$format     = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$statuses   = array(
			'sent'          => __( 'sent', 'adx-gated-resources' ),
			'failed'        => __( 'failed to send', 'adx-gated-resources' ),
			'empty'         => __( 'skipped (no downloads)', 'adx-gated-resources' ),
			'no_recipients' => __( 'not sent (no "Notify by email" addresses)', 'adx-gated-resources' ),
		);
		?>
		<tr>
			<th scope="row"><?php esc_html_e( 'Daily download report', 'adx-gated-resources' ); ?></th>
			<td>
				<label style="display:block;margin-bottom:8px;">
					<input type="checkbox" name="adx_gr_report_enabled" value="1" <?php checked( $enabled ); ?> />
					<?php esc_html_e( 'Email a report of downloads to the "Notify by email" addresses on the days below', 'adx-gated-resources' ); ?>
				</label>
				<fieldset style="margin-bottom:8px;">
					<legend class="screen-reader-text"><?php esc_html_e( 'Send on', 'adx-gated-resources' ); ?></legend>
					<?php esc_html_e( 'Send on', 'adx-gated-resources' ); ?>
					<?php
					$days = self::get_days();
					// Week shown Monday first; values are PHP 'w' numbers (0 = Sunday).
					foreach ( array( 1, 2, 3, 4, 5, 6, 0 ) as $day ) :
						?>
						<label style="margin-left:10px;white-space:nowrap;">
							<input type="checkbox" name="adx_gr_report_days[]" value="<?php echo esc_attr( $day ); ?>" <?php checked( in_array( $day, $days, true ) ); ?> />
							<?php echo esc_html( $GLOBALS['wp_locale']->get_weekday_abbrev( $GLOBALS['wp_locale']->get_weekday( $day ) ) ); ?>
						</label>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'Downloads on days without an email roll into the next report, e.g. with Saturday off, Sunday\'s "Weekend downloads report" covers Friday 5pm to Sunday 5pm.', 'adx-gated-resources' ); ?></p>
				</fieldset>
				<label style="display:block;margin-bottom:8px;">
					<?php esc_html_e( 'Send at', 'adx-gated-resources' ); ?>
					<input type="time" name="adx_gr_report_time" value="<?php echo esc_attr( self::get_time() ); ?>" />
					<?php
					/* translators: %s: timezone name */
					echo esc_html( sprintf( __( '(%s time)', 'adx-gated-resources' ), wp_timezone_string() ) );
					?>
				</label>
				<label style="display:block;margin-bottom:8px;">
					<input type="checkbox" name="adx_gr_report_skip_empty" value="1" <?php checked( $skip_empty, '1' ); ?> />
					<?php esc_html_e( "Don't send on days with no downloads", 'adx-gated-resources' ); ?>
				</label>
				<p class="description">
					<?php esc_html_e( 'Each report covers every download since the previous report was sent, and links to a report page (no login needed) where receivers can filter by date and download a CSV.', 'adx-gated-resources' ); ?>
				</p>
				<?php if ( ! $tz_string ) : ?>
					<div class="notice notice-warning inline"><p>
						<?php
						printf(
							/* translators: %s: link to General Settings */
							esc_html__( 'The site timezone is a fixed UTC offset, so the send time won\'t follow daylight saving. Pick a city (e.g. Sydney) under %s.', 'adx-gated-resources' ),
							'<a href="' . esc_url( admin_url( 'options-general.php' ) ) . '">' . esc_html__( 'Settings > General', 'adx-gated-resources' ) . '</a>'
						);
						?>
					</p></div>
				<?php endif; ?>
				<?php if ( $enabled && $next ) : ?>
					<p class="description">
						<?php
						/* translators: %s: date/time */
						echo esc_html( sprintf( __( 'Next report: %s.', 'adx-gated-resources' ), wp_date( $format, $next ) ) );
						?>
					</p>
				<?php endif; ?>
				<?php if ( is_array( $last_run ) && ! empty( $last_run['time'] ) ) : ?>
					<p class="description">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: date/time, 2: status, 3: download count */
								__( 'Last report: %1$s, %2$s (%3$d downloads).', 'adx-gated-resources' ),
								wp_date( $format, $last_run['time'] ),
								( isset( $statuses[ $last_run['status'] ] ) ? $statuses[ $last_run['status'] ] : $last_run['status'] )
									. ( empty( $last_run['manual'] ) ? '' : ' ' . __( 'manually', 'adx-gated-resources' ) ),
								(int) $last_run['downloads']
							)
						);
						?>
					</p>
				<?php endif; ?>

				<?php
				$daily_from = self::daily_window_start();
				$recipients = ADX_GR_Ajax::get_notification_recipients();
				?>
				<div style="margin:16px 0 12px;padding:14px 16px;border:1px solid #c3c4c7;border-left:4px solid #2271b1;background:#fff;max-width:720px;">
					<strong style="display:block;margin-bottom:4px;"><?php esc_html_e( "End-of-day report (manual)", 'adx-gated-resources' ); ?></strong>
					<p class="description" style="margin:0 0 10px;">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: start date/time */
								__( 'Emails the real report to the "Notify by email" addresses, covering all downloads from %s up to the moment you click. Use it at the end of each day; the next one carries on from there, so nothing is missed or repeated.', 'adx-gated-resources' ),
								wp_date( 'D j M, g:i a', $daily_from )
							)
						);
						?>
					</p>
					<?php if ( $recipients ) : ?>
						<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=adx_gr_send_daily_report' ), 'adx_gr_send_daily_report' ) ); ?>" onclick="return confirm('<?php echo esc_js( sprintf( /* translators: 1: start date/time, 2: email addresses */ __( 'Send the report of all downloads since %1$s to: %2$s?', 'adx-gated-resources' ), wp_date( 'D j M, g:i a', $daily_from ), implode( ', ', $recipients ) ) ); ?>');"><?php esc_html_e( "Send today's report", 'adx-gated-resources' ); ?></a>
					<?php else : ?>
						<button type="button" class="button button-primary" disabled><?php esc_html_e( "Send today's report", 'adx-gated-resources' ); ?></button>
						<span class="description"><?php esc_html_e( 'Add at least one address under "Notify by email" first.', 'adx-gated-resources' ); ?></span>
					<?php endif; ?>
					<?php if ( $enabled ) : ?>
						<p class="description" style="margin:10px 0 0;">
							<?php esc_html_e( 'The automatic daily email above is also on. If you send this report by hand each day, untick it to avoid two report emails.', 'adx-gated-resources' ); ?>
						</p>
					<?php endif; ?>
				</div>

				<p>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=adx_gr_send_report_now' ), 'adx_gr_send_report_now' ) ); ?>"><?php esc_html_e( 'Send test report', 'adx-gated-resources' ); ?></a>
					<?php if ( $last_sent ) : ?>
						<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=adx_gr_resend_report' ), 'adx_gr_resend_report' ) ); ?>"><?php esc_html_e( 'Resend last report', 'adx-gated-resources' ); ?></a>
					<?php else : ?>
						<button type="button" class="button" disabled title="<?php esc_attr_e( 'Available once a report has been sent.', 'adx-gated-resources' ); ?>"><?php esc_html_e( 'Resend last report', 'adx-gated-resources' ); ?></button>
					<?php endif; ?>
					<a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( self::report_url( time() - DAY_IN_SECONDS, time() ) ); ?>"><?php esc_html_e( 'Open report page', 'adx-gated-resources' ); ?></a>
					<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=adx_gr_reset_report_links' ), 'adx_gr_reset_report_links' ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Links in every report email sent so far will stop working. Continue?', 'adx-gated-resources' ) ); ?>');"><?php esc_html_e( 'Reset report links', 'adx-gated-resources' ); ?></a>
				</p>
				<p class="description">
					<?php esc_html_e( '"Send test report" emails the downloads from the last 24 hours, so you can send it as often as you like while testing; "Resend last report" emails the last report again. Neither changes what the next report covers.', 'adx-gated-resources' ); ?>
					<?php if ( $last_sent ) : ?>
						<?php
						/* translators: %s: date range */
						echo esc_html( sprintf( __( 'Last report covered %s.', 'adx-gated-resources' ), self::period_label( array( 'from' => $last_sent[0], 'to' => $last_sent[1] ) ) ) );
						?>
					<?php else : ?>
						<?php esc_html_e( '"Resend last report" becomes available once a report has been emailed, by hand or automatically (days skipped for having no downloads don\'t count).', 'adx-gated-resources' ); ?>
					<?php endif; ?>
				</p>
				<?php ADX_GR_Report_Endpoint::render_panel(); ?>
				<p class="description">
					<?php esc_html_e( 'WordPress only runs scheduled tasks when the site gets a visit, so for the report to go out on time every day, have your hosting provider call the scheduler URL above (or run wp-cron.php from a server cron job). Both can be on: the report is still only sent once.', 'adx-gated-resources' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}
}
