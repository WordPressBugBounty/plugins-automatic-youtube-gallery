<?php

/**
 * Thumbnail
 *
 * @link    https://plugins360.com
 * @since   1.0.0
 *
 * @package Automatic_YouTube_Gallery
 */

$single_video_page_url = ayg_get_single_video_url( $video, $attributes );
?>

<div class="ayg-thumbnail" data-id="<?php echo esc_attr( $video->id ); ?>" data-title="<?php echo esc_attr( $video->title ); ?>" data-url="<?php echo esc_attr( $single_video_page_url ); ?>">
    <div class="ayg-thumbnail-image-wrapper">
        <?php
        // Image
        $image_src = '';
    
        if ( isset( $video->thumbnails->default ) ) {
            $image_src = $video->thumbnails->default->url;
        }    
        
        if ( 75 == (int) $attributes['thumb_ratio'] ) { // 4:3 ( default - 120x90, high - 480x360, standard - 640x480 )
            if ( isset( $video->thumbnails->high ) ) {
                $image_src = $video->thumbnails->high->url;
            }

            if ( isset( $video->thumbnails->standard ) ) {
                $image_src = $video->thumbnails->standard->url;
            }
        }    
        
        if ( 56.25 == (float) $attributes['thumb_ratio'] ) { // 16:9 ( medium - 320x180, maxres - 1280x720 )
            if ( isset( $video->thumbnails->medium ) ) {
                $image_src = $video->thumbnails->medium->url;
            }

            if ( isset( $video->thumbnails->maxres ) ) {
                $image_src = $video->thumbnails->maxres->url;
            }
        }
        
        echo sprintf(
            '<img src="%s" class="ayg-thumbnail-image" alt="%s" />',
            esc_url( $image_src ),
            esc_attr( $video->title )
        );

        // Play Icon
        echo sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" class="ayg-icon ayg-thumbnail-icon-play" width="32" height="32" viewBox="0 0 32 32" fill="#fff" title="%1$s" aria-label="%1$s"><path d="M16 0c-8.837 0-16 7.163-16 16s7.163 16 16 16 16-7.163 16-16-7.163-16-16-16zM16 29c-7.18 0-13-5.82-13-13s5.82-13 13-13 13 5.82 13 13-5.82 13-13 13zM12 9l12 7-12 7z"></path></svg>',
            esc_attr__( 'Play', 'automatic-youtube-gallery' )
        );

        // Visualizer
        echo '<div class="ayg-visualizer ayg-thumbnail-visualizer" style="display: none;"><span></span><span></span><span></span></div>';
        ?>        
    </div>

    <div class="ayg-thumbnail-caption">
        <?php if ( ! empty( $attributes['thumb_title'] ) ) : ?> 
            <div class="ayg-thumbnail-title"><?php echo esc_html( ayg_trim_words( $video->title, (int) $attributes['thumb_title_length'] ) ); ?></div>
        <?php endif; ?> 

        <?php if ( ! empty( $attributes['thumb_excerpt'] ) && ! empty( $video->description ) ) : ?>
            <div class="ayg-thumbnail-excerpt"><?php echo wp_kses_post( ayg_trim_words( $video->description, (int) $attributes['thumb_excerpt_length'] ) ); ?></div>
        <?php endif; ?>

        <?php if ( ! empty( $attributes['player_description'] ) && ! empty( $video->description ) ) : ?>  
            <div class="ayg-thumbnail-description" style="display: none;"><?php echo wp_kses_post( ayg_get_player_description( $video ) ); ?></div>
        <?php endif; ?>
    </div>           
</div>