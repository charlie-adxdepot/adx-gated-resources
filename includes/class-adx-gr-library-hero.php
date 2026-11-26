<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The hero band at the top of the [adx_gated_library] page: title, intro,
 * three headline numbers and a "Download complete library" button.
 *
 * This class holds the settings and the admin screen
 * (ADX Gated Resources > Library Hero). The band itself is drawn by
 * ADX_GR_Frontend::render_hero(), which fills in the numbers from the
 * library's own data:
 *
 * - Families: the individual downloads (not the "Download all" zips).
 * - Product Series: the series that have downloads.
 * - File format: the most common file type among the families.
 *
 * Any of the three can be overridden by typing a value. The button
 * downloads an ordinary Resource (the complete-library zip), picked here;
 * that resource is kept out of the library grid.
 */
class ADX_GR_Library_Hero {

	const OPTION = 'adx_gr_library_hero';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_post_adx_gr_save_library_hero', array( $this, 'save' ) );
	}

	public static function defaults() {
		return array(
			'enabled'        => '1',
			'title'          => __( 'BIM Library', 'adx-gated-resources' ),
			'intro'          => __( 'Revit families for every 1000 Series, 3000 Series, and Nexus Series door and window frame we supply — ready to drop into your project.', 'adx-gated-resources' ),
			'families_label' => __( 'Families available', 'adx-gated-resources' ),
			'families'       => '',
			'series_label'   => __( 'Product Series', 'adx-gated-resources' ),
			'series'         => '',
			'format_label'   => __( 'File format', 'adx-gated-resources' ),
			'format'         => '',
			'button_label'   => __( 'Download complete library', 'adx-gated-resources' ),
			'resource_id'    => 0,
		);
	}

	/**
	 * The saved settings, with anything never saved filled in from the
	 * defaults.
	 */
	public static function get() {
		$saved = get_option( self::OPTION, array() );

		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * The complete-library resource, or 0 if none is chosen (or it was
	 * deleted or unpublished).
	 */
	public static function resource_id() {
		$id = absint( self::get()['resource_id'] );

		return $id && ADX_GR_CPT::POST_TYPE === get_post_type( $id ) && 'publish' === get_post_status( $id ) ? $id : 0;
	}

	public function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'adx-gated-resources' ) );
		}

		check_admin_referer( 'adx_gr_save_library_hero' );

		$text = function ( $key ) {
			return isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		};

		$resource_id = isset( $_POST['resource_id'] ) ? absint( $_POST['resource_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing

		update_option(
			self::OPTION,
			array(
				'enabled'        => isset( $_POST['enabled'] ) ? '1' : '0', // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'title'          => $text( 'title' ),
				'intro'          => isset( $_POST['intro'] ) ? sanitize_textarea_field( wp_unslash( $_POST['intro'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'families_label' => $text( 'families_label' ),
				'families'       => $text( 'families' ),
				'series_label'   => $text( 'series_label' ),
				'series'         => $text( 'series' ),
				'format_label'   => $text( 'format_label' ),
				'format'         => $text( 'format' ),
				'button_label'   => $text( 'button_label' ),
				'resource_id'    => ADX_GR_CPT::POST_TYPE === get_post_type( $resource_id ) ? $resource_id : 0,
			),
			false
		);

		// The file behind the button is never a card in the library grid.
		if ( ADX_GR_CPT::POST_TYPE === get_post_type( $resource_id ) ) {
			update_post_meta( $resource_id, '_adx_gr_hide_in_library', '1' );
		}

		wp_safe_redirect( add_query_arg( 'updated', '1', admin_url( 'admin.php?page=adx-gr-library-hero' ) ) );
		exit;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$s         = self::get();
		$resources = get_posts(
			array(
				'post_type'      => ADX_GR_CPT::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Library Hero', 'adx-gated-resources' ); ?></h1>
			<p class="description">
				<?php
				echo wp_kses(
					__( 'The dark banner at the top of the library page (the <code>[adx_gated_library]</code> shortcode): a title, a short intro, three headline numbers and a button that downloads the whole library as one zip.', 'adx-gated-resources' ),
					array( 'code' => array() )
				);
				?>
			</p>

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Library hero saved.', 'adx-gated-resources' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="adx_gr_save_library_hero" />
				<?php wp_nonce_field( 'adx_gr_save_library_hero' ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Show the hero', 'adx-gated-resources' ); ?></th>
						<td>
							<label><input type="checkbox" name="enabled" value="1" <?php checked( '1', $s['enabled'] ); ?> /> <?php esc_html_e( 'Show it at the top of the library page', 'adx-gated-resources' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="adx-gr-hero-title"><?php esc_html_e( 'Title', 'adx-gated-resources' ); ?></label></th>
						<td><input type="text" class="regular-text" id="adx-gr-hero-title" name="title" value="<?php echo esc_attr( $s['title'] ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="adx-gr-hero-intro"><?php esc_html_e( 'Intro', 'adx-gated-resources' ); ?></label></th>
						<td><textarea class="large-text" rows="3" id="adx-gr-hero-intro" name="intro"><?php echo esc_textarea( $s['intro'] ); ?></textarea></td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Headline numbers', 'adx-gated-resources' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Each number is worked out from the library, so it stays right as you add families. Type a value only to override it.', 'adx-gated-resources' ); ?></p>
				<table class="widefat striped" style="max-width:760px;margin-top:10px">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Number', 'adx-gated-resources' ); ?></th>
							<th><?php esc_html_e( 'Label under it', 'adx-gated-resources' ); ?></th>
							<th><?php esc_html_e( 'Override (leave blank for automatic)', 'adx-gated-resources' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( array( 'families' => __( 'Families', 'adx-gated-resources' ), 'series' => __( 'Product Series', 'adx-gated-resources' ), 'format' => __( 'File format', 'adx-gated-resources' ) ) as $key => $name ) : ?>
							<tr>
								<td><strong><?php echo esc_html( $name ); ?></strong></td>
								<td><input type="text" class="regular-text" name="<?php echo esc_attr( $key ); ?>_label" value="<?php echo esc_attr( $s[ $key . '_label' ] ); ?>" /></td>
								<td><input type="text" style="width:140px" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $s[ $key ] ); ?>" placeholder="<?php esc_attr_e( 'Automatic', 'adx-gated-resources' ); ?>" /></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Download button', 'adx-gated-resources' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="adx-gr-hero-resource"><?php esc_html_e( 'Complete library file', 'adx-gated-resources' ); ?></label></th>
						<td>
							<select id="adx-gr-hero-resource" name="resource_id">
								<option value="0"><?php esc_html_e( '— None (hide the button) —', 'adx-gated-resources' ); ?></option>
								<?php foreach ( $resources as $resource ) : ?>
									<option value="<?php echo esc_attr( $resource->ID ); ?>" <?php selected( absint( $s['resource_id'] ), $resource->ID ); ?>><?php echo esc_html( get_the_title( $resource ) ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">
								<?php esc_html_e( 'Add the zip of the whole library as a normal resource first (All Resources > Add New), then pick it here. The email gate works as on every other download, the size is shown on the button automatically, and this resource is kept out of the library grid.', 'adx-gated-resources' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="adx-gr-hero-button"><?php esc_html_e( 'Button text', 'adx-gated-resources' ); ?></label></th>
						<td><input type="text" class="regular-text" id="adx-gr-hero-button" name="button_label" value="<?php echo esc_attr( $s['button_label'] ); ?>" /></td>
					</tr>
				</table>

				<?php submit_button( __( 'Save hero', 'adx-gated-resources' ) ); ?>
			</form>
		</div>
		<?php
	}
}
