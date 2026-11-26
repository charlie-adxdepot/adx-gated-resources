<?php
/**
 * Theme functions and definitions
 *
 * @package HelloElementor
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'HELLO_ELEMENTOR_VERSION', '3.1.1' );

if ( ! isset( $content_width ) ) {
	$content_width = 800; // Pixels.
}

if ( ! function_exists( 'hello_elementor_setup' ) ) {
	/**
	 * Set up theme support.
	 *
	 * @return void
	 */
	function hello_elementor_setup() {
		if ( is_admin() ) {
			hello_maybe_update_theme_version_in_db();
		}

		if ( apply_filters( 'hello_elementor_register_menus', true ) ) {
			register_nav_menus( [ 'menu-1' => esc_html__( 'Header', 'hello-elementor' ) ] );
			register_nav_menus( [ 'menu-2' => esc_html__( 'Footer', 'hello-elementor' ) ] );
		}

		if ( apply_filters( 'hello_elementor_post_type_support', true ) ) {
			add_post_type_support( 'page', 'excerpt' );
		}

		if ( apply_filters( 'hello_elementor_add_theme_support', true ) ) {
			add_theme_support( 'post-thumbnails' );
			add_theme_support( 'automatic-feed-links' );
			add_theme_support( 'title-tag' );
			add_theme_support(
				'html5',
				[
					'search-form',
					'comment-form',
					'comment-list',
					'gallery',
					'caption',
					'script',
					'style',
				]
			);
			add_theme_support(
				'custom-logo',
				[
					'height'      => 100,
					'width'       => 350,
					'flex-height' => true,
					'flex-width'  => true,
				]
			);

			/*
			 * Editor Style.
			 */
			add_editor_style( 'classic-editor.css' );

			/*
			 * Gutenberg wide images.
			 */
			add_theme_support( 'align-wide' );

			/*
			 * WooCommerce.
			 */
			if ( apply_filters( 'hello_elementor_add_woocommerce_support', true ) ) {
				// WooCommerce in general.
				add_theme_support( 'woocommerce' );
				// Enabling WooCommerce product gallery features (are off by default since WC 3.0.0).
				// zoom.
				add_theme_support( 'wc-product-gallery-zoom' );
				// lightbox.
				add_theme_support( 'wc-product-gallery-lightbox' );
				// swipe.
				add_theme_support( 'wc-product-gallery-slider' );
			}
		}
	}
}
add_action( 'after_setup_theme', 'hello_elementor_setup' );

function hello_maybe_update_theme_version_in_db() {
	$theme_version_option_name = 'hello_theme_version';
	// The theme version saved in the database.
	$hello_theme_db_version = get_option( $theme_version_option_name );

	// If the 'hello_theme_version' option does not exist in the DB, or the version needs to be updated, do the update.
	if ( ! $hello_theme_db_version || version_compare( $hello_theme_db_version, HELLO_ELEMENTOR_VERSION, '<' ) ) {
		update_option( $theme_version_option_name, HELLO_ELEMENTOR_VERSION );
	}
}

if ( ! function_exists( 'hello_elementor_display_header_footer' ) ) {
	/**
	 * Check whether to display header footer.
	 *
	 * @return bool
	 */
	function hello_elementor_display_header_footer() {
		$hello_elementor_header_footer = true;

		return apply_filters( 'hello_elementor_header_footer', $hello_elementor_header_footer );
	}
}

if ( ! function_exists( 'hello_elementor_scripts_styles' ) ) {
	/**
	 * Theme Scripts & Styles.
	 *
	 * @return void
	 */
	function hello_elementor_scripts_styles() {
		$min_suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		if ( apply_filters( 'hello_elementor_enqueue_style', true ) ) {
			wp_enqueue_style(
				'hello-elementor',
				get_template_directory_uri() . '/style' . $min_suffix . '.css',
				[],
				HELLO_ELEMENTOR_VERSION
			);
		}

		if ( apply_filters( 'hello_elementor_enqueue_theme_style', true ) ) {
			wp_enqueue_style(
				'hello-elementor-theme-style',
				get_template_directory_uri() . '/theme' . $min_suffix . '.css',
				[],
				HELLO_ELEMENTOR_VERSION
			);
		}

		if ( hello_elementor_display_header_footer() ) {
			wp_enqueue_style(
				'hello-elementor-header-footer',
				get_template_directory_uri() . '/header-footer' . $min_suffix . '.css',
				[],
				HELLO_ELEMENTOR_VERSION
			);
		}
	}
}
add_action( 'wp_enqueue_scripts', 'hello_elementor_scripts_styles' );

if ( ! function_exists( 'hello_elementor_register_elementor_locations' ) ) {
	/**
	 * Register Elementor Locations.
	 *
	 * @param ElementorPro\Modules\ThemeBuilder\Classes\Locations_Manager $elementor_theme_manager theme manager.
	 *
	 * @return void
	 */
	function hello_elementor_register_elementor_locations( $elementor_theme_manager ) {
		if ( apply_filters( 'hello_elementor_register_elementor_locations', true ) ) {
			$elementor_theme_manager->register_all_core_location();
		}
	}
}
add_action( 'elementor/theme/register_locations', 'hello_elementor_register_elementor_locations' );

if ( ! function_exists( 'hello_elementor_content_width' ) ) {
	/**
	 * Set default content width.
	 *
	 * @return void
	 */
	function hello_elementor_content_width() {
		$GLOBALS['content_width'] = apply_filters( 'hello_elementor_content_width', 800 );
	}
}
add_action( 'after_setup_theme', 'hello_elementor_content_width', 0 );

if ( ! function_exists( 'hello_elementor_add_description_meta_tag' ) ) {
	/**
	 * Add description meta tag with excerpt text.
	 *
	 * @return void
	 */
	function hello_elementor_add_description_meta_tag() {
		if ( ! apply_filters( 'hello_elementor_description_meta_tag', true ) ) {
			return;
		}

		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();
		if ( empty( $post->post_excerpt ) ) {
			return;
		}

		echo '<meta name="description" content="' . esc_attr( wp_strip_all_tags( $post->post_excerpt ) ) . '">' . "\n";
	}
}
add_action( 'wp_head', 'hello_elementor_add_description_meta_tag' );

// Admin notice
if ( is_admin() ) {
	require get_template_directory() . '/includes/admin-functions.php';
}

// Settings page
require get_template_directory() . '/includes/settings-functions.php';

// Header & footer styling option, inside Elementor
require get_template_directory() . '/includes/elementor-functions.php';

if ( ! function_exists( 'hello_elementor_customizer' ) ) {
	// Customizer controls
	function hello_elementor_customizer() {
		if ( ! is_customize_preview() ) {
			return;
		}

		if ( ! hello_elementor_display_header_footer() ) {
			return;
		}

		require get_template_directory() . '/includes/customizer-functions.php';
	}
}
add_action( 'init', 'hello_elementor_customizer' );

if ( ! function_exists( 'hello_elementor_check_hide_title' ) ) {
	/**
	 * Check whether to display the page title.
	 *
	 * @param bool $val default value.
	 *
	 * @return bool
	 */
	function hello_elementor_check_hide_title( $val ) {
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			$current_doc = Elementor\Plugin::instance()->documents->get( get_the_ID() );
			if ( $current_doc && 'yes' === $current_doc->get_settings( 'hide_title' ) ) {
				$val = false;
			}
		}
		return $val;
	}
}
add_filter( 'hello_elementor_page_title', 'hello_elementor_check_hide_title' );

/**
 * BC:
 * In v2.7.0 the theme removed the `hello_elementor_body_open()` from `header.php` replacing it with `wp_body_open()`.
 * The following code prevents fatal errors in child themes that still use this function.
 */
if ( ! function_exists( 'hello_elementor_body_open' ) ) {
	function hello_elementor_body_open() {
		wp_body_open();
	}
}

// Register the [costcalculator_custom] shortcode for single product pages.
function costcalculator_custom_shortcode( $atts = [], $content = null ) {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) {
		return '';
	}

	global $product;

	if ( ! $product instanceof WC_Product ) {
		$product = wc_get_product( get_the_ID() );
	}

	if ( ! $product ) {
		return '';
	}

	$categories = wp_get_post_terms( $product->get_id(), 'product_cat', [ 'fields' => 'slugs' ] );

	if ( is_wp_error( $categories ) || empty( $categories ) ) {
		return '';
	}

	$calculator_rules = [
		[
			'id'      => 17608,
			'include' => [
				'timber-flooring',
				'particle-board-f',
				'plywood-f',
				'underlay-f',
				'fibre-cement-f',
			],
			'exclude' => [
				'flooring-accessories',
			],
		],
		[
			'id'      => 1775,
			'include' => [
				'timber-decking',
				'composite-decking',
				'fibre-cement-decking',
			],
			'exclude' => [
				'decking-hardware',
			],
		],
		[
			'id'      => 1789,
			'include' => [
				'timber',
				'weathertex',
				'composite-cladding',
				'fibre-cement-cl',
				'weatherboard-cl',
			],
			'exclude' => [
				'cladding-lining-accessories',
			],
		],
		[
			'id'      => 17607,
			'include' => [
				'screening',
			],
			'exclude' => [],
		],
	];

	foreach ( $calculator_rules as $rule ) {
		$has_included_category = ! empty( array_intersect( $rule['include'], $categories ) );
		$has_excluded_category = ! empty( array_intersect( $rule['exclude'], $categories ) );

		if ( $has_included_category && ! $has_excluded_category ) {
			return do_shortcode( sprintf( '[stm-calc id="%d"]', absint( $rule['id'] ) ) );
		}
	}

	return '';
}
add_shortcode( 'costcalculator_custom', 'costcalculator_custom_shortcode' );


/**
 * Checkout notice: show once, UNDER the order review table (before payment methods).
 */
add_action( 'woocommerce_review_order_before_payment', 'bt_checkout_returns_notice_under_table', 10 );
function bt_checkout_returns_notice_under_table() {
    // Only on checkout (not thank you page)
    if ( ! is_checkout() || is_order_received_page() ) {
        return;
    }

    // Prevent duplicates if the hook runs twice due to theme/layout/AJAX fragments
    static $printed = false;
    if ( $printed ) {
        return;
    }
    $printed = true;

    $notice = '<p>Due to the nature of the products we supply, no returns or credits are accepted once goods are collected or delivered, except where items are faulty or supplied incorrectly*.</p>';
	$notice_italize = '<p><i>*Returns may be accepted only in limited circumstances for purchased stock items (excluding special or manufactured orders) within 14 days of purchase, provided goods are in original condition. All returns are subject to management approval and may incur pickup, restocking, and handling fees.</i></p>';
	echo '<div class="bt-checkout-returns-notice" role="alert">' . $notice . ' ' . $notice_italize . '</div>';
}

/**
 * Styles for the red notice box (checkout only).
 */
add_action( 'wp_enqueue_scripts', 'bt_checkout_returns_notice_styles' );
function bt_checkout_returns_notice_styles() {
    if ( ! is_checkout() || is_order_received_page() ) {
        return;
    }

    $css = '
    .bt-checkout-returns-notice{
        margin: 12px 0 16px;
        padding: 14px 16px;
        border: 1px solid rgba(185,28,28,.35);
        border-left: 6px solid #b91c1c;
        background: rgba(220,38,38,.10);
        color: #7f1d1d;
        border-radius: 8px;
        font-weight: 600;
        line-height: 1.5;
    }';

    // Add inline CSS safely without depending on theme files
    wp_register_style( 'bt-checkout-returns-notice', false );
    wp_enqueue_style( 'bt-checkout-returns-notice' );
    wp_add_inline_style( 'bt-checkout-returns-notice', $css );
}

/**
 * Cart notice: show once UNDER the cart items table (before cart totals).
 */
add_action( 'woocommerce_after_cart_table', 'bt_cart_returns_notice_under_items', 10 );
function bt_cart_returns_notice_under_items() {
	// Only on cart page
	if ( ! function_exists( 'is_cart' ) || ! is_cart() ) {
		return;
	}

	// Prevent duplicates (some themes render parts twice)
	static $printed = false;
	if ( $printed ) {
		return;
	}
	$printed = true;

	//$notice = 'Please note: Due to the nature of the products Bone Timber supplies, once goods have been collected or delivered, returns or credits are not accepted except where items are faulty or supplied incorrectly, so please choose carefully.';
	$notice = '<p>Due to the nature of the products we supply, no returns or credits are accepted once goods are collected or delivered, except where items are faulty or supplied incorrectly*.</p>';
	$notice_italize = '<p><i>*Returns may be accepted only in limited circumstances for purchased stock items (excluding special or manufactured orders) within 14 days of purchase, provided goods are in original condition. All returns are subject to management approval and may incur pickup, restocking, and handling fees.</i></p>';
	echo '<div class="bt-checkout-returns-notice" role="alert">' . $notice . ' ' . $notice_italize . '</div>';
}

/**
 * Ensure the same red-box styling also loads on cart (and checkout).
 * If you already have this function from earlier, just REPLACE it with this version.
 */
add_action( 'wp_enqueue_scripts', 'bt_returns_notice_styles_cart_and_checkout' );
function bt_returns_notice_styles_cart_and_checkout() {
	if ( ( function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page() ) || ( function_exists( 'is_cart' ) && is_cart() ) ) {
		$css = '
		.bt-checkout-returns-notice{
			margin: 12px 0 16px;
			padding: 14px 16px;
			border: 1px solid rgba(185,28,28,.35);
			border-left: 6px solid #b91c1c;
			background: rgba(220,38,38,.10);
			color: #7f1d1d;
			border-radius: 8px;
			font-weight: 600;
			line-height: 1.5;
		}';

		wp_register_style( 'bt-returns-notice', false );
		wp_enqueue_style( 'bt-returns-notice' );
		wp_add_inline_style( 'bt-returns-notice', $css );
	}
}


add_filter('style_loader_tag', function ($html, $handle, $href, $media) {
    // Only target CSS files that may contain @font-face declarations.
    $target_handles = [
        'elementor-icons',
        'font-awesome',
        'fontawesome',
        'font-awesome-5-all',
        'font-awesome-4-shim',
        'jet-woo-builder',
        'jet-elements',
        'jet-blocks',
    ];

    if (in_array($handle, $target_handles, true)) {
        $html = str_replace(
            "rel='stylesheet'",
            "rel='stylesheet' data-font-display='swap'",
            $html
        );
    }

    return $html;
}, 10, 4);

/**
 * Add font-display: swap for plugin/custom fonts not covered by Elementor settings.
 *
 * Place this in your child theme's functions.php.
 */
add_action('wp_head', function () {
    ?>
    <style id="bonetimber-font-display-swap">
        /**
         * Elementor Icons
         */
        @font-face {
            font-family: "eicons";
            src: url("https://bonetimber.com.au/wp-content/plugins/elementor/assets/lib/eicons/fonts/eicons.woff2?5.49.0") format("woff2");
            font-weight: normal;
            font-style: normal;
            font-display: swap;
        }

        /**
         * Font Awesome Solid
         */
        @font-face {
            font-family: "Font Awesome 5 Free";
            src: url("https://bonetimber.com.au/wp-content/plugins/elementor/assets/lib/font-awesome/webfonts/fa-solid-900.woff2") format("woff2");
            font-weight: 900;
            font-style: normal;
            font-display: swap;
        }

        /**
         * Font Awesome Brands
         */
        @font-face {
            font-family: "Font Awesome 5 Brands";
            src: url("https://bonetimber.com.au/wp-content/plugins/elementor/assets/lib/font-awesome/webfonts/fa-brands-400.woff2") format("woff2");
            font-weight: 400;
            font-style: normal;
            font-display: swap;
        }

        /**
         * JetWooBuilder Frontend Font
         */
        @font-face {
            font-family: "jetwoobuilder-frontend-font";
            src: url("https://bonetimber.com.au/wp-content/plugins/jet-woo-builder/assets/css/lib/jetwoobuilder-frontend-font/fonts/jetwoobuilder-frontend-font.ttf?p4gf2") format("truetype");
            font-weight: normal;
            font-style: normal;
            font-display: swap;
        }

        /**
         * Monument Extended Regular
         */
        @font-face {
            font-family: "Monument Extended";
            src: url("https://bonetimber.com.au/wp-content/uploads/MonumentExtended-Regular.woff2") format("woff2");
            font-weight: 400;
            font-style: normal;
            font-display: swap;
        }

        /**
         * Monument Extended Ultrabold
         */
        @font-face {
            font-family: "Monument Extended";
            src: url("https://bonetimber.com.au/wp-content/uploads/MonumentExtended-Ultrabold.woff2") format("woff2");
            font-weight: 800;
            font-style: normal;
            font-display: swap;
        }

		@font-face {
            font-family: "MonumentExtended-Regular";
            src: url("https://bonetimber.com.au/wp-content/uploads/MonumentExtended-Regular.woff2") format("woff2");
            font-weight: 400;
            font-style: normal;
            font-display: swap;
        }

        @font-face {
            font-family: "MonumentExtended-Ultrabold";
            src: url("https://bonetimber.com.au/wp-content/uploads/MonumentExtended-Ultrabold.woff2") format("woff2");
            font-weight: 800;
            font-style: normal;
            font-display: swap;
        }
    </style>
    <?php
}, 99);

// make Inspire collection banner clickable from the home page
add_action('wp_footer', function () {
    if (!is_front_page()) {
        return;
    }
    ?>
    <script>
    document.querySelectorAll('.clickable-container').forEach(container => {
        container.addEventListener('click', function () {
            window.location.href = this.dataset.url;
        });
    });
    </script>
    <?php
});

/**
 * Sticky site notices: scheduled maintenance + OX promo
 * Add to your child theme's functions.php
 */
function adx_stocktake_notice() {

	// Maintenance window (Adelaide time). Change these if the schedule moves.
	$tz               = new DateTimeZone( 'Australia/Adelaide' );
	$maintenance_end  = new DateTime( '2026-10-20 19:30', $tz ); // 7:30pm ACDT tonight
	$show_maintenance = new DateTime( 'now', $tz ) < $maintenance_end; // hides itself once the window has passed
	?>
	<style>
		#adx-site-notices {
			position: sticky;
			top: 0;
			z-index: 9999;
			width: 100%;
			font-family: inherit;
		}
		#adx-maintenance-notice {
			background: #1f1f1f;
			color: #fff;
			border-bottom: 3px solid #ffba59;
			font-size: 16px;
			line-height: 1.6;
			text-align: center;
			padding: 10px 40px;
			box-sizing: border-box;
		}
		#adx-maintenance-notice a {
			color: #ffba59;
			text-decoration: underline;
			white-space: nowrap;
		}
		@media (max-width: 767px) {
			#adx-maintenance-notice { font-size: 14px; padding: 8px 16px; }
			#adx-stocktake-notice { font-size: 15px !important; padding: 10px 16px !important; }
		}
	</style>

	<div id="adx-site-notices">

		<?php if ( $show_maintenance ) : ?>
			<!--<div id="adx-maintenance-notice" role="status">
				<strong>Scheduled maintenance tonight, 7:00–7:30pm ACDT.</strong>
				Our website may be briefly unavailable during this time. <br/>For urgent orders or enquiries, call
				<a href="tel:+61882978277">(08) 8297 8277</a> or email
				<a href="mailto:sales@bonetimber.com.au">sales@bonetimber.com.au</a>.
			</div>-->
		<?php endif; ?>

		<div id="adx-stocktake-notice" style="
			width: 100%;
			background: #fff3cd;
			color: #664d03;
			border-bottom: 1px solid #ffe69c;
			font-family: inherit;
			font-size: 18px;
			line-height: 2.5;
			text-align: center;
			padding: 12px 40px;
			box-sizing: border-box;
		">
			<strong>LIMITED TIME OFFER: Warrior Fibre Cement Flooring — $90 per sheet + GST 
			<a href="https://bonetimber.com.au/shop/fibre-cement-warrior-flooring-2700x600x19mm/" style="padding: 10px;background-color: #ffba59;color: #000;border: 1px solid #d48d29;border-radius: 7px;">Shop now →</a></strong>
		</div>

	</div>
	<?php
}
add_action( 'wp_body_open', 'adx_stocktake_notice' );

// Fallback for themes that don't call wp_body_open()
add_action( 'wp_footer', function() {
	if ( ! did_action( 'wp_body_open' ) ) {
		adx_stocktake_notice();
	}
} );

/**
 * Blog loop cards: make the featured image background fill the card.
 * Elementor isn't outputting the Cover / Centre Centre settings for
 * the .blog-featured-id container, so we force them here.
 */
add_action( 'wp_head', function () {
	?>
	<style id="blog-featured-image-fix">
		.blog-featured-id {
			background-size: cover !important;
			background-position: center center !important;
			background-repeat: no-repeat !important;
		}
	</style>
	<?php
}, 100 );



