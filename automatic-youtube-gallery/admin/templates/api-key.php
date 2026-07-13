<?php

/**
 * Dashboard: Api Key.
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
?>

<div class="ayg-form ayg-card">
	<div class="ayg-form-controls">
		<h2 class="ayg-no-margin">
			<?php esc_html_e( 'Connect your YouTube API key', 'automatic-youtube-gallery' ); ?> 
			<span class="ayg-form-required" aria-hidden="true">*</span>
		</h2>

		<div class="ayg-form-control">
			<input type="text" id="ayg-form-field-api-key" class="ayg-form-field ayg-form-field-api-key widefat" value="" placeholder="<?php esc_attr_e( 'Enter your YouTube API key', 'automatic-youtube-gallery' ); ?>" aria-label="<?php esc_attr_e( 'YouTube API Key', 'automatic-youtube-gallery' ); ?>" autofocus>

			<span class="ayg-form-status"></span>

			<p class="description">
				<?php
				printf(
					__( 'A free YouTube Data API key is required to fetch your videos. <a href="%s" target="_blank" rel="noopener noreferrer">Get your API key</a> in just a few minutes.', 'automatic-youtube-gallery' ),
					'https://plugins360.com/automatic-youtube-gallery/how-to-get-youtube-api-key/'
				);
				?>
			</p>
		</div>

		<div class="ayg-form-actions">
			<button type="button" id="ayg-button-save-api-key" class="ayg-button button button-primary button-hero">
				<?php esc_html_e( 'Proceed', 'automatic-youtube-gallery' ); ?>
			</button>
			<span class="ayg-form-status"></span>
		</div>
	</div>
</div>
