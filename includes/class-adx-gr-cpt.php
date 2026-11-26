<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the "Gated Resource" custom post type used to pick which
 * files (by URL, or by Media Library selection) require the email gate.
 */
class ADX_GR_CPT {

	const POST_TYPE      = 'adx_gated_resource';
	const TAX_CATEGORY   = 'adx_gr_category';
	const TAX_SERIES     = 'adx_gr_series';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'init', array( __CLASS__, 'register_taxonomies' ) );
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_meta_box' ) );
		add_action( 'deleted_post', array( __CLASS__, 'flush_resource_map_cache' ) );
		add_action( 'trashed_post', array( __CLASS__, 'flush_resource_map_cache' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( $this, 'column_content' ), 10, 2 );
	}

	public static function register_post_type() {
		$labels = array(
			'name'               => __( 'Gated Resources', 'adx-gated-resources' ),
			'singular_name'      => __( 'Gated Resource', 'adx-gated-resources' ),
			'add_new_item'       => __( 'Add New Gated Resource', 'adx-gated-resources' ),
			'edit_item'          => __( 'Edit Gated Resource', 'adx-gated-resources' ),
			'new_item'           => __( 'New Gated Resource', 'adx-gated-resources' ),
			'all_items'          => __( 'All Resources', 'adx-gated-resources' ),
			'search_items'       => __( 'Search Gated Resources', 'adx-gated-resources' ),
			'not_found'          => __( 'No gated resources found.', 'adx-gated-resources' ),
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => $labels,
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'adx-gated-resources',
				'capability_type' => 'page',
				'supports'        => array( 'title', 'thumbnail' ),
				'menu_icon'       => 'dashicons-lock',
			)
		);
	}

	/**
	 * Category and Series are the two independent filter rows on the
	 * front-end [adx_gated_library] grid (see ADX_GR_Frontend). Both are
	 * plain hierarchical taxonomies so WP gives us the term-management
	 * screen and the checkbox meta box on the resource editor for free.
	 *
	 * `show_in_menu` is false here -- WordPress's automatic taxonomy
	 * menu placement doesn't reliably attach to a custom top-level menu
	 * slug the way a post type's own `show_in_menu` does. Instead
	 * ADX_GR_Admin::register_menu() adds these screens itself via
	 * add_submenu_page(), the same explicit way as every other screen
	 * in this plugin.
	 */
	public static function register_taxonomies() {
		register_taxonomy(
			self::TAX_CATEGORY,
			self::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Categories', 'adx-gated-resources' ),
					'singular_name' => __( 'Category', 'adx-gated-resources' ),
				),
				'hierarchical'      => true,
				'public'            => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_menu'      => false,
				'query_var'         => false,
				'rewrite'           => false,
			)
		);

		register_taxonomy(
			self::TAX_SERIES,
			self::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Series', 'adx-gated-resources' ),
					'singular_name' => __( 'Series', 'adx-gated-resources' ),
				),
				'hierarchical'      => true,
				'public'            => false,
				'show_ui'           => true,
				// No Series box on the add/edit resource screen.
				'meta_box_cb'       => false,
				'show_admin_column' => true,
				'show_in_menu'      => false,
				'query_var'         => false,
				'rewrite'           => false,
			)
		);
	}

	public function add_meta_box() {
		add_meta_box(
			'adx_gr_resource_meta',
			__( 'File & Gate Settings', 'adx-gated-resources' ),
			array( $this, 'render_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	public function render_meta_box( $post ) {
		wp_nonce_field( 'adx_gr_save_resource', 'adx_gr_resource_nonce' );

		$gate_type     = self::get_gate_type( $post->ID );
		$file_url      = get_post_meta( $post->ID, '_adx_gr_file_url', true );
		$attachment_id = get_post_meta( $post->ID, '_adx_gr_attachment_id', true );
		$auto_gate     = get_post_meta( $post->ID, '_adx_gr_auto_gate', true );
		$auto_gate     = '' === $auto_gate ? '1' : $auto_gate;
		$bundle_files  = self::get_bundle_files( $post->ID );
		$badges        = implode( ', ', self::get_badges( $post->ID ) );

		wp_enqueue_media();
		?>
		<p>
			<label for="adx_gr_gate_type"><strong><?php esc_html_e( 'Gate Type', 'adx-gated-resources' ); ?></strong></label><br />
			<select id="adx_gr_gate_type" name="adx_gr_gate_type">
				<option value="file" <?php selected( $gate_type, 'file' ); ?>><?php esc_html_e( 'Single file', 'adx-gated-resources' ); ?></option>
				<option value="bundle" <?php selected( $gate_type, 'bundle' ); ?>><?php esc_html_e( 'Bundle (multiple files)', 'adx-gated-resources' ); ?></option>
			</select>
		</p>

		<div id="adx_gr_file_fields" <?php echo 'bundle' === $gate_type ? 'style="display:none;"' : ''; ?>>
			<p>
				<label for="adx_gr_file_url"><strong><?php esc_html_e( 'File URL', 'adx-gated-resources' ); ?></strong></label><br />
				<input type="url" id="adx_gr_file_url" name="adx_gr_file_url" class="large-text" value="<?php echo esc_attr( $file_url ); ?>" placeholder="https://" />
				<button type="button" class="button" id="adx_gr_media_button"><?php esc_html_e( 'Select from Media Library', 'adx-gated-resources' ); ?></button>
			</p>
			<input type="hidden" id="adx_gr_attachment_id" name="adx_gr_attachment_id" value="<?php echo esc_attr( $attachment_id ); ?>" />
			<p class="description"><?php esc_html_e( 'Paste the exact download URL as it appears on the site, or pick a file from the Media Library. Any matching link on the front end will automatically be gated.', 'adx-gated-resources' ); ?></p>

			<p>
				<label>
					<input type="checkbox" name="adx_gr_auto_gate" value="1" <?php checked( $auto_gate, '1' ); ?> />
					<?php esc_html_e( 'Automatically gate any link on the site pointing to this URL (recommended).', 'adx-gated-resources' ); ?>
				</label>
			</p>
		</div>

		<div id="adx_gr_bundle_fields" <?php echo 'file' === $gate_type ? 'style="display:none;"' : ''; ?>>
			<p class="description"><?php esc_html_e( 'Add each file that should be included in this bundle. Visitors get all of them zipped into a single download after passing the gate.', 'adx-gated-resources' ); ?></p>
			<table class="widefat" id="adx_gr_bundle_table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'File URL', 'adx-gated-resources' ); ?></th>
						<th><?php esc_html_e( 'Label', 'adx-gated-resources' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody id="adx_gr_bundle_rows">
					<?php
					if ( empty( $bundle_files ) ) {
						$bundle_files = array( array( 'url' => '', 'label' => '', 'attachment_id' => 0 ) );
					}
					foreach ( $bundle_files as $file ) {
						$this->render_bundle_row( $file );
					}
					?>
				</tbody>
			</table>
			<p><button type="button" class="button" id="adx_gr_bundle_add_row"><?php esc_html_e( 'Add file', 'adx-gated-resources' ); ?></button></p>
			<template id="adx_gr_bundle_row_template">
				<?php $this->render_bundle_row( array( 'url' => '', 'label' => '', 'attachment_id' => 0 ) ); ?>
			</template>
		</div>

		<p>
			<label for="adx_gr_badges"><strong><?php esc_html_e( 'Badges (optional)', 'adx-gated-resources' ); ?></strong></label><br />
			<input type="text" id="adx_gr_badges" name="adx_gr_badges" class="large-text" value="<?php echo esc_attr( $badges ); ?>" placeholder="<?php esc_attr_e( 'e.g. LOD 300, RFA, 2021+', 'adx-gated-resources' ); ?>" />
			<p class="description"><?php esc_html_e( 'Comma-separated short tags shown on the resource card in the [adx_gated_library] grid (e.g. spec details). Leave blank to show none.', 'adx-gated-resources' ); ?></p>
		</p>

		<p>
			<label>
				<input type="checkbox" name="adx_gr_hide_in_library" value="1" <?php checked( self::is_hidden_in_library( $post->ID ) ); ?> />
				<strong><?php esc_html_e( 'Keep this resource out of the library page', 'adx-gated-resources' ); ?></strong>
			</label>
		</p>
		<p class="description"><?php esc_html_e( 'It is not listed as a card in the [adx_gated_library] grid. Use it for the complete-library zip behind the hero button: that button (Library Hero) downloads it, behind the email form, and this is ticked for you when you pick it there.', 'adx-gated-resources' ); ?></p>

		<?php if ( $post->ID ) : ?>
			<p>
				<strong><?php esc_html_e( 'Manual shortcode (optional):', 'adx-gated-resources' ); ?></strong><br />
				<code>[adx_gated_resource id="<?php echo (int) $post->ID; ?>"]<?php esc_html_e( 'Download', 'adx-gated-resources' ); ?>[/adx_gated_resource]</code>
			</p>
		<?php endif; ?>

		<script>
		(function(){
			var typeSelect = document.getElementById('adx_gr_gate_type');
			var fileFields = document.getElementById('adx_gr_file_fields');
			var bundleFields = document.getElementById('adx_gr_bundle_fields');

			function toggleFields(){
				var isBundle = typeSelect.value === 'bundle';
				fileFields.style.display = isBundle ? 'none' : '';
				bundleFields.style.display = isBundle ? '' : 'none';
			}
			if ( typeSelect ) {
				typeSelect.addEventListener('change', toggleFields);
			}

			function openMediaPicker( row ){
				var frame = wp.media({
					title: '<?php echo esc_js( __( 'Select gated file', 'adx-gated-resources' ) ); ?>',
					multiple: false
				});
				frame.on('select', function(){
					var attachment = frame.state().get('selection').first().toJSON();
					if ( row ) {
						row.querySelector('.adx_gr_bundle_url').value = attachment.url;
						row.querySelector('.adx_gr_bundle_attachment_id').value = attachment.id;
					} else {
						document.getElementById('adx_gr_file_url').value = attachment.url;
						document.getElementById('adx_gr_attachment_id').value = attachment.id;
					}
				});
				frame.open();
			}

			var singleBtn = document.getElementById('adx_gr_media_button');
			if ( singleBtn ) {
				singleBtn.addEventListener('click', function(e){
					e.preventDefault();
					openMediaPicker( null );
				});
			}

			var rowsBody = document.getElementById('adx_gr_bundle_rows');
			rowsBody.addEventListener('click', function(e){
				var row = e.target.closest('.adx-gr-bundle-row');
				if ( ! row ) { return; }
				if ( e.target.closest('.adx_gr_bundle_media_button') ) {
					e.preventDefault();
					openMediaPicker( row );
				} else if ( e.target.closest('.adx_gr_bundle_remove_row') ) {
					e.preventDefault();
					if ( rowsBody.querySelectorAll('.adx-gr-bundle-row').length > 1 ) {
						row.remove();
					} else {
						row.querySelector('.adx_gr_bundle_url').value = '';
						row.querySelector('.adx_gr_bundle_label').value = '';
						row.querySelector('.adx_gr_bundle_attachment_id').value = '';
					}
				}
			});

			var addBtn = document.getElementById('adx_gr_bundle_add_row');
			var template = document.getElementById('adx_gr_bundle_row_template');
			if ( addBtn && template ) {
				addBtn.addEventListener('click', function(e){
					e.preventDefault();
					rowsBody.appendChild( template.content.cloneNode( true ) );
				});
			}

			toggleFields();
		})();
		</script>
		<?php
	}

	private function render_bundle_row( array $file ) {
		?>
		<tr class="adx-gr-bundle-row">
			<td>
				<input type="url" class="large-text adx_gr_bundle_url" name="adx_gr_bundle_url[]" value="<?php echo esc_attr( $file['url'] ); ?>" placeholder="https://" />
				<input type="hidden" class="adx_gr_bundle_attachment_id" name="adx_gr_bundle_attachment_id[]" value="<?php echo esc_attr( $file['attachment_id'] ); ?>" />
			</td>
			<td>
				<input type="text" class="regular-text adx_gr_bundle_label" name="adx_gr_bundle_label[]" value="<?php echo esc_attr( $file['label'] ); ?>" placeholder="<?php esc_attr_e( 'Optional label', 'adx-gated-resources' ); ?>" />
			</td>
			<td>
				<button type="button" class="button adx_gr_bundle_media_button"><?php esc_html_e( 'Media Library', 'adx-gated-resources' ); ?></button>
				<button type="button" class="button adx_gr_bundle_remove_row"><?php esc_html_e( 'Remove', 'adx-gated-resources' ); ?></button>
			</td>
		</tr>
		<?php
	}

	public function save_meta_box( $post_id ) {
		if ( ! isset( $_POST['adx_gr_resource_nonce'] ) || ! wp_verify_nonce( $_POST['adx_gr_resource_nonce'], 'adx_gr_save_resource' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$gate_type = isset( $_POST['adx_gr_gate_type'] ) && 'bundle' === $_POST['adx_gr_gate_type'] ? 'bundle' : 'file';
		update_post_meta( $post_id, '_adx_gr_gate_type', $gate_type );

		if ( isset( $_POST['adx_gr_file_url'] ) ) {
			update_post_meta( $post_id, '_adx_gr_file_url', esc_url_raw( wp_unslash( $_POST['adx_gr_file_url'] ) ) );
		}

		if ( isset( $_POST['adx_gr_attachment_id'] ) ) {
			update_post_meta( $post_id, '_adx_gr_attachment_id', absint( $_POST['adx_gr_attachment_id'] ) );
		}

		update_post_meta( $post_id, '_adx_gr_auto_gate', isset( $_POST['adx_gr_auto_gate'] ) ? '1' : '0' );
		update_post_meta( $post_id, '_adx_gr_hide_in_library', isset( $_POST['adx_gr_hide_in_library'] ) ? '1' : '0' );

		// The file count is now always read from the zip; drop any number
		// entered by hand in 1.12.1.
		delete_post_meta( $post_id, '_adx_gr_file_count' );

		if ( isset( $_POST['adx_gr_badges'] ) ) {
			$badges = array_values( array_filter( array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $_POST['adx_gr_badges'] ) ) ) ) ) );
			update_post_meta( $post_id, '_adx_gr_badges', $badges );
		}

		if ( isset( $_POST['adx_gr_bundle_url'] ) ) {
			$urls   = (array) wp_unslash( $_POST['adx_gr_bundle_url'] );
			$labels = isset( $_POST['adx_gr_bundle_label'] ) ? (array) wp_unslash( $_POST['adx_gr_bundle_label'] ) : array();
			$attach = isset( $_POST['adx_gr_bundle_attachment_id'] ) ? (array) $_POST['adx_gr_bundle_attachment_id'] : array();

			$files = array();
			foreach ( $urls as $i => $url ) {
				$url = esc_url_raw( $url );
				if ( '' === $url ) {
					continue;
				}
				$files[] = array(
					'url'           => $url,
					'label'         => isset( $labels[ $i ] ) ? sanitize_text_field( $labels[ $i ] ) : '',
					'attachment_id' => isset( $attach[ $i ] ) ? absint( $attach[ $i ] ) : 0,
				);
			}
			update_post_meta( $post_id, '_adx_gr_bundle_files', $files );
		}

		self::flush_resource_map_cache();
		self::refresh_remote_sizes( $post_id );
	}

	public function columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['adx_gr_file_url'] = __( 'Files', 'adx-gated-resources' );
				$new['adx_gr_shortcode'] = __( 'Shortcode', 'adx-gated-resources' );
			}
		}
		return $new;
	}

	public function column_content( $column, $post_id ) {
		if ( 'adx_gr_file_url' === $column ) {
			if ( 'bundle' === self::get_gate_type( $post_id ) ) {
				$count = count( self::get_bundle_files( $post_id ) );
				echo esc_html(
					sprintf(
						/* translators: %d: number of files in the bundle */
						_n( '%d file (bundle)', '%d files (bundle)', $count, 'adx-gated-resources' ),
						$count
					)
				);
			} else {
				$url = get_post_meta( $post_id, '_adx_gr_file_url', true );
				echo $url ? '<code style="word-break:break-all;">' . esc_html( $url ) . '</code>' : '&mdash;';
			}
		}

		if ( 'adx_gr_shortcode' === $column ) {
			echo '<code>[adx_gated_resource id="' . (int) $post_id . '"]</code>';
		}
	}

	/**
	 * Returns a map of gated file URL => resource post ID, for resources
	 * that have auto-gate enabled. Cached in a transient, cleared on save.
	 */
	public static function get_auto_gate_url_map() {
		$cached = get_transient( 'adx_gr_url_map' );
		if ( false !== $cached ) {
			return $cached;
		}

		$map = array();

		$query = new WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'meta_query'     => array(
					array(
						'key'   => '_adx_gr_auto_gate',
						'value' => '1',
					),
				),
				'fields'         => 'ids',
			)
		);

		foreach ( $query->posts as $post_id ) {
			if ( 'bundle' === self::get_gate_type( $post_id ) ) {
				continue;
			}
			$url = get_post_meta( $post_id, '_adx_gr_file_url', true );
			if ( $url ) {
				$map[ untrailingslashit( $url ) ] = $post_id;
			}
		}

		set_transient( 'adx_gr_url_map', $map, HOUR_IN_SECONDS );

		return $map;
	}

	public static function flush_resource_map_cache() {
		delete_transient( 'adx_gr_url_map' );
	}

	public static function get_resource_file_url( $resource_id ) {
		return get_post_meta( $resource_id, '_adx_gr_file_url', true );
	}

	/**
	 * Returns 'file' or 'bundle'. Existing resources saved before this
	 * meta existed have no value set, so anything other than 'bundle'
	 * is treated as 'file' -- this keeps every resource already live in
	 * production working unchanged.
	 */
	public static function get_gate_type( $resource_id ) {
		$type = get_post_meta( $resource_id, '_adx_gr_gate_type', true );
		return 'bundle' === $type ? 'bundle' : 'file';
	}

	/**
	 * Whether the resource is kept out of the [adx_gated_library] grid: it
	 * was ticked "Keep this resource out of the library page", or it is the
	 * complete-library file chosen for the hero button.
	 */
	public static function is_hidden_in_library( $resource_id ) {
		return '1' === get_post_meta( $resource_id, '_adx_gr_hide_in_library', true )
			|| (int) $resource_id === (int) ADX_GR_Library_Hero::resource_id();
	}

	/**
	 * IDs of the resources the library grid must leave out.
	 */
	public static function get_hidden_in_library_ids() {
		$ids = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_adx_gr_hide_in_library', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);

		$hero = ADX_GR_Library_Hero::resource_id();

		if ( $hero ) {
			$ids[] = $hero;
		}

		return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

	public static function get_bundle_files( $resource_id ) {
		$files = get_post_meta( $resource_id, '_adx_gr_bundle_files', true );
		return is_array( $files ) ? $files : array();
	}

	public static function get_badges( $resource_id ) {
		$badges = get_post_meta( $resource_id, '_adx_gr_badges', true );
		return is_array( $badges ) ? $badges : array();
	}

	/**
	 * The series a resource belongs to, as names (e.g. array( '1000 series' )),
	 * for the Downloads log. A series is a category with no sub-categories
	 * (the parent, e.g. "Aluminium Partitioning", isn't one). A resource
	 * ticked in more than one series lists them all, which also makes a
	 * stray tick easy to spot. Empty for a resource that is deleted or has
	 * no category. Read from the resource's current categories, so it also
	 * covers downloads logged before this existed.
	 */
	public static function get_series_names( $resource_id ) {
		static $cache = array();

		$resource_id = absint( $resource_id );

		if ( isset( $cache[ $resource_id ] ) ) {
			return $cache[ $resource_id ];
		}

		$terms = $resource_id ? get_the_terms( $resource_id, self::TAX_CATEGORY ) : false;
		$terms = is_array( $terms ) ? $terms : array();
		$names = array();

		foreach ( $terms as $term ) {
			$children = get_term_children( $term->term_id, self::TAX_CATEGORY );
			if ( empty( $children ) || is_wp_error( $children ) ) {
				$names[] = $term->name;
			}
		}

		// Only a parent category ticked: show that rather than nothing.
		if ( ! $names ) {
			$names = wp_list_pluck( $terms, 'name' );
		}

		$cache[ $resource_id ] = $names;

		return $names;
	}

	/**
	 * Best-effort file type label for a card's meta line (e.g. "RFA"),
	 * guessed from the file's extension. Falls back to an empty string
	 * for extension-less URLs rather than guessing wrong.
	 */
	public static function get_file_extension_label( $url ) {
		$path = wp_parse_url( (string) $url, PHP_URL_PATH );
		$ext  = $path ? pathinfo( $path, PATHINFO_EXTENSION ) : '';
		return $ext ? strtoupper( $ext ) : '';
	}

	/**
	 * What the library page needs to know about a resource's download:
	 *
	 * - is_bundle: a "Download all" item, i.e. a Bundle resource or a
	 *   single file that is a .zip.
	 * - format: the file type to show ("RFA"); for a zip, the most common
	 *   type inside it.
	 * - bytes: total size, or null if a file isn't on this server.
	 * - count: number of files inside, for bundles and zips (null if unknown).
	 *
	 * Reading a large zip's contents isn't free, so results are cached on
	 * the resource and only recalculated when the file changes.
	 */
	public static function get_download_info( $resource_id ) {
		if ( 'bundle' === self::get_gate_type( $resource_id ) ) {
			$files = self::get_bundle_files( $resource_id );

			// A bundle holding just one .zip is that zip: it downloads as it
			// is, and it's the files inside it that are counted.
			if ( 1 === count( $files ) && 'ZIP' === self::get_file_extension_label( $files[0]['url'] ) ) {
				return self::single_file_info( $resource_id, (string) $files[0]['url'], ! empty( $files[0]['attachment_id'] ) ? (int) $files[0]['attachment_id'] : 0 );
			}

			$bytes = 0;

			foreach ( $files as $file ) {
				$path = ADX_GR_Ajax::resolve_local_path( $file['url'], ! empty( $file['attachment_id'] ) ? (int) $file['attachment_id'] : 0 );
				$size = $path ? (int) filesize( $path ) : self::get_remote_file_size( $file['url'] );
				if ( ! $size ) {
					$bytes = null;
					break;
				}
				$bytes += $size;
			}

			return array(
				'is_bundle' => true,
				'format'    => $files ? self::get_file_extension_label( $files[0]['url'] ) : '',
				'bytes'     => $files ? $bytes : null,
				'count'     => count( $files ),
			);
		}

		return self::single_file_info( $resource_id, (string) self::get_resource_file_url( $resource_id ), absint( get_post_meta( $resource_id, '_adx_gr_attachment_id', true ) ) );
	}

	/**
	 * get_download_info() for one file (a single-file resource, or the one
	 * .zip inside a bundle).
	 */
	private static function single_file_info( $resource_id, $url, $attachment_id ) {
		$format = self::get_file_extension_label( $url );
		$path   = $url ? ADX_GR_Ajax::resolve_local_path( $url, $attachment_id ) : false;

		$info = array(
			'is_bundle' => 'ZIP' === $format,
			'format'    => $format,
			'bytes'     => null,
			'count'     => null,
		);

		// Hosted elsewhere: ask that server for the size, and for a zip,
		// read its list of contents from the end of the file.
		if ( ! $path ) {
			$info['bytes'] = $url ? self::get_remote_file_size( $url ) : null;

			if ( $url && $info['is_bundle'] ) {
				$contents = self::get_remote_zip_contents( $url );
				if ( $contents ) {
					$info['count']  = $contents['count'];
					$info['format'] = $contents['format'] ? $contents['format'] : $format;
				}
			}

			return $info;
		}

		$cache_key = md5( $url . '|' . filemtime( $path ) . '|' . filesize( $path ) );
		$cached    = get_post_meta( $resource_id, '_adx_gr_download_info', true );

		if ( is_array( $cached ) && isset( $cached['key'], $cached['info'] ) && $cached['key'] === $cache_key ) {
			return $cached['info'];
		}

		$info['bytes'] = (int) filesize( $path );

		if ( $info['is_bundle'] ) {
			$contents = self::read_zip_contents( $path );
			if ( $contents ) {
				$info['count']  = $contents['count'];
				$info['format'] = $contents['format'] ? $contents['format'] : $format;
			}
		}

		update_post_meta( $resource_id, '_adx_gr_download_info', array( 'key' => $cache_key, 'info' => $info ) );

		return $info;
	}

	/**
	 * Size in bytes of a file hosted on another server, from that
	 * server's response headers (no file is downloaded), or null if it
	 * can't be found. Cached for a week (failures for 10 minutes) and
	 * refreshed whenever the resource is saved, so the library page
	 * rarely has to ask. A page view makes at most a handful of new
	 * lookups; any others fill in on later views.
	 */
	public static function get_remote_file_size( $url ) {
		$url = trim( (string) $url );

		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return null;
		}

		$key    = self::remote_size_key( $url );
		$cached = get_transient( $key );

		if ( false !== $cached ) {
			return (int) $cached > 0 ? (int) $cached : null;
		}

		if ( self::$remote_lookups >= self::MAX_LOOKUPS ) {
			return null;
		}
		self::$remote_lookups++;

		$size = self::fetch_remote_file_size( $url );

		set_transient( $key, $size ? $size : -1, $size ? WEEK_IN_SECONDS : self::LOOKUP_RETRY );

		return $size;
	}

	private static $remote_lookups = 0;

	/** How long a failed lookup is remembered before it is tried again. */
	const LOOKUP_RETRY = 10 * MINUTE_IN_SECONDS;

	/** New lookups one page view may make (each remote zip needs two: size and contents). */
	const MAX_LOOKUPS = 20;

	private static function remote_size_key( $url ) {
		return 'adx_gr_rsize_' . md5( trim( (string) $url ) );
	}

	/**
	 * Asks for just the headers first; if the server won't say, requests
	 * the first byte only and reads the total from Content-Range.
	 */
	private static function fetch_remote_file_size( $url ) {
		$args = array(
			'timeout'     => 6,
			'redirection' => 5,
		);

		$size = self::size_from_response( wp_safe_remote_head( $url, $args ) );

		if ( ! $size ) {
			$size = self::size_from_response(
				wp_safe_remote_get(
					$url,
					$args + array(
						'headers'             => array( 'Range' => 'bytes=0-0' ),
						'limit_response_size' => 1024,
					)
				)
			);
		}

		return $size;
	}

	private static function size_from_response( $response ) {
		if ( is_wp_error( $response ) ) {
			return null;
		}

		$header = function ( $name ) use ( $response ) {
			$value = wp_remote_retrieve_header( $response, $name );
			return is_array( $value ) ? (string) end( $value ) : (string) $value;
		};

		// A web page (e.g. a Google Drive or Dropbox share link) rather
		// than the file itself: its size would be wrong, so show none.
		if ( false !== stripos( $header( 'content-type' ), 'text/html' ) ) {
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 206 === $code && preg_match( '#/(\d+)\s*$#', $header( 'content-range' ), $match ) ) {
			return (int) $match[1];
		}

		$length = $header( 'content-length' );

		return 200 === $code && ctype_digit( $length ) && (int) $length > 0 ? (int) $length : null;
	}

	/**
	 * After a resource is saved: forget the cached remote size(s) for
	 * its file(s) and look them up again, so the library shows the
	 * current size straight away.
	 */
	private static function refresh_remote_sizes( $post_id ) {
		$urls = array( self::get_resource_file_url( $post_id ) );
		foreach ( self::get_bundle_files( $post_id ) as $file ) {
			$urls[] = $file['url'];
		}

		foreach ( array_filter( array_unique( $urls ) ) as $url ) {
			if ( ADX_GR_Ajax::resolve_local_path( $url ) ) {
				continue;
			}
			delete_transient( self::remote_size_key( $url ) );
			self::get_remote_file_size( $url );

			if ( 'ZIP' === self::get_file_extension_label( $url ) ) {
				delete_transient( self::remote_zip_key( $url ) );
				self::get_remote_zip_contents( $url );
			}
		}
	}

	/**
	 * Counts the files in a zip on this server and finds the most common
	 * file type among them.
	 */
	private static function read_zip_contents( $path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return null;
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return null;
		}

		$names = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$names[] = (string) $zip->getNameIndex( $i );
		}
		$zip->close();

		return self::tally_zip_names( $names );
	}

	/**
	 * Shared counting rules for local and remote zips: folders, macOS
	 * metadata (__MACOSX/) and hidden files (.DS_Store etc.) don't count.
	 */
	private static function tally_zip_names( array $names ) {
		$count      = 0;
		$extensions = array();

		foreach ( $names as $name ) {
			$name = (string) $name;

			if ( '' === $name || '/' === substr( $name, -1 ) || false !== strpos( $name, '__MACOSX/' ) || '.' === substr( basename( $name ), 0, 1 ) ) {
				continue;
			}

			$count++;
			$ext = strtoupper( pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( $ext ) {
				$extensions[ $ext ] = ( isset( $extensions[ $ext ] ) ? $extensions[ $ext ] : 0 ) + 1;
			}
		}

		arsort( $extensions );

		return array(
			'count'  => $count,
			'format' => $extensions ? (string) key( $extensions ) : '',
		);
	}

	/**
	 * File count and main file type of a zip hosted on another server,
	 * or null if it can't be read. Cached like remote sizes (a week, or a
	 * 10 minutes after a failure) and refreshed when the resource is saved.
	 */
	public static function get_remote_zip_contents( $url ) {
		$url = trim( (string) $url );

		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return null;
		}

		$key    = self::remote_zip_key( $url );
		$cached = get_transient( $key );

		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : null;
		}

		if ( self::$remote_lookups >= self::MAX_LOOKUPS ) {
			return null;
		}
		self::$remote_lookups++;

		$contents = self::fetch_remote_zip_contents( $url );

		set_transient( $key, $contents ? $contents : 'none', $contents ? WEEK_IN_SECONDS : self::LOOKUP_RETRY );

		return $contents;
	}

	private static function remote_zip_key( $url ) {
		return 'adx_gr_rzip_' . md5( trim( (string) $url ) );
	}

	/**
	 * A zip lists its contents in a "central directory" at the end of the
	 * file, so only that part is fetched (normally a few KB, however big
	 * the zip is): first the last 64 KB to find where the directory
	 * starts, then the directory itself if it wasn't already in there.
	 * Needs the server to support partial downloads (HTTP Range), which
	 * web servers and CDNs generally do; if it doesn't, nothing more is
	 * downloaded and no count is shown.
	 */
	private static function fetch_remote_zip_contents( $url ) {
		$tail = self::fetch_remote_range( $url, 'bytes=-65557', 65557 );

		// Some servers and CDNs don't answer a "last N bytes" request: ask
		// for the same bytes by position, using the file's size.
		if ( ! $tail ) {
			$size = self::fetch_remote_file_size( $url );

			if ( $size ) {
				$start = max( 0, $size - 65557 );
				$tail  = self::fetch_remote_range( $url, 'bytes=' . $start . '-' . ( $size - 1 ), $size - $start );
			}
		}

		if ( ! $tail ) {
			return null;
		}

		$data       = $tail['body'];
		$total      = $tail['total'];
		$data_start = $total - strlen( $data );
		$eocd_pos   = strrpos( $data, "PK\x05\x06" );

		if ( false === $eocd_pos || strlen( $data ) < $eocd_pos + 22 ) {
			return null;
		}

		$eocd      = unpack( 'vdisk/vcd_disk/vdisk_entries/ventries/Vcd_size/Vcd_offset', substr( $data, $eocd_pos + 4, 16 ) );
		$cd_size   = $eocd['cd_size'];
		$cd_offset = $eocd['cd_offset'];

		// ZIP64 (very large zips, or more than 65,535 entries): the real
		// numbers are in a separate record that the locator points to.
		if ( 0xFFFFFFFF === $cd_offset || 0xFFFFFFFF === $cd_size || 0xFFFF === $eocd['entries'] ) {
			$locator_pos = $eocd_pos - 20;

			if ( $locator_pos < 0 || "PK\x06\x07" !== substr( $data, $locator_pos, 4 ) ) {
				return null;
			}

			$zip64_offset = self::unpack_uint64( substr( $data, $locator_pos + 8, 8 ) );
			$record       = $zip64_offset >= $data_start
				? substr( $data, $zip64_offset - $data_start, 56 )
				: self::fetch_remote_range_body( $url, $zip64_offset, $zip64_offset + 55 );

			if ( ! $record || "PK\x06\x06" !== substr( $record, 0, 4 ) ) {
				return null;
			}

			$cd_size   = self::unpack_uint64( substr( $record, 40, 8 ) );
			$cd_offset = self::unpack_uint64( substr( $record, 48, 8 ) );
		}

		// A directory over 8 MB would mean an enormous number of files;
		// don't fetch it.
		if ( $cd_size <= 0 || $cd_size > 8 * MB_IN_BYTES || $cd_offset + $cd_size > $total ) {
			return null;
		}

		$directory = $cd_offset >= $data_start
			? substr( $data, $cd_offset - $data_start, $cd_size )
			: self::fetch_remote_range_body( $url, $cd_offset, $cd_offset + $cd_size - 1 );

		if ( ! $directory ) {
			return null;
		}

		$names  = array();
		$pos    = 0;
		$length = strlen( $directory );

		while ( $pos + 46 <= $length && "PK\x01\x02" === substr( $directory, $pos, 4 ) ) {
			$header  = unpack( 'vname_len/vextra_len/vcomment_len', substr( $directory, $pos + 28, 6 ) );
			$names[] = substr( $directory, $pos + 46, $header['name_len'] );
			$pos    += 46 + $header['name_len'] + $header['extra_len'] + $header['comment_len'];
		}

		return $names ? self::tally_zip_names( $names ) : null;
	}

	/**
	 * Fetches part of a remote file. Only accepts a real partial response
	 * (206), and never reads more than $max_bytes, so a server that
	 * ignores the range can't make it download the whole file.
	 *
	 * @return array|null body and the file's total size.
	 */
	private static function fetch_remote_range( $url, $range, $max_bytes ) {
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 10,
				'redirection'         => 5,
				'headers'             => array( 'Range' => $range ),
				'limit_response_size' => $max_bytes,
			)
		);

		if ( is_wp_error( $response ) || 206 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}

		$content_range = wp_remote_retrieve_header( $response, 'content-range' );
		$content_range = is_array( $content_range ) ? (string) end( $content_range ) : (string) $content_range;

		if ( ! preg_match( '#/(\d+)\s*$#', $content_range, $match ) ) {
			return null;
		}

		return array(
			'body'  => (string) wp_remote_retrieve_body( $response ),
			'total' => (int) $match[1],
		);
	}

	private static function fetch_remote_range_body( $url, $start, $end ) {
		$part = self::fetch_remote_range( $url, 'bytes=' . $start . '-' . $end, $end - $start + 1 );

		return $part && strlen( $part['body'] ) === $end - $start + 1 ? $part['body'] : null;
	}

	private static function unpack_uint64( $bytes ) {
		$parts = unpack( 'Vlow/Vhigh', $bytes );

		return $parts['low'] + $parts['high'] * 4294967296;
	}
}
