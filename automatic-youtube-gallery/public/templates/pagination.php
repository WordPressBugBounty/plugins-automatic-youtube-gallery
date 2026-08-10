<?php

/**
 * Pagination
 *
 * @link    https://plugins360.com
 * @since   1.0.0
 *
 * @package Automatic_YouTube_Gallery
 */

// Build attributes		
$source_type           = sanitize_text_field( $attributes['type'] );
$more_button_label     = ! empty( $attributes['more_button_label'] ) ? $attributes['more_button_label'] : __( 'Load More', 'automatic-youtube-gallery' );
$previous_button_label = ! empty( $attributes['previous_button_label'] ) ? $attributes['previous_button_label'] : __( 'Previous', 'automatic-youtube-gallery' );
$next_button_label     = ! empty( $attributes['next_button_label'] ) ? $attributes['next_button_label'] : __( 'Next', 'automatic-youtube-gallery' );

$params = array(
    'uid'                  => sanitize_text_field( $attributes['uid'] ),
    'post_id'              => (int) $attributes['post_id'],
    'type'                 => $source_type,
    'src'                  => sanitize_text_field( $attributes[ $source_type ] ),
    'featured_video_id'    => ! empty( $attributes['featured_video_id'] ) ? sanitize_text_field( $attributes['featured_video_id'] ) : '', // Works only when type=db (deeplinked video pinned first)
    'order'                => sanitize_text_field( $attributes['order'] ),                                                                // Works only when type=search
    'sort_by'              => ! empty( $attributes['sort_by'] ) ? sanitize_key( $attributes['sort_by'] ) : 'date',                        // Works only when type=db
    'sort_order'           => ! empty( $attributes['sort_order'] ) ? sanitize_key( $attributes['sort_order'] ) : 'desc',                  // Works only when type=db
    'sort_seed'            => ! empty( $attributes['sort_seed'] ) ? (int) $attributes['sort_seed'] : 0,                                   // Works only when type=db + sort_by=random
    'duration_filter'      => ! empty( $attributes['duration_filter'] ) ? sanitize_key( $attributes['duration_filter'] ) : '',            // Works only when type=db
    'duration'             => ! empty( $attributes['duration'] ) ? (int) $attributes['duration'] : 0,                                     // Works only when type=db
    'limit'                => (int) $attributes['limit'],                                                                                 // Works only when type=search
    'per_page'             => (int) $attributes['per_page'],
    'cache'                => (int) $attributes['cache'],
    'columns'              => ! empty( $attributes['columns'] ) ? (int) $attributes['columns'] : 1,
    'thumb_ratio'          => ! empty( $attributes['thumb_ratio'] ) ? (float) $attributes['thumb_ratio'] : 56.25,
    'thumb_title'          => ! empty( $attributes['thumb_title'] ) ? (int) $attributes['thumb_title'] : 0,
    'thumb_title_length'   => ! empty( $attributes['thumb_title_length'] ) ? (int) $attributes['thumb_title_length'] : 0,
    'thumb_excerpt'        => ! empty( $attributes['thumb_excerpt'] ) ? (int) $attributes['thumb_excerpt'] : 0,
    'thumb_excerpt_length' => ! empty( $attributes['thumb_excerpt_length'] ) ? (int) $attributes['thumb_excerpt_length'] : 0,
    'player_description'   => ! empty( $attributes['player_description'] ) ? (int) $attributes['player_description'] : 0,	
    'total_pages'          => ! empty( $attributes['total_pages'] ) ? (int) $attributes['total_pages'] : 1,		
    'paged'                => 1,	
    'next_page_token'      => ! empty( $attributes['next_page_token'] ) ? sanitize_text_field( $attributes['next_page_token'] ) : '',
    'prev_page_token'      => ! empty( $attributes['prev_page_token'] ) ? sanitize_text_field( $attributes['prev_page_token'] ) : ''
);

$params = apply_filters( 'ayg_pagination_args', $params, $attributes );

// Signed last, so the signature covers the values that are actually sent — including any a filter
// changed above. It ties the gallery UID to its source and cache duration, so the AJAX endpoint
// can tell a genuine set from one a visitor put together. See ayg_get_gallery_signature().
$params['signature'] = ayg_get_gallery_signature(
    isset( $params['uid'] ) ? $params['uid'] : '',
    isset( $params['type'] ) ? $params['type'] : '',
    isset( $params['src'] ) ? $params['src'] : '',
    isset( $params['cache'] ) ? $params['cache'] : 0
);

// Process output
if ( $params['total_pages'] <= 1 ) {
    return false;
}
?>
<ayg-pagination class="ayg-pagination" data-params="<?php echo esc_attr( wp_json_encode( $params ) ); ?>">
    <?php if ( 'pager' == $attributes['pagination_type'] ) : // pager ?>
        <div class="ayg-pagination-prev">
            <button type="button" class="ayg-btn ayg-pagination-prev-btn" data-type="previous" style="display: none;"><?php echo esc_html( $previous_button_label ); ?></button>
        </div>
        <div class="ayg-pagination-info">
            <span class="ayg-pagination-current-page-number">1</span>
            <?php esc_html_e( 'of', 'automatic-youtube-gallery' ); ?>
            <span class="ayg-pagination-total-pages"><?php echo (int) $params['total_pages']; ?></span>
        </div>
        <div class="ayg-pagination-next">
            <button type="button" class="ayg-btn ayg-pagination-next-btn" data-type="next"><?php echo esc_html( $next_button_label ); ?></button>
        </div>
    <?php else : // more ?>
        <div class="ayg-pagination-next">
            <button type="button" class="ayg-btn ayg-pagination-next-btn" data-type="more"><?php echo esc_html( $more_button_label ); ?></button>
        </div>
    <?php endif; ?>
</ayg-pagination>