<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Front-end output: the [adx_gated_resource] shortcode, automatic
 * gating of matching <a> links in post content, and the modal markup
 * plus assets that drive the email-gate UX.
 */
class ADX_GR_Frontend {

	private static $instance = null;
	private $needs_assets = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'adx_gated_resource', array( $this, 'shortcode' ) );
		add_shortcode( 'adx_gated_library', array( $this, 'library_shortcode' ) );
		add_shortcode( 'adx_library_hero', array( $this, 'hero_shortcode' ) );
		add_filter( 'pre_do_shortcode_tag', array( $this, 'tolerate_curly_quotes' ), 10, 4 );
		add_shortcode( 'adx_library_access', array( $this, 'library_access_shortcode' ) );
		add_filter( 'the_content', array( $this, 'auto_gate_content' ), 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		add_action( 'wp_footer', array( $this, 'maybe_print_modal' ) );
	}

	public function register_assets() {
		wp_register_style( 'adx-gr-frontend', ADX_GR_URL . 'assets/css/frontend.css', array(), ADX_GR_VERSION );
		wp_register_script( 'adx-gr-frontend', ADX_GR_URL . 'assets/js/frontend.js', array(), ADX_GR_VERSION, true );
		// The library page's typefaces (Archivo headings, Inter text), per the BIM library design.
		wp_register_style( 'adx-gr-library-fonts', 'https://fonts.googleapis.com/css2?family=Archivo:wght@600;700;800&family=Inter:wght@400;500;600&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		wp_register_style( 'adx-gr-library', ADX_GR_URL . 'assets/css/library.css', array( 'adx-gr-frontend', 'adx-gr-library-fonts' ), ADX_GR_VERSION );
		wp_register_script( 'adx-gr-library', ADX_GR_URL . 'assets/js/library.js', array(), ADX_GR_VERSION, true );

		wp_localize_script(
			'adx-gr-frontend',
			'adxGatedResources',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'adx_gr_frontend' ),
				'i18n'      => array(
					'submitLabel'     => __( 'Get the file', 'adx-gated-resources' ),
					'submitting'      => __( 'Verifying…', 'adx-gated-resources' ),
					'genericError'    => __( 'Something went wrong. Please try again.', 'adx-gated-resources' ),
					'validationError' => __( 'Please complete all required fields.', 'adx-gated-resources' ),
					'libraryLabel'    => __( 'Go to the library', 'adx-gated-resources' ),
					'libraryNote'     => __( "You'll be taken to the library once verified.", 'adx-gated-resources' ),
				),
			)
		);

		wp_localize_script(
			'adx-gr-library',
			'adxGatedLibrary',
			array(
				'i18n' => array(
					'oneResult'   => __( 'listing', 'adx-gated-resources' ),
					'manyResults' => __( 'listings', 'adx-gated-resources' ),
				),
			)
		);
	}

	public function enqueue_assets() {
		if ( $this->needs_assets ) {
			return;
		}
		$this->needs_assets = true;
		wp_enqueue_style( 'adx-gr-frontend' );
		wp_enqueue_script( 'adx-gr-frontend' );
	}

	private function enqueue_library_assets() {
		// The library grid's download buttons are gate triggers too
		// (same markup, same click handling), so it needs the modal
		// assets in addition to its own grid/filter styling and JS.
		$this->enqueue_assets();
		wp_enqueue_style( 'adx-gr-library' );
		wp_enqueue_script( 'adx-gr-library' );
	}

	/**
	 * Editors and pasted text often swap the straight quotes in a shortcode
	 * for curly ones (or just one of the two), and WordPress then cannot read
	 * the attribute: [adx_library_access text="Get files”] showed the
	 * default "Library". This reads the attributes of this plugin's
	 * shortcodes again with the quotes straightened, whenever that makes a
	 * difference.
	 */
	public function tolerate_curly_quotes( $return, $tag, $attr, $match ) {
		global $shortcode_tags;

		if ( false !== $return || ! in_array( $tag, array( 'adx_gated_resource', 'adx_gated_library', 'adx_library_access', 'adx_library_hero' ), true ) || empty( $shortcode_tags[ $tag ] ) ) {
			return $return;
		}

		$raw = isset( $match[3] ) ? (string) $match[3] : '';

		if ( '' === trim( $raw ) ) {
			return $return;
		}

		$fixed = str_ireplace(
			array( "\xE2\x80\x9C", "\xE2\x80\x9D", "\xE2\x80\x9E", "\xE2\x80\x9F", "\xE2\x80\xB3", '&#8220;', '&#8221;', '&#8222;', '&#8243;', '&#x201c;', '&#x201d;', '&ldquo;', '&rdquo;', '&bdquo;', '&quot;', '&#34;', '&#038;quot;' ),
			'"',
			$raw
		);
		$fixed = str_ireplace(
			array( "\xE2\x80\x98", "\xE2\x80\x99", "\xE2\x80\x9A", "\xE2\x80\xB2", '&#8216;', '&#8217;', '&#8218;', '&#8242;', '&lsquo;', '&rsquo;' ),
			"'",
			$fixed
		);

		if ( $fixed === $raw ) {
			return $return;
		}

		return call_user_func( $shortcode_tags[ $tag ], shortcode_parse_atts( $fixed ), isset( $match[5] ) ? $match[5] : null, $tag );
	}

	public function shortcode( $atts, $content = null ) {
		$atts = shortcode_atts(
			array(
				'id'   => 0,
				'text' => __( 'Download', 'adx-gated-resources' ),
			),
			$atts,
			'adx_gated_resource'
		);

		$resource_id = absint( $atts['id'] );

		if ( ! $resource_id || ADX_GR_CPT::POST_TYPE !== get_post_type( $resource_id ) ) {
			return '';
		}

		$label = null !== $content && '' !== trim( (string) $content ) ? $content : $atts['text'];

		$this->enqueue_assets();

		return $this->render_trigger_button( $resource_id, wp_kses_post( $label ) );
	}

	/**
	 * [adx_library_access]: a button for product pages that opens the
	 * gate form and, once submitted, emails Sales which product the
	 * visitor came from and sends them on to the library page (Settings
	 * > Library page). The product defaults to the page's title; set
	 * product="..." to name it explicitly. The name is signed to this
	 * page so the visitor can't change what lands in the email.
	 */
	public function library_access_shortcode( $atts, $content = null ) {
		$atts = shortcode_atts(
			array(
				'text'    => __( 'Library', 'adx-gated-resources' ),
				'product' => '',
				'class'   => '',
				'icon'    => 'yes',
			),
			$atts,
			'adx_library_access'
		);

		$source_id = get_the_ID() ? get_the_ID() : get_queried_object_id();

		if ( ! $source_id || ! ADX_GR_Ajax::get_library_page_id() ) {
			return '';
		}

		$this->enqueue_assets();

		$label   = null !== $content && '' !== trim( (string) $content ) ? $content : $atts['text'];
		$product = sanitize_text_field( $atts['product'] );
		$classes = array_map( 'sanitize_html_class', preg_split( '/\s+/', $atts['class'], -1, PREG_SPLIT_NO_EMPTY ) );

		$product_attrs = '';
		if ( '' !== $product ) {
			$product_attrs = sprintf(
				' data-adx-gr-product="%1$s" data-adx-gr-product-key="%2$s"',
				esc_attr( $product ),
				esc_attr( ADX_GR_Ajax::sign_product( $source_id, $product ) )
			);
		}

		// A download icon before the text (icon="no" leaves it out).
		$icon = in_array( strtolower( trim( (string) $atts['icon'] ) ), array( 'no', 'false', '0' ), true )
			? ''
			: '<span class="adx-gr-access-button__icon">' . $this->download_icon() . '</span>';

		return sprintf(
			'<a href="#" class="%1$s" data-adx-gr-context="library" data-adx-gr-source="%2$d"%3$s>%4$s<span class="adx-gr-access-button__text">%5$s</span></a>',
			esc_attr( trim( 'adx-gr-trigger adx-gr-access-button ' . implode( ' ', $classes ) ) ),
			$source_id,
			$product_attrs,
			$icon, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			wp_kses_post( $label )
		);
	}

	/**
	 * [adx_gated_library]: the BIM library page. Resources are grouped by
	 * product series, taken from the Category taxonomy in two levels:
	 * a parent such as "Aluminium Partitioning", with one child category
	 * per series ("1000 Series", "Nexus 64"...). Each series shows as:
	 *
	 * 1. a green description card (logo, name, headline, description;
	 *    see ADX_GR_Library_Terms),
	 * 2. its "Download all" card(s): any resource in the series that is a
	 *    Bundle or a single .zip file,
	 * 3. a card for every other resource in the series.
	 *
	 * Above the grid: parent-category chips, series chips and a search
	 * box, all filtering client-side (assets/js/library.js). Resources
	 * with no category are listed last under "Other resources".
	 *
	 * Download buttons are the plugin's usual .adx-gr-trigger links, so
	 * the email gate works exactly as everywhere else.
	 *
	 * The library sits inside the theme's content column, and its cards
	 * share that width evenly, so the number of columns follows the space
	 * available. layout="full" instead spans the full browser width.
	 */
	public function library_shortcode( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'layout' => 'contained',
				'hero'   => 'yes',
			),
			$atts,
			'adx_gated_library'
		);
		$full = 'full' === strtolower( trim( (string) $atts['layout'] ) );

		$this->enqueue_library_assets();

		$groups  = $this->load_library_groups();
		$parents = array();

		foreach ( $groups as $group ) {
			if ( $group['parent'] ) {
				$parents[ $group['parent']->term_id ] = $group['parent'];
			}
		}
		$parents = ADX_GR_Library_Terms::sort_terms( array_values( $parents ) );

		$series_groups = array_filter(
			$groups,
			function ( $group ) {
				return null !== $group['term'];
			}
		);

		$example_code = '';
		foreach ( $series_groups as $group ) {
			if ( '' !== $group['code'] ) {
				$example_code = $group['code'];
				break;
			}
		}

		ob_start();
		?>
		<div class="adx-gr-lib<?php echo $full ? ' adx-gr-lib--full' : ''; ?>" data-adx-gr-library>
				<?php
				if ( ! in_array( strtolower( trim( (string) $atts['hero'] ) ), array( 'no', 'false', '0' ), true ) ) {
					$this->render_hero( $groups );
				}
				?>
			<?php if ( $parents ) : ?>
				<div class="adx-gr-lib__parents" role="group" aria-label="<?php esc_attr_e( 'Product category', 'adx-gated-resources' ); ?>">
					<button type="button" class="adx-gr-lib__parent is-active" data-adx-gr-parent="" aria-pressed="true"><?php esc_html_e( 'All categories', 'adx-gated-resources' ); ?></button>
					<?php foreach ( $parents as $parent ) : ?>
						<button type="button" class="adx-gr-lib__parent" data-adx-gr-parent="<?php echo esc_attr( $parent->slug ); ?>" aria-pressed="false"><?php echo esc_html( ADX_GR_Library_Terms::display_name( $parent->name ) ); ?></button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<?php if ( count( $series_groups ) > 1 ) : ?>
				<div class="adx-gr-lib__series-nav" role="group" aria-label="<?php esc_attr_e( 'Product Series', 'adx-gated-resources' ); ?>">
					<button type="button" class="adx-gr-lib__chip is-active" data-adx-gr-series="" aria-pressed="true"><?php esc_html_e( 'All', 'adx-gated-resources' ); ?></button>
					<?php foreach ( $series_groups as $group ) : ?>
						<button type="button" class="adx-gr-lib__chip" data-adx-gr-series="<?php echo esc_attr( $group['key'] ); ?>" data-parent="<?php echo esc_attr( $group['parent'] ? $group['parent']->slug : '' ); ?>" aria-pressed="false"><?php echo esc_html( ADX_GR_Library_Terms::display_name( $group['term']->name ) ); ?></button>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<div class="adx-gr-lib__search-row">
				<span class="adx-gr-lib__search-field">
					<input
						type="search"
						class="adx-gr-lib__search"
						data-adx-gr-library-search
						placeholder="<?php echo esc_attr( $example_code ? sprintf( /* translators: %s: example series code */ __( 'Search families, e.g. "%s door frame" or "sidelight"', 'adx-gated-resources' ), $example_code ) : __( 'Search resources…', 'adx-gated-resources' ) ); ?>"
						aria-label="<?php esc_attr_e( 'Search resources', 'adx-gated-resources' ); ?>"
					/>
					<button type="button" class="adx-gr-lib__search-clear" data-adx-gr-library-clear aria-label="<?php esc_attr_e( 'Clear search and show all', 'adx-gated-resources' ); ?>" title="<?php esc_attr_e( 'Clear search and show all', 'adx-gated-resources' ); ?>" hidden>
						<svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
					</button>
				</span>
				<span class="adx-gr-lib__count" data-adx-gr-library-count aria-live="polite"></span>
			</div>

			<div class="adx-gr-lib__grid">
				<?php foreach ( $groups as $group ) : ?>
					<?php $this->render_library_group( $group ); ?>
				<?php endforeach; ?>
			</div>

			<p class="adx-gr-lib__empty" data-adx-gr-library-empty hidden>
				<?php esc_html_e( 'No resources match your search or filters.', 'adx-gated-resources' ); ?>
			</p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Sorts resources into series groups. A resource's series is the
	 * deepest category it's in (one with no sub-categories); the series'
	 * top-level ancestor is its parent category, if it has one.
	 */
	private function build_library_groups( array $posts ) {
		$groups   = array();
		$other    = array(
			'key'     => '_other',
			'term'    => null,
			'parent'  => null,
			'code'    => '',
			'bundles' => array(),
			'items'   => array(),
		);
		$is_leaf  = array();
		$taxonomy = ADX_GR_CPT::TAX_CATEGORY;

		foreach ( $posts as $post ) {
			$terms  = get_the_terms( $post->ID, $taxonomy );
			$terms  = is_array( $terms ) ? $terms : array();
			$series = null;

			$series_count = 0;

			foreach ( $terms as $term ) {
				if ( ! isset( $is_leaf[ $term->term_id ] ) ) {
					$children                  = get_term_children( $term->term_id, $taxonomy );
					$is_leaf[ $term->term_id ] = empty( $children ) || is_wp_error( $children );
				}
				if ( $is_leaf[ $term->term_id ] ) {
					$series_count++;
					if ( ! $series ) {
						$series = $term;
					}
				}
			}
			if ( ! $series && $terms ) {
				$series = $terms[0];
			}

			$entry = array(
				'post'         => $post,
				'info'         => ADX_GR_CPT::get_download_info( $post->ID ),
				// Ticked in more than one series (e.g. a copied resource
				// that kept the original's tick): its image may belong to
				// another series, so it's left out of collages.
				'multi_series' => $series_count > 1,
			);

			if ( ! $series ) {
				$other[ $entry['info']['is_bundle'] ? 'bundles' : 'items' ][] = $entry;
				continue;
			}

			if ( ! isset( $groups[ $series->term_id ] ) ) {
				$ancestors = get_ancestors( $series->term_id, $taxonomy, 'taxonomy' );
				$parent    = $ancestors ? get_term( end( $ancestors ), $taxonomy ) : null;
				$fields    = ADX_GR_Library_Terms::get( $series->term_id );

				$groups[ $series->term_id ] = array(
					'key'     => $series->slug,
					'term'    => $series,
					'parent'  => $parent instanceof WP_Term ? $parent : null,
					'code'    => $fields['code'],
					'fields'  => $fields,
					'bundles' => array(),
					'items'   => array(),
				);
			}

			$groups[ $series->term_id ][ $entry['info']['is_bundle'] ? 'bundles' : 'items' ][] = $entry;
		}

		// Series in parent order, then their own order within a parent.
		$parent_rank = array();
		$parents     = array();
		foreach ( $groups as $group ) {
			if ( $group['parent'] ) {
				$parents[ $group['parent']->term_id ] = $group['parent'];
			}
		}
		foreach ( ADX_GR_Library_Terms::sort_terms( array_values( $parents ) ) as $rank => $parent ) {
			$parent_rank[ $parent->term_id ] = $rank;
		}

		$series_rank = array();
		foreach ( ADX_GR_Library_Terms::sort_terms( wp_list_pluck( $groups, 'term' ) ) as $rank => $term ) {
			$series_rank[ $term->term_id ] = $rank;
		}

		uasort(
			$groups,
			function ( $a, $b ) use ( $parent_rank, $series_rank ) {
				$pa = $a['parent'] ? $parent_rank[ $a['parent']->term_id ] : PHP_INT_MAX;
				$pb = $b['parent'] ? $parent_rank[ $b['parent']->term_id ] : PHP_INT_MAX;

				return $pa === $pb ? $series_rank[ $a['term']->term_id ] <=> $series_rank[ $b['term']->term_id ] : $pa <=> $pb;
			}
		);

		$by_title = function ( $a, $b ) {
			return strnatcasecmp( $a['post']->post_title, $b['post']->post_title );
		};

		foreach ( $groups as &$group ) {
			usort( $group['bundles'], $by_title );
			usort( $group['items'], $by_title );
		}
		unset( $group );

		$groups = array_values( $groups );

		if ( $other['bundles'] || $other['items'] ) {
			$groups[] = $other;
		}

		return $groups;
	}

	/**
	 * Every published resource, grouped by series. The complete-library zip
	 * chosen for the hero button is left out, so it doesn't also appear as
	 * a card in the grid.
	 */
	private function load_library_groups() {
		$args = array(
			'post_type'      => ADX_GR_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		// Resources kept out of the grid: ticked as such, or the file behind
		// the hero's download button. This holds whether or not the hero is
		// shown, so that file never turns up as a card.
		$hidden = ADX_GR_CPT::get_hidden_in_library_ids();

		if ( $hidden ) {
			$args['post__not_in'] = $hidden;
		}

		$query = new WP_Query( $args );

		return $this->build_library_groups( $query->posts );
	}

	/**
	 * [adx_library_hero]: just the hero band, for placing it somewhere other
	 * than the top of [adx_gated_library] (which shows it by default; use
	 * [adx_gated_library hero="no"] to hide that one).
	 */
	public function hero_shortcode() {
		$this->enqueue_library_assets();

		ob_start();
		echo '<div class="adx-gr-lib adx-gr-lib--hero-only">';
		$this->render_hero( $this->load_library_groups() );
		echo '</div>';

		return ob_get_clean();
	}

	/**
	 * The hero band (settings: ADX_GR_Library_Hero). Its three numbers are
	 * counted from the same groups the grid is built from unless a value
	 * was typed in to override them.
	 */
	private function render_hero( array $groups ) {
		$s = ADX_GR_Library_Hero::get();

		if ( '1' !== $s['enabled'] ) {
			return;
		}

		$families = 0;
		$series   = 0;
		$formats  = array();

		foreach ( $groups as $group ) {
			if ( $group['term'] && ( $group['items'] || $group['bundles'] ) ) {
				$series++;
			}

			foreach ( $group['items'] as $entry ) {
				$families++;
				$format = $entry['info']['format'];
				if ( '' !== $format ) {
					$formats[ $format ] = ( isset( $formats[ $format ] ) ? $formats[ $format ] : 0 ) + 1;
				}
			}
		}

		arsort( $formats );

		$stats = array(
			array( '' !== trim( $s['families'] ) ? $s['families'] : (string) $families, $s['families_label'] ),
			array( '' !== trim( $s['series'] ) ? $s['series'] : (string) $series, $s['series_label'] ),
			array( '' !== trim( $s['format'] ) ? $s['format'] : (string) key( $formats ), $s['format_label'] ),
		);

		$resource_id = ADX_GR_Library_Hero::resource_id();
		?>
		<section class="adx-gr-lib__hero">
			<?php if ( '' !== $s['title'] ) : ?>
				<h2 class="adx-gr-lib__hero-title"><?php echo esc_html( ADX_GR_Library_Terms::display_name( $s['title'] ) ); ?></h2>
			<?php endif; ?>
			<?php if ( '' !== trim( $s['intro'] ) ) : ?>
				<p class="adx-gr-lib__hero-intro"><?php echo esc_html( ADX_GR_Library_Terms::display_name( $s['intro'] ) ); ?></p>
			<?php endif; ?>

			<div class="adx-gr-lib__hero-stats">
				<?php foreach ( $stats as $stat ) : ?>
					<?php if ( '' !== $stat[0] ) : ?>
						<div class="adx-gr-lib__hero-stat">
							<span class="adx-gr-lib__hero-num"><?php echo esc_html( $stat[0] ); ?></span>
							<span class="adx-gr-lib__hero-label"><?php echo esc_html( $stat[1] ); ?></span>
						</div>
					<?php endif; ?>
				<?php endforeach; ?>
			</div>

			<?php
			if ( $resource_id ) {
				$info  = ADX_GR_CPT::get_download_info( $resource_id );
				$label = '<span class="adx-gr-lib__btn-label">' . $this->zip_icon() . esc_html( $s['button_label'] ) . '</span>' . $this->size_label( $info['bytes'] );

				echo $this->render_trigger_button( $resource_id, $label, 'adx-gr-lib__hero-btn' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			?>
		</section>
		<?php
	}

	private function render_library_group( array $group ) {
		$parent_slug = $group['parent'] ? $group['parent']->slug : '';
		$data_attrs  = sprintf( ' data-group="%1$s" data-parent="%2$s"', esc_attr( $group['key'] ), esc_attr( $parent_slug ) );

		if ( $group['term'] ) {
			$this->render_series_card( $group, $data_attrs );
		} else {
			?>
			<div class="adx-gr-lib__card adx-gr-lib__card--desc adx-gr-lib__card--other" data-kind="desc"<?php echo $data_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
				<div class="adx-gr-lib__desc">
					<h2 class="adx-gr-lib__desc-title"><?php esc_html_e( 'Other resources', 'adx-gated-resources' ); ?></h2>
				</div>
			</div>
			<?php
		}

		// Up to four family images for the "Download all" card's collage,
		// only from families that belong to this series alone.
		$collage = array();
		foreach ( $group['items'] as $entry ) {
			if ( $entry['multi_series'] ) {
				continue;
			}
			$url = get_the_post_thumbnail_url( $entry['post']->ID, 'medium' );
			if ( $url ) {
				$collage[] = $url;
			}
			if ( count( $collage ) >= 4 ) {
				break;
			}
		}

		foreach ( $group['bundles'] as $entry ) {
			$this->render_bundle_card( $group, $entry, $collage, $data_attrs );
		}

		foreach ( $group['items'] as $entry ) {
			$this->render_item_card( $group, $entry, $data_attrs );
		}
	}

	private function render_series_card( array $group, $data_attrs ) {
		$term   = $group['term'];
		$fields = $group['fields'];
		$logo   = $fields['logo_id'] ? wp_get_attachment_image_url( $fields['logo_id'], 'medium' ) : '';
		?>
		<div class="adx-gr-lib__card adx-gr-lib__card--desc" id="<?php echo esc_attr( $group['key'] ); ?>" data-kind="desc"<?php echo $data_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<div class="adx-gr-lib__desc">
				<?php if ( $logo ) : ?>
					<img class="adx-gr-lib__desc-logo" src="<?php echo esc_url( $logo ); ?>" alt="<?php echo esc_attr( get_post_meta( $fields['logo_id'], '_wp_attachment_image_alt', true ) ); ?>" loading="lazy" />
				<?php endif; ?>
				<h2 class="adx-gr-lib__desc-title"><?php echo esc_html( ADX_GR_Library_Terms::display_name( $term->name ) ); ?></h2>
				<?php if ( '' !== $fields['headline'] ) : ?>
					<p class="adx-gr-lib__desc-sub"><?php echo esc_html( $fields['headline'] ); ?></p>
				<?php endif; ?>
				<?php if ( '' !== trim( $fields['description'] ) ) : ?>
					<p class="adx-gr-lib__desc-text"><?php echo nl2br( esc_html( $fields['description'] ) ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private function render_bundle_card( array $group, array $entry, array $collage, $data_attrs ) {
		$post  = $entry['post'];
		$info  = $entry['info'];
		$label = $group['term'] ? ADX_GR_Library_Terms::display_name( $group['term']->name ) : '';
		$title = $group['term']
			/* translators: %s: series name, e.g. "1000 Series" */
			? sprintf( __( 'Download all %s families', 'adx-gated-resources' ), $label )
			: ADX_GR_Library_Terms::display_name( get_the_title( $post ) );
		$own = get_the_post_thumbnail_url( $post->ID, 'medium_large' );

		$button = '<span class="adx-gr-lib__btn-label">' . $this->zip_icon() . esc_html__( 'Download .zip', 'adx-gated-resources' ) . '</span>' . $this->size_label( $info['bytes'] );
		?>
		<div class="adx-gr-lib__card adx-gr-lib__card--bundle" data-kind="bundle" data-search="<?php echo esc_attr( $this->search_text( $group, $title . ' ' . $post->post_title . ' zip all' ) ); ?>"<?php echo $data_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<?php if ( $own || count( $collage ) < 2 ) : ?>
				<div class="adx-gr-lib__thumb"<?php echo $own || $collage ? ' style="background-image:url(' . esc_url( $own ? $own : $collage[0] ) . ')"' : ''; ?>>
					<?php $this->render_badge( $group ); ?>
					<?php if ( ! $own && ! $collage ) : ?>
						<span class="adx-gr-lib__thumb-icon"><?php $this->render_default_icon(); ?></span>
					<?php endif; ?>
				</div>
			<?php else : ?>
				<div class="adx-gr-lib__thumb adx-gr-lib__thumb--collage">
					<?php $this->render_badge( $group ); ?>
					<?php foreach ( array_slice( array_pad( $collage, 4, $collage[0] ), 0, 4 ) as $url ) : ?>
						<span style="background-image:url(<?php echo esc_url( $url ); ?>)"></span>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
			<div class="adx-gr-lib__body">
				<?php $this->render_meta_line( $post, $info ); ?>
				<h3 class="adx-gr-lib__name"><?php echo esc_html( $title ); ?></h3>
				<?php $file_count = (int) $info['count']; ?>
				<?php if ( $file_count ) : ?>
					<p class="adx-gr-lib__note">
						<?php
						/* translators: %d: number of files in the zip */
						echo esc_html( sprintf( _n( 'Contains %d file', 'Contains %d files', $file_count, 'adx-gated-resources' ), $file_count ) );
						?>
					</p>
				<?php endif; ?>
				<?php echo $this->render_trigger_button( $post->ID, $button, 'adx-gr-lib__btn adx-gr-lib__btn--bundle' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</div>
		<?php
	}

	private function render_item_card( array $group, array $entry, $data_attrs ) {
		$post  = $entry['post'];
		$info  = $entry['info'];
		$code  = $group['code'];
		$title = ADX_GR_Library_Terms::display_name( get_the_title( $post ) );

		// Titles usually start with the series code ("1000 Door Frame...");
		// show that code once, highlighted, rather than twice.
		$name = $title;
		if ( '' !== $code && 0 === stripos( $title, $code . ' ' ) ) {
			$name = ltrim( substr( $title, strlen( $code ) ) );
		}

		$thumb  = get_the_post_thumbnail_url( $post->ID, 'medium_large' );
		$button = '<span class="adx-gr-lib__btn-label">' . $this->download_icon() . esc_html__( 'Download', 'adx-gated-resources' ) . '</span>' . $this->size_label( $info['bytes'] );
		?>
		<div class="adx-gr-lib__card" data-kind="item" data-search="<?php echo esc_attr( $this->search_text( $group, $title ) ); ?>"<?php echo $data_attrs; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
			<div class="adx-gr-lib__thumb"<?php echo $thumb ? ' style="background-image:url(' . esc_url( $thumb ) . ')"' : ''; ?>>
				<?php $this->render_badge( $group ); ?>
				<?php if ( ! $thumb ) : ?>
					<span class="adx-gr-lib__thumb-icon"><?php $this->render_default_icon(); ?></span>
				<?php endif; ?>
			</div>
			<div class="adx-gr-lib__body">
				<?php $this->render_meta_line( $post, $info ); ?>
				<h3 class="adx-gr-lib__name">
					<?php if ( '' !== $code ) : ?>
						<span class="adx-gr-lib__prefix"><?php echo esc_html( $code ); ?></span>
					<?php endif; ?>
					<?php echo esc_html( $name ); ?>
				</h3>
				<?php echo $this->render_trigger_button( $post->ID, $button, 'adx-gr-lib__btn' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</div>
		<?php
	}

	private function render_badge( array $group ) {
		$badge = '' !== $group['code'] ? $group['code'] : ( $group['term'] ? ADX_GR_Library_Terms::display_name( $group['term']->name ) : '' );

		if ( '' !== $badge ) {
			echo '<span class="adx-gr-lib__badge">' . esc_html( $badge ) . '</span>';
		}
	}

	/**
	 * "RFA · Updated Oct 2026"
	 */
	private function render_meta_line( $post, array $info ) {
		$updated = sprintf(
			/* translators: %s: month and year the resource was last updated */
			__( 'Updated %s', 'adx-gated-resources' ),
			wp_date( 'M Y', (int) get_post_modified_time( 'U', true, $post ) )
		);

		echo '<p class="adx-gr-lib__meta">' . esc_html( implode( ' · ', array_filter( array( $info['format'], $updated ) ) ) ) . '</p>';
	}

	/**
	 * " · 40.1 MB" for a download button, or nothing if the size is unknown.
	 */
	private function size_label( $bytes ) {
		if ( null === $bytes || $bytes <= 0 ) {
			return '';
		}

		$mb = $bytes / MB_IN_BYTES;

		if ( $mb >= 1 && $mb < 1024 ) {
			$number = number_format( $mb, $mb >= 100 ? 0 : ( $mb >= 10 ? 1 : 2 ), '.', '' );

			// Drop the trailing zeros of a decimal ("40.10" becomes "40.1"),
			// but never the zeros of a whole number ("280" stays "280").
			if ( false !== strpos( $number, '.' ) ) {
				$number = rtrim( rtrim( $number, '0' ), '.' );
			}
		}

		$text = $mb >= 1024
			? number_format_i18n( $mb / 1024, 1 ) . ' GB'
			: ( $mb >= 1 ? $number . ' MB' : max( 1, round( $bytes / KB_IN_BYTES ) ) . ' KB' );

		return '<span class="adx-gr-lib__size">· ' . esc_html( $text ) . '</span>';
	}

	private function search_text( array $group, $text ) {
		$parts = array( $text, $group['code'] );
		if ( $group['term'] ) {
			$parts[] = $group['term']->name;
		}
		if ( $group['parent'] ) {
			$parts[] = $group['parent']->name;
		}

		return strtolower( trim( preg_replace( '/\s+/', ' ', implode( ' ', array_filter( $parts ) ) ) ) );
	}

	private function download_icon() {
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/><path d="M7 11l5 5 5-5"/><path d="M12 4v12"/></svg>';
	}

	private function zip_icon() {
		return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M10 6h2M10 9.5h2M10 13h2"/><rect x="9.5" y="16" width="3" height="3.2" rx="0.6"/></svg>';
	}

	private function render_default_icon() {
		?>
		<svg viewBox="0 0 48 48" width="32" height="32" fill="none" xmlns="http://www.w3.org/2000/svg">
			<rect x="6" y="10" width="36" height="26" rx="2" stroke="currentColor" stroke-width="2" />
			<line x1="6" y1="19" x2="42" y2="19" stroke="currentColor" stroke-width="2" />
			<line x1="6" y1="27" x2="42" y2="27" stroke="currentColor" stroke-width="2" />
			<line x1="18" y1="10" x2="18" y2="36" stroke="currentColor" stroke-width="2" />
			<line x1="30" y1="10" x2="30" y2="36" stroke="currentColor" stroke-width="2" />
		</svg>
		<?php
	}

	/**
	 * Rewrites any <a href="..."> in post content that matches a gated
	 * resource's file URL (auto-gate enabled) into a gate trigger,
	 * so editors don't have to touch existing links or use a shortcode.
	 */
	public function auto_gate_content( $content ) {
		$map = ADX_GR_CPT::get_auto_gate_url_map();

		if ( empty( $map ) || false === strpos( $content, '<a ' ) ) {
			return $content;
		}

		$pattern = '/<a\s+([^>]*?)href=["\']([^"\']+)["\']([^>]*)>(.*?)<\/a>/is';

		$replaced = preg_replace_callback(
			$pattern,
			function ( $matches ) use ( $map ) {
				$href = html_entity_decode( $matches[2] );
				$key  = untrailingslashit( $href );

				if ( ! isset( $map[ $key ] ) ) {
					return $matches[0];
				}

				$resource_id = $map[ $key ];
				$this->enqueue_assets();

				return $this->render_trigger_button( $resource_id, $matches[4] );
			},
			$content
		);

		return null === $replaced ? $content : $replaced;
	}

	private function render_trigger_button( $resource_id, $label, $extra_class = '' ) {
		$class = trim( 'adx-gr-trigger ' . $extra_class );
		return sprintf(
			'<a href="#" class="%1$s" data-adx-gr-resource="%2$d">%3$s</a>',
			esc_attr( $class ),
			$resource_id,
			$label
		);
	}

	private function render_select_options( array $options ) {
		foreach ( $options as $option ) {
			printf( '<option value="%1$s">%2$s</option>', esc_attr( $option ), esc_html( $option ) );
		}
	}

	/**
	 * Renders one configured gate-form field (see ADX_GR_Form_Fields) as
	 * markup matching the modal's existing CSS classes.
	 */
	private function render_field( array $field ) {
		$id       = 'adx-gr-field-' . $field['key'];
		$required = $field['required'];
		?>
		<div class="adx-gr-modal__field">
			<?php if ( 'checkbox' !== $field['type'] ) : ?>
				<label class="adx-gr-modal__label" for="<?php echo esc_attr( $id ); ?>">
					<?php echo esc_html( $field['label'] ); ?>
					<?php if ( $required ) : ?>
						<span class="adx-gr-modal__required">*</span>
					<?php else : ?>
						<span class="adx-gr-modal__optional"><?php esc_html_e( '(optional)', 'adx-gated-resources' ); ?></span>
					<?php endif; ?>
				</label>
			<?php endif; ?>

			<?php if ( 'textarea' === $field['type'] ) : ?>
				<textarea id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $field['key'] ); ?>" class="adx-gr-modal__input" rows="3" <?php echo $required ? 'required' : ''; ?>></textarea>
			<?php elseif ( 'select' === $field['type'] ) : ?>
				<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $field['key'] ); ?>" class="adx-gr-modal__input adx-gr-modal__select" <?php echo $required ? 'required' : ''; ?>>
					<option value="" disabled selected><?php esc_html_e( 'Select…', 'adx-gated-resources' ); ?></option>
					<?php $this->render_select_options( $field['options'] ); ?>
				</select>
			<?php elseif ( 'checkbox' === $field['type'] ) : ?>
				<label class="adx-gr-modal__checkbox-label">
					<input type="checkbox" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $field['key'] ); ?>" value="1" <?php echo $required ? 'required' : ''; ?> />
					<?php echo esc_html( $field['label'] ); ?>
					<?php if ( $required ) : ?><span class="adx-gr-modal__required">*</span><?php endif; ?>
				</label>
			<?php else : ?>
				<input type="text" id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $field['key'] ); ?>" class="adx-gr-modal__input" <?php echo $required ? 'required' : ''; ?> />
			<?php endif; ?>
		</div>
		<?php
	}

	public function maybe_print_modal() {
		if ( ! $this->needs_assets ) {
			return;
		}

		$heading     = get_option( 'adx_gr_modal_heading', __( 'Access the Revit Library', 'adx-gated-resources' ) );
		$description = get_option( 'adx_gr_modal_description', __( 'Enter your email to download this file. Our team may follow up to help with your project.', 'adx-gated-resources' ) );
		?>
		<div id="adx-gr-modal" class="adx-gr-modal" hidden aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="adx-gr-modal-heading">
			<div class="adx-gr-modal__overlay" data-adx-gr-close></div>
			<div class="adx-gr-modal__panel">
				<button type="button" class="adx-gr-modal__close" data-adx-gr-close aria-label="<?php esc_attr_e( 'Close', 'adx-gated-resources' ); ?>">&times;</button>
				<h2 id="adx-gr-modal-heading" class="adx-gr-modal__heading"><?php echo esc_html( $heading ); ?></h2>
				<p class="adx-gr-modal__description"><?php echo esc_html( $description ); ?></p>
				<form class="adx-gr-modal__form" novalidate>
					<div class="adx-gr-modal__field">
						<label class="adx-gr-modal__label" for="adx-gr-email"><?php esc_html_e( 'Email address', 'adx-gated-resources' ); ?> <span class="adx-gr-modal__required">*</span></label>
						<input type="email" id="adx-gr-email" name="email" required class="adx-gr-modal__input" autocomplete="email" />
					</div>

					<?php foreach ( ADX_GR_Form_Fields::get_fields() as $field ) : ?>
						<?php $this->render_field( $field ); ?>
					<?php endforeach; ?>

					<div class="adx-gr-modal__field adx-gr-modal__consent">
						<label class="adx-gr-modal__checkbox-label">
							<input type="checkbox" id="adx-gr-privacy-consent" name="privacy_consent" value="1" required />
							<span><?php echo wp_kses( ADX_GR_Form_Fields::get_privacy_label_html(), array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) ); ?> <span class="adx-gr-modal__required">*</span></span>
						</label>
					</div>

					<p class="adx-gr-modal__error" role="alert" hidden></p>

					<button type="submit" class="adx-gr-modal__submit">
						<span class="adx-gr-modal__submit-text"><?php esc_html_e( 'Get the file', 'adx-gated-resources' ); ?></span>
						<span class="adx-gr-modal__submit-arrow" aria-hidden="true">&rarr;</span>
					</button>
					<p class="adx-gr-modal__note" data-default-text="<?php esc_attr_e( 'Your download will start once verified.', 'adx-gated-resources' ); ?>"><?php esc_html_e( 'Your download will start once verified.', 'adx-gated-resources' ); ?></p>
				</form>
			</div>
		</div>
		<?php
	}
}
