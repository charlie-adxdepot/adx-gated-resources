<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Leave the downloads/subscribers tables and gated resource posts in
// place on uninstall by default -- losing captured leads or the
// gated-file list is far worse than leaving a small unused table
// behind. Only remove settings.
delete_option( 'adx_gr_zerobounce_api_key' );
delete_option( 'adx_gr_acceptable_statuses' );
delete_option( 'adx_gr_fail_open' );
delete_option( 'adx_gr_cookie_days' );
delete_option( 'adx_gr_modal_heading' );
delete_option( 'adx_gr_modal_description' );
delete_option( 'adx_gr_notification_emails' );
delete_option( 'adx_gr_library_page_id' );
delete_option( 'adx_gr_last_mail_error' );
delete_option( 'adx_gr_last_mail_sent' );
foreach ( array( 'host', 'port', 'encryption', 'username', 'password', 'from_email', 'from_name' ) as $adx_gr_smtp_key ) {
	delete_option( 'adx_gr_smtp_' . $adx_gr_smtp_key );
}
delete_option( 'adx_gr_role_options' );
delete_option( 'adx_gr_lookup_cache_version' );
delete_option( 'adx_gr_state_options' );
delete_option( 'adx_gr_form_fields' );
delete_option( 'adx_gr_db_version' );
foreach ( array( 'enabled', 'time', 'skip_empty', 'days', 'secret', 'last_to', 'last_run', 'last_window', 'last_slot', 'slot_attempts', 'run_lock', 'cron_token', 'endpoint_last' ) as $adx_gr_report_key ) {
	delete_option( 'adx_gr_report_' . $adx_gr_report_key );
}
delete_transient( 'adx_gr_report_lock' );
wp_clear_scheduled_hook( 'adx_gr_daily_report' );
delete_transient( 'adx_gr_url_map' );
