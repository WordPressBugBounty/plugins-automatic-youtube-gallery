<?php

/**
 * Plugin Dashboard.
 *
 * @link    https://plugins360.com
 * @since   1.3.0
 *
 * @package Automatic_YouTube_Gallery
 */

// Exit if accessed directly
if ( ! defined( 'WPINC' ) ) {
	die;
}

$general_settings = ayg_get_option( 'ayg_general_settings' );

$file = 'api-key';

if ( ! empty( $general_settings['api_key'] ) ) {
	$action = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : '';

	if ( 'new' === $action || 'edit' === $action ) {
		$file = 'gallery-form';
	} else {
		$file = 'gallery-list';
	}
}
?>

<div id="ayg-dashboard" class="ayg ayg-dashboard clear">
	<?php if ( 'gallery-form' !== $file ) : ?>
		<div class="wrap about-wrap">
			<h1><?php esc_html_e( 'Automatic YouTube Gallery', 'automatic-youtube-gallery' ); ?></h1>

			<p class="about-text">
				<?php esc_html_e( 'Build beautiful, responsive YouTube galleries from a Channel, Playlist, Username, Search, Livestream, Single Video, or a custom list of videos — then keep them automatically up to date with scheduled imports.', 'automatic-youtube-gallery' ); ?>
			</p>

			<div class="wp-badge"><?php printf( esc_html__( 'Version %s', 'automatic-youtube-gallery' ), AYG_VERSION ); ?></div>

			<?php if ( 'gallery-list' === $file ) : ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=automatic-youtube-gallery&action=new' ) ); ?>" class="ayg-button button button-primary button-hero">
					<svg xmlns="http://www.w3.org/2000/svg" fill="none" width="20" height="20" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
						<path stroke-linecap="round" stroke-linejoin="round" d="M13.5 16.875h3.375m0 0h3.375m-3.375 0V13.5m0 3.375v3.375M6 10.5h2.25a2.25 2.25 0 0 0 2.25-2.25V6a2.25 2.25 0 0 0-2.25-2.25H6A2.25 2.25 0 0 0 3.75 6v2.25A2.25 2.25 0 0 0 6 10.5Zm0 9.75h2.25A2.25 2.25 0 0 0 10.5 18v-2.25a2.25 2.25 0 0 0-2.25-2.25H6a2.25 2.25 0 0 0-2.25 2.25V18A2.25 2.25 0 0 0 6 20.25Zm9.75-9.75H18a2.25 2.25 0 0 0 2.25-2.25V6A2.25 2.25 0 0 0 18 3.75h-2.25A2.25 2.25 0 0 0 13.5 6v2.25a2.25 2.25 0 0 0 2.25 2.25Z" />
					</svg>
					<?php esc_html_e( 'Add New Gallery', 'automatic-youtube-gallery' ); ?>
				</a>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php require_once AYG_DIR . "admin/templates/{$file}.php"; ?>
</div>
