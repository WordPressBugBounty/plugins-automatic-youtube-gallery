<?php

/**
 * Theme: Live Stream.
 *
 * @link    https://plugins360.com
 * @since   1.6.4
 *
 * @package Automatic_YouTube_Gallery
 */

$player_width = ! empty( $attributes['player_width'] ) ? (int) $attributes['player_width'] . 'px' : '100%';
$player_ratio = ! empty( $attributes['player_ratio'] ) ? (float) $attributes['player_ratio'] : '56.25';

$params = array(  
    'uid'            => sanitize_text_field( $attributes['uid'] ),
    'autoplay'       => (int) $attributes['autoplay'],
    'muted'          => (int) $attributes['muted'],
    'controls'       => (int) $attributes['controls'],
    'modestbranding' => (int) $attributes['modestbranding'],
    'cc_load_policy' => (int) $attributes['cc_load_policy'],
    'iv_load_policy' => (int) $attributes['iv_load_policy'],
    'hl'             => sanitize_text_field( $attributes['hl'] ),
    'cc_lang_pref'   => sanitize_text_field( $attributes['cc_lang_pref'] ),
    'is_live'        => 1
);

$featured = $videos[0]; // Featured Video
?>

<div id="ayg-<?php echo esc_attr( $attributes['uid'] ); ?>" class="ayg ayg-theme-livestream" data-params='<?php echo wp_json_encode( $params ); ?>'>
    <!-- Player -->
    <div class="ayg-player">
        <div class="ayg-player-container" style="max-width: <?php echo $player_width; ?>;">
            <div class="ayg-player-wrapper" style="padding-bottom: <?php echo $player_ratio; ?>%;">
                <?php
                // Image
                $image_src = sprintf( 'https://i.ytimg.com/vi/%s/0.jpg', esc_attr( $featured->id ) );       
                
                if ( 56.25 == $player_ratio ) { // 16:9 ( medium - 320x180, maxres - 1280x720 )
                    $image_src = sprintf( 'https://i.ytimg.com/vi/%s/maxresdefault.jpg', esc_attr( $featured->id ) );  
                } 

                // Player
                $tag = 'div';
                if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
                    $tag = 'iframe';
                }

                printf(
                    '<%1$s class="ayg-player-iframe" width="100%%" height="100%%" src="%2$s/embed/%3$s" data-id="%3$s" data-image="%4$s" frameborder="0" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></%1$s>',
                    $tag,
                    ayg_get_youtube_domain(),
                    esc_attr( $featured->id ),
                    esc_attr( $image_src )
                );
                ?>            
            </div>
        </div>
    </div>
</div>
