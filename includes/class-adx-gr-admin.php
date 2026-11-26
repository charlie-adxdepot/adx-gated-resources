<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * wp-admin UI: top-level menu (Subscribers, Downloads log, Form
 * Fields builder, Settings). Gated resource files/bundles/pages are
 * managed via their own post type / meta boxes (ADX_GR_CPT,
 * ADX_GR_Page_Gate) and appear as automatic submenus of this same menu.
 */
class ADX_GR_Admin {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_post_adx_gr_export_downloads', array( $this, 'export_downloads_csv' ) );
		add_action( 'admin_post_adx_gr_export_subscribers', array( $this, 'export_subscribers_csv' ) );
		add_action( 'admin_post_adx_gr_save_form_fields', array( $this, 'save_form_fields' ) );
		add_action( 'admin_post_adx_gr_clear_downloads', array( $this, 'clear_downloads_log' ) );
		add_action( 'admin_post_adx_gr_test_notification', array( $this, 'send_test_notification' ) );
		add_filter( 'parent_file', array( $this, 'menu_parent_file' ) );
		add_filter( 'submenu_file', array( $this, 'menu_submenu_file' ) );
	}

	/**
	 * The resource and Category/Series screens are WordPress's own
	 * post-type and taxonomy screens, which work out their parent menu
	 * from the post type ("edit.php?post_type=...") rather than this
	 * plugin's custom top-level menu. Without this, the ADX Gated
	 * Resources menu stays collapsed on those screens.
	 */
	public function menu_parent_file( $parent_file ) {
		return $this->is_plugin_wp_screen() ? 'adx-gated-resources' : $parent_file;
	}

	/**
	 * Highlights the matching item in the expanded menu: "All Resources"
	 * on the resource list/add/edit screens, "Categories" or "Series" on
	 * their term screens (including editing a single term).
	 */
	public function menu_submenu_file( $submenu_file ) {
		if ( ! $this->is_plugin_wp_screen() ) {
			return $submenu_file;
		}

		$screen = get_current_screen();

		if ( in_array( $screen->taxonomy, array( ADX_GR_CPT::TAX_CATEGORY, ADX_GR_CPT::TAX_SERIES ), true ) ) {
			return 'edit-tags.php?taxonomy=' . $screen->taxonomy . '&post_type=' . ADX_GR_CPT::POST_TYPE;
		}

		return 'edit.php?post_type=' . ADX_GR_CPT::POST_TYPE;
	}

	private function is_plugin_wp_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen ) {
			return false;
		}

		return ADX_GR_CPT::POST_TYPE === $screen->post_type
			|| in_array( $screen->taxonomy, array( ADX_GR_CPT::TAX_CATEGORY, ADX_GR_CPT::TAX_SERIES ), true );
	}

	public function register_menu() {
		$subscribers_hook = add_menu_page(
			__( 'ADX Gated Resources', 'adx-gated-resources' ),
			__( 'ADX Gated Resources', 'adx-gated-resources' ),
			'manage_options',
			'adx-gated-resources',
			array( $this, 'render_subscribers_page' ),
			'dashicons-lock',
			26
		);

		// Runs before any output, so deletes can redirect back cleanly.
		add_action( 'load-' . $subscribers_hook, array( $this, 'process_subscriber_actions' ) );

		add_submenu_page(
			'adx-gated-resources',
			__( 'Subscribers', 'adx-gated-resources' ),
			__( 'Subscribers', 'adx-gated-resources' ),
			'manage_options',
			'adx-gated-resources',
			array( $this, 'render_subscribers_page' )
		);

		add_submenu_page(
			'adx-gated-resources',
			__( 'Downloads', 'adx-gated-resources' ),
			__( 'Downloads', 'adx-gated-resources' ),
			'manage_options',
			'adx-gr-downloads',
			array( $this, 'render_downloads_page' )
		);

		// WordPress's automatic taxonomy-menu placement doesn't reliably
		// attach to a custom top-level slug (see ADX_GR_CPT::register_taxonomies),
		// so these two screens are added explicitly instead.
		add_submenu_page(
			'adx-gated-resources',
			__( 'Categories', 'adx-gated-resources' ),
			__( 'Categories', 'adx-gated-resources' ),
			'manage_categories',
			'edit-tags.php?taxonomy=' . ADX_GR_CPT::TAX_CATEGORY . '&post_type=' . ADX_GR_CPT::POST_TYPE
		);

		add_submenu_page(
			'adx-gated-resources',
			__( 'Series', 'adx-gated-resources' ),
			__( 'Series', 'adx-gated-resources' ),
			'manage_categories',
			'edit-tags.php?taxonomy=' . ADX_GR_CPT::TAX_SERIES . '&post_type=' . ADX_GR_CPT::POST_TYPE
		);

		add_submenu_page(
			'adx-gated-resources',
			__( 'Library Hero', 'adx-gated-resources' ),
			__( 'Library Hero', 'adx-gated-resources' ),
			'manage_options',
			'adx-gr-library-hero',
			array( ADX_GR_Library_Hero::instance(), 'render_page' )
		);

		add_submenu_page(
			'adx-gated-resources',
			__( 'Form Fields', 'adx-gated-resources' ),
			__( 'Form Fields', 'adx-gated-resources' ),
			'manage_options',
			'adx-gr-form-fields',
			array( $this, 'render_form_fields_page' )
		);

		add_submenu_page(
			'adx-gated-resources',
			__( 'Import / Export', 'adx-gated-resources' ),
			__( 'Import / Export', 'adx-gated-resources' ),
			'manage_options',
			ADX_GR_Import_Export::PAGE,
			array( ADX_GR_Import_Export::instance(), 'render_page' )
		);

		add_submenu_page(
			'adx-gated-resources',
			__( 'Settings', 'adx-gated-resources' ),
			__( 'Settings', 'adx-gated-resources' ),
			'manage_options',
			'adx-gr-settings',
			array( $this, 'render_settings_page' )
		);
	}

	public function register_settings() {
		register_setting( 'adx_gr_settings', 'adx_gr_zerobounce_api_key', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'adx_gr_settings', 'adx_gr_acceptable_statuses', array( 'sanitize_callback' => array( $this, 'sanitize_statuses' ) ) );
		register_setting( 'adx_gr_settings', 'adx_gr_fail_open', array( 'sanitize_callback' => array( $this, 'sanitize_checkbox' ) ) );
		register_setting( 'adx_gr_settings', 'adx_gr_cookie_days', array( 'sanitize_callback' => 'absint' ) );
		register_setting( 'adx_gr_settings', 'adx_gr_modal_heading', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'adx_gr_settings', 'adx_gr_modal_description', array( 'sanitize_callback' => 'sanitize_textarea_field' ) );
		register_setting( 'adx_gr_settings', 'adx_gr_notification_emails', array( 'sanitize_callback' => array( $this, 'sanitize_notification_emails' ) ) );
		register_setting( 'adx_gr_settings', 'adx_gr_library_page_id', array( 'sanitize_callback' => 'absint' ) );
		register_setting( 'adx_gr_settings', 'adx_gr_smtp_host', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'adx_gr_settings', 'adx_gr_smtp_port', array( 'sanitize_callback' => 'absint' ) );
		register_setting( 'adx_gr_settings', 'adx_gr_smtp_encryption', array( 'sanitize_callback' => array( $this, 'sanitize_smtp_encryption' ) ) );
		register_setting( 'adx_gr_settings', 'adx_gr_smtp_username', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting( 'adx_gr_settings', 'adx_gr_smtp_password', array( 'sanitize_callback' => array( $this, 'sanitize_smtp_password' ) ) );
		register_setting( 'adx_gr_settings', 'adx_gr_smtp_from_email', array( 'sanitize_callback' => 'sanitize_email' ) );
		register_setting( 'adx_gr_settings', 'adx_gr_smtp_from_name', array( 'sanitize_callback' => 'sanitize_text_field' ) );
	}

	public function sanitize_smtp_encryption( $value ) {
		return in_array( $value, array( 'tls', 'ssl', 'none' ), true ) ? $value : 'tls';
	}

	/**
	 * The password field is never printed back into the page, so a
	 * blank submission means "keep the saved one". Not run through
	 * sanitize_text_field(), which could alter valid password characters.
	 */
	public function sanitize_smtp_password( $value ) {
		$value = trim( (string) $value );
		return '' === $value ? (string) get_option( 'adx_gr_smtp_password', '' ) : $value;
	}

	/**
	 * Accepts emails separated by commas and/or new lines, drops
	 * anything that isn't a valid address, and stores the result as a
	 * comma-separated list.
	 */
	public function sanitize_notification_emails( $value ) {
		$candidates = preg_split( '/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY );
		$valid      = array_filter( array_map( 'sanitize_email', $candidates ), 'is_email' );

		return implode( ', ', array_unique( $valid ) );
	}

	public function sanitize_statuses( $value ) {
		$allowed = array( 'valid', 'catch-all', 'unknown', 'spamtrap', 'abuse', 'do_not_mail', 'invalid', 'disposable' );
		if ( ! is_array( $value ) ) {
			return array( 'valid' );
		}
		return array_values( array_intersect( $allowed, $value ) );
	}

	public function sanitize_checkbox( $value ) {
		return '1' === $value ? '1' : '0';
	}

	/**
	 * Handles the Subscribers screen's single-row "Delete" link and the
	 * "Delete" bulk action, then redirects back with a count so a
	 * refresh can't repeat the delete.
	 */
	public function process_subscriber_actions() {
		$table  = new ADX_GR_Subscribers_List_Table();
		$action = $table->current_action();

		if ( 'delete' !== $action ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'adx-gated-resources' ) );
		}

		if ( isset( $_REQUEST['subscriber'] ) && is_array( $_REQUEST['subscriber'] ) ) {
			check_admin_referer( 'bulk-subscribers' );
			$ids = array_map( 'absint', wp_unslash( $_REQUEST['subscriber'] ) );
		} elseif ( isset( $_REQUEST['subscriber'] ) ) {
			$id = absint( $_REQUEST['subscriber'] );
			check_admin_referer( 'adx_gr_delete_subscriber_' . $id );
			$ids = array( $id );
		} else {
			$ids = array();
		}

		$deleted = ADX_GR_DB::delete_subscribers( $ids );

		wp_safe_redirect( add_query_arg( 'deleted', $deleted, admin_url( 'admin.php?page=adx-gated-resources' ) ) );
		exit;
	}

	/**
	 * Subscribers: one row per unique email, aggregated across every
	 * download/gate pass, with a link through to their full history.
	 */
	public function render_subscribers_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$table = new ADX_GR_Subscribers_List_Table();
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Subscribers', 'adx-gated-resources' ); ?></h1>

			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=adx_gr_export_subscribers' ), 'adx_gr_export_subscribers' ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Export CSV', 'adx-gated-resources' ); ?>
			</a>

			<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . ADX_GR_CPT::POST_TYPE ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Manage Resources', 'adx-gated-resources' ); ?>
			</a>

			<?php if ( isset( $_GET['deleted'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					$deleted_count = absint( $_GET['deleted'] );
					echo esc_html(
						sprintf(
							/* translators: %d: number of subscribers deleted */
							_n( '%d subscriber deleted.', '%d subscribers deleted.', $deleted_count, 'adx-gated-resources' ),
							$deleted_count
						)
					);
					?>
				</p></div>
			<?php endif; ?>

			<p class="description"><?php esc_html_e( 'Everyone who has passed the email gate at least once. Downloads count and dates are aggregated across every resource they unlocked.', 'adx-gated-resources' ); ?></p>

			<form method="get">
				<input type="hidden" name="page" value="adx-gated-resources" />
				<?php $table->search_box( __( 'Search subscribers', 'adx-gated-resources' ), 'adx-gr-subscribers' ); ?>
				<?php $table->display(); ?>
			</form>
		</div>
		<?php
	}

	public function export_subscribers_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'adx-gated-resources' ) );
		}

		check_admin_referer( 'adx_gr_export_subscribers' );

		$subscribers = ADX_GR_DB::get_all_subscribers_for_export();
		$fields      = ADX_GR_Form_Fields::get_fields();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=adx-gated-resource-subscribers-' . gmdate( 'Y-m-d' ) . '.csv' );

		$output = fopen( 'php://output', 'w' );

		$headers = array( 'Email' );
		foreach ( $fields as $field ) {
			$headers[] = $field['label'];
		}
		$headers = array_merge( $headers, array( 'Downloads', 'ZeroBounce Status', 'First Seen', 'Last Download' ) );
		fputcsv( $output, $headers );

		foreach ( $subscribers as $subscriber ) {
			$decoded = ! empty( $subscriber->field_data ) ? json_decode( $subscriber->field_data, true ) : array();
			$decoded = is_array( $decoded ) ? $decoded : array();

			$row = array( $subscriber->email );
			foreach ( $fields as $field ) {
				$value = isset( $decoded[ $field['key'] ] ) ? $decoded[ $field['key'] ] : '';
				if ( 'checkbox' === $field['type'] ) {
					$value = '1' === $value ? 'Yes' : 'No';
				}
				$row[] = $value;
			}
			$row[] = $subscriber->downloads_count;
			$row[] = $subscriber->zerobounce_status;
			$row[] = $subscriber->first_seen;
			$row[] = $subscriber->last_seen;

			fputcsv( $output, $row );
		}

		fclose( $output );
		exit;
	}

	/**
	 * Downloads: the per-event log (every gate pass), formerly called
	 * "Leads" -- kept as a separate screen from Subscribers so Sales
	 * can see exactly what was downloaded and when, not just who opted in.
	 */
	public function render_downloads_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$table = new ADX_GR_Leads_List_Table();
		$table->prepare_items();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Download Log', 'adx-gated-resources' ); ?></h1>

			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=adx_gr_export_downloads' ), 'adx_gr_export_downloads' ) ); ?>" class="page-title-action">
				<?php esc_html_e( 'Export CSV', 'adx-gated-resources' ); ?>
			</a>

			<?php if ( isset( $_GET['cleared'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Download log cleared.', 'adx-gated-resources' ); ?></p></div>
			<?php elseif ( isset( $_GET['clear_failed'] ) ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Could not clear the download log. Check the site\'s database error log for details.', 'adx-gated-resources' ); ?></p></div>
			<?php endif; ?>

			<p class="description"><?php esc_html_e( 'Every time someone unlocked a resource, whether by filling in the form or via a remembered (cookie) repeat visit.', 'adx-gated-resources' ); ?></p>

			<form method="get">
				<input type="hidden" name="page" value="adx-gr-downloads" />
				<?php $table->search_box( __( 'Search downloads', 'adx-gated-resources' ), 'adx-gr-downloads' ); ?>
				<?php $table->display(); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:16px;">
				<input type="hidden" name="action" value="adx_gr_clear_downloads" />
				<?php wp_nonce_field( 'adx_gr_clear_downloads' ); ?>
				<button
					type="submit"
					class="button"
					onclick="return confirm('<?php echo esc_js( __( 'Permanently delete every entry in the download log? This cannot be undone. Subscribers are not affected.', 'adx-gated-resources' ) ); ?>');"
				>
					<?php esc_html_e( 'Clear Log', 'adx-gated-resources' ); ?>
				</button>
			</form>
		</div>
		<?php
	}

	public function clear_downloads_log() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'adx-gated-resources' ) );
		}

		check_admin_referer( 'adx_gr_clear_downloads' );

		$cleared = ADX_GR_DB::clear_leads();

		wp_safe_redirect(
			add_query_arg( $cleared ? 'cleared' : 'clear_failed', '1', admin_url( 'admin.php?page=adx-gr-downloads' ) )
		);
		exit;
	}

	/**
	 * Sends a sample download notification to the configured addresses
	 * so admins can confirm the site can actually deliver email.
	 */
	public function send_test_notification() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'adx-gated-resources' ) );
		}

		check_admin_referer( 'adx_gr_test_notification' );

		// "Test SMTP" skips PHP mail() so the fallback can be checked
		// even while mail() is working.
		$smtp_only = ! empty( $_GET['smtp'] );

		$sent = ADX_GR_Ajax::notify_library_access(
			wp_get_current_user()->user_email,
			array(
				'first_name'   => 'Test',
				'last_name'    => 'Person',
				'company'      => 'Example Pty Ltd',
				'role'         => 'Architect',
				'state'        => 'NSW',
				'project_name' => 'Sample project',
			),
			__( '(test notification)', 'adx-gated-resources' ),
			0,
			'',
			$smtp_only
		);

		wp_safe_redirect( add_query_arg( 'mail_test', $sent ? 'sent' : 'failed', admin_url( 'admin.php?page=adx-gr-settings' ) ) );
		exit;
	}

	public function export_downloads_csv() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'adx-gated-resources' ) );
		}

		check_admin_referer( 'adx_gr_export_downloads' );

		$leads  = ADX_GR_DB::get_all_leads_for_export();
		$fields = ADX_GR_Form_Fields::get_fields();

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=adx-gated-resource-downloads-' . gmdate( 'Y-m-d' ) . '.csv' );

		$output = fopen( 'php://output', 'w' );

		$headers = array( 'Email' );
		foreach ( $fields as $field ) {
			$headers[] = $field['label'];
		}
		$headers = array_merge( $headers, array( 'Resource', 'Series', 'ZeroBounce Status', 'ZeroBounce Sub-status', 'Returning (cookie)', 'IP Address', 'Page URL', 'Date' ) );
		fputcsv( $output, $headers );

		foreach ( $leads as $lead ) {
			$row = array( $lead->email );
			foreach ( $fields as $field ) {
				$value = ADX_GR_DB::get_row_field_value( $lead, $field['key'] );
				if ( 'checkbox' === $field['type'] ) {
					$value = '1' === $value ? 'Yes' : 'No';
				}
				$row[] = $value;
			}
			$row = array_merge(
				$row,
				array(
					$lead->resource_title,
					implode( ', ', ADX_GR_CPT::get_series_names( $lead->resource_id ) ),
					$lead->zerobounce_status,
					$lead->zerobounce_sub_status,
					$lead->via_cookie ? 'Yes' : 'No',
					$lead->ip_address,
					$lead->page_url,
					$lead->created_at,
				)
			);

			fputcsv( $output, $row );
		}

		fclose( $output );
		exit;
	}

	/**
	 * Form Fields builder: lets the admin add/edit/remove/reorder the
	 * fields shown on the gate form (everything except email, which is
	 * fixed). Saved as a normal admin-post submission (not the Settings
	 * API) since it's a repeatable row structure, not flat options.
	 */
	public function render_form_fields_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$fields = ADX_GR_Form_Fields::get_fields();
		$types  = array(
			'text'     => __( 'Text', 'adx-gated-resources' ),
			'textarea' => __( 'Textarea', 'adx-gated-resources' ),
			'select'   => __( 'Dropdown', 'adx-gated-resources' ),
			'checkbox' => __( 'Checkbox', 'adx-gated-resources' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Gate Form Fields', 'adx-gated-resources' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Email is always collected and cannot be removed. Add, edit, remove, or reorder any other field shown on the gate form.', 'adx-gated-resources' ); ?>
			</p>

			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Form fields saved.', 'adx-gated-resources' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="adx_gr_save_form_fields" />
				<?php wp_nonce_field( 'adx_gr_save_form_fields' ); ?>

				<table class="widefat" id="adx_gr_field_table">
					<thead>
						<tr>
							<th style="width:16px;"></th>
							<th><?php esc_html_e( 'Label', 'adx-gated-resources' ); ?></th>
							<th><?php esc_html_e( 'Type', 'adx-gated-resources' ); ?></th>
							<th><?php esc_html_e( 'Options (one per line, for Dropdown)', 'adx-gated-resources' ); ?></th>
							<th style="width:80px;"><?php esc_html_e( 'Required', 'adx-gated-resources' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody id="adx_gr_field_rows">
						<?php foreach ( $fields as $i => $field ) : ?>
							<?php $this->render_field_row( $field, $types, $i ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p>
					<button type="button" class="button" id="adx_gr_field_add_row"><?php esc_html_e( 'Add field', 'adx-gated-resources' ); ?></button>
				</p>

				<template id="adx_gr_field_row_template">
					<?php $this->render_field_row( array( 'key' => '', 'label' => '', 'type' => 'text', 'required' => false, 'options' => array() ), $types, '__INDEX__' ); ?>
				</template>
				<script>window.adxGrFieldRowCounter = <?php echo (int) count( $fields ); ?>;</script>

				<?php submit_button( __( 'Save Fields', 'adx-gated-resources' ) ); ?>
			</form>
		</div>

		<script>
		(function(){
			var rowsBody = document.getElementById('adx_gr_field_rows');
			var template = document.getElementById('adx_gr_field_row_template');
			var addBtn = document.getElementById('adx_gr_field_add_row');

			function toggleOptionsVisibility( row ){
				// Hide the <textarea> itself, not its <td>: hiding the
				// whole cell removes it from that row's column count,
				// which misaligns every column after it against rows
				// that still render all six cells (e.g. Dropdown rows).
				var type = row.querySelector('.adx_gr_field_type').value;
				var optionsField = row.querySelector('.adx_gr_field_options textarea');
				optionsField.style.visibility = ( 'select' === type ) ? '' : 'hidden';
			}

			rowsBody.querySelectorAll('.adx-gr-field-row').forEach( toggleOptionsVisibility );

			rowsBody.addEventListener('change', function(e){
				if ( e.target.classList.contains('adx_gr_field_type') ) {
					toggleOptionsVisibility( e.target.closest('.adx-gr-field-row') );
				}
			});

			rowsBody.addEventListener('click', function(e){
				var row = e.target.closest('.adx-gr-field-row');
				if ( ! row ) { return; }

				if ( e.target.closest('.adx_gr_field_remove_row') ) {
					e.preventDefault();
					row.remove();
				} else if ( e.target.closest('.adx_gr_field_move_up') ) {
					e.preventDefault();
					var prev = row.previousElementSibling;
					if ( prev ) { rowsBody.insertBefore( row, prev ); }
				} else if ( e.target.closest('.adx_gr_field_move_down') ) {
					e.preventDefault();
					var next = row.nextElementSibling;
					if ( next ) { rowsBody.insertBefore( next, row ); }
				}
			});

			if ( addBtn && template ) {
				addBtn.addEventListener('click', function(e){
					e.preventDefault();
					var index = window.adxGrFieldRowCounter++;
					var clone = template.content.cloneNode( true );
					clone.querySelectorAll('[name]').forEach( function( el ){
						el.name = el.name.replace( '__INDEX__', index );
					});
					rowsBody.appendChild( clone );
					toggleOptionsVisibility( rowsBody.lastElementChild );
				});
			}
		})();
		</script>
		<?php
	}

	/**
	 * $index is a per-row token shared by all five inputs below (label,
	 * type, options, required, key) so they can be correlated back into
	 * one field on save. It must NOT be a plain sequential array (`[]`)
	 * across separate inputs: an unchecked "Required" checkbox is simply
	 * missing from the POST body, which would silently shift a plain
	 * `[]` array's indices out of alignment with the other fields for
	 * every row after it.
	 */
	private function render_field_row( array $field, array $types, $index ) {
		$key      = isset( $field['key'] ) ? $field['key'] : '';
		$label    = isset( $field['label'] ) ? $field['label'] : '';
		$type     = isset( $field['type'] ) ? $field['type'] : 'text';
		$required = ! empty( $field['required'] );
		$options  = isset( $field['options'] ) && is_array( $field['options'] ) ? implode( "\n", $field['options'] ) : '';
		$idx      = esc_attr( $index );
		?>
		<tr class="adx-gr-field-row">
			<td class="adx-gr-field-handle">
				<button type="button" class="button-link adx_gr_field_move_up" title="<?php esc_attr_e( 'Move up', 'adx-gated-resources' ); ?>">&uarr;</button>
				<button type="button" class="button-link adx_gr_field_move_down" title="<?php esc_attr_e( 'Move down', 'adx-gated-resources' ); ?>">&darr;</button>
			</td>
			<td>
				<input type="hidden" name="adx_gr_field_key[<?php echo $idx; ?>]" value="<?php echo esc_attr( $key ); ?>" />
				<input type="text" class="regular-text" name="adx_gr_field_label[<?php echo $idx; ?>]" value="<?php echo esc_attr( $label ); ?>" placeholder="<?php esc_attr_e( 'e.g. Phone Number', 'adx-gated-resources' ); ?>" />
			</td>
			<td>
				<select class="adx_gr_field_type" name="adx_gr_field_type[<?php echo $idx; ?>]">
					<?php foreach ( $types as $value => $type_label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $type, $value ); ?>><?php echo esc_html( $type_label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td class="adx_gr_field_options">
				<textarea class="large-text" name="adx_gr_field_options[<?php echo $idx; ?>]" rows="2" placeholder="<?php esc_attr_e( "One option per line\ne.g. Architect", 'adx-gated-resources' ); ?>"><?php echo esc_textarea( $options ); ?></textarea>
			</td>
			<td>
				<input type="checkbox" name="adx_gr_field_required[<?php echo $idx; ?>]" value="1" <?php checked( $required ); ?> />
			</td>
			<td>
				<button type="button" class="button adx_gr_field_remove_row"><?php esc_html_e( 'Remove', 'adx-gated-resources' ); ?></button>
			</td>
		</tr>
		<?php
	}

	public function save_form_fields() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'adx-gated-resources' ) );
		}

		check_admin_referer( 'adx_gr_save_form_fields' );

		$fields = ADX_GR_Form_Fields::sanitize_fields_from_request( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing
		update_option( ADX_GR_Form_Fields::OPTION_NAME, $fields );

		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'admin.php?page=adx-gr-form-fields' ) ) );
		exit;
	}

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$api_key             = get_option( 'adx_gr_zerobounce_api_key', '' );
		$acceptable_statuses = get_option( 'adx_gr_acceptable_statuses', array( 'valid', 'catch-all' ) );
		$fail_open           = get_option( 'adx_gr_fail_open', '1' );
		$cookie_days         = get_option( 'adx_gr_cookie_days', 30 );
		$modal_heading       = get_option( 'adx_gr_modal_heading', __( 'Access the Revit Library', 'adx-gated-resources' ) );
		$modal_description   = get_option( 'adx_gr_modal_description', __( 'Enter your email to download this file. Our team may follow up to help with your project.', 'adx-gated-resources' ) );
		$notification_emails = get_option( 'adx_gr_notification_emails', '' );
		$library_page_id     = absint( get_option( 'adx_gr_library_page_id', 0 ) );
		$last_mail_error    = get_option( 'adx_gr_last_mail_error' );
		$last_mail_sent      = get_option( 'adx_gr_last_mail_sent' );
		$smtp_host           = get_option( 'adx_gr_smtp_host', '' );
		$smtp_port           = get_option( 'adx_gr_smtp_port', 587 );
		$smtp_encryption     = get_option( 'adx_gr_smtp_encryption', 'tls' );
		$smtp_username       = get_option( 'adx_gr_smtp_username', '' );
		$smtp_has_password   = '' !== (string) get_option( 'adx_gr_smtp_password', '' );
		$smtp_from_email     = get_option( 'adx_gr_smtp_from_email', '' );
		$smtp_from_name      = get_option( 'adx_gr_smtp_from_name', '' );

		$status_options = array(
			'valid'       => __( 'Valid', 'adx-gated-resources' ),
			'catch-all'   => __( 'Catch-all', 'adx-gated-resources' ),
			'unknown'     => __( 'Unknown', 'adx-gated-resources' ),
			'disposable'  => __( 'Disposable', 'adx-gated-resources' ),
			'spamtrap'    => __( 'Spamtrap', 'adx-gated-resources' ),
			'abuse'       => __( 'Abuse', 'adx-gated-resources' ),
			'do_not_mail' => __( 'Do Not Mail', 'adx-gated-resources' ),
			'invalid'     => __( 'Invalid', 'adx-gated-resources' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Gated Resources Settings', 'adx-gated-resources' ); ?></h1>
			<?php if ( isset( $_GET['mail_test'] ) && 'sent' === $_GET['mail_test'] ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Test notification handed to the mail server -- see "Notify by email" below for which method sent it. If it doesn\'t arrive within a few minutes, check spam/quarantine.', 'adx-gated-resources' ); ?></p></div>
			<?php elseif ( isset( $_GET['mail_test'] ) ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'The test notification could not be sent. See the error under "Notify by email" below.', 'adx-gated-resources' ); ?></p></div>
			<?php endif; ?>
			<?php ADX_GR_Daily_Report::render_admin_notice(); ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'adx_gr_settings' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="adx_gr_zerobounce_api_key"><?php esc_html_e( 'ZeroBounce API Key', 'adx-gated-resources' ); ?></label></th>
						<td>
							<input type="text" id="adx_gr_zerobounce_api_key" name="adx_gr_zerobounce_api_key" class="regular-text" value="<?php echo esc_attr( $api_key ); ?>" autocomplete="off" />
							<p class="description"><?php esc_html_e( 'Your ZeroBounce real-time email validation API key.', 'adx-gated-resources' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Acceptable ZeroBounce statuses', 'adx-gated-resources' ); ?></th>
						<td>
							<?php foreach ( $status_options as $key => $label ) : ?>
								<label style="display:block;margin-bottom:4px;">
									<input type="checkbox" name="adx_gr_acceptable_statuses[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, (array) $acceptable_statuses, true ) ); ?> />
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
							<p class="description"><?php esc_html_e( 'Email statuses that are allowed through the gate. "Valid" and "Catch-all" is recommended.', 'adx-gated-resources' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="adx_gr_fail_open"><?php esc_html_e( 'If ZeroBounce is unreachable', 'adx-gated-resources' ); ?></label></th>
						<td>
							<label>
								<input type="checkbox" id="adx_gr_fail_open" name="adx_gr_fail_open" value="1" <?php checked( $fail_open, '1' ); ?> />
								<?php esc_html_e( 'Allow the download anyway (fail open) instead of blocking the visitor.', 'adx-gated-resources' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Recommended, so a ZeroBounce outage or exhausted credits never blocks a genuine lead. Leads captured this way are logged with status "cookie"/failure so you can review them.', 'adx-gated-resources' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="adx_gr_library_page_id"><?php esc_html_e( 'Library page', 'adx-gated-resources' ); ?></label></th>
						<td>
							<?php
							wp_dropdown_pages(
								array(
									'name'              => 'adx_gr_library_page_id',
									'id'                => 'adx_gr_library_page_id',
									'selected'          => $library_page_id,
									'show_option_none'  => esc_html__( '— Select a page —', 'adx-gated-resources' ),
									'option_none_value' => '0',
								)
							);
							?>
							<p class="description"><?php esc_html_e( 'Where the [adx_library_access] button on product pages sends visitors once they submit the form. This page is always gated. Place the button on a product page with e.g. [adx_library_access text="BIM & Revit Library"]; add product="..." to name the product in the email instead of the page title.', 'adx-gated-resources' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="adx_gr_notification_emails"><?php esc_html_e( 'Notify by email', 'adx-gated-resources' ); ?></label></th>
						<td>
							<textarea id="adx_gr_notification_emails" name="adx_gr_notification_emails" class="large-text" rows="3" placeholder="sales@adxdepot.com.au"><?php echo esc_textarea( $notification_emails ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Email addresses to notify when a new subscriber submits the form, either via the library button on a product page (the email names the product) or on a gated page\'s lock screen such as the library (the email names the page they came from). People already in the Subscribers list don\'t trigger an email again, e.g. when they return after their cookie expires; their downloads still appear in the daily report. Downloads are logged but don\'t send an email. Separate multiple addresses with a comma or a new line. Leave blank to disable.', 'adx-gated-resources' ); ?></p>
							<?php if ( $notification_emails ) : ?>
								<p>
									<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=adx_gr_test_notification' ), 'adx_gr_test_notification' ) ); ?>"><?php esc_html_e( 'Send test email', 'adx-gated-resources' ); ?></a>
									<?php if ( ADX_GR_Mailer::smtp_configured() ) : ?>
										<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=adx_gr_test_notification&smtp=1' ), 'adx_gr_test_notification' ) ); ?>"><?php esc_html_e( 'Test SMTP only', 'adx-gated-resources' ); ?></a>
									<?php endif; ?>
								</p>
							<?php endif; ?>
							<?php if ( is_array( $last_mail_sent ) && ! empty( $last_mail_sent['time'] ) && empty( $last_mail_error ) ) : ?>
								<p class="description">
									<?php
									printf(
										/* translators: 1: date/time, 2: sending method */
										esc_html__( 'Last notification sent %1$s via %2$s.', 'adx-gated-resources' ),
										esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_mail_sent['time'] ) ),
										'smtp' === $last_mail_sent['method'] ? esc_html__( 'SMTP fallback', 'adx-gated-resources' ) : esc_html__( 'PHP mail()', 'adx-gated-resources' )
									);
									?>
								</p>
							<?php endif; ?>
							<?php if ( is_array( $last_mail_error ) && ! empty( $last_mail_error['message'] ) ) : ?>
								<div class="notice notice-error inline"><p>
									<?php
									printf(
										/* translators: 1: date/time, 2: error message */
										esc_html__( 'Last notification failed (%1$s): %2$s', 'adx-gated-resources' ),
										esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_mail_error['time'] ) ),
										esc_html( $last_mail_error['message'] )
									);
									?>
								</p></div>
							<?php endif; ?>
						</td>
					</tr>
					<?php ADX_GR_Daily_Report::render_settings_row(); ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'SMTP fallback', 'adx-gated-resources' ); ?></th>
						<td>
							<p class="description" style="margin-bottom:8px;"><?php esc_html_e( 'Notifications are sent with PHP mail() first. If that fails, they are sent again through this SMTP server (e.g. SMTP2GO). Leave Host blank to disable the fallback.', 'adx-gated-resources' ); ?></p>
							<table role="presentation">
								<tr>
									<td><label for="adx_gr_smtp_host"><?php esc_html_e( 'Host', 'adx-gated-resources' ); ?></label></td>
									<td><input type="text" id="adx_gr_smtp_host" name="adx_gr_smtp_host" class="regular-text" value="<?php echo esc_attr( $smtp_host ); ?>" placeholder="mail.smtp2go.com" /></td>
								</tr>
								<tr>
									<td><label for="adx_gr_smtp_port"><?php esc_html_e( 'Port', 'adx-gated-resources' ); ?></label></td>
									<td><input type="number" id="adx_gr_smtp_port" name="adx_gr_smtp_port" class="small-text" value="<?php echo esc_attr( $smtp_port ); ?>" /></td>
								</tr>
								<tr>
									<td><label for="adx_gr_smtp_encryption"><?php esc_html_e( 'Encryption', 'adx-gated-resources' ); ?></label></td>
									<td>
										<select id="adx_gr_smtp_encryption" name="adx_gr_smtp_encryption">
											<option value="tls" <?php selected( $smtp_encryption, 'tls' ); ?>><?php esc_html_e( 'TLS (usually port 587 or 2525)', 'adx-gated-resources' ); ?></option>
											<option value="ssl" <?php selected( $smtp_encryption, 'ssl' ); ?>><?php esc_html_e( 'SSL (usually port 465)', 'adx-gated-resources' ); ?></option>
											<option value="none" <?php selected( $smtp_encryption, 'none' ); ?>><?php esc_html_e( 'None', 'adx-gated-resources' ); ?></option>
										</select>
									</td>
								</tr>
								<tr>
									<td><label for="adx_gr_smtp_username"><?php esc_html_e( 'Username', 'adx-gated-resources' ); ?></label></td>
									<td><input type="text" id="adx_gr_smtp_username" name="adx_gr_smtp_username" class="regular-text" value="<?php echo esc_attr( $smtp_username ); ?>" autocomplete="off" /></td>
								</tr>
								<tr>
									<td><label for="adx_gr_smtp_password"><?php esc_html_e( 'Password', 'adx-gated-resources' ); ?></label></td>
									<td>
										<input type="password" id="adx_gr_smtp_password" name="adx_gr_smtp_password" class="regular-text" value="" autocomplete="new-password" placeholder="<?php echo $smtp_has_password ? esc_attr__( '(saved — leave blank to keep)', 'adx-gated-resources' ) : ''; ?>" />
									</td>
								</tr>
								<tr>
									<td><label for="adx_gr_smtp_from_email"><?php esc_html_e( 'From email', 'adx-gated-resources' ); ?></label></td>
									<td><input type="email" id="adx_gr_smtp_from_email" name="adx_gr_smtp_from_email" class="regular-text" value="<?php echo esc_attr( $smtp_from_email ); ?>" placeholder="noreply@adxdepot.com.au" /></td>
								</tr>
								<tr>
									<td><label for="adx_gr_smtp_from_name"><?php esc_html_e( 'From name', 'adx-gated-resources' ); ?></label></td>
									<td><input type="text" id="adx_gr_smtp_from_name" name="adx_gr_smtp_from_name" class="regular-text" value="<?php echo esc_attr( $smtp_from_name ); ?>" placeholder="<?php echo esc_attr( get_bloginfo( 'name' ) ); ?>" /></td>
								</tr>
							</table>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="adx_gr_cookie_days"><?php esc_html_e( 'Remember visitor for (days)', 'adx-gated-resources' ); ?></label></th>
						<td>
							<input type="number" min="1" max="365" id="adx_gr_cookie_days" name="adx_gr_cookie_days" value="<?php echo esc_attr( $cookie_days ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'Once verified, a visitor will not need to re-submit the form for this many days.', 'adx-gated-resources' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="adx_gr_modal_heading"><?php esc_html_e( 'Form heading', 'adx-gated-resources' ); ?></label></th>
						<td><input type="text" id="adx_gr_modal_heading" name="adx_gr_modal_heading" class="regular-text" value="<?php echo esc_attr( $modal_heading ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="adx_gr_modal_description"><?php esc_html_e( 'Form description', 'adx-gated-resources' ); ?></label></th>
						<td><textarea id="adx_gr_modal_description" name="adx_gr_modal_description" class="large-text" rows="3"><?php echo esc_textarea( $modal_description ); ?></textarea></td>
					</tr>
				</table>
				<p class="description">
					<?php
					printf(
						/* translators: %s: link to the Form Fields screen */
						esc_html__( 'To add, edit, remove, or reorder the fields shown on the gate form (name, company, custom fields, etc.), go to %s.', 'adx-gated-resources' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=adx-gr-form-fields' ) ) . '">' . esc_html__( 'Form Fields', 'adx-gated-resources' ) . '</a>'
					);
					?>
				</p>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
