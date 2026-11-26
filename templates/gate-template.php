<?php
/**
 * Default locked-page view for a gated Page. A theme can override this
 * by copying it to {theme}/adx-gated-resources/gate-template.php.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$post_id = get_queried_object_id();
$teaser  = get_post_meta( $post_id, ADX_GR_Page_Gate::META_TEASER, true );

ADX_GR_Frontend::instance()->enqueue_assets();

// Use the active theme's own header.php/footer.php (nav, logo, footer
// widgets, etc.) so the lock screen looks like part of the site rather
// than a bare page -- only the content in between is swapped out for
// the gate, the same way a normal page template would work.
get_header();
?>
<div class="adx-gr-gate-screen">
	<h1><?php echo esc_html( get_the_title( $post_id ) ); ?></h1>
	<?php if ( $teaser ) : ?>
		<div class="adx-gr-gate-screen__teaser"><?php echo wp_kses_post( wpautop( $teaser ) ); ?></div>
	<?php endif; ?>
	<button type="button" class="adx-gr-trigger" data-adx-gr-context="page" data-adx-gr-page="<?php echo (int) $post_id; ?>" data-adx-gr-autoopen>
		<?php esc_html_e( 'Unlock this page', 'adx-gated-resources' ); ?>
	</button>
</div>
<?php
get_footer();
