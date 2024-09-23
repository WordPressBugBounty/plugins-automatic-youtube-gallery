<?php

/**
 * Theme: Classic.
 *
 * @link    https://plugins360.com
 * @since   1.0.0
 *
 * @package Automatic_YouTube_Gallery
 */

$columns = (int) $attributes['columns'];
$player_width = ! empty( $attributes['player_width'] ) ? (int) $attributes['player_width'] . 'px' : '100%';
$player_ratio = ! empty( $attributes['player_ratio'] ) ? (float) $attributes['player_ratio'] : '56.25';

$params = array(  
    'uid'                => sanitize_text_field( $attributes['uid'] ),
    'autoplay'           => (int) $attributes['autoplay'],
    'loop'               => (int) $attributes['loop'],
    'muted'              => (int) $attributes['muted'],
    'controls'           => (int) $attributes['controls'],
    'modestbranding'     => (int) $attributes['modestbranding'],
    'cc_load_policy'     => (int) $attributes['cc_load_policy'],
    'iv_load_policy'     => (int) $attributes['iv_load_policy'],
    'hl'                 => sanitize_text_field( $attributes['hl'] ),
    'cc_lang_pref'       => sanitize_text_field( $attributes['cc_lang_pref'] ),
    'autoadvance'        => (int) $attributes['autoadvance'],
    'player_title'       => (int) $attributes['player_title'],
    'player_description' => (int) $attributes['player_description']
);

$featured = $videos[0]; // Featured Video
?>

<div id="ayg-<?php echo esc_attr( $attributes['uid'] ); ?>" class="ayg ayg-theme-classic" data-params='<?php echo wp_json_encode( $params ); ?>'>
    <!-- Player -->
    <div class="ayg-player">
        <div class="ayg-player-container" style="max-width: <?php echo $player_width; ?>;">
            <div class="ayg-player-wrapper" style="padding-bottom: <?php echo $player_ratio; ?>%;">
                <?php
                $tag = 'div';
                if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
                    $tag = 'iframe';
                }

                printf(
                    '<%1$s class="ayg-player-iframe" width="100%%" height="100%%" src="%2$s/embed/%3$s" data-id="%3$s" frameborder="0" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></%1$s>',
                    $tag,
                    ayg_get_youtube_domain(),
                    esc_attr( $featured->id )
                );
                ?>
            </div>
        </div>

        <div class="ayg-player-caption">
            <?php if ( ! empty( $attributes['player_title'] ) ) : ?>    
                <h2 class="ayg-player-title"><?php echo esc_html( $featured->title ); ?></h2>  
            <?php endif; ?>

            <?php if ( ! empty( $attributes['player_description'] ) ) : ?>  
                <div class="ayg-player-description"><?php if ( ! empty( $featured->description ) ) echo wp_kses_post( ayg_get_player_description( $featured ) ); ?></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Gallery -->
    <div class="ayg-gallery ayg-row">
        <?php foreach ( $videos as $index => $video ) :
            $classes = array(); 
            $classes[] = 'ayg-item';
            $classes[] = 'ayg-item-' . $video->id;
            $classes[] = 'ayg-col';
            $classes[] = 'ayg-col-' . $columns;
            if ( $columns > 3 ) $classes[] = 'ayg-col-sm-3';
            if ( $columns > 2 ) $classes[] = 'ayg-col-xs-2';

            if ( $video->id == $featured->id ) {
                $classes[] = 'ayg-active';
            }
            ?>
            <div class="<?php echo implode( ' ', $classes ); ?>">
                <?php the_ayg_gallery_thumbnail( $video, $attributes ); ?>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Pagination -->    
    <?php the_ayg_pagination( $attributes ); ?>
</div>
