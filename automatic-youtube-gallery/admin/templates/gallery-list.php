<?php

/**
 * Gallery List.
 *
 * @link    https://plugins360.com
 * @since   2.8.0
 *
 * @package Automatic_YouTube_Gallery
 */

// Exit if accessed directly
if ( ! defined( 'WPINC' ) ) {
	die;
}

require_once AYG_DIR . 'admin/galleries.php';

$table = $this->gallery_list_table;
$table->prepare_items();
?>

<div id="ayg-gallery-list" class="ayg-gallery-list">
	<?php if ( ! empty( $_GET['deleted'] ) ) : ?>
		<p class="ayg-notice ayg-notice-success">
			<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
			<?php
			$count = absint( $_GET['deleted'] );

			printf(
				/* translators: %d: number of deleted galleries */
				esc_html( _n( '%d gallery deleted.', '%d galleries deleted.', $count, 'automatic-youtube-gallery' ) ),
				$count
			);
			?>
		</p>
	<?php endif; ?>

	<?php if ( ! empty( $table->items ) ) : ?>
		<p class="ayg-notice ayg-notice-info">
			<span class="dashicons dashicons-update" aria-hidden="true"></span>
			<?php esc_html_e( 'Your galleries stay up to date automatically on a schedule. If a gallery ever looks out of date, open it and click "Update Gallery" to pull the latest videos right away.', 'automatic-youtube-gallery' ); ?>
		</p>
	<?php endif; ?>

	<form class="ayg-form" method="get">
		<input type="hidden" name="page" value="automatic-youtube-gallery">
		<?php
		$table->search_box( __( 'Search Galleries', 'automatic-youtube-gallery' ), 'ayg-gallery' );
		$table->display();
		?>
	</form>
</div>
