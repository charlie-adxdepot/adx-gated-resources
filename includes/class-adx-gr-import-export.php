<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export and import of the library setup as one CSV file, for moving it
 * from one site to another (e.g. staging to live): ADX Gated Resources >
 * Import / Export.
 *
 * The file has one row per Category (series) and one per Resource, told
 * apart by the record_type column. Records are matched by slug, so
 * importing the same file again updates rather than duplicates.
 *
 * - Category rows: title, slug, parent (slug), code, headline,
 *   card_description, logo (image address), order.
 * - Resource rows: title, slug, status, categories (slugs, separated by |),
 *   gate_type (file or bundle), file_url, bundle_urls / bundle_labels
 *   (separated by |), auto_gate, badges (separated by commas), image
 *   (featured image address).
 *
 * File links are carried over as links (the files themselves are not
 * copied), with an optional find-and-replace on the address so staging
 * links can point to the live site. Images are copied into the Media
 * Library of the site importing them.
 *
 * Importing is two steps: the file is read and checked first (nothing is
 * saved), and only a second click makes the changes.
 */
class ADX_GR_Import_Export {

	const PAGE      = 'adx-gr-import-export';
	const MAX_BYTES = 5 * MB_IN_BYTES;
	const MAX_CHECK = 25;

	const COLUMNS = array(
		'record_type',
		'title',
		'slug',
		'parent',
		'status',
		'categories',
		'gate_type',
		'file_url',
		'bundle_urls',
		'bundle_labels',
		'auto_gate',
		'badges',
		'image',
		'code',
		'headline',
		'card_description',
		'logo',
		'order',
		'hide_in_library',
	);

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_post_adx_gr_export_library', array( $this, 'export' ) );
		add_action( 'admin_post_adx_gr_import_preview', array( $this, 'preview' ) );
		add_action( 'admin_post_adx_gr_import_select', array( $this, 'select' ) );
		add_action( 'admin_post_adx_gr_import_run', array( $this, 'run' ) );
		add_action( 'wp_ajax_adx_gr_import_images', array( $this, 'ajax_images' ) );
	}

	/* ------------------------------------------------------------------
	 * Choosing categories (export and import)
	 * --------------------------------------------------------------- */

	/**
	 * The categories ticked in a list drawn by render_category_list(): the
	 * slugs ("__none" stands for resources without a category), or null if
	 * no list was submitted, which means everything.
	 */
	private function posted_selection() {
		if ( empty( $_POST['adx_gr_cats_present'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return null;
		}

		$posted = isset( $_POST['cats'] ) ? (array) wp_unslash( $_POST['cats'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
		$slugs  = array();

		foreach ( $posted as $slug ) {
			$slug    = sanitize_text_field( $slug );
			$slugs[] = '__none' === $slug ? $slug : sanitize_title( $slug );
		}

		return array_values( array_unique( array_filter( $slugs ) ) );
	}

	private function row_slug( array $row ) {
		return sanitize_title( ! empty( $row['slug'] ) ? $row['slug'] : ( isset( $row['title'] ) ? $row['title'] : '' ) );
	}

	private function row_categories( array $row ) {
		$slugs = preg_split( '/[|;,]/', isset( $row['categories'] ) ? (string) $row['categories'] : '', -1, PREG_SPLIT_NO_EMPTY );

		return array_values( array_filter( array_map( 'sanitize_title', $slugs ) ) );
	}

	/**
	 * Keeps the ticked categories (plus the parents they hang under, so the
	 * hierarchy survives) and the resources in any of them. $selected null
	 * keeps everything.
	 */
	private function filter_rows( array $rows, $selected ) {
		if ( null === $selected ) {
			return $rows;
		}

		$parent_of = array();

		foreach ( $rows as $row ) {
			if ( 'category' === $row['record_type'] ) {
				$parent_of[ $this->row_slug( $row ) ] = isset( $row['parent'] ) ? sanitize_title( $row['parent'] ) : '';
			}
		}

		$keep = array();

		foreach ( $selected as $slug ) {
			$slug = (string) $slug;

			while ( '' !== $slug && isset( $parent_of[ $slug ] ) && ! isset( $keep[ $slug ] ) ) {
				$keep[ $slug ] = true;
				$slug          = (string) $parent_of[ $slug ];
			}
		}

		$out = array();

		foreach ( $rows as $row ) {
			if ( 'category' === $row['record_type'] ) {
				if ( isset( $keep[ $this->row_slug( $row ) ] ) ) {
					$out[] = $row;
				}
			} elseif ( 'resource' === $row['record_type'] ) {
				$cats = $this->row_categories( $row );

				if ( $cats ? array_intersect( $cats, $selected ) : in_array( '__none', $selected, true ) ) {
					$out[] = $row;
				}
			}
		}

		return $out;
	}

	/**
	 * The categories in a set of rows, parents first with their series
	 * under them, each with how many resources are in it.
	 */
	private function category_items( array $rows ) {
		$cats = array();
		$none = 0;

		foreach ( $rows as $row ) {
			if ( 'category' === $row['record_type'] ) {
				$slug          = $this->row_slug( $row );
				$cats[ $slug ] = array(
					'slug'   => $slug,
					'title'  => $row['title'],
					'parent' => isset( $row['parent'] ) ? sanitize_title( $row['parent'] ) : '',
					'count'  => 0,
				);
			}
		}

		foreach ( $rows as $row ) {
			if ( 'resource' !== $row['record_type'] ) {
				continue;
			}

			$list = $this->row_categories( $row );

			if ( ! $list ) {
				$none++;
				continue;
			}

			foreach ( $list as $slug ) {
				if ( ! isset( $cats[ $slug ] ) ) {
					// Used by a resource but not described in the file.
					$cats[ $slug ] = array(
						'slug'   => $slug,
						'title'  => $slug,
						'parent' => '',
						'count'  => 0,
					);
				}
				$cats[ $slug ]['count']++;
			}
		}

		$children = array();

		foreach ( $cats as $cat ) {
			$parent                = '' !== $cat['parent'] && isset( $cats[ $cat['parent'] ] ) && $cat['parent'] !== $cat['slug'] ? $cat['parent'] : '';
			$children[ $parent ][] = $cat['slug'];
		}

		$items = array();
		$seen  = array();
		$walk  = function ( $parent, $depth ) use ( &$walk, &$children, &$cats, &$items, &$seen ) {
			if ( empty( $children[ $parent ] ) ) {
				return;
			}

			foreach ( $children[ $parent ] as $slug ) {
				if ( isset( $seen[ $slug ] ) ) {
					continue;
				}

				$seen[ $slug ] = true;
				$items[]       = array(
					'slug'  => (string) $slug,
					'title' => $cats[ $slug ]['title'],
					'depth' => $depth,
					'count' => $cats[ $slug ]['count'],
				);
				$walk( (string) $slug, $depth + 1 );
			}
		};
		$walk( '', 0 );

		if ( $none ) {
			$items[] = array(
				'slug'  => '__none',
				'title' => __( 'Resources without a category', 'adx-gated-resources' ),
				'depth' => 0,
				'count' => $none,
			);
		}

		return $items;
	}

	/**
	 * A tick list of categories (series indented under their parent) with
	 * Select all / none. Ticking a parent ticks the series under it.
	 *
	 * @param array      $items    From category_items().
	 * @param array|null $selected Slugs to tick; null ticks everything.
	 */
	private function render_category_list( array $items, $selected ) {
		?>
		<input type="hidden" name="adx_gr_cats_present" value="1" />
		<div class="adx-gr-ie-cats" style="max-width:640px;background:#fff;border:1px solid #c3c4c7;padding:8px 14px">
			<p style="margin:4px 0 8px">
				<a href="#" class="adx-gr-ie-all"><?php esc_html_e( 'Select all', 'adx-gated-resources' ); ?></a> |
				<a href="#" class="adx-gr-ie-none"><?php esc_html_e( 'Select none', 'adx-gated-resources' ); ?></a>
			</p>
			<?php foreach ( $items as $item ) : ?>
				<label style="display:block;margin:3px 0 3px <?php echo (int) $item['depth'] * 22; ?>px">
					<input type="checkbox" name="cats[]" value="<?php echo esc_attr( $item['slug'] ); ?>" data-depth="<?php echo (int) $item['depth']; ?>" <?php checked( null === $selected || in_array( $item['slug'], $selected, true ) ); ?> />
					<?php echo esc_html( $item['title'] ); ?>
					<span class="description">(<?php echo esc_html( sprintf( /* translators: %d: number of resources */ _n( '%d resource', '%d resources', $item['count'], 'adx-gated-resources' ), $item['count'] ) ); ?>)</span>
				</label>
			<?php endforeach; ?>
		</div>
		<script>
		( function () {
			var box = document.currentScript.previousElementSibling;
			var checks = Array.prototype.slice.call( box.querySelectorAll( 'input[type=checkbox]' ) );

			function setAll( value ) {
				checks.forEach( function ( c ) { c.checked = value; } );
			}

			box.querySelector( '.adx-gr-ie-all' ).addEventListener( 'click', function ( e ) { e.preventDefault(); setAll( true ); } );
			box.querySelector( '.adx-gr-ie-none' ).addEventListener( 'click', function ( e ) { e.preventDefault(); setAll( false ); } );

			checks.forEach( function ( check, i ) {
				check.addEventListener( 'change', function () {
					var depth = parseInt( check.getAttribute( 'data-depth' ), 10 );
					for ( var j = i + 1; j < checks.length && parseInt( checks[ j ].getAttribute( 'data-depth' ), 10 ) > depth; j++ ) {
						checks[ j ].checked = check.checked;
					}
				} );
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Saves a new choice of categories for the import being checked, then
	 * goes back to the summary.
	 */
	public function select() {
		$this->check_access( 'adx_gr_import_select' );

		$key   = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$state = $this->load_state( $key );

		if ( ! $state ) {
			$this->back_with_notice( __( 'That import has expired. Upload the file again.', 'adx-gated-resources' ) );
		}

		$state['sel'] = $this->posted_selection();
		set_transient( 'adx_gr_import_' . $key, $state, HOUR_IN_SECONDS );

		wp_safe_redirect( add_query_arg( array( 'step' => 'preview', 'key' => $key ), $this->page_url() ) );
		exit;
	}

	/* ------------------------------------------------------------------
	 * Export
	 * --------------------------------------------------------------- */

	public function export() {
		$this->check_access( 'adx_gr_export_library' );

		$selected = $this->posted_selection();
		$rows     = $this->filter_rows( $this->export_rows(), $selected );

		if ( ! $rows ) {
			$this->back_with_notice( __( 'Tick at least one category to export.', 'adx-gated-resources' ) );
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="adx-gated-resources-' . wp_date( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		// Byte order mark, so Excel opens it as UTF-8.
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fputcsv( $out, self::COLUMNS, ',', '"', '\\' );

		foreach ( $rows as $row ) {
			$cells = array();
			foreach ( self::COLUMNS as $column ) {
				$cells[] = isset( $row[ $column ] ) ? $row[ $column ] : '';
			}
			fputcsv( $out, $cells, ',', '"', '\\' );
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	private function export_rows() {
		$rows = array();
		$tax  = ADX_GR_CPT::TAX_CATEGORY;

		// Parents before their children, each level in Display order.
		$terms = get_terms(
			array(
				'taxonomy'   => $tax,
				'hide_empty' => false,
			)
		);
		$terms = is_array( $terms ) ? ADX_GR_Library_Terms::sort_terms( $terms ) : array();
		$depth = array();

		foreach ( $terms as $term ) {
			$depth[ count( get_ancestors( $term->term_id, $tax, 'taxonomy' ) ) ][] = $term;
		}
		ksort( $depth );

		foreach ( $depth as $level_terms ) {
			foreach ( $level_terms as $term ) {
				$parent  = $term->parent ? get_term( $term->parent, $tax ) : null;
				$logo_id = absint( get_term_meta( $term->term_id, ADX_GR_Library_Terms::META_LOGO, true ) );

				$rows[] = array(
					'record_type'      => 'category',
					'title'            => $term->name,
					'slug'             => $term->slug,
					'parent'           => $parent instanceof WP_Term ? $parent->slug : '',
					'code'             => (string) get_term_meta( $term->term_id, ADX_GR_Library_Terms::META_CODE, true ),
					'headline'         => (string) get_term_meta( $term->term_id, ADX_GR_Library_Terms::META_HEADLINE, true ),
					'card_description' => ADX_GR_Library_Terms::description( $term->term_id ),
					'logo'             => $logo_id ? (string) wp_get_attachment_url( $logo_id ) : '',
					'order'            => (string) get_term_meta( $term->term_id, ADX_GR_Library_Terms::META_ORDER, true ),
				);
			}
		}

		$posts = get_posts(
			array(
				'post_type'      => ADX_GR_CPT::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		foreach ( $posts as $post ) {
			$gate  = ADX_GR_CPT::get_gate_type( $post->ID );
			$files = ADX_GR_CPT::get_bundle_files( $post->ID );
			$slugs = wp_get_post_terms( $post->ID, $tax, array( 'fields' => 'slugs' ) );

			$rows[] = array(
				'record_type'   => 'resource',
				'title'         => $post->post_title,
				'slug'          => $post->post_name,
				'status'        => $post->post_status,
				'categories'    => is_array( $slugs ) ? implode( '|', $slugs ) : '',
				'gate_type'     => $gate,
				'file_url'      => (string) get_post_meta( $post->ID, '_adx_gr_file_url', true ),
				'bundle_urls'   => implode( '|', wp_list_pluck( $files, 'url' ) ),
				'bundle_labels' => implode( '|', wp_list_pluck( $files, 'label' ) ),
				'auto_gate'     => '0' === get_post_meta( $post->ID, '_adx_gr_auto_gate', true ) ? '0' : '1',
				'badges'        => implode( ', ', ADX_GR_CPT::get_badges( $post->ID ) ),
				'hide_in_library' => '1' === get_post_meta( $post->ID, '_adx_gr_hide_in_library', true ) ? '1' : '',
				'image'         => (string) get_the_post_thumbnail_url( $post->ID, 'full' ),
			);
		}

		return $rows;
	}

	/* ------------------------------------------------------------------
	 * Import, step 1: read the file and show what would happen
	 * --------------------------------------------------------------- */

	public function preview() {
		$this->check_access( 'adx_gr_import_preview' );

		$file = isset( $_FILES['adx_gr_csv'] ) ? $_FILES['adx_gr_csv'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput, WordPress.Security.NonceVerification.Missing

		if ( ! $file || empty( $file['tmp_name'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			$this->back_with_notice( __( 'Choose a CSV file to import.', 'adx-gated-resources' ) );
		}

		if ( (int) $file['size'] > self::MAX_BYTES ) {
			$this->back_with_notice( __( 'That file is too large (5 MB is the limit).', 'adx-gated-resources' ) );
		}

		if ( 'csv' !== strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) ) ) {
			$this->back_with_notice( __( 'That is not a .csv file.', 'adx-gated-resources' ) );
		}

		$contents = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$parsed   = $this->parse_csv( (string) $contents );

		if ( is_string( $parsed ) ) {
			$this->back_with_notice( $parsed );
		}

		if ( ! $parsed ) {
			$this->back_with_notice( __( 'The file has no records in it.', 'adx-gated-resources' ) );
		}

		$find    = isset( $_POST['adx_gr_find'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['adx_gr_find'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$replace = isset( $_POST['adx_gr_replace'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['adx_gr_replace'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		$key = strtolower( wp_generate_password( 20, false ) );

		set_transient(
			'adx_gr_import_' . $key,
			array(
				'user' => get_current_user_id(),
				'sel'  => null,
				'rows' => $parsed,
				'opts' => array(
					'find'     => $find,
					'replace'  => $replace,
					'existing' => isset( $_POST['adx_gr_existing'] ) && 'skip' === $_POST['adx_gr_existing'] ? 'skip' : 'update', // phpcs:ignore WordPress.Security.NonceVerification.Missing
				),
			),
			HOUR_IN_SECONDS
		);

		wp_safe_redirect( add_query_arg( array( 'step' => 'preview', 'key' => $key ), $this->page_url() ) );
		exit;
	}

	/**
	 * @return array|string Rows as arrays keyed by column name, or an error message.
	 */
	private function parse_csv( $contents ) {
		$contents = preg_replace( '/^\xEF\xBB\xBF/', '', $contents );
		$first    = (string) strtok( $contents, "\n" );

		$counts = array(
			','  => substr_count( $first, ',' ),
			';'  => substr_count( $first, ';' ),
			"\t" => substr_count( $first, "\t" ),
		);
		arsort( $counts );
		$delimiter = (string) key( $counts );

		$stream = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		fwrite( $stream, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		rewind( $stream );

		$header = fgetcsv( $stream, 0, $delimiter, '"', '\\' );

		if ( ! is_array( $header ) ) {
			return __( 'The file could not be read as a CSV.', 'adx-gated-resources' );
		}

		$header = array_map(
			function ( $name ) {
				return strtolower( trim( (string) $name ) );
			},
			$header
		);

		if ( ! in_array( 'record_type', $header, true ) || ! in_array( 'title', $header, true ) ) {
			return __( 'This does not look like an export from this plugin: the record_type and title columns are missing.', 'adx-gated-resources' );
		}

		$rows = array();
		$line = 1;

		while ( false !== ( $cells = fgetcsv( $stream, 0, $delimiter, '"', '\\' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition
			$line++;
			$row = array( '_row' => $line );

			foreach ( $header as $i => $name ) {
				if ( '' !== $name ) {
					$row[ $name ] = isset( $cells[ $i ] ) ? trim( (string) $cells[ $i ] ) : '';
				}
			}

			$has_data = false;
			foreach ( self::COLUMNS as $column ) {
				$row[ $column ] = isset( $row[ $column ] ) ? $row[ $column ] : '';
				$has_data       = $has_data || '' !== $row[ $column ];
			}

			if ( $has_data ) {
				$row['record_type'] = strtolower( $row['record_type'] );
				$rows[]             = $row;
			}
		}

		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $rows;
	}

	/* ------------------------------------------------------------------
	 * Import, step 2: do it
	 * --------------------------------------------------------------- */

	public function run() {
		$this->check_access( 'adx_gr_import_run' );

		$key   = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$state = $this->load_state( $key );

		if ( ! $state ) {
			$this->back_with_notice( __( 'That import has expired. Upload the file again.', 'adx-gated-resources' ) );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit
		}

		$rows = $this->filter_rows( $state['rows'], $state['sel'] );

		if ( ! $rows ) {
			$this->back_with_notice( __( 'Tick at least one category to import.', 'adx-gated-resources' ) );
		}

		// Records first, all of them, without waiting for any image; the
		// images are copied afterwards in small batches (see ajax_images()),
		// so a slow download or a host's time limit can never cut the import
		// short and leave resources out.
		$report            = $this->process( $rows, $state['opts'], false, true );
		$report['tidied']  = $this->clean_attachment_leftovers();

		$this->recount_categories();
		$report['verify'] = $this->verify( $rows );

		$tasks = $report['tasks'];
		unset( $report['tasks'] );
		$report['images']['queued'] = count( $tasks );

		ADX_GR_CPT::flush_resource_map_cache();
		delete_transient( 'adx_gr_import_' . $key );
		set_transient( 'adx_gr_import_done_' . $key, $report, HOUR_IN_SECONDS );

		if ( $tasks ) {
			set_transient(
				'adx_gr_import_img_' . $key,
				array(
					'user'     => get_current_user_id(),
					'tasks'    => $tasks,
					'next'     => 0,
					'copied'   => 0,
					'reused'   => 0,
					'failed'   => 0,
				),
				DAY_IN_SECONDS
			);
		}

		wp_safe_redirect( add_query_arg( array( 'step' => 'done', 'key' => $key ), $this->page_url() ) );
		exit;
	}

	/**
	 * Goes through the rows. With $dry set nothing is saved: it only counts
	 * what would happen and collects warnings.
	 *
	 * @return array new/update/skip counts for categories and resources,
	 *               image counts, warnings and the file links used.
	 */
	private function process( array $rows, array $opts, $dry, $defer = false ) {
		$r = array(
			'categories' => array( 'new' => 0, 'update' => 0, 'skip' => 0 ),
			'resources'  => array( 'new' => 0, 'update' => 0, 'skip' => 0 ),
			'images'     => array( 'new' => 0, 'reuse' => 0, 'failed' => 0 ),
			'warnings'   => array(),
			'files'      => array(),
			'tasks'      => array(), // images still to copy, when $defer is set
		);

		$tax    = ADX_GR_CPT::TAX_CATEGORY;
		$update = 'skip' !== $opts['existing'];
		$ids    = array(); // category slug => term id (0 for one a dry run only pretends to create)
		$names  = array(); // category slug => name, for finding a category whose slug differs on this site
		$parent = array(); // category slug => parent slug

		// Categories: create or update each, then set the parents.
		foreach ( $rows as $row ) {
			if ( 'category' !== $row['record_type'] ) {
				if ( 'resource' !== $row['record_type'] ) {
					$r['warnings'][] = sprintf(
						/* translators: 1: row number, 2: what the record_type said */
						__( 'Row %1$d: record_type "%2$s" is not category or resource, so the row was skipped.', 'adx-gated-resources' ),
						$row['_row'],
						$row['record_type']
					);
				}
				continue;
			}

			$name = $row['title'];
			$slug = sanitize_title( '' !== $row['slug'] ? $row['slug'] : $name );

			if ( '' === $name || '' === $slug ) {
				/* translators: %d: row number */
				$r['warnings'][] = sprintf( __( 'Row %d: a category needs a title, so the row was skipped.', 'adx-gated-resources' ), $row['_row'] );
				continue;
			}

			$names[ $slug ] = $name;

			// By slug, or failing that by name: WordPress can give a new
			// category a slightly different slug when another part of the
			// site already uses it, and the category must still be found.
			$term = get_term_by( 'slug', $slug, $tax );
			$term = $term ? $term : get_term_by( 'name', $name, $tax );

			if ( $term ) {
				$ids[ $slug ] = $term->term_id;

				if ( ! $update ) {
					$r['categories']['skip']++;
					continue;
				}

				$r['categories']['update']++;
				$id = $term->term_id;

				if ( ! $dry ) {
					wp_update_term( $id, $tax, array( 'name' => $name ) );
				}
			} else {
				$r['categories']['new']++;
				$id = 0;

				if ( ! $dry ) {
					$created = wp_insert_term( $name, $tax, array( 'slug' => $slug ) );

					if ( is_wp_error( $created ) ) {
						$r['categories']['new']--;

						// Already there under another slug: use that one.
						if ( 'term_exists' === $created->get_error_code() && (int) $created->get_error_data() ) {
							$id = (int) $created->get_error_data();
							$r['categories']['update']++;
						} else {
							/* translators: 1: row number, 2: error message */
							$r['warnings'][] = sprintf( __( 'Row %1$d: the category could not be created (%2$s).', 'adx-gated-resources' ), $row['_row'], $created->get_error_message() );
							continue;
						}
					} else {
						$id = (int) $created['term_id'];
					}
				}

				$ids[ $slug ] = $id;
			}

			$parent[ $slug ] = sanitize_title( $row['parent'] );

			$logo_id = $this->image_for( $row['logo'], $id, 'term', $dry, $defer, $r, $row['_row'] );

			if ( ! $dry ) {
				$this->save_term_fields( $id, $row, $logo_id );
			}
		}

		foreach ( $parent as $slug => $parent_slug ) {
			if ( '' === $parent_slug ) {
				continue;
			}

			if ( ! isset( $ids[ $parent_slug ] ) ) {
				$existing = get_term_by( 'slug', $parent_slug, $tax );

				if ( ! $existing ) {
					/* translators: 1: category slug, 2: parent slug */
					$r['warnings'][] = sprintf( __( 'Category "%1$s": its parent "%2$s" is not in the file or on this site, so it was left at the top level.', 'adx-gated-resources' ), $slug, $parent_slug );
					continue;
				}

				$ids[ $parent_slug ] = $existing->term_id;
			}

			if ( ! $dry && ! empty( $ids[ $slug ] ) ) {
				wp_update_term( $ids[ $slug ], $tax, array( 'parent' => $ids[ $parent_slug ] ) );
			}
		}

		// Resources.
		foreach ( $rows as $row ) {
			if ( 'resource' !== $row['record_type'] ) {
				continue;
			}

			$title = $row['title'];
			$slug  = sanitize_title( '' !== $row['slug'] ? $row['slug'] : $title );

			if ( '' === $title || '' === $slug ) {
				/* translators: %d: row number */
				$r['warnings'][] = sprintf( __( 'Row %d: a resource needs a title, so the row was skipped.', 'adx-gated-resources' ), $row['_row'] );
				continue;
			}

			$existing = $this->find_resource( $slug );
			$status   = in_array( $row['status'], array( 'publish', 'draft', 'pending', 'private' ), true ) ? $row['status'] : 'publish';

			if ( $existing && ! $update ) {
				$r['resources']['skip']++;
				continue;
			}

			$gate    = 'bundle' === strtolower( $row['gate_type'] ) ? 'bundle' : 'file';
			$file    = $this->swap( $row['file_url'], $opts );
			$urls    = array_map( function ( $url ) use ( $opts ) {
				return $this->swap( $url, $opts );
			}, $this->split( $row['bundle_urls'] ) );
			$labels  = $this->split( $row['bundle_labels'], true );
			$cat_ids    = array();
			$unresolved = false;

			if ( 'file' === $gate && '' === $file ) {
				/* translators: %s: resource title */
				$r['warnings'][] = sprintf( __( '"%s" has no file link.', 'adx-gated-resources' ), $title );
			}

			if ( 'bundle' === $gate && ! $urls ) {
				/* translators: %s: resource title */
				$r['warnings'][] = sprintf( __( '"%s" is a bundle with no file links.', 'adx-gated-resources' ), $title );
			}

			foreach ( 'file' === $gate ? array( $file ) : $urls as $url ) {
				if ( '' !== $url ) {
					$r['files'][ $url ] = true;
				}
			}

			foreach ( preg_split( '/[|;,]/', $row['categories'], -1, PREG_SPLIT_NO_EMPTY ) as $cat_slug ) {
				$cat_slug = sanitize_title( $cat_slug );

				if ( ! isset( $ids[ $cat_slug ] ) ) {
					$term = get_term_by( 'slug', $cat_slug, $tax );
					$term = $term || ! isset( $names[ $cat_slug ] ) ? $term : get_term_by( 'name', $names[ $cat_slug ], $tax );

					if ( $term ) {
						$ids[ $cat_slug ] = $term->term_id;
					}
				}

				if ( isset( $ids[ $cat_slug ] ) ) {
					$cat_ids[] = (int) $ids[ $cat_slug ];
				} else {
					$unresolved = true;
					/* translators: 1: resource title, 2: category slug */
					$r['warnings'][] = sprintf( __( '"%1$s": the category "%2$s" is not in the file or on this site, so it was not assigned.', 'adx-gated-resources' ), $title, $cat_slug );
				}
			}

			if ( $existing ) {
				$r['resources']['update']++;
			} else {
				$r['resources']['new']++;
			}

			if ( $dry ) {
				$this->image_for( $row['image'], 0, 'post', true, $defer, $r, $row['_row'] );
				continue;
			}

			if ( $existing ) {
				$post_id = (int) $existing->ID;
				wp_update_post(
					array(
						'ID'          => $post_id,
						'post_title'  => $title,
						'post_status' => $status,
					)
				);
			} else {
				$post_id = wp_insert_post(
					array(
						'post_type'   => ADX_GR_CPT::POST_TYPE,
						'post_title'  => $title,
						'post_name'   => $slug,
						'post_status' => $status,
					),
					true
				);

				if ( is_wp_error( $post_id ) || ! $post_id ) {
					$r['resources']['new']--;
					/* translators: 1: resource title, 2: error message */
					$r['warnings'][] = sprintf( __( '"%1$s" could not be created (%2$s).', 'adx-gated-resources' ), $title, is_wp_error( $post_id ) ? $post_id->get_error_message() : '' );
					continue;
				}
			}

			update_post_meta( $post_id, '_adx_gr_gate_type', $gate );
			update_post_meta( $post_id, '_adx_gr_file_url', esc_url_raw( $file ) );
			update_post_meta( $post_id, '_adx_gr_attachment_id', $file ? absint( attachment_url_to_postid( $file ) ) : 0 );

			$files = array();
			foreach ( $urls as $i => $url ) {
				$url = esc_url_raw( $url );
				if ( '' !== $url ) {
					$files[] = array(
						'url'           => $url,
						'label'         => isset( $labels[ $i ] ) ? sanitize_text_field( $labels[ $i ] ) : '',
						'attachment_id' => absint( attachment_url_to_postid( $url ) ),
					);
				}
			}
			update_post_meta( $post_id, '_adx_gr_bundle_files', $files );

			if ( '' !== $row['auto_gate'] ) {
				update_post_meta( $post_id, '_adx_gr_auto_gate', in_array( strtolower( $row['auto_gate'] ), array( '1', 'yes', 'true', 'on' ), true ) ? '1' : '0' );
			} elseif ( ! $existing ) {
				update_post_meta( $post_id, '_adx_gr_auto_gate', '1' );
			}

			update_post_meta( $post_id, '_adx_gr_badges', array_values( array_filter( array_map( 'trim', explode( ',', $row['badges'] ) ) ) ) );

			// Blank (an older file) leaves a resource's setting alone.
			if ( '' !== $row['hide_in_library'] ) {
				update_post_meta( $post_id, '_adx_gr_hide_in_library', in_array( strtolower( $row['hide_in_library'] ), array( '1', 'yes', 'true', 'on' ), true ) ? '1' : '0' );
			}

			// A category that could not be found must not strip the ones the
			// resource already has: add to them instead of replacing them.
			wp_set_object_terms( $post_id, array_values( array_unique( $cat_ids ) ), $tax, $unresolved );

			$image_id = $this->image_for( $row['image'], $post_id, 'post', false, $defer, $r, $row['_row'] );

			if ( $image_id ) {
				set_post_thumbnail( $post_id, $image_id );
			}
		}

		$r['files'] = array_keys( $r['files'] );

		return $r;
	}

	/**
	 * The resource with this slug, or null. Only a real resource counts:
	 * WordPress's get_page_by_path() also searches the Media Library, so a
	 * picture with the same slug (a family's image is often named like the
	 * family) was mistaken for the resource and "updated" in its place,
	 * which left the resource itself uncreated.
	 */
	private function find_resource( $slug ) {
		$found = get_posts(
			array(
				'post_type'      => ADX_GR_CPT::POST_TYPE,
				'name'           => $slug,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => 1,
				'no_found_rows'  => true,
			)
		);

		return $found ? $found[0] : null;
	}

	/**
	 * Earlier versions of the import could write a resource's data onto a
	 * Media Library picture that had the same slug. Takes that data (and the
	 * category) off any picture that carries it.
	 *
	 * @return int Number of pictures tidied.
	 */
	private function clean_attachment_leftovers() {
		$pictures = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_adx_gr_gate_type', // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);

		foreach ( $pictures as $id ) {
			foreach ( array( '_adx_gr_gate_type', '_adx_gr_file_url', '_adx_gr_attachment_id', '_adx_gr_bundle_files', '_adx_gr_auto_gate', '_adx_gr_badges', '_adx_gr_download_info' ) as $key ) {
				delete_post_meta( $id, $key );
			}

			wp_set_object_terms( $id, array(), ADX_GR_CPT::TAX_CATEGORY, false );
		}

		return count( $pictures );
	}

	private function save_term_fields( $term_id, array $row, $logo_id ) {
		$fields = array(
			ADX_GR_Library_Terms::META_CODE     => $row['code'],
			ADX_GR_Library_Terms::META_HEADLINE => $row['headline'],
			ADX_GR_Library_Terms::META_ORDER    => '' !== $row['order'] ? (string) (int) $row['order'] : '',
			ADX_GR_Library_Terms::META_LOGO     => $logo_id ? $logo_id : '',
		);

		// null: the logo is still being copied, and is set once it has been.
		if ( null === $logo_id ) {
			unset( $fields[ ADX_GR_Library_Terms::META_LOGO ] );
		}

		foreach ( $fields as $key => $value ) {
			if ( '' === $value ) {
				delete_term_meta( $term_id, $key );
			} else {
				update_term_meta( $term_id, $key, $value );
			}
		}

		// Stored even when empty, as the category screen does.
		update_term_meta( $term_id, ADX_GR_Library_Terms::META_DESC, sanitize_textarea_field( $row['card_description'] ) );
	}

	/**
	 * An image already in this site's Media Library for an address: one at
	 * that address on this site, or one copied by an earlier import.
	 *
	 * @return int Attachment ID, or 0.
	 */
	private function find_image( $url ) {
		$local = absint( attachment_url_to_postid( $url ) );

		if ( $local ) {
			return $local;
		}

		$earlier = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_adx_gr_source_url', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => $url, // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);

		return $earlier ? (int) $earlier[0] : 0;
	}

	/**
	 * What to do about an image address while importing: use the image if
	 * it is already here, otherwise count it (dry run), queue it to be
	 * copied afterwards ($defer), or copy it now.
	 *
	 * @return int|null Attachment ID if there is one; 0 if there is none (no
	 *                  address, or it could not be copied); null if the image
	 *                  is queued and will be attached once it is copied.
	 */
	private function image_for( $url, $target_id, $kind, $dry, $defer, array &$r, $row_number ) {
		if ( '' === $url || ! preg_match( '#^https?://#i', $url ) ) {
			return 0;
		}

		$found = $this->find_image( $url );

		if ( $found ) {
			$r['images']['reuse']++;
			return $found;
		}

		if ( $dry ) {
			$r['images']['new']++;
			return 0;
		}

		if ( $defer ) {
			$r['images']['new']++;
			$r['tasks'][] = array(
				'url'  => $url,
				'kind' => $kind,
				'id'   => (int) $target_id,
				'row'  => (int) $row_number,
			);

			return null;
		}

		$error = '';
		$id    = $this->fetch_image( $url, $error );

		if ( $id ) {
			$r['images']['new']++;
			return $id;
		}

		$r['images']['failed']++;
		/* translators: 1: row number, 2: image address, 3: error message */
		$r['warnings'][] = sprintf( __( 'Row %1$d: the image %2$s could not be copied (%3$s).', 'adx-gated-resources' ), $row_number, $url, $error );

		return 0;
	}

	/**
	 * Downloads an image into the Media Library.
	 *
	 * @param string $error Set to the reason when it fails.
	 * @return int Attachment ID, or 0.
	 */
	private function fetch_image( $url, &$error ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$tmp = download_url( $url, 60 );

		if ( is_wp_error( $tmp ) ) {
			$error = $tmp->get_error_message();
			return 0;
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );
		$id   = media_handle_sideload(
			array(
				'name'     => sanitize_file_name( rawurldecode( wp_basename( (string) $path ) ) ),
				'tmp_name' => $tmp,
			),
			0
		);

		if ( is_wp_error( $id ) ) {
			wp_delete_file( $tmp );
			$error = $id->get_error_message();
			return 0;
		}

		update_post_meta( $id, '_adx_gr_source_url', $url );

		return (int) $id;
	}

	/**
	 * Copies the queued images a few at a time; the finished page calls
	 * this over and over until none are left. Progress is saved after every
	 * image, so a request that is cut off loses at most one.
	 */
	public function ajax_images() {
		check_ajax_referer( 'adx_gr_import_images', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'adx-gated-resources' ) ), 403 );
		}

		$key = isset( $_POST['key'] ) ? sanitize_key( wp_unslash( $_POST['key'] ) ) : '';
		$job = get_transient( 'adx_gr_import_img_' . $key );

		if ( ! is_array( $job ) || (int) $job['user'] !== get_current_user_id() ) {
			wp_send_json_error( array( 'message' => __( 'This import has expired.', 'adx-gated-resources' ) ) );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 90 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit
		}

		$total    = count( $job['tasks'] );
		$started  = microtime( true );
		$messages = array();

		while ( $job['next'] < $total && ( microtime( true ) - $started ) < 12 ) {
			$task = $job['tasks'][ $job['next'] ];
			$job['next']++;

			$id = $this->find_image( $task['url'] );

			if ( $id ) {
				$job['reused']++;
			} else {
				$error = '';
				$id    = $this->fetch_image( $task['url'], $error );

				if ( $id ) {
					$job['copied']++;
				} else {
					$job['failed']++;
					/* translators: 1: row number, 2: image address, 3: error message */
					$messages[] = sprintf( __( 'Row %1$d: the image %2$s could not be copied (%3$s).', 'adx-gated-resources' ), $task['row'], $task['url'], $error );
				}
			}

			if ( $id && $task['id'] ) {
				if ( 'term' === $task['kind'] ) {
					update_term_meta( $task['id'], ADX_GR_Library_Terms::META_LOGO, $id );
				} else {
					set_post_thumbnail( $task['id'], $id );
				}
			}

			set_transient( 'adx_gr_import_img_' . $key, $job, DAY_IN_SECONDS );
		}

		wp_send_json_success(
			array(
				'done'     => $job['next'],
				'total'    => $total,
				'copied'   => $job['copied'],
				'reused'   => $job['reused'],
				'failed'   => $job['failed'],
				'messages' => $messages,
				'finished' => $job['next'] >= $total,
			)
		);
	}

	/**
	 * After an import: checks every resource in the file against what is
	 * now on this site: that it exists, is in the status the file gives it
	 * (a draft is not shown in the library), and is in every category the
	 * file lists for it.
	 *
	 * @return array expected count, and lists of what is missing, in the
	 *               wrong status, or not in its category.
	 */
	private function verify( array $rows ) {
		$tax    = ADX_GR_CPT::TAX_CATEGORY;
		$names  = array();
		$result = array(
			'expected' => 0,
			'missing'  => array(),
			'status'   => array(),
			'category' => array(),
		);

		foreach ( $rows as $row ) {
			if ( 'category' === $row['record_type'] ) {
				$names[ $this->row_slug( $row ) ] = $row['title'];
			}
		}

		foreach ( $rows as $row ) {
			if ( 'resource' !== $row['record_type'] || '' === $row['title'] ) {
				continue;
			}

			$result['expected']++;

			$wanted = $this->row_categories( $row );
			$post   = $this->find_resource( $this->row_slug( $row ) );

			if ( ! $post ) {
				$result['missing'][] = $row['title'] . ' (' . ( $wanted ? implode( ', ', $wanted ) : __( 'no category', 'adx-gated-resources' ) ) . ')';
				continue;
			}

			$status = in_array( $row['status'], array( 'publish', 'draft', 'pending', 'private' ), true ) ? $row['status'] : 'publish';

			if ( $post->post_status !== $status ) {
				/* translators: 1: resource title, 2: its status on this site, 3: the status the file gives it */
				$result['status'][] = sprintf( __( '%1$s is "%2$s" here, the file says "%3$s"', 'adx-gated-resources' ), $row['title'], $post->post_status, $status );
			}

			$have = wp_get_post_terms( $post->ID, $tax, array( 'fields' => 'slugs' ) );
			$have = is_array( $have ) ? $have : array();

			foreach ( $wanted as $slug ) {
				$term = get_term_by( 'slug', $slug, $tax );
				$term = $term || ! isset( $names[ $slug ] ) ? $term : get_term_by( 'name', $names[ $slug ], $tax );

				if ( $term && ! has_term( $term->term_id, $tax, $post ) ) {
					/* translators: 1: resource title, 2: the category it should be in, 3: the categories it is in */
					$result['category'][] = sprintf( __( '%1$s is not in "%2$s" (it is in: %3$s)', 'adx-gated-resources' ), $row['title'], $slug, $have ? implode( ', ', $have ) : __( 'no category', 'adx-gated-resources' ) );
				}
			}
		}

		return $result;
	}

	/**
	 * Recounts the resources in every category, so the Count column and
	 * the library show the real numbers whatever an earlier import or edit
	 * left behind.
	 */
	private function recount_categories() {
		$tt_ids = get_terms(
			array(
				'taxonomy'   => ADX_GR_CPT::TAX_CATEGORY,
				'hide_empty' => false,
				'fields'     => 'tt_ids',
			)
		);

		if ( is_array( $tt_ids ) && $tt_ids ) {
			wp_update_term_count_now( $tt_ids, ADX_GR_CPT::TAX_CATEGORY );
		}
	}


	private function swap( $url, array $opts ) {
		$url = trim( (string) $url );

		return '' !== $opts['find'] ? str_replace( $opts['find'], $opts['replace'], $url ) : $url;
	}

	/**
	 * Splits a "a|b|c" cell. Labels keep their gaps ("a||c") so they stay
	 * lined up with their links.
	 */
	private function split( $cell, $keep_empty = false ) {
		$parts = array_map( 'trim', explode( '|', (string) $cell ) );

		return $keep_empty ? $parts : array_values( array_filter( $parts, 'strlen' ) );
	}

	/**
	 * Asks each file's server whether the link answers, so links that point
	 * at files not uploaded yet show up before importing.
	 *
	 * @return array link => true (answers), false (does not), null (not checked).
	 */
	private function check_files( array $urls ) {
		$result = array();

		foreach ( $urls as $i => $url ) {
			if ( $i >= self::MAX_CHECK || ! preg_match( '#^https?://#i', $url ) ) {
				$result[ $url ] = null;
				continue;
			}

			$args     = array(
				'timeout'     => 5,
				'redirection' => 5,
			);
			$response = wp_safe_remote_head( $url, $args );
			$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

			if ( $code < 200 || $code >= 400 ) {
				$response = wp_safe_remote_get( $url, $args + array( 'headers' => array( 'Range' => 'bytes=0-0' ), 'limit_response_size' => 1024 ) );
				$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
			}

			$result[ $url ] = $code >= 200 && $code < 400;
		}

		return $result;
	}

	/* ------------------------------------------------------------------
	 * Screens
	 * --------------------------------------------------------------- */

	private function page_url() {
		return admin_url( 'admin.php?page=' . self::PAGE );
	}

	private function check_access( $nonce_action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'adx-gated-resources' ) );
		}

		check_admin_referer( $nonce_action );
	}

	private function back_with_notice( $message ) {
		set_transient( 'adx_gr_import_notice_' . get_current_user_id(), $message, MINUTE_IN_SECONDS );

		wp_safe_redirect( $this->page_url() );
		exit;
	}

	private function load_state( $key ) {
		$state = '' !== $key ? get_transient( 'adx_gr_import_' . $key ) : false;

		return is_array( $state ) && (int) $state['user'] === get_current_user_id() ? $state : false;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$step = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key  = isset( $_GET['key'] ) ? sanitize_key( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import / Export', 'adx-gated-resources' ); ?></h1>
			<?php
			$notice_key = 'adx_gr_import_notice_' . get_current_user_id();
			$notice     = get_transient( $notice_key );

			if ( $notice ) {
				delete_transient( $notice_key );
				echo '<div class="notice notice-error"><p>' . esc_html( $notice ) . '</p></div>';
			}

			if ( 'preview' === $step && $this->load_state( $key ) ) {
				$this->render_preview( $key );
			} elseif ( 'done' === $step && is_array( get_transient( 'adx_gr_import_done_' . $key ) ) ) {
				$this->render_done( get_transient( 'adx_gr_import_done_' . $key ), $key );
			} else {
				$this->render_main();
			}
			?>
		</div>
		<?php
	}

	private function render_main() {
		?>
		<p class="description" style="max-width:760px">
			<?php esc_html_e( 'Move the library from one site to another (for example staging to live) with one CSV file. It holds every Category (with its badge code, headline, card description, logo and order) and every Resource (with its file links, categories, badges and image).', 'adx-gated-resources' ); ?>
		</p>

		<h2><?php esc_html_e( '1. Export', 'adx-gated-resources' ); ?></h2>
		<p><?php esc_html_e( 'Run this on the site that has the records. Tick the categories to export: you get those categories and the resources in them (a series always brings the parent category it sits under). The file can be opened and edited in Excel.', 'adx-gated-resources' ); ?></p>
		<?php $items = $this->category_items( $this->export_rows() ); ?>
		<?php if ( $items ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="adx_gr_export_library" />
				<?php wp_nonce_field( 'adx_gr_export_library' ); ?>
				<?php $this->render_category_list( $items, null ); ?>
				<p><?php submit_button( __( 'Download CSV', 'adx-gated-resources' ), 'primary', 'submit', false ); ?></p>
			</form>
		<?php else : ?>
			<p><em><?php esc_html_e( 'There are no categories or resources to export yet.', 'adx-gated-resources' ); ?></em></p>
		<?php endif; ?>

		<h2 style="margin-top:32px"><?php esc_html_e( '2. Import', 'adx-gated-resources' ); ?></h2>
		<p><?php esc_html_e( 'Run this on the site that should receive them. After choosing the file you pick which categories to bring in, and nothing is saved until you have seen a summary and confirmed it. Importing the same file again updates the records already there, it does not duplicate them.', 'adx-gated-resources' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="adx_gr_import_preview" />
			<?php wp_nonce_field( 'adx_gr_import_preview' ); ?>
			<table class="form-table" role="presentation" style="max-width:860px">
				<tr>
					<th scope="row"><label for="adx-gr-csv"><?php esc_html_e( 'CSV file', 'adx-gated-resources' ); ?></label></th>
					<td><input type="file" id="adx-gr-csv" name="adx_gr_csv" accept=".csv,text/csv" required /></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Change the address in file links', 'adx-gated-resources' ); ?></th>
					<td>
						<label><?php esc_html_e( 'Replace', 'adx-gated-resources' ); ?>
							<input type="text" class="regular-text" name="adx_gr_find" value="https://staging.adxdepot.com.au" />
						</label><br />
						<label><?php esc_html_e( 'with', 'adx-gated-resources' ); ?>&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;
							<input type="text" class="regular-text" name="adx_gr_replace" value="<?php echo esc_attr( untrailingslashit( home_url() ) ); ?>" />
						</label>
						<p class="description"><?php esc_html_e( 'Applied to the file links (the .rfa and .zip addresses). The files themselves are not copied: upload them to the new site under the same folders, or point the links to wherever they are. Leave "Replace" empty to keep the links as they are.', 'adx-gated-resources' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'If a record already exists', 'adx-gated-resources' ); ?></th>
					<td>
						<label><input type="radio" name="adx_gr_existing" value="update" checked /> <?php esc_html_e( 'Update it with the values in the file', 'adx-gated-resources' ); ?></label><br />
						<label><input type="radio" name="adx_gr_existing" value="skip" /> <?php esc_html_e( 'Leave it as it is', 'adx-gated-resources' ); ?></label>
						<p class="description"><?php esc_html_e( 'Records are matched by their slug. Nothing is ever deleted.', 'adx-gated-resources' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Check the file', 'adx-gated-resources' ), 'secondary', 'submit', false ); ?>
		</form>

		<h3 style="margin-top:32px"><?php esc_html_e( 'Good to know', 'adx-gated-resources' ); ?></h3>
		<ul style="list-style:disc;margin-left:20px;max-width:760px">
			<li><?php esc_html_e( 'Images (category logos and resource images) are copied into the Media Library of the site you import into, so the address in the file must be reachable from there.', 'adx-gated-resources' ); ?></li>
			<li><?php esc_html_e( 'Settings (ZeroBounce key, notification emails, form fields, the daily report, the Library Hero) are not part of this file. Set them up on the new site directly.', 'adx-gated-resources' ); ?></li>
			<li><?php esc_html_e( 'Subscribers and the download log are not included.', 'adx-gated-resources' ); ?></li>
		</ul>
		<?php
	}

	private function render_preview( $key ) {
		$state = $this->load_state( $key );

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_set_time_limit
		}

		$rows  = $this->filter_rows( $state['rows'], $state['sel'] );
		$r     = $this->process( $rows, $state['opts'], true );
		$check = $this->check_files( $r['files'] );
		$bad   = array_keys( array_filter( $check, function ( $ok ) {
			return false === $ok;
		} ) );
		$unchecked = count( array_filter( $check, 'is_null' ) );
		?>
		<h2><?php esc_html_e( 'Check before importing', 'adx-gated-resources' ); ?></h2>

		<h3><?php esc_html_e( 'Categories to import', 'adx-gated-resources' ); ?></h3>
		<p class="description"><?php esc_html_e( 'The file contains these categories. Untick the ones you do not want on this site, then update the summary. A series always brings the parent category it sits under.', 'adx-gated-resources' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="adx_gr_import_select" />
			<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>" />
			<?php wp_nonce_field( 'adx_gr_import_select' ); ?>
			<?php $this->render_category_list( $this->category_items( $state['rows'] ), $state['sel'] ); ?>
			<p><?php submit_button( __( 'Update the summary', 'adx-gated-resources' ), 'secondary', 'submit', false ); ?></p>
		</form>

		<h3><?php esc_html_e( 'What the import would do', 'adx-gated-resources' ); ?></h3>
		<p><?php esc_html_e( 'Nothing has been saved yet.', 'adx-gated-resources' ); ?></p>

		<?php $this->render_counts( $r, false ); ?>

		<?php
		// Resources that are already here but differ from the file: the
		// import corrects them, and this says which they are and where they are.
		$differ = $this->verify( $rows );
		?>
		<?php if ( $differ['status'] || $differ['category'] ) : ?>
			<h3><?php esc_html_e( 'Already on this site, but different from the file', 'adx-gated-resources' ); ?></h3>
			<p class="description"><?php esc_html_e( 'Importing puts these as the file says.', 'adx-gated-resources' ); ?></p>
			<ul style="list-style:disc;margin-left:20px">
				<?php foreach ( array_slice( array_merge( $differ['category'], $differ['status'] ), 0, 60 ) as $line ) : ?>
					<li><?php echo esc_html( $line ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<?php if ( $r['warnings'] ) : ?>
			<h3><?php esc_html_e( 'Things to look at', 'adx-gated-resources' ); ?></h3>
			<ul style="list-style:disc;margin-left:20px">
				<?php foreach ( array_slice( $r['warnings'], 0, 100 ) as $warning ) : ?>
					<li><?php echo esc_html( $warning ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<h3><?php esc_html_e( 'File links', 'adx-gated-resources' ); ?></h3>
		<?php if ( ! $r['files'] ) : ?>
			<p><?php esc_html_e( 'The file has no file links.', 'adx-gated-resources' ); ?></p>
		<?php elseif ( $bad ) : ?>
			<div class="notice notice-warning inline"><p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of file links that did not answer */
						_n( '%d file link does not answer yet. You can still import: the records are saved, and the link works once the file is uploaded there.', '%d file links do not answer yet. You can still import: the records are saved, and each link works once its file is uploaded there.', count( $bad ), 'adx-gated-resources' ),
						count( $bad )
					)
				);
				?>
			</p></div>
			<ul style="list-style:disc;margin-left:20px;word-break:break-all">
				<?php foreach ( $bad as $url ) : ?>
					<li><code><?php echo esc_html( $url ); ?></code></li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<p><?php esc_html_e( 'Every file link that was checked answers.', 'adx-gated-resources' ); ?></p>
		<?php endif; ?>
		<?php if ( $unchecked ) : ?>
			<p class="description">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of links not checked */
						__( '%d more links were not checked (only the first 25 are).', 'adx-gated-resources' ),
						$unchecked
					)
				);
				?>
			</p>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:24px">
			<input type="hidden" name="action" value="adx_gr_import_run" />
			<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>" />
			<?php wp_nonce_field( 'adx_gr_import_run' ); ?>
			<?php submit_button( __( 'Import now', 'adx-gated-resources' ), 'primary', 'submit', false, $rows ? array() : array( 'disabled' => 'disabled' ) ); ?>
			<a class="button" href="<?php echo esc_url( $this->page_url() ); ?>"><?php esc_html_e( 'Cancel', 'adx-gated-resources' ); ?></a>
			<?php if ( ! $rows ) : ?>
				<span class="description"><?php esc_html_e( 'Tick at least one category first.', 'adx-gated-resources' ); ?></span>
			<?php endif; ?>
		</form>
		<?php
	}

	private function render_done( array $r, $key ) {
		$verify = isset( $r['verify'] ) ? $r['verify'] : null;
		$job    = get_transient( 'adx_gr_import_img_' . $key );
		$wrong  = $verify && ( $verify['missing'] || $verify['status'] || $verify['category'] );
		?>
		<?php if ( $wrong ) : ?>
			<div class="notice notice-error inline"><p>
				<strong><?php esc_html_e( 'Not everything in the file ended up as the file says.', 'adx-gated-resources' ); ?></strong>
				<?php esc_html_e( 'What differs is listed below. Running the same import again corrects it (it updates what is already there).', 'adx-gated-resources' ); ?>
			</p></div>
			<?php foreach ( array( 'missing' => __( 'Resources that are not on this site', 'adx-gated-resources' ), 'status' => __( 'Resources in a different status than the file gives them (only published ones show in the library)', 'adx-gated-resources' ), 'category' => __( 'Resources that are not in the category the file gives them', 'adx-gated-resources' ) ) as $list => $heading ) : ?>
				<?php if ( $verify[ $list ] ) : ?>
					<h3><?php echo esc_html( $heading ); ?></h3>
					<ul style="list-style:disc;margin-left:20px">
						<?php foreach ( $verify[ $list ] as $line ) : ?>
							<li><?php echo esc_html( $line ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			<?php endforeach; ?>
		<?php else : ?>
			<div class="notice notice-success inline"><p>
				<?php
				echo esc_html(
					$verify
						/* translators: %d: number of resources in the file */
						? sprintf( _n( 'The records are imported. The resource in the file is on this site.', 'The records are imported. All %d resources in the file are on this site, published as the file says and each in its category, and the category counts are refreshed.', $verify['expected'], 'adx-gated-resources' ), $verify['expected'] )
						: __( 'The records are imported.', 'adx-gated-resources' )
				);
				?>
			</p></div>
		<?php endif; ?>

		<?php $this->render_counts( $r, true ); ?>

		<?php if ( ! empty( $r['tidied'] ) ) : ?>
			<div class="notice notice-info inline"><p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of pictures */
						_n( 'An earlier import had written a resource\'s data onto %d picture in the Media Library (one with the same slug). That data has been removed from it.', 'An earlier import had written resource data onto %d pictures in the Media Library (ones with the same slug). That data has been removed from them.', (int) $r['tidied'], 'adx-gated-resources' ),
						(int) $r['tidied']
					)
				);
				?>
			</p></div>
		<?php endif; ?>

		<?php if ( is_array( $job ) && $job['tasks'] ) : ?>
			<div id="adx-gr-ie-img" style="max-width:640px;margin:18px 0">
				<p><strong><?php esc_html_e( 'Copying images', 'adx-gated-resources' ); ?></strong> <span class="adx-gr-ie-n"></span></p>
				<div style="background:#dcdcde;height:10px;border-radius:5px"><div class="adx-gr-ie-bar" style="width:0;height:10px;background:#3a9b3a;border-radius:5px;transition:width .3s"></div></div>
				<p class="description adx-gr-ie-msg" style="margin-top:6px"></p>
				<ul class="adx-gr-ie-warn" style="list-style:disc;margin-left:20px"></ul>
				<p><button type="button" class="button adx-gr-ie-retry" style="display:none"><?php esc_html_e( 'Continue copying', 'adx-gated-resources' ); ?></button></p>
			</div>
			<script>
			( function () {
				var box = document.getElementById( 'adx-gr-ie-img' );
				var n = box.querySelector( '.adx-gr-ie-n' );
				var bar = box.querySelector( '.adx-gr-ie-bar' );
				var msg = box.querySelector( '.adx-gr-ie-msg' );
				var warn = box.querySelector( '.adx-gr-ie-warn' );
				var retry = box.querySelector( '.adx-gr-ie-retry' );
				var fails = 0;

				function step() {
					retry.style.display = 'none';
					var body = new URLSearchParams( { action: 'adx_gr_import_images', key: <?php echo wp_json_encode( $key ); ?>, nonce: <?php echo wp_json_encode( wp_create_nonce( 'adx_gr_import_images' ) ); ?> } );

					fetch( <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', credentials: 'same-origin', body: body } )
						.then( function ( response ) { return response.json(); } )
						.then( function ( res ) {
							if ( ! res.success ) {
								msg.textContent = ( res.data && res.data.message ) || <?php echo wp_json_encode( __( 'The images could not be copied.', 'adx-gated-resources' ) ); ?>;
								return;
							}

							var d = res.data;
							fails = 0;
							n.textContent = d.done + ' / ' + d.total;
							bar.style.width = ( d.total ? Math.round( d.done / d.total * 100 ) : 100 ) + '%';
							d.messages.forEach( function ( text ) {
								var li = document.createElement( 'li' );
								li.textContent = text;
								warn.appendChild( li );
							} );

							if ( d.finished ) {
								msg.textContent = d.copied + <?php echo wp_json_encode( __( ' copied, ', 'adx-gated-resources' ) ); ?> + d.reused + <?php echo wp_json_encode( __( ' already in the Media Library, ', 'adx-gated-resources' ) ); ?> + d.failed + <?php echo wp_json_encode( __( ' failed.', 'adx-gated-resources' ) ); ?>;
							} else {
								step();
							}
						} )
						.catch( function () {
							fails++;
							if ( fails < 6 ) {
								msg.textContent = <?php echo wp_json_encode( __( 'Connection problem, trying again…', 'adx-gated-resources' ) ); ?>;
								setTimeout( step, 2500 );
							} else {
								msg.textContent = <?php echo wp_json_encode( __( 'Copying stopped. The records are all imported; press Continue to carry on with the images.', 'adx-gated-resources' ) ); ?>;
								retry.style.display = '';
							}
						} );
				}

				retry.addEventListener( 'click', function () { fails = 0; step(); } );
				step();
			} )();
			</script>
		<?php endif; ?>

		<?php if ( $r['warnings'] ) : ?>
			<h3><?php esc_html_e( 'Things to look at', 'adx-gated-resources' ); ?></h3>
			<ul style="list-style:disc;margin-left:20px">
				<?php foreach ( array_slice( $r['warnings'], 0, 100 ) as $warning ) : ?>
					<li><?php echo esc_html( $warning ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<p style="margin-top:20px">
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . ADX_GR_CPT::POST_TYPE ) ); ?>"><?php esc_html_e( 'View resources', 'adx-gated-resources' ); ?></a>
			<a class="button" href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=' . ADX_GR_CPT::TAX_CATEGORY . '&post_type=' . ADX_GR_CPT::POST_TYPE ) ); ?>"><?php esc_html_e( 'View categories', 'adx-gated-resources' ); ?></a>
			<a class="button" href="<?php echo esc_url( $this->page_url() ); ?>"><?php esc_html_e( 'Back', 'adx-gated-resources' ); ?></a>
		</p>
		<?php
	}

	private function render_counts( array $r, $done ) {
		$rows = array(
			__( 'Categories', 'adx-gated-resources' ) => $r['categories'],
			__( 'Resources', 'adx-gated-resources' )  => $r['resources'],
		);
		?>
		<table class="widefat striped" style="max-width:640px">
			<thead>
				<tr>
					<th></th>
					<th><?php echo esc_html( $done ? __( 'Created', 'adx-gated-resources' ) : __( 'To create', 'adx-gated-resources' ) ); ?></th>
					<th><?php echo esc_html( $done ? __( 'Updated', 'adx-gated-resources' ) : __( 'To update', 'adx-gated-resources' ) ); ?></th>
					<th><?php esc_html_e( 'Left as they are', 'adx-gated-resources' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $label => $counts ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $label ); ?></strong></td>
						<td><?php echo esc_html( $counts['new'] ); ?></td>
						<td><?php echo esc_html( $counts['update'] ); ?></td>
						<td><?php echo esc_html( $counts['skip'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				<tr>
					<td><strong><?php esc_html_e( 'Images', 'adx-gated-resources' ); ?></strong></td>
					<td colspan="3">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: images copied (or to copy), 2: images already in the library, 3: images that failed */
								$done
									? __( '%1$d being copied into the Media Library (progress is shown below), %2$d already there, %3$d failed', 'adx-gated-resources' )
									: __( '%1$d to copy into the Media Library, %2$d already there, %3$d failed', 'adx-gated-resources' ),
								$r['images']['new'],
								$r['images']['reuse'],
								$r['images']['failed']
							)
						);
						?>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}
}
