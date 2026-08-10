<?php

/**
 * The public-facing functionality of the plugin.
 *
 * @link    https://plugins360.com
 * @since   1.0.0
 *
 * @package Automatic_YouTube_Gallery
 */

// Exit if accessed directly
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * AYG_Public class.
 *
 * @since 1.0.0
 */
class AYG_Public {

	/**
	 * Get things started.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		add_shortcode( 'automatic_youtube_gallery', array( $this, 'shortcode_automatic_youtube_gallery' ) );
	}	

	/**
	 * Enqueue styles for the public-facing side of the site.
	 *
	 * @since 1.0.0
	 */
	public function register_styles() {
		$general_settings = ayg_get_option( 'ayg_general_settings' );
		
		// Register Styles
		wp_register_style( 
			AYG_SLUG . '-public', 
			AYG_URL . 'public/assets/css/public.min.css', 
			array(), 
			AYG_VERSION, 
			'all' 
		);

		// Enqueue Styles
		if ( ! empty( $general_settings['force_load_assets']['css'] ) ) {
			wp_enqueue_style( AYG_SLUG . '-public' );
		}
	}

	/**
	 * Enqueue scripts for the public-facing side of the site.
	 *
	 * @since 1.0.0
	 */
	public function register_scripts() {
		$general_settings = ayg_get_option( 'ayg_general_settings' );
		$gallery_settings = ayg_get_option( 'ayg_gallery_settings' );
		$player_settings  = ayg_get_option( 'ayg_player_settings' );
		$privacy_settings = ayg_get_option( 'ayg_privacy_settings' );
		$strings_settings = ayg_get_option( 'ayg_strings_settings' );

		$player_type = isset( $player_settings['player_type'] ) ? sanitize_text_field( $player_settings['player_type'] ) : 'youtube';

		// YouTube rejects embeds rendered inside wp-admin with "Error 153" (missing/unacceptable
		// referrer). Force the Plyr.js player in editor previews so the poster image is shown
		// instead of the failed native embed. Front-end output is unaffected.
		if ( is_admin() ) {
			$player_type = 'custom';
		}
		
		$scroll_top_offset = ( isset( $gallery_settings['scroll_top_offset'] ) && ! empty( $gallery_settings['scroll_top_offset'] ) ) ? (int) $gallery_settings['scroll_top_offset'] : 10;
		$scroll_top_offset = apply_filters( 'ayg_gallery_scrolltop_offset', $scroll_top_offset ); // Backward compatibility to 2.4.3
		$scroll_top_offset = apply_filters( 'ayg_gallery_scroll_top_offset', $scroll_top_offset );

		$show_more_label = ! empty( $strings_settings['show_more_label'] ) ? sanitize_text_field( $strings_settings['show_more_label'] ) : __( 'Show More', 'automatic-youtube-gallery' );
		$show_less_label = ! empty( $strings_settings['show_less_label'] ) ? sanitize_text_field( $strings_settings['show_less_label'] ) : __( 'Show Less', 'automatic-youtube-gallery' );

		$script_args = array(
			'plugin_url'            => AYG_URL,
			'plugin_version'        => AYG_VERSION,
			'ajax_url'              => admin_url( 'admin-ajax.php' ),
			'ajax_nonce'            => wp_create_nonce( 'ayg_ajax_nonce' ),	
			'current_page_url'      => get_permalink(),
			'current_gallery_id'    => get_query_var( 'ayg_gallery_id' ),					
			'player_type'           => $player_type,
			'player_color'          => isset( $player_settings['player_color'] ) ? sanitize_text_field( $player_settings['player_color'] ) : '#00b3ff',	
			'privacy_enhanced_mode' => isset( $player_settings['privacy_enhanced_mode'] ) ? (int) $player_settings['privacy_enhanced_mode'] : 0,
			'origin'                => '',
			'cookieconsent'         => 0,
			'top_offset'            => $scroll_top_offset,
			'i18n'                  => array(
				'show_more' => $show_more_label,
				'show_less' => $show_less_label
			)
		);

		if ( isset( $player_settings['origin'] ) && ! empty( $player_settings['origin'] ) ) {
			$url_parts = parse_url( site_url() );
			$script_args['origin'] = $url_parts['scheme'] . '://' . $url_parts['host'];
		}

		if ( ! isset( $_COOKIE['ayg_gdpr_consent'] ) ) {
			if ( ! empty( $privacy_settings['cookie_consent'] ) && ! empty( $privacy_settings['consent_message'] ) && ! empty( $privacy_settings['button_label'] ) ) {
				$script_args['cookieconsent'] = 1;
				$script_args['cookieconsent_message'] = wp_kses_post( trim( $privacy_settings['consent_message'] ) );
				$script_args['cookieconsent_button_label'] = esc_html( $privacy_settings['button_label'] );
			}
		}

		// Register Scripts
		$deps = array( 'jquery' );

		wp_register_script( 
			AYG_SLUG . '-plyr', 
			AYG_URL . 'vendor/plyr/plyr.polyfilled.js', 
			array(), 
			'3.7.8', 
			array( 'strategy' => 'defer' )  
		);		
		
		if ( empty( $general_settings['force_load_assets']['js'] ) ) {
			if ( isset( $player_settings['player_type'] ) && 'custom' == $player_settings['player_type'] ) {
				$deps[] = AYG_SLUG . '-plyr';
			}
		}

		wp_register_script( 
			AYG_SLUG . '-public', 
			AYG_URL . 'public/assets/js/public.min.js', 
			$deps, 
			AYG_VERSION, 
			array( 'strategy' => 'defer' )  
		);

		wp_localize_script( 
			AYG_SLUG . '-public', 
			'ayg_config', 
			$script_args
		);

		wp_register_script( 
			AYG_SLUG . '-theme-classic', 
			AYG_URL . 'public/assets/js/theme-classic.min.js', 
			array( 'jquery' ), 
			AYG_VERSION, 
			array( 'strategy' => 'defer' )  
		);

		// Enqueue Scripts
		if ( ! empty( $general_settings['force_load_assets']['js'] ) ) {
			wp_enqueue_script( AYG_SLUG . '-public' );
		}
	}

	/**
	 * Enqueue block assets inside the block editor (iframe).
	 *
	 * Hooked to enqueue_block_assets with an is_admin() guard so styles and scripts
	 * are injected inside the iframed block editor (WP 6.3+ / WP 7.0 always) only,
	 * and not duplicated on the front end where wp_enqueue_scripts already handles them.
	 *
	 * @since 2.7.2
	 */
	public function enqueue_block_assets() {
		if ( ! is_admin() ) {
			return;
		}

		$this->enqueue_editor_assets();
	}

	/**
	 * Enqueue the plugin's public styles and scripts in any editor context.
	 *
	 * Called by enqueue_block_assets() (WordPress block editor, guarded by is_admin())
	 * and hooked directly to Elementor actions so assets are also available in the
	 * Elementor editor panel and its frontend live-preview iframe:
	 *   - elementor/editor/after_enqueue_scripts  (admin context)
	 *   - elementor/preview/enqueue_scripts       (frontend context, is_admin() = false)
	 *
	 * @since 1.6.1
	 */
	public function enqueue_editor_assets() {
		// Styles
		$this->register_styles();
		wp_enqueue_style( AYG_SLUG . '-public' );

		// Scripts
		$this->register_scripts();

		wp_enqueue_script( AYG_SLUG . '-public' );
		wp_enqueue_script( AYG_SLUG . '-theme-classic' );
	}

	/**
	 * Process the shortcode [automatic_youtube_gallery].
	 *
	 * @since  1.0.0
	 * @param  array  $attributes An associative array of attributes.
	 * @param  string $content    Enclosing content.
	 * @return string             Shortcode HTML output.
	 */
	public function shortcode_automatic_youtube_gallery( $attributes, $content = null ) {
		if ( ! empty( $content ) ) {
			$attributes['content'] = $content;
		}

		return ayg_build_gallery( $attributes );
	}

	/**
	 * Load more videos.
	 *
	 * Registered for both wp_ajax_ and wp_ajax_nopriv_, so every value in $_POST arrives from an
	 * unauthenticated visitor. The gallery being paginated is therefore resolved server side
	 * rather than taken from the request — see resolve_gallery_request().
	 *
	 * @since 1.0.0
	 */
	public function ajax_callback_load_videos() {
		// Security check
		check_ajax_referer( 'ayg_ajax_nonce', 'security' );

		// Proceed safe
		$json       = array();
		$attributes = array_map( 'sanitize_text_field', $_POST );

		// Work out which gallery this request belongs to, from the site's own data.
		$request = $this->resolve_gallery_request( $attributes );

		// Only galleries this site actually displays may reach the YouTube API from here. A saved
		// Gallery Builder record qualifies, and so does a legacy shortcode gallery that has been
		// rendered at least once — its videos are already in the relationship table. Anything else
		// describes a gallery that does not exist here, or a UID that could not be tied to the
		// source sent with it, so it is refused before a single unit of API quota is spent on it.
		if ( ! $request['gallery'] && ! ayg_db_gallery_has_videos( $request['uid'] ) ) {
			wp_send_json_error( array(
				'message' => __( 'No videos found matching your query.', 'automatic-youtube-gallery' )
			) );
		}

		$source_type = $request['type'];

		// The resolved values replace whatever was posted, so the thumbnails rendered below — and
		// the deeplink URLs built from them — belong to the gallery we resolved.
		$attributes['uid']  = $request['uid'];
		$attributes['type'] = $source_type;

		// Videos per page and search limit are normalised exactly as ayg_build_gallery() does, so a
		// paginated request can't ask for a page size the initial render would never produce.
		$per_page = isset( $attributes['per_page'] ) ? (int) $attributes['per_page'] : 0;

		if ( 'db' === $source_type ) {
			$per_page = max( 0, $per_page ); // 0 = every video on a single page (DB served galleries only).
		} else {
			$per_page = min( 50, $per_page ); // YouTube returns at most 50 results per request.

			if ( $per_page < 1 ) {
				$per_page = 50;
			}
		}

		$limit = isset( $attributes['limit'] ) ? min( 500, (int) $attributes['limit'] ) : 500;

		if ( $limit < 1 ) {
			$limit = 500;
		}

		// Page token. For the sources that page through the live API, every distinct token costs
		// another API call, so only tokens this site actually issued are accepted — see
		// ayg_sign_page_token(). A search request is answered from our own tables instead, so its
		// page number is just a number and needs no signature.
		$page_token = isset( $attributes['pageToken'] ) ? $attributes['pageToken'] : '';

		if ( empty( $attributes['searchTerm'] ) && ayg_page_token_is_signed( $source_type ) ) {
			$page_token = ayg_verify_page_token( $page_token, $request['uid'] );

			if ( false === $page_token ) {
				wp_send_json_error( array(
					'message' => __( 'No videos found matching your query.', 'automatic-youtube-gallery' )
				) );
			}
		}

		$api_params = array(
			'uid'               => $request['uid'],
			'type'              => $source_type,
			'src'               => $request['src'],
			'store'             => true,                                                                             // Safe: the uid above is resolved server side, never posted.
			'featured_video_id' => isset( $attributes['featured_video_id'] ) ? $attributes['featured_video_id'] : '', // Works only when type=db (deeplinked video pinned first)
			'order'             => isset( $attributes['order'] ) ? $attributes['order'] : 'date',                     // Works only when type=search
			'sort_by'           => isset( $attributes['sort_by'] ) ? $attributes['sort_by'] : 'date',                 // Works only when type=db
			'sort_order'        => isset( $attributes['sort_order'] ) ? $attributes['sort_order'] : 'desc',           // Works only when type=db
			'sort_seed'         => isset( $attributes['sort_seed'] ) ? (int) $attributes['sort_seed'] : 0,            // Works only when type=db + sort_by=random
			'duration_filter'   => isset( $attributes['duration_filter'] ) ? $attributes['duration_filter'] : '',     // Works only when type=db
			'duration'          => isset( $attributes['duration'] ) ? (int) $attributes['duration'] : 0,              // Works only when type=db
			'limit'             => $limit,
			'maxResults'        => $per_page,
			'cache'             => (int) apply_filters( 'ayg_ajax_cache_duration', $request['cache'], $attributes ),
			'pageToken'         => $page_token
		);

		if ( ! empty( $attributes['searchTerm'] ) ) {
			$api_params['searchTerm'] = $attributes['searchTerm'];
		}

		$youtube_api = new AYG_YouTube_API();
		$response = $youtube_api->query( $api_params );

		if ( ! isset( $response->error ) ) {
			if ( isset( $response->page_info ) ) {
				$json = $response->page_info;

				// Sign the tokens handed back to the browser, exactly as the initial render does,
				// so the next page request can be verified the same way.
				if ( ayg_page_token_is_signed( $source_type ) ) {
					$json = ayg_sign_page_tokens( $json, $request['uid'] );
				}

				$json['message'] = sprintf(
					_n( '%s video found matching your query.', '%s videos found matching your query.', $json['videos_found'], 'automatic-youtube-gallery' ), 
					number_format_i18n( $json['videos_found'] )
				);
			}

			if ( isset( $response->videos ) ) {
				$videos = $response->videos;
				$columns = isset( $attributes['columns'] ) ? min( 12, max( 1, (int) $attributes['columns'] ) ) : 3;

				ob_start();
				foreach ( $videos as $index => $video ) {
					$classes = array(); 
					$classes[] = 'ayg-video';
					$classes[] = 'ayg-video-' . $video->id;
					$classes[] = 'ayg-col';
					$classes[] = 'ayg-col-' . $columns;
					if ( $columns > 3 ) $classes[] = 'ayg-col-sm-3';
					if ( $columns > 2 ) $classes[] = 'ayg-col-xs-2';

					echo'<div class="' . implode( ' ', $classes ) . '">';
					the_ayg_gallery_thumbnail( $video, $attributes );
					echo '</div>';
				}
				$json['html'] = ob_get_clean();
			}	

			wp_send_json_success( $json );			
		} else {
			$json['message'] =  $response->error_message;
			wp_send_json_error( $json );			
		}		
	}

	/**
	 * Work out which gallery an AJAX request belongs to, without trusting the request.
	 *
	 * The gallery UID decides which gallery any videos fetched by the request are linked to, and a
	 * UID on its own proves nothing — it is printed in the page for anyone to read. So it is only
	 * accepted when it is demonstrably tied to the source posted with it.
	 *
	 * A numeric UID may be a Gallery Builder gallery ID — but it may equally be a legacy shortcode
	 * gallery that was given a numeric "uid" attribute ( e.g. [automatic_youtube_gallery
	 * type="channel" channel="UC..." uid="5"] ). So a numeric UID is only treated as a Builder
	 * gallery when the request is genuinely tied to that gallery's own saved source — signed for
	 * it, or, for pages cached before this version, posting that exact source. Otherwise it is
	 * resolved as the legacy gallery it is, so a legacy "uid" that happens to match a Builder ID is
	 * never misrouted to the wrong gallery.
	 *
	 * For a Builder gallery the source type and value come from the saved row, the one thing a
	 * shortcode cannot override. Its other settings, cache duration included, can be overridden per
	 * shortcode, so the posted cache is honoured when the signature proves it.
	 *
	 * A legacy shortcode gallery has no saved row, so its UID must prove itself in one of two ways:
	 *
	 * 1. It is the source's own fingerprint. ayg_build_gallery() derives the UID as
	 *    md5( source type + source ), so for the great majority of galleries the posted UID
	 *    already proves which source it belongs to and needs nothing else.
	 * 2. It carries a valid signature. A gallery can set its own UID through the "uid" shortcode
	 *    attribute or the ayg_gallery_id filter, and neither can be recomputed here. For those the
	 *    render signs the UID together with its source, and that pairing is verified here.
	 *
	 * Either way the caller cannot combine one gallery's UID with another source, which is what
	 * the whole fix rests on.
	 *
	 * @since  2.9.0
	 * @access private
	 * @param  array   $attributes Sanitized $_POST values.
	 * @return array               Resolved "gallery" row (null for legacy galleries), "uid",
	 *                             "type" and "src". The UID is an empty string when the request
	 *                             could not be tied to a gallery.
	 */
	private function resolve_gallery_request( $attributes ) {
		$requested_uid = isset( $attributes['uid'] ) ? (string) $attributes['uid'] : '';
		$source_type   = isset( $attributes['type'] ) ? $attributes['type'] : '';
		$source_url    = isset( $attributes['src'] ) ? $attributes['src'] : '';
		$signature     = isset( $attributes['signature'] ) ? $attributes['signature'] : '';
		$cache         = isset( $attributes['cache'] ) ? (int) $attributes['cache'] : 0;

		// A numeric UID might be a Gallery Builder gallery — look it up, but only commit to that
		// reading when the request is actually tied to the gallery's own source ( see the method
		// note ). This is what stops a legacy gallery using a numeric "uid" attribute from being
		// misrouted to a Builder gallery that happens to share the number.
		$gallery = ( '' !== $requested_uid && ctype_digit( $requested_uid ) ) ? ayg_get_gallery( (int) $requested_uid ) : null;

		if ( $gallery ) {
			$source = ayg_get_gallery_source( $gallery );

			// Signed for this gallery's own source ( normal, post-2.9.0 render ), or — for pages
			// cached before this version, which carry no signature — posting that exact source.
			$is_signed      = ( '' !== $signature && hash_equals( ayg_get_gallery_signature( $requested_uid, $source['type'], $source['src'], $cache ), $signature ) );
			$matches_source = ( $source['type'] === $source_type && $source['src'] === $source_url );

			if ( $is_signed || $matches_source ) {
				return array(
					'gallery' => $gallery,
					'uid'     => strval( $gallery->id ),
					'type'    => $source['type'],
					'src'     => $source['src'],
					'cache'   => $is_signed ? max( 0, $cache ) : $this->get_gallery_cache_duration( $gallery )
				);
			}

			// Not this Builder gallery after all — fall through and resolve as a legacy gallery.
		}

		// Legacy shortcode gallery.
		//
		// Route 1 — the UID is the fingerprint of the source posted with it. Accepting this
		// without a signature also means pages rendered before this version, including ones held
		// in a full page cache, keep paginating normally.
		$is_derived = ( '' !== $requested_uid && hash_equals( md5( $source_type . $source_url ), $requested_uid ) );

		// Route 2 — the UID, source and cache duration were all signed together when the gallery
		// was rendered, so the whole set is known to be one this site issued. This is the route a
		// legacy gallery with a custom numeric "uid" takes.
		$is_signed = ( '' !== $signature && hash_equals( ayg_get_gallery_signature( $requested_uid, $source_type, $source_url, $cache ), $signature ) );

		return array(
			'gallery' => null,
			'uid'     => ( $is_derived || $is_signed ) ? $requested_uid : '',
			'type'    => $source_type,
			'src'     => $source_url,
			// The gallery's own "Cache Duration" setting is honoured in full — including "No
			// Caching" — because a signed request proves the value is the one the shortcode was
			// rendered with rather than one the caller picked. Only an unsigned request (a page
			// rendered before this version) falls back to the default.
			'cache'   => $is_signed ? max( 0, $cache ) : DAY_IN_SECONDS
		);
	}

	/**
	 * Read a Gallery Builder gallery's saved cache duration.
	 *
	 * Taken from the gallery's own row rather than the request, so there is nothing for a caller
	 * to influence.
	 *
	 * @since  2.9.0
	 * @access private
	 * @param  object  $gallery Gallery row.
	 * @return int              Cache duration in seconds.
	 */
	private function get_gallery_cache_duration( $gallery ) {
		$cache_duration = DAY_IN_SECONDS; // Matches the "Cache Duration" field default.

		$params = json_decode( (string) $gallery->params, true );

		if ( is_array( $params ) && isset( $params['cache'] ) ) {
			$cache_duration = (int) $params['cache'];
		}

		return max( 0, $cache_duration );
	}

	/**
	 * Set cookie for accepting the privacy consent.
	 *
	 * @since 2.0.0
	 */
	public function set_gdpr_cookie() {	
		// Security check
		check_ajax_referer( 'ayg_ajax_nonce', 'security' );	

		// Proceed safe
		setcookie( 'ayg_gdpr_consent', 1, time() + ( 30 * 24 * 60 * 60 ), COOKIEPATH, COOKIE_DOMAIN );		
		wp_send_json_success();			
	}

	/**
	 * [SMUSH] Skip YouTube iframes from lazy loading.
	 *
	 * @since  1.5.0
	 * @param  bool   $skip Should skip? Default: false.
	 * @param  string $src  Iframe url.
	 * @return bool
	 */
	public function smush( $skip, $src ) {
		return false !== strpos( $src, 'youtube' );
	}

}
