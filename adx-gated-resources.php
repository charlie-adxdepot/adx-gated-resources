<?php
/**
 * Plugin Name:       ADX Gated Resources
 * Plugin URI:        https://www.adxdepot.com.au
 * Description:       Soft-gates selected resource downloads (e.g. Revit library files) behind an email capture form, verified in real time via the ZeroBounce API, and logs leads for Sales follow-up.
 * Version:           1.19.8
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            ADX Depot
 * Text Domain:       adx-gated-resources
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
define( 'ADX_GR_VERSION', '1.19.8' );
define( 'ADX_GR_FILE', __FILE__ );
define( 'ADX_GR_PATH', plugin_dir_path( __FILE__ ) );
define( 'ADX_GR_URL', plugin_dir_url( __FILE__ ) );
define( 'ADX_GR_LEADS_TABLE', 'adx_gr_leads' );

require_once ADX_GR_PATH . 'includes/class-adx-gr-db.php';
require_once ADX_GR_PATH . 'includes/class-adx-gr-form-fields.php';
require_once ADX_GR_PATH . 'includes/class-adx-gr-cpt.php';
require_once ADX_GR_PATH . 'includes/class-adx-gr-library-terms.php';
require_once ADX_GR_PATH . 'includes/class-adx-gr-library-hero.php';
require_once ADX_GR_PATH . 'includes/class-adx-gr-import-export.php';
require_once ADX_GR_PATH . 'includes/class-adx-gr-zerobounce.php';
require_once ADX_GR_PATH . 'includes/class-adx-gr-mailer.php';
require_once ADX_GR_PATH . 'includes/class-adx-gr-ajax.php';
require_once ADX_GR_PATH . 'includes/class-adx-gr-frontend.php';
require_once ADX_GR_PATH . 'includes/class-adx-gr-page-gate.php';
require_once ADX_GR_PATH . 'includes/class-adx-gr-leads-list-table.php';
require_once ADX_GR_PATH . 'includes/class-adx-gr-subscribers-list-table.php';
require_once ADX_GR_PATH . 'includes/class-adx-gr-admin.php';
require_once ADX_GR_PATH . 'includes/class-adx-gr-daily-report.php';
require_once ADX_GR_PATH . 'includes/class-adx-gr-report-endpoint.php';

/**
 * Central bootstrap. Individual classes hook themselves in their constructors.
 */
final class ADX_Gated_Resources {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		ADX_GR_DB::maybe_upgrade();
		ADX_GR_CPT::instance();
		ADX_GR_Library_Terms::instance();
		ADX_GR_Library_Hero::instance();
		ADX_GR_Import_Export::instance();
		ADX_GR_Ajax::instance();
		ADX_GR_Frontend::instance();
		ADX_GR_Page_Gate::instance();
		ADX_GR_Admin::instance();
		ADX_GR_Daily_Report::instance();
		ADX_GR_Report_Endpoint::instance();
	}
}

register_activation_hook( ADX_GR_FILE, array( 'ADX_GR_DB', 'activate' ) );
register_deactivation_hook( ADX_GR_FILE, array( 'ADX_GR_Daily_Report', 'unschedule' ) );

add_action( 'plugins_loaded', array( 'ADX_Gated_Resources', 'instance' ) );
