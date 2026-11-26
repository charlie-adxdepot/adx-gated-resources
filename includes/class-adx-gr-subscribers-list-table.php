<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Renders the Subscribers admin table: one row per unique email,
 * aggregated across every gate pass, with their captured field values
 * (dynamic, per the current Form Fields config) and a link to their
 * full download history.
 */
class ADX_GR_Subscribers_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'subscriber',
				'plural'   => 'subscribers',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		$columns = array(
			'cb'    => '<input type="checkbox" />',
			'email' => __( 'Email', 'adx-gated-resources' ),
		);

		foreach ( ADX_GR_Form_Fields::get_fields() as $field ) {
			$columns[ 'field_' . $field['key'] ] = $field['label'];
		}

		$columns['downloads_count'] = __( 'Downloads', 'adx-gated-resources' );
		$columns['zerobounce_status'] = __( 'ZeroBounce Status', 'adx-gated-resources' );
		$columns['first_seen']       = __( 'First Seen', 'adx-gated-resources' );
		$columns['last_seen']        = __( 'Last Download', 'adx-gated-resources' );

		return $columns;
	}

	protected function get_sortable_columns() {
		return array(
			'email'           => array( 'email', false ),
			'downloads_count' => array( 'downloads_count', false ),
			'first_seen'      => array( 'first_seen', false ),
			'last_seen'       => array( 'last_seen', true ),
		);
	}

	public function prepare_items() {
		$per_page = 20;
		$paged    = $this->get_pagenum();
		$search   = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		$orderby  = isset( $_REQUEST['orderby'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['orderby'] ) ) : 'last_seen';
		$order    = isset( $_REQUEST['order'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['order'] ) ) : 'desc';

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$this->items = ADX_GR_DB::get_subscribers(
			array(
				'search'   => $search,
				'orderby'  => $orderby,
				'order'    => $order,
				'per_page' => $per_page,
				'paged'    => $paged,
			)
		);

		$total_items = ADX_GR_DB::count_subscribers( $search );

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
			)
		);
	}

	protected function get_bulk_actions() {
		return array(
			'delete' => __( 'Delete', 'adx-gated-resources' ),
		);
	}

	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="subscriber[]" value="%d" />', (int) $item->id );
	}

	public function column_email( $item ) {
		$downloads_url = add_query_arg(
			array(
				'page' => 'adx-gr-downloads',
				's'    => rawurlencode( $item->email ),
			),
			admin_url( 'admin.php' )
		);

		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'       => 'adx-gated-resources',
					'action'     => 'delete',
					'subscriber' => (int) $item->id,
				),
				admin_url( 'admin.php' )
			),
			'adx_gr_delete_subscriber_' . (int) $item->id
		);

		$actions = array(
			'view_downloads' => sprintf(
				'<a href="%1$s">%2$s</a>',
				esc_url( $downloads_url ),
				esc_html__( 'View downloads', 'adx-gated-resources' )
			),
			'delete'         => sprintf(
				'<a href="%1$s" class="submitdelete" onclick="return confirm(\'%2$s\');">%3$s</a>',
				esc_url( $delete_url ),
				esc_js(
					/* translators: %s: subscriber email */
					sprintf( __( 'Delete subscriber %s? Their download log entries are kept.', 'adx-gated-resources' ), $item->email )
				),
				esc_html__( 'Delete', 'adx-gated-resources' )
			),
		);

		return sprintf( '%1$s %2$s', esc_html( $item->email ), $this->row_actions( $actions ) );
	}

	public function column_default( $item, $column_name ) {
		if ( 0 === strpos( $column_name, 'field_' ) ) {
			$key   = substr( $column_name, strlen( 'field_' ) );
			$value = $this->get_field_value( $item, $key );
			return $value ? esc_html( $value ) : '&mdash;';
		}

		switch ( $column_name ) {
			case 'downloads_count':
				return esc_html( number_format_i18n( (int) $item->downloads_count ) );
			case 'zerobounce_status':
				return esc_html( $item->zerobounce_status );
			case 'first_seen':
				return esc_html( $this->format_date( $item->first_seen ) );
			case 'last_seen':
				return esc_html( $this->format_date( $item->last_seen ) );
			default:
				return '';
		}
	}

	private function get_field_value( $item, $key ) {
		if ( empty( $item->field_data ) ) {
			return '';
		}
		$decoded = json_decode( $item->field_data, true );
		if ( ! is_array( $decoded ) || ! isset( $decoded[ $key ] ) ) {
			return '';
		}

		$field = null;
		foreach ( ADX_GR_Form_Fields::get_fields() as $f ) {
			if ( $f['key'] === $key ) {
				$field = $f;
				break;
			}
		}

		if ( $field && 'checkbox' === $field['type'] ) {
			return '1' === $decoded[ $key ] ? __( 'Yes', 'adx-gated-resources' ) : __( 'No', 'adx-gated-resources' );
		}

		return $decoded[ $key ];
	}

	private function format_date( $mysql_date ) {
		if ( empty( $mysql_date ) ) {
			return '';
		}
		return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $mysql_date ) );
	}

	public function no_items() {
		esc_html_e( 'No subscribers yet.', 'adx-gated-resources' );
	}
}
