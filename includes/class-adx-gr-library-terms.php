<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extra fields on the resource Category screens, used by the
 * [adx_gated_library] page. Categories are arranged in two levels: a
 * parent (e.g. "Aluminium Partitioning") with one child per product
 * series (e.g. "1000 Series"). Each series gets a green description
 * card on the library page, built from these fields plus the term's
 * own Description:
 *
 * - Badge code: the short code on every card in the series ("1000", "NX64").
 * - Headline: the green line under the series name.
 * - Logo: shown at the top of the card. A series without one uses its
 *   parent's, so the brand logo only needs setting once.
 * - Display order: where the series (or parent) sits in the filters and
 *   on the page. Lower numbers first; ties go alphabetically.
 */
class ADX_GR_Library_Terms {

	const META_CODE     = '_adx_gr_code';
	const META_HEADLINE = '_adx_gr_headline';
	const META_LOGO     = '_adx_gr_logo_id';
	const META_ORDER    = '_adx_gr_order';
	const META_DESC     = '_adx_gr_card_desc';

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$taxonomy = ADX_GR_CPT::TAX_CATEGORY;

		add_action( $taxonomy . '_add_form_fields', array( $this, 'render_add_fields' ) );
		add_action( $taxonomy . '_edit_form_fields', array( $this, 'render_edit_fields' ) );
		add_action( 'created_' . $taxonomy, array( $this, 'save' ) );
		add_action( 'edited_' . $taxonomy, array( $this, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_media' ) );
		add_action( 'admin_head', array( $this, 'admin_css' ) );
		add_filter( 'manage_edit-' . $taxonomy . '_columns', array( $this, 'columns' ) );
		add_filter( 'manage_' . $taxonomy . '_custom_column', array( $this, 'column_content' ), 10, 3 );
	}

	/**
	 * The library fields for one category, with the logo falling back
	 * to the nearest ancestor that has one.
	 */
	public static function get( $term_id ) {
		$logo_id = absint( get_term_meta( $term_id, self::META_LOGO, true ) );

		if ( ! $logo_id ) {
			foreach ( get_ancestors( $term_id, ADX_GR_CPT::TAX_CATEGORY, 'taxonomy' ) as $ancestor_id ) {
				$logo_id = absint( get_term_meta( $ancestor_id, self::META_LOGO, true ) );
				if ( $logo_id ) {
					break;
				}
			}
		}

		$order = get_term_meta( $term_id, self::META_ORDER, true );

		return array(
			'code'        => (string) get_term_meta( $term_id, self::META_CODE, true ),
			'headline'    => self::display_name( get_term_meta( $term_id, self::META_HEADLINE, true ) ),
			'description' => self::display_name( self::description( $term_id ) ),
			'logo_id'     => $logo_id,
			'order'       => '' === $order ? null : (int) $order,
		);
	}

	/**
	 * Text as the library shows it: the words "series" and "nexus" always
	 * start with a capital ("1000 series" > "1000 Series", "NEXUS 64" >
	 * "Nexus 64"), whatever was typed in the admin.
	 */
	public static function display_name( $text ) {
		return preg_replace( array( '/\bseries\b/i', '/\bnexus\b/i' ), array( 'Series', 'Nexus' ), (string) $text );
	}

	/**
	 * The text under the headline on the card. Categories that existed
	 * before the card section was added keep using WordPress's own
	 * Description until the card is saved once.
	 */
	public static function description( $term_id ) {
		if ( metadata_exists( 'term', $term_id, self::META_DESC ) ) {
			return (string) get_term_meta( $term_id, self::META_DESC, true );
		}

		$term = get_term( $term_id, ADX_GR_CPT::TAX_CATEGORY );

		return $term instanceof WP_Term ? (string) $term->description : '';
	}

	/**
	 * Sorts terms by Display order (unset ones last), then by name.
	 */
	public static function sort_terms( array $terms ) {
		usort(
			$terms,
			function ( $a, $b ) {
				$order_a = self::get( $a->term_id )['order'];
				$order_b = self::get( $b->term_id )['order'];
				$order_a = null === $order_a ? PHP_INT_MAX : $order_a;
				$order_b = null === $order_b ? PHP_INT_MAX : $order_b;

				return $order_a === $order_b ? strnatcasecmp( $a->name, $b->name ) : $order_a <=> $order_b;
			}
		);

		return $terms;
	}

	public function enqueue_media( $hook ) {
		$screen = get_current_screen();

		if ( $screen && ADX_GR_CPT::TAX_CATEGORY === $screen->taxonomy && in_array( $hook, array( 'edit-tags.php', 'term.php' ), true ) ) {
			wp_enqueue_media();
		}
	}

	/**
	 * Hides WordPress's own Description box on these screens: the card
	 * has its own, in the Library card section.
	 */
	public function admin_css() {
		$screen = get_current_screen();

		if ( ! $screen || ADX_GR_CPT::TAX_CATEGORY !== $screen->taxonomy ) {
			return;
		}
		?>
		<style>
			.term-description-wrap { display: none !important; }
			.adx-gr-card-section { background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #3a9b3a; padding: 4px 16px 12px; margin: 16px 0; max-width: 760px; }
			.adx-gr-card-section h2 { margin: 12px 0 2px; font-size: 15px; }
			.adx-gr-card-section > .description { margin: 0 0 8px; }
			.adx-gr-card-section textarea { width: 100%; }
			.adx-gr-card-section input[type=text] { width: 100%; max-width: 100%; }
			.adx-gr-card-preview { width: 260px; background: #18332F; color: #fff; border-radius: 14px; padding: 22px; margin-top: 6px; }
			.adx-gr-card-preview img { display: block; max-height: 34px; width: auto; margin-bottom: 14px; }
			.adx-gr-card-preview .p-title { font-size: 22px; font-weight: 700; line-height: 1.15; margin: 0 0 8px; }
			.adx-gr-card-preview .p-sub { color: #4caf50; font-weight: 600; margin: 0 0 8px; }
			.adx-gr-card-preview .p-text { margin: 0; font-size: 13px; line-height: 1.5; opacity: .9; white-space: pre-line; }
		</style>
		<?php
	}

	public function render_add_fields() {
		wp_nonce_field( 'adx_gr_term_fields', 'adx_gr_term_fields_nonce' );
		?>
		<div class="adx-gr-card-section">
			<h2><?php esc_html_e( 'Library card', 'adx-gated-resources' ); ?></h2>
			<p class="description"><?php esc_html_e( 'The dark card shown for this series on the library page. The card title is the Name above.', 'adx-gated-resources' ); ?></p>
			<div class="form-field">
				<label><?php esc_html_e( 'Category image (logo)', 'adx-gated-resources' ); ?></label>
				<?php $this->render_logo_picker( 0 ); ?>
				<p><?php esc_html_e( 'Shown at the top of the card. Set it on a parent category to use it for every series under it.', 'adx-gated-resources' ); ?></p>
			</div>
			<div class="form-field">
				<label for="adx-gr-headline"><?php esc_html_e( 'Sub description (green line)', 'adx-gated-resources' ); ?></label>
				<input type="text" id="adx-gr-headline" name="adx_gr_headline" />
				<p><?php esc_html_e( 'e.g. "Heavy-duty Centreline Glazing".', 'adx-gated-resources' ); ?></p>
			</div>
			<div class="form-field">
				<label for="adx-gr-card-desc"><?php esc_html_e( 'Description', 'adx-gated-resources' ); ?></label>
				<textarea id="adx-gr-card-desc" name="adx_gr_card_desc" rows="4"></textarea>
			</div>
			<?php $this->render_preview(); ?>
		</div>
		<div class="adx-gr-card-section">
			<h2><?php esc_html_e( 'Library settings', 'adx-gated-resources' ); ?></h2>
			<div class="form-field">
				<label for="adx-gr-code"><?php esc_html_e( 'Badge code', 'adx-gated-resources' ); ?></label>
				<input type="text" id="adx-gr-code" name="adx_gr_code" maxlength="12" style="width:120px" />
				<p><?php esc_html_e( 'For a series: the short code shown on every card in it, e.g. 1000 or NX64.', 'adx-gated-resources' ); ?></p>
			</div>
			<div class="form-field">
				<label for="adx-gr-order"><?php esc_html_e( 'Display order', 'adx-gated-resources' ); ?></label>
				<input type="number" id="adx-gr-order" name="adx_gr_order" step="1" style="width:100px" />
				<p><?php esc_html_e( 'Lower numbers come first in the library. Leave blank to sort alphabetically.', 'adx-gated-resources' ); ?></p>
			</div>
		</div>
		<?php
	}

	public function render_edit_fields( $term ) {
		$code     = get_term_meta( $term->term_id, self::META_CODE, true );
		$headline = get_term_meta( $term->term_id, self::META_HEADLINE, true );
		$desc     = self::description( $term->term_id );
		$logo_id  = absint( get_term_meta( $term->term_id, self::META_LOGO, true ) );
		$order    = get_term_meta( $term->term_id, self::META_ORDER, true );
		?>
		<tr class="form-field">
			<td colspan="2" style="padding:0">
				<?php wp_nonce_field( 'adx_gr_term_fields', 'adx_gr_term_fields_nonce' ); ?>
				<div class="adx-gr-card-section">
					<h2><?php esc_html_e( 'Library card', 'adx-gated-resources' ); ?></h2>
					<p class="description"><?php esc_html_e( 'The dark card shown for this series on the library page. The card title is the Name above.', 'adx-gated-resources' ); ?></p>
					<p><strong><?php esc_html_e( 'Category image (logo)', 'adx-gated-resources' ); ?></strong></p>
					<?php $this->render_logo_picker( $logo_id ); ?>
					<p class="description"><?php esc_html_e( 'Shown at the top of the card. Set it on a parent category to use it for every series under it.', 'adx-gated-resources' ); ?></p>
					<p><label for="adx-gr-headline"><strong><?php esc_html_e( 'Sub description (green line)', 'adx-gated-resources' ); ?></strong></label></p>
					<input type="text" id="adx-gr-headline" name="adx_gr_headline" value="<?php echo esc_attr( $headline ); ?>" />
					<p class="description"><?php esc_html_e( 'e.g. "Heavy-duty Centreline Glazing".', 'adx-gated-resources' ); ?></p>
					<p><label for="adx-gr-card-desc"><strong><?php esc_html_e( 'Description', 'adx-gated-resources' ); ?></strong></label></p>
					<textarea id="adx-gr-card-desc" name="adx_gr_card_desc" rows="4"><?php echo esc_textarea( $desc ); ?></textarea>
					<?php $this->render_preview(); ?>
				</div>
				<div class="adx-gr-card-section">
					<h2><?php esc_html_e( 'Library settings', 'adx-gated-resources' ); ?></h2>
					<p><label for="adx-gr-code"><strong><?php esc_html_e( 'Badge code', 'adx-gated-resources' ); ?></strong></label></p>
					<input type="text" id="adx-gr-code" name="adx_gr_code" value="<?php echo esc_attr( $code ); ?>" maxlength="12" style="width:120px" />
					<p class="description"><?php esc_html_e( 'For a series: the short code shown on every card in it, e.g. 1000 or NX64.', 'adx-gated-resources' ); ?></p>
					<p><label for="adx-gr-order"><strong><?php esc_html_e( 'Display order', 'adx-gated-resources' ); ?></strong></label></p>
					<input type="number" id="adx-gr-order" name="adx_gr_order" value="<?php echo esc_attr( $order ); ?>" step="1" style="width:100px" />
					<p class="description"><?php esc_html_e( 'Lower numbers come first in the library. Leave blank to sort alphabetically.', 'adx-gated-resources' ); ?></p>
				</div>
			</td>
		</tr>
		<?php
	}

	/**
	 * A small copy of the card that follows what is typed above it.
	 */
	private function render_preview() {
		?>
		<p><strong><?php esc_html_e( 'Preview', 'adx-gated-resources' ); ?></strong></p>
		<div class="adx-gr-card-preview">
			<img alt="" style="display:none" />
			<p class="p-title"></p>
			<p class="p-sub"></p>
			<p class="p-text"></p>
		</div>
		<script>
		( function () {
			var section = document.currentScript.closest( '.adx-gr-card-section' );
			var box = section.querySelector( '.adx-gr-card-preview' );
			var logo = section.querySelector( '.adx-gr-logo-preview img' );
			var nameInput = document.getElementById( 'tag-name' ) || document.getElementById( 'name' );
			var sub = section.querySelector( '#adx-gr-headline' );
			var desc = section.querySelector( '#adx-gr-card-desc' );
			var idField = section.querySelector( '.adx-gr-logo-id' );
			var fallbackName = <?php echo wp_json_encode( __( 'Series name', 'adx-gated-resources' ) ); ?>;

			function update() {
				box.querySelector( '.p-title' ).textContent = ( nameInput && nameInput.value ) || fallbackName;
				box.querySelector( '.p-sub' ).textContent = sub.value;
				box.querySelector( '.p-text' ).textContent = desc.value;
				var img = box.querySelector( 'img' );
				var src = idField.value ? logo.getAttribute( 'src' ) : '';
				img.style.display = src ? 'block' : 'none';
				if ( src ) { img.src = src; }
			}

			[ nameInput, sub, desc ].forEach( function ( el ) { if ( el ) { el.addEventListener( 'input', update ); } } );
			idField.addEventListener( 'change', update );
			update();

			// The "Add New Category" form clears itself after saving.
			if ( window.jQuery ) {
				window.jQuery( document ).ajaxSuccess( function ( event, xhr, settings ) {
					if ( settings.data && String( settings.data ).indexOf( 'action=add-tag' ) !== -1 && ! section.closest( '#edittag' ) ) {
						setTimeout( update, 50 );
					}
				} );
			}
		} )();
		</script>
		<?php
	}

	private function render_logo_picker( $logo_id ) {
		$preview = $logo_id ? wp_get_attachment_image_url( $logo_id, 'medium' ) : '';
		?>
		<div class="adx-gr-logo-picker">
			<input type="hidden" name="adx_gr_logo_id" class="adx-gr-logo-id" value="<?php echo esc_attr( $logo_id ? $logo_id : '' ); ?>" />
			<div class="adx-gr-logo-preview" style="margin:0 0 8px;padding:10px;background:#18332F;border-radius:4px;display:<?php echo $preview ? 'inline-block' : 'none'; ?>;">
				<img src="<?php echo esc_url( $preview ); ?>" alt="" style="display:block;max-height:40px;width:auto;" />
			</div>
			<div>
				<button type="button" class="button adx-gr-logo-choose"><?php esc_html_e( 'Choose logo', 'adx-gated-resources' ); ?></button>
				<button type="button" class="button-link adx-gr-logo-remove" style="margin-left:8px;<?php echo $logo_id ? '' : 'display:none;'; ?>"><?php esc_html_e( 'Remove', 'adx-gated-resources' ); ?></button>
			</div>
		</div>
		<script>
		( function () {
			var picker = document.currentScript.previousElementSibling;
			var idField = picker.querySelector( '.adx-gr-logo-id' );
			var previewWrap = picker.querySelector( '.adx-gr-logo-preview' );
			var preview = previewWrap.querySelector( 'img' );
			var removeBtn = picker.querySelector( '.adx-gr-logo-remove' );
			var frame;

			picker.querySelector( '.adx-gr-logo-choose' ).addEventListener( 'click', function () {
				if ( ! frame ) {
					frame = wp.media( { title: <?php echo wp_json_encode( __( 'Choose a logo', 'adx-gated-resources' ) ); ?>, library: { type: 'image' }, multiple: false } );
					frame.on( 'select', function () {
						var image = frame.state().get( 'selection' ).first().toJSON();
						idField.value = image.id;
						preview.src = ( image.sizes && image.sizes.medium ) ? image.sizes.medium.url : image.url;
						previewWrap.style.display = 'inline-block';
						removeBtn.style.display = '';
						idField.dispatchEvent( new Event( 'change', { bubbles: true } ) );
					} );
				}
				frame.open();
			} );

			removeBtn.addEventListener( 'click', function () {
				idField.value = '';
				preview.removeAttribute( 'src' );
				previewWrap.style.display = 'none';
				removeBtn.style.display = 'none';
				idField.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			} );

			// The "Add New Category" form is submitted by AJAX and cleared
			// afterwards; clear the logo with it.
			if ( window.jQuery ) {
				window.jQuery( document ).ajaxSuccess( function ( event, xhr, settings ) {
					if ( settings.data && String( settings.data ).indexOf( 'action=add-tag' ) !== -1 && ! picker.closest( '#edittag' ) ) {
						removeBtn.click();
					}
				} );
			}
		} )();
		</script>
		<?php
	}

	public function save( $term_id ) {
		if ( ! isset( $_POST['adx_gr_term_fields_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['adx_gr_term_fields_nonce'] ) ), 'adx_gr_term_fields' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_categories' ) ) {
			return;
		}

		$fields = array(
			self::META_CODE     => isset( $_POST['adx_gr_code'] ) ? sanitize_text_field( wp_unslash( $_POST['adx_gr_code'] ) ) : '',
			self::META_HEADLINE => isset( $_POST['adx_gr_headline'] ) ? sanitize_text_field( wp_unslash( $_POST['adx_gr_headline'] ) ) : '',
			self::META_LOGO     => isset( $_POST['adx_gr_logo_id'] ) ? absint( $_POST['adx_gr_logo_id'] ) : 0,
			self::META_ORDER    => isset( $_POST['adx_gr_order'] ) && '' !== trim( (string) wp_unslash( $_POST['adx_gr_order'] ) ) ? (string) (int) $_POST['adx_gr_order'] : '',
		);

		// Always stored, even when empty, so clearing it doesn't bring the
		// old WordPress Description back.
		if ( isset( $_POST['adx_gr_card_desc'] ) ) {
			update_term_meta( $term_id, self::META_DESC, sanitize_textarea_field( wp_unslash( $_POST['adx_gr_card_desc'] ) ) );
		}

		foreach ( $fields as $key => $value ) {
			if ( '' === $value || 0 === $value ) {
				delete_term_meta( $term_id, $key );
			} else {
				update_term_meta( $term_id, $key, $value );
			}
		}
	}

	public function columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'name' === $key ) {
				$new['adx_gr_code']  = __( 'Badge', 'adx-gated-resources' );
				$new['adx_gr_order'] = __( 'Order', 'adx-gated-resources' );
			}
		}
		return $new;
	}

	public function column_content( $content, $column, $term_id ) {
		if ( 'adx_gr_code' === $column ) {
			$code = get_term_meta( $term_id, self::META_CODE, true );
			return $code ? '<code>' . esc_html( $code ) . '</code>' : '&mdash;';
		}

		if ( 'adx_gr_order' === $column ) {
			$order = get_term_meta( $term_id, self::META_ORDER, true );
			return '' === $order ? '&mdash;' : esc_html( $order );
		}

		return $content;
	}
}
