<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gates an entire WordPress Page behind the same email-capture flow used
 * for files and bundles: unverified visitors get a locked-page view
 * instead of the real template.
 */
class ADX_GR_Page_Gate {

	const META_GATED  = '_adx_gr_page_gated';
	const META_TEASER = '_adx_gr_page_teaser';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_page', array( $this, 'save_meta_box' ) );
		add_action( 'template_redirect', array( $this, 'maybe_render_gate_template' ), 5 );
	}

	public function add_meta_box() {
		add_meta_box(
			'adx_gr_page_gate_meta',
			__( 'Gate Access', 'adx-gated-resources' ),
			array( $this, 'render_meta_box' ),
			'page',
			'side',
			'default'
		);
	}

	public function render_meta_box( $post ) {
		wp_nonce_field( 'adx_gr_save_page_gate', 'adx_gr_page_gate_nonce' );

		$gated  = '1' === get_post_meta( $post->ID, self::META_GATED, true );
		$teaser = get_post_meta( $post->ID, self::META_TEASER, true );
		?>
		<?php if ( (int) $post->ID === ADX_GR_Ajax::get_library_page_id() ) : ?>
			<p><em><?php esc_html_e( 'This is the Library page (Gated Resources > Settings), so it is always gated.', 'adx-gated-resources' ); ?></em></p>
		<?php endif; ?>
		<p>
			<label>
				<input type="checkbox" name="adx_gr_page_gated" value="1" <?php checked( $gated ); ?> />
				<?php esc_html_e( 'Require email verification to view this page', 'adx-gated-resources' ); ?>
			</label>
		</p>
		<p>
			<label for="adx_gr_page_teaser"><?php esc_html_e( 'Teaser text (optional)', 'adx-gated-resources' ); ?></label><br />
			<textarea id="adx_gr_page_teaser" name="adx_gr_page_teaser" class="widefat" rows="3"><?php echo esc_textarea( $teaser ); ?></textarea>
		</p>
		<p class="description"><?php esc_html_e( 'Shown on the lock screen before visitors verify their email. Logged-in users who can edit this page always see the real content.', 'adx-gated-resources' ); ?></p>
		<?php
	}

	public function save_meta_box( $post_id ) {
		if ( ! isset( $_POST['adx_gr_page_gate_nonce'] ) || ! wp_verify_nonce( $_POST['adx_gr_page_gate_nonce'], 'adx_gr_save_page_gate' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, self::META_GATED, isset( $_POST['adx_gr_page_gated'] ) ? '1' : '0' );
		update_post_meta(
			$post_id,
			self::META_TEASER,
			isset( $_POST['adx_gr_page_teaser'] ) ? sanitize_textarea_field( wp_unslash( $_POST['adx_gr_page_teaser'] ) ) : ''
		);
	}

	/**
	 * The library page (Settings > Library page) is always gated, so a
	 * visitor without a valid cookie always gets the form there.
	 */
	public static function is_gated( $post_id ) {
		if ( 'page' !== get_post_type( $post_id ) ) {
			return false;
		}

		return '1' === get_post_meta( $post_id, self::META_GATED, true )
			|| ( $post_id && (int) $post_id === ADX_GR_Ajax::get_library_page_id() );
	}

	/**
	 * Swaps in the locked-page template for unverified visitors on a
	 * gated page. Logged-in users who can edit the page, and visitors
	 * with a valid "remember me" cookie, see the real template as usual.
	 */
	public function maybe_render_gate_template() {
		if ( ! is_page() ) {
			return;
		}

		$post_id = get_queried_object_id();

		if ( ! self::is_gated( $post_id ) ) {
			return;
		}

		if ( ADX_GR_Ajax::get_verified_email() || current_user_can( 'edit_post', $post_id ) ) {
			// Serving the real content: make sure no page cache stores
			// it, or it would then be handed to unverified visitors.
			// (The lock screen itself is safe to cache.)
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			nocache_headers();
			return;
		}

		$this->render_gate_template( $post_id );
		exit;
	}

	private function render_gate_template( $post_id ) {
		status_header( 200 );

		$template = locate_template( 'adx-gated-resources/gate-template.php' );
		if ( ! $template ) {
			$template = ADX_GR_PATH . 'templates/gate-template.php';
		}

		include $template;
	}
}
