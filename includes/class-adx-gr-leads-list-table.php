<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Renders the Downloads admin table: one row per gate pass / download
 * event (every visit that unlocked a resource, file, bundle, or page),
 * with the field values captured at that moment and how ZeroBounce
 * scored the email.
 */
class ADX_GR_Leads_List_Table extends WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'download',
				'plural'   => 'downloads',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		$columns = array( 'email' => __( 'Email', 'adx-gated-resources' ) );

		foreach ( ADX_GR_Form_Fields::get_fields() as $field ) {
			$columns[ 'field_' . $field['key'] ] = $field['label'];
		}

		$columns['resource']   = __( 'Resource', 'adx-gated-resources' );
		$columns['series']     = __( 'Series', 'adx-gated-resources' );
		$columns['status']     = __( 'ZeroBounce Status', 'adx-gated-resources' );
		$columns['via_cookie'] = __( 'Returning?', 'adx-gated-resources' );
		$columns['ip_address'] = __( 'IP', 'adx-gated-resources' );
		$columns['created_at'] = __( 'Date', 'adx-gated-resources' );

		return $columns;
	}

	protected function get_sortable_columns() {
		return array(
			'email'      => array( 'email', false ),
			'resource'   => array( 'resource_title', false ),
			'status'     => array( 'zerobounce_status', false ),
			'created_at' => array( 'created_at', true ),
		);
	}

	public function prepare_items() {
		$per_page = 20;
		$paged    = $this->get_pagenum();
		$search   = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		$orderby  = isset( $_REQUEST['orderby'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['orderby'] ) ) : 'created_at';
		$order    = isset( $_REQUEST['order'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['order'] ) ) : 'desc';

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$items = ADX_GR_DB::get_leads(
			array(
				'search'   => $search,
				'orderby'  => 'resource' === $orderby ? 'resource_title' : $orderby,
				'order'    => $order,
				'per_page' => $per_page,
				'paged'    => $paged,
			)
		);

		$total_items = ADX_GR_DB::count_leads( $search );

		// Load every row's categories in one go for the Series column.
		update_object_term_cache( array_filter( array_map( 'absint', wp_list_pluck( $items, 'resource_id' ) ) ), ADX_GR_CPT::POST_TYPE );

		$this->items = $items;

		$this->set_pagination_args(
			array(
				'total_items' => $total_items,
				'per_page'    => $per_page,
			)
		);
	}

	public function column_default( $item, $column_name ) {
		if ( 0 === strpos( $column_name, 'field_' ) ) {
			$key   = substr( $column_name, strlen( 'field_' ) );
			$value = ADX_GR_DB::get_row_field_value( $item, $key );
			return $value ? esc_html( $value ) : '&mdash;';
		}

		switch ( $column_name ) {
			case 'email':
				return esc_html( $item->email );
			case 'resource':
				return esc_html( $item->resource_title );
			case 'series':
				$series = ADX_GR_CPT::get_series_names( $item->resource_id );
				return $series ? esc_html( implode( ', ', $series ) ) : '&mdash;';
			case 'status':
				return esc_html( $item->zerobounce_status );
			case 'via_cookie':
				return $item->via_cookie ? esc_html__( 'Yes', 'adx-gated-resources' ) : esc_html__( 'No', 'adx-gated-resources' );
			case 'ip_address':
				return esc_html( $item->ip_address );
			case 'created_at':
				return esc_html(
					date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $item->created_at ) )
				);
			default:
				return '';
		}
	}

	public function no_items() {
		esc_html_e( 'No downloads logged yet.', 'adx-gated-resources' );
	}
}
