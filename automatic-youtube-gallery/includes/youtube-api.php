<?php

/**
 * A wrapper class for the Youtube Data API v3.
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
 * AYG_YouTube_API class.
 *
 * @since 1.0.0
 */
class AYG_YouTube_API {

	/**
     * The YouTube API Key.
	 * 
	 * @since  1.0.0
	 * @access protected
     * @var    string
     */
	protected $api_key;

	/**
	 * Array of query params.
	 * 
	 * @since  2.5.7
	 * @access protected
     * @var    array
     */
    protected $params = array();

	/**
	 * May this query write gallery membership rows?
	 *
	 * Opt-in, set from the "store" query param. Only trusted server side callers set it — the
	 * page renderer (ayg_build_gallery), the Gallery Builder importer, and the public AJAX
	 * endpoint once it has resolved the gallery itself. See request_api().
	 *
	 * @since  2.9.0
	 * @access protected
     * @var    bool
     */
	protected $can_store = false;

	/**
     * Is development mode enabled?
	 * 
	 * @since  2.3.0
	 * @access protected
     * @var    bool
     */
	protected $is_development_mode = false;

	/**
	 * The YouTube API URLs.
	 * 
	 * @since  1.0.0
	 * @access protected
     * @var    array
     */
    public $api_urls = array(       
		'playlistItems.list' => 'https://www.googleapis.com/youtube/v3/playlistItems',
		'channels.list'      => 'https://www.googleapis.com/youtube/v3/channels',
		'search.list'        => 'https://www.googleapis.com/youtube/v3/search',
		'videos.list'        => 'https://www.googleapis.com/youtube/v3/videos'
	);

	/**
	 * Get videos.
	 *
	 * Side effect: successful responses are persisted to the custom tables via
	 * ayg_db_store_videos() (called inside request_api()) — video rows always, and
	 * gallery relationships only when the caller passes both a "uid" and "store" => true.
	 * This is how both legacy galleries and the Gallery Builder importer store their videos.
	 *
	 * "store" must never be set from user input: it decides which gallery the fetched videos
	 * are shown in. Callers reachable by unauthenticated visitors have to resolve the gallery
	 * server side first — see AYG_Public::ajax_callback_load_videos().
	 *
	 * @since  1.0.0
     * @param  array $params Array of query params.
     * @return mixed
     */
    public function query( $params = array() ) {
		// Get YouTube API Key
		$general_settings = ayg_get_option( 'ayg_general_settings' );

		// DB-served responses (gallery search + the internal "db" source type) read from the
		// custom tables, so they're handled before the API-key guard — no key required.
		if ( ! empty( $params['searchTerm'] ) || ( isset( $params['type'] ) && 'db' === $params['type'] ) ) {
			return $this->get_videos_from_db( $params );
		}

		if ( empty( $general_settings['api_key'] ) ) {
			return $this->get_error( __( 'YouTube API key not found.', 'automatic-youtube-gallery' ) . ' ' . sprintf( __( 'Kindly follow this URL <a href="%s" target="_blank" rel="noopener noreferrer">this guide</a> to get your own API key.', 'automatic-youtube-gallery' ), 'https://plugins360.com/automatic-youtube-gallery/how-to-get-youtube-api-key/' ) );
		}

		$this->api_key   = $general_settings['api_key'];
		$this->params    = $params;
		$this->can_store = ! empty( $params['store'] );

		// Is development mode enabled?
		if ( isset( $general_settings['development_mode'] ) && ! empty( $general_settings['development_mode'] ) ) {
			$this->is_development_mode = true;
		}

		// Advanced mode fetches duration + live broadcast details via a supplementary
		// videos.list call (used by the Gallery Builder importer). Defaults to off so
		// legacy galleries make no extra API request.
		$mode = isset( $params['mode'] ) ? $params['mode'] : 'basic';

		// Process output
		$response = array();

		switch ( $params['type'] ) {
			case 'playlist':
				if ( empty( $params['src'] ) ) {
					return $this->get_error( __( 'A YouTube playlist ID (or) URL is required.', 'automatic-youtube-gallery' ) );
				}
				
				$response = $this->request_api_playlist_items( $params );
				break;

			case 'channel':
				if ( empty( $params['src'] ) ) {
					return $this->get_error( __( 'A YouTube channel ID (or) a video URL from the channel is required.', 'automatic-youtube-gallery' ) );
				}

				// @handle URLs can't be resolved to a channel ID here (mirrors the client-side
				// check in admin.js / gallery-form.php).
				if ( false !== strpos( $params['src'], '@' ) ) {
					return $this->get_error( __( 'YouTube @handle URLs aren’t supported here. Please enter a channel ID, a /channel/ URL, or a video URL from the channel.', 'automatic-youtube-gallery' ) );
				}

				$params['id'] = $this->get_channel_id( $params );

				if ( empty( $params['id'] ) ) {
					return $this->get_error( __( 'Invalid YouTube channel ID.', 'automatic-youtube-gallery' ) );
				}

				// Get playlist id from the channel	
				$playlist_id = $this->get_playlist_id( $params );

				if ( is_object( $playlist_id ) && isset( $playlist_id->error ) ) {
					return $playlist_id;
				}

				if ( empty( $playlist_id ) ) {
					return $this->get_error( __( 'No videos found matching your query.', 'automatic-youtube-gallery' ) );
				}

				// Get videos using the playlist id
				$params['src'] = $playlist_id;
				$response = $this->request_api_playlist_items( $params );
				break;

			case 'username':
				if ( empty( $params['src'] ) ) {
					return $this->get_error( __( 'A YouTube account username is required.', 'automatic-youtube-gallery' ) );
				}

				// Get playlist id from the channel 
				$params['forUsername'] = $this->parse_youtube_id_from_url( $params['src'], 'username' );
				$playlist_id = $this->get_playlist_id( $params );

				if ( is_object( $playlist_id ) && isset( $playlist_id->error ) ) {
					return $playlist_id;
				}

				if ( empty( $playlist_id ) ) {
					return $this->get_error( __( 'No videos found matching your query.', 'automatic-youtube-gallery' ) );
				}

				// Get videos using the playlist id
				$params['src'] = $playlist_id;
				$response = $this->request_api_playlist_items( $params );
				break;

			case 'search':
				if ( empty( $params['src'] ) ) {
					return $this->get_error( __( 'A search keyword is required.', 'automatic-youtube-gallery' ) );
				}
				
				$response = $this->request_api_search( $params );						
				break;

			case 'videos':			
				if ( empty( $params['src'] ) ) {
					return $this->get_error( __( 'At least one YouTube video ID (or) URL is required.', 'automatic-youtube-gallery' ) );
				}

				$response = $this->request_api_videos( $params );
				break;

			case 'livestream':
				if ( empty( $params['src'] ) ) {
					return $this->get_error( __( 'A YouTube channel ID (or) a video URL from the channel is required.', 'automatic-youtube-gallery' ) );
				}

				// @handle URLs can't be resolved to a channel ID here (mirrors the client-side
				// check in admin.js / gallery-form.php).
				if ( false !== strpos( $params['src'], '@' ) ) {
					return $this->get_error( __( 'YouTube @handle URLs aren’t supported here. Please enter a channel ID, a /channel/ URL, or a video URL from the channel.', 'automatic-youtube-gallery' ) );
				}

				$params['channelId'] = $this->get_channel_id( $params );

				if ( empty( $params['channelId'] ) ) {
					return $this->get_error( __( 'Invalid YouTube channel ID.', 'automatic-youtube-gallery' ) );
				}

				// Get live video using the channel id
				$response = $this->request_api_live_video( $params );
				break;

			default: // video
				if ( empty( $params['src'] ) ) {
					return $this->get_error( __( 'A YouTube video ID (or) URL is required.', 'automatic-youtube-gallery' ) );
				}
				
				$response = $this->request_api_video( $params );
				break;
		}

		// Advanced mode: enrich channel/playlist/username/search videos (which come from
		// playlistItems.list / search.list and lack duration + live broadcast details).
		if ( 'advanced' === $mode && ! isset( $response->error ) && ! empty( $response->videos ) && in_array( $params['type'], array( 'playlist', 'channel', 'username', 'search' ), true ) ) {
			$response->videos = $this->enrich_video_details( $response->videos );
		}

		return $response;
	}

	/**
	 * Grab the playlist, channel or video ID using the YouTube URL given.
	 * 
	 * @since  1.0.0
	 * @access private
     * @param  string  $url  YouTube URL.
	 * @param  string  $type Type of the URL (playlist|channel|video).
     * @return mixed
     */
    private function parse_youtube_id_from_url( $url, $type = 'video' ) {
		$url = trim( $url );
		$id  = $url;

		switch ( $type ) {
			case 'playlist':
				if ( preg_match( '/[?&]list=([^&]+)/', $url, $matches ) ) {
					$id = $matches[1];
				}
				break;

			case 'channel':
				if ( wp_http_validate_url( $id ) ) {
					$id = '';
				}
				
				$url = parse_url( rtrim( $url, '/' ) );

				if ( isset( $url['path'] ) && preg_match( '/^\/channel\/(([^\/])+?)$/', $url['path'], $matches ) ) {
					$id = $matches[1];
				}
				break;

			case 'username':
				$url = parse_url( rtrim( $url, '/' ) );

				if ( isset( $url['path'] ) && preg_match( '/^\/user\/(([^\/])+?)$/', $url['path'], $matches ) ) {
					$id = $matches[1];
				}
				break;
			
			default: // video
				if ( wp_http_validate_url( $id ) ) {
					$id = '';
				}

				$url = parse_url( $url );
			
				if ( array_key_exists( 'host', $url ) ) {				
					if ( 0 === strcasecmp( $url['host'], 'youtu.be' ) ) {
						$id = substr( $url['path'], 1 );
					} elseif ( 0 === strcasecmp( $url['host'], 'www.youtube.com' ) || 0 === strcasecmp( $url['host'], 'youtube.com' ) ) {
						if ( isset( $url['query'] ) ) {
							parse_str( $url['query'], $url['query'] );

							if ( isset( $url['query']['v'] ) ) {
								$id = $url['query']['v'];
							}
						}
							
						if ( empty( $id ) ) {
							$url['path'] = explode( '/', substr( $url['path'], 1 ) );
							if ( in_array( $url['path'][0], array( 'e', 'embed', 'v', 'shorts', 'live' ) ) ) {
								$id = $url['path'][1];
							}
						}
					}
				}
		}

		return $id;
	}

	/**
	 * Get the channel ID.
	 * 
	 * @since  2.0.0
	 * @access private
     * @param  array   $params Array of query params.
     * @return string
     */
    private function get_channel_id( $params = array() ) {
		// Parse channel ID from URL: https://www.youtube.com/channel/XXXXXXXXXX
		$id = $this->parse_youtube_id_from_url( $params['src'], 'channel' );

		if ( empty( $id ) ) {
			// Get channel ID from a Video URL: https://www.youtube.com/watch?v=XXXXXXXXXX		
			$video_id = $this->parse_youtube_id_from_url( $params['src'], 'video' );

			// Request from cache
			$channel_ids = ayg_get_option( 'ayg_channel_ids' );

			if ( isset( $channel_ids[ $video_id ] ) && ! empty( $channel_ids[ $video_id ] ) ) {
				return $channel_ids[ $video_id ];
			}

			// Request from API
			$api_url = $this->get_api_url( 'videos.list' );

			$params['id'] = $video_id;
			
			$api_params = $this->safe_merge_params(
				array(
					'id'    => '',
					'part'  => 'id,snippet,contentDetails,status',
					'cache' => 0
				), 
				$params
			);

			$api_response = $this->request_api( $api_url, $api_params, 'channel_id' );
			if ( isset( $api_response->error ) ) {
				return $id;	
			}

			$videos = $this->parse_videos( $api_response );
			if ( isset( $videos->error ) ) {
				return $id;	
			}

			// Process output
			if ( $id = $videos[0]->channel_id ) {
				// Store in cache
				$channel_ids[ $video_id ] = $id;
				update_option( 'ayg_channel_ids', $channel_ids, false );
			}
		}

		return $id;		
	}

	/**
	 * Get playlist id using channels API.
	 * 
	 * @since  1.0.0
	 * @access private
     * @param  array   $params Array of query params.
     * @return mixed
     */
    private function get_playlist_id( $params = array() ) {
		// Request from cache
		$playlist_ids = ayg_get_option( 'ayg_playlist_ids' );

		$key = '';

		if ( isset( $params['forUsername'] ) && ! empty( $params['forUsername'] ) ) {
			$key = $params['forUsername'];
		}

		if ( isset( $params['id'] ) && ! empty( $params['id'] ) ) {
			unset( $params['forUsername'] );
			$key = $params['id'];
		}

		if ( isset( $playlist_ids[ $key ] ) && ! empty( $playlist_ids[ $key ] ) ) {
			return $playlist_ids[ $key ];
		}

		// Request from API		
		$api_url = $this->get_api_url( 'channels.list' );

		$api_params = $this->safe_merge_params(
			array(
				'id'          => '',
				'forUsername' => '',
				'part'        => 'contentDetails',
				'cache'       => 0
			),
			$params
		);

		$api_response = $this->request_api( $api_url, $api_params, 'playlist_id' );
		if ( isset( $api_response->error ) ) {
			return $api_response;
		}

		if ( ! isset( $api_response->items ) ) {
			return false;
		}

		$items = $api_response->items;
		if ( ! is_array( $items ) || count( $items ) == 0 ) {
			return false;
		}

		// Process output
		if ( $id = $items[0]->contentDetails->relatedPlaylists->uploads ) {
			// Store in cache
			$playlist_ids[ $key ] = $id;
			update_option( 'ayg_playlist_ids', $playlist_ids, false );

			// Return
			return $id;
		}	

		return false;
	}

	/**
	 * Get videos using playlistItems API.
	 * 
	 * @since  1.0.0
	 * @access private
     * @param  array    $params Array of query params.
     * @return stdClass
     */
    private function request_api_playlist_items( $params = array() ) {
		$api_url = $this->get_api_url( 'playlistItems.list' );
		
		$params['playlistId'] = $this->parse_youtube_id_from_url( $params['src'], 'playlist' );

        $api_params = $this->safe_merge_params(
			array(
				'playlistId' => '',
				'part'       => 'id,snippet,contentDetails,status',
				'maxResults' => 50,
				'pageToken'  => '',
				'cache'      => 0
			),
			$params
		);
		
		$api_response = $this->request_api( $api_url, $api_params );
		if ( isset( $api_response->error ) ) {
			return $api_response;
		}

		$videos = $this->parse_videos( $api_response );
		if ( isset( $videos->error ) ) {
			return $videos;
		}

		// Process output
		$response = new stdClass();
		$response->page_info = $this->parse_page_info( $api_response );
		$response->videos = $videos;

		return $response;		
	}	

	/**
	 * Get videos using search API.
	 * 
	 * @since  1.0.0
	 * @access private
     * @param  array    $params Array of query params.
     * @return stdClass
     */
    private function request_api_search( $params = array() ) {
		$api_url = $this->get_api_url( 'search.list' );

		// Passed through unmodified: request_api() now runs every parameter through
		// http_build_query(), which encodes the OR operator "|" to %7C on its own. Pre-encoding it
		// here would be double encoded into %257C and break the search.
		$params['q'] = $params['src'];

		$params['type'] = 'video'; // Overrides user defined type value 'search'

		$api_params = $this->safe_merge_params(
			array(
				'q'               => '',
				'channelId'       => '',
				'type'            => 'video',
				'videoEmbeddable' => true,
				'part'            => 'id,snippet',
				'order'           => 'date',
				'publishedAfter'  => '', // Set by incremental sync to fetch only newly published videos
				'maxResults'      => 50,
				'pageToken'       => '',
				'cache'           => 0
			),
			$params
		);
		
		$api_response = $this->request_api( $api_url, $api_params );
		if ( isset( $api_response->error ) ) {
			return $api_response;
		}

		$videos = $this->parse_videos( $api_response );
		if ( isset( $videos->error ) ) {
			return $videos;
		}

		// Process output
		$response = new stdClass();
		$response->page_info = $this->parse_page_info( $api_response );
		$response->videos = $videos;

		return $response;		
	}	

	/**
	 * Get live video using search API.
	 * 
	 * @since  2.3.7
	 * @access private
     * @param  array   $params Array of query params.
     * @return mixed
     */
    private function request_api_live_video( $params = array() ) {
		$api_url = $this->get_api_url( 'search.list' );

		$params['type'] = 'video'; // Overrides user defined type value 'livestream'

		$api_params = $this->safe_merge_params(
			array(
				'type'      => 'video',
				'eventType' => 'live',
				'part'      => 'snippet',
				'channelId' => '',
				'cache'     => 0
			),
			$params
		);

		$api_response = $this->request_api( $api_url, $api_params, 'live' );
		if ( isset( $api_response->error ) ) {
			return $api_response;
		}

		$videos = $this->parse_videos( $api_response );
		if ( isset( $videos->error ) ) {
			$livestream_settings = ayg_get_option( 'ayg_livestream_settings' );
			return $this->get_error( '<div class="ayg-livestream-fallback-message">' . $livestream_settings['fallback_message'] . '</div>' );
		}

		// Process output
		$response = new stdClass();
		$response->videos = $videos;

		return $response;	
	}

	/**
	 * Get details of the given video ID.
	 * 
	 * @since  1.0.0
	 * @access private
     * @param  array    $params Array of query params.
     * @return stdClass
     */
    private function request_api_video( $params = array() ) {
		$api_url = $this->get_api_url( 'videos.list' );
		
		$params['id'] = $this->parse_youtube_id_from_url( $params['src'], 'video' );
		
		$api_params = $this->safe_merge_params(
			array(
            	'id'    => '',
				'part'  => 'id,snippet,contentDetails,status',
				'cache' => 0
			), 
			$params
		);

		$api_response = $this->request_api( $api_url, $api_params );
		if ( isset( $api_response->error ) ) {
			return $api_response;
		}

		$videos = $this->parse_videos( $api_response );
		if ( isset( $videos->error ) ) {
			return $videos;
		}

		// Process output
		$response = new stdClass();
		$response->videos = $videos;

		return $response;		
	}	

	/**
	 * Get details of the given video IDs.
	 * 
	 * @since  1.0.0
	 * @access private
     * @param  array    $params Array of query params.
     * @return stdClass
     */
    private function request_api_videos( $params = array() ) {
		$api_url = $this->get_api_url( 'videos.list' );		

		// Accept the video list separated by commas, spaces, or newlines (one per line) in any
		// combination and line-ending style. The old "\n\r" replace looked for LF+CR (reversed),
		// so a one-per-line list never split — it collapsed into a single invalid ID and returned
		// "No videos found". Split on any run of whitespace or commas and drop empties instead.
		$urls = preg_split( '/[\s,]+/', trim( (string) $params['src'] ), -1, PREG_SPLIT_NO_EMPTY );
		$urls = is_array( $urls ) ? $urls : array();

		$all_ids = array();
		foreach ( $urls as $url ) {
			$all_ids[] = $this->parse_youtube_id_from_url( $url, 'video' );
		}
		$total_videos = count( $all_ids );
		$total_pages  = ceil( $total_videos / $params['maxResults'] );

		$current_page = isset( $params['pageToken'] ) ? (int) $params['pageToken'] : 1;
		$current_page = max( $current_page, 1 );
		$current_page = min( $current_page, $total_pages );

		$offset = max( 0, ( $current_page - 1 ) * $params['maxResults'] );

		$current_ids  = array_slice( $all_ids, $offset, $params['maxResults'] );
		$params['id'] = implode( ',', $current_ids );

		$api_params = $this->safe_merge_params(
			array(
            	'id'    => '',
				'part'  => 'id,snippet,contentDetails,status',
				'cache' => 0
			), 
			$params
		);

		$api_response = $this->request_api( $api_url, $api_params );
		if ( isset( $api_response->error ) ) {
			return $api_response;
		}

		$videos = $this->parse_videos( $api_response );
		if ( isset( $videos->error ) ) {
			return $videos;
		}

		// Process output
		$response = new stdClass();
		$response->videos = $videos;

		$response->page_info = array(
			'videos_found' => $total_videos,
			'total_pages'  => $total_pages,
			'paged'        => $current_page
		);

		if ( $current_page > 1 ) {
			$response->page_info['prev_page_token'] = $current_page - 1;
		}

		if ( $current_page < $total_pages ) {
			$response->page_info['next_page_token'] = $current_page + 1;
		}

		return $response;		
	}

	/**
	 * Get videos from our custom database table "{$wpdb->prefix}ayg_videos".
	 * 
	 * @since  2.5.7
	 * @access private
     * @param  array    $params Array of query params.
     * @return stdClass
     */
    private function get_videos_from_db( $params = array() ) {
		global $wpdb;

		$videos_table = $wpdb->prefix . 'ayg_videos';
		$rel_table    = $wpdb->prefix . 'ayg_gallery_relationships';

		$gallery_id = $params['uid'];

		// Base query: every video linked to this gallery. An optional search term (the search
		// form) narrows it by title/description; the "db" source type passes none and gets all.
		$where  = 'r.gallery_id = %s';
		$values = array( $gallery_id );

		if ( ! empty( $params['searchTerm'] ) ) {
			$search_term = '%' . $wpdb->esc_like( $params['searchTerm'] ) . '%';

			$where   .= ' AND (v.title LIKE %s OR v.description LIKE %s)';
			$values[] = $search_term;
			$values[] = $search_term;
		}

		// Optional duration filter. Whitelisted operator; value parameterized. Affects count + select.
		$duration_filter = isset( $params['duration_filter'] ) ? $params['duration_filter'] : '';
		$duration        = isset( $params['duration'] ) ? (int) $params['duration'] : 0;

		if ( $duration > 0 && in_array( $duration_filter, array( 'long', 'short' ), true ) ) {
			$where   .= ( 'long' === $duration_filter ) ? ' AND v.duration_seconds > %d' : ' AND v.duration_seconds < %d';
			$values[] = $duration;
		}

		// Get Total Videos Count
		$total_videos = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM $videos_table AS v
				INNER JOIN $rel_table AS r ON v.video_id = r.video_id
				WHERE $where",
				$values
			)
		);

		if ( empty( $total_videos ) ) {
			return $this->get_error( __( 'No videos found matching your query.', 'automatic-youtube-gallery' ) );
		}

		// Fetch Paginated Videos
		$limit = (int) $params['maxResults'];

		if ( $limit <= 0 ) {
			// 0 = show all videos on a single page (Gallery Builder "unlimited" per page).
			// A LIMIT of the total count avoids the empty result set that LIMIT 0 would return.
			$limit        = (int) $total_videos;
			$total_pages  = 1;
			$current_page = 1;
			$offset       = 0;
		} else {
			$total_pages  = ceil( $total_videos / $limit );

			$current_page = isset( $params['pageToken'] ) ? (int) $params['pageToken'] : 1;
			$current_page = max( $current_page, 1 );
			$current_page = min( $current_page, $total_pages );

			$offset = max( 0, ( $current_page - 1 ) * $limit );
		}

		// Display-time ordering. Whitelisted (ORDER BY can't be parameterized); v.id breaks ties.
		$orderby_map = array(
			'date'     => 'v.published_at_datetime',
			'title'    => 'v.title',
			'duration' => 'v.duration_seconds'
		);

		$sort_by_raw = isset( $params['sort_by'] ) ? $params['sort_by'] : 'date';
		$sort_order  = ( isset( $params['sort_order'] ) && 'asc' === strtolower( $params['sort_order'] ) ) ? 'ASC' : 'DESC';

		// Random: a seed (set per render in ayg_build_gallery) makes RAND(seed) stable across this
		// gallery's own pagination/search, so pages don't repeat or skip videos.
		$random_seed = 0;

		if ( 'random' === $sort_by_raw ) {
			$random_seed = isset( $params['sort_seed'] ) ? (int) $params['sort_seed'] : 0;
			$order_by    = ( $random_seed > 0 ) ? 'RAND(%d)' : 'RAND()';
		} else {
			$sort_by  = isset( $orderby_map[ $sort_by_raw ] ) ? $orderby_map[ $sort_by_raw ] : 'v.published_at_datetime';
			$order_by = "$sort_by $sort_order, v.id $sort_order";
		}

		// Deeplinked video: pin it to the top of the list so page 1 starts with the shared video
		// while the per-page count stays exact. The pin reorders the whole list (not just page 1),
		// so paginated AJAX requests passing the same id never repeat or skip videos. Ignored
		// while searching — search results are a fresh listing of their own.
		$featured_video_id = '';

		if ( empty( $params['searchTerm'] ) && ! empty( $params['featured_video_id'] ) ) {
			$featured_video_id = (string) $params['featured_video_id'];
			$order_by          = '(v.video_id = %s) DESC, ' . $order_by;
		}

		// Assemble placeholder values in SQL order: WHERE ..., [featured video], [seed], LIMIT, OFFSET.
		$query_values = $values;

		if ( '' !== $featured_video_id ) {
			$query_values[] = $featured_video_id;
		}

		if ( $random_seed > 0 ) {
			$query_values[] = $random_seed;
		}

		$query_values[] = $limit;
		$query_values[] = $offset;

		$query = $wpdb->prepare(
			"SELECT v.*
			FROM $videos_table AS v
			INNER JOIN $rel_table AS r ON v.video_id = r.video_id
			WHERE $where
			ORDER BY $order_by
			LIMIT %d OFFSET %d",
			$query_values
		);

		$videos = $wpdb->get_results( $query );

		if ( empty( $videos ) ) {
			return $this->get_error( __( 'No videos found matching your query.', 'automatic-youtube-gallery' ) );
		}

		foreach ( $videos as $index => $video ) {
			if ( ! empty( $video->thumbnails ) ) {
				$videos[ $index ]->thumbnails = ayg_maybe_unserialize( $video->thumbnails );
			}

			// Backward compat: templates reference $video->id as the YouTube video ID.
			$videos[ $index ]->id = $video->video_id;
		}

		// Process output
		$response = new stdClass();
		$response->videos = $videos;

		$response->page_info = array(
			'videos_found' => $total_videos,
			'total_pages'  => $total_pages,
			'paged'        => $current_page
 		);

		if ( $current_page > 1 ) {
			$response->page_info['prev_page_token'] = $current_page - 1;
		}

		if ( $current_page < $total_pages ) {
			$response->page_info['next_page_token'] = $current_page + 1;
		}

		return $response;		
	}
	
	/**
     * Get API URL by request.
	 *
	 * @since  1.0.0
	 * @access private
     * @param  array   $name
     * @return string
     */
    private function get_api_url( $name ) {
        return $this->api_urls[ $name ];
	}	

	/**
     * Request data from the API server.
     *
	 * @since  1.0.0
	 * @access private
     * @param  string  $url     YouTube API URL.
     * @param  array   $params  Array of query params.
	 * @param  string  $context "channel_id", "playlist_id", "videos", or "live"
     * @return mixed     
     */
    private function request_api( $url, $params, $context = 'videos' ) {
		$params['key'] = $this->api_key;	
		
		// Build API URL
		$cache_duration = 0;		
		if ( isset( $params['cache'] ) ) {
			$cache_duration = (int) $params['cache'];
			unset( $params['cache'] );
		}
		$cache_duration = min( $cache_duration, 2419200 ); // Max cache duration: 1 Month

		// Every parameter — the search term "q" included — goes through http_build_query() so it is
		// URL encoded. "q" used to be appended to the URL raw, which meant a caller could smuggle
		// extra parameters into the outbound request by putting "&" in the search keywords.
		$api_url = $url . ( strpos( $url, '?' ) === false ? '?' : '&' ) . http_build_query( $params );

		// Prefix the cache key with the gallery uid so a single gallery's transients can be cleared
		// on demand (e.g. saving a live search/livestream gallery) via ayg_delete_cache( $uid ).
		$cache_uid = isset( $this->params['uid'] ) ? (string) $this->params['uid'] : '';
		$cache_key = 'ayg_' . ( '' !== $cache_uid ? $cache_uid . '_' : '' ) . md5( $api_url );

		// Request from cache
		if ( ! $this->is_development_mode && $cache_duration > 0 ) {
			$cache_data = get_transient( $cache_key );

			if ( ! empty( $cache_data ) ) {
				return $cache_data;
			}		
		}

		// Request from API
		$timeout = apply_filters( 'ayg_api_request_timeout', 15 );
		
		$request = wp_remote_get( $api_url, array(
			'headers' => [ 'referer' => home_url() ],
			'timeout' => $timeout, // Increase timeout if needed
		) );

		if ( is_wp_error( $request ) ) {
			return $this->get_error( $request->get_error_message() );
		}

		$body = wp_remote_retrieve_body( $request );
		$data = json_decode( $body );

		if ( empty( $data ) ) {
			return $this->get_error( __( 'Empty or invalid API response', 'automatic-youtube-gallery' ) );
		}

		if ( isset( $data->error ) ) {
			$message = "Error " . $data->error->code . " " . $data->error->message;
			
			if ( isset( $data->error->errors[0] ) ) {
				$message .= " : " . $data->error->errors[0]->reason;
			}
			
			return $this->get_error( $message );			
		}

		// Store in cache (transients)
		$cache_enabled = false;

		if ( ! $this->is_development_mode && $cache_duration > 0 ) {
			if ( 'videos' === $context ) {
				if ( ! empty( $data->items ) && is_array( $data->items ) ) {	
					$cache_enabled = true;
				}
			}

			if ( 'live' === $context ) {
				$cache_enabled = true;
			}
		}

		if ( $cache_enabled ) {
			set_transient( $cache_key, $data, $cache_duration );

			// Get the current list of transients
			$cache_keys = ayg_get_option( 'ayg_transient_keys' );

			// Append our new one
			if ( ! in_array( $cache_key, $cache_keys ) ) {
				$cache_keys[] = $cache_key;
			}

			// Save it to the DB (autoload=no: this list can grow large and is not needed on every page load)
			update_option( 'ayg_transient_keys', $cache_keys, false );
		}		

		// Store videos in our custom database table "{$wpdb->prefix}ayg_videos"
		//
		// What reaches the storage layer is rebuilt from an explicit allowlist instead of being
		// handed the raw request params, so the allowlist that governs the outbound request now
		// governs the database write made from the same function. Gallery membership ("uid") is
		// the value that decides what a gallery displays, so it is only passed along when the
		// caller opted in through "store" — never on the strength of a "uid" alone.
		$store_attributes = array(
			'type'    => isset( $this->params['type'] ) ? $this->params['type'] : '',
			'exclude' => ( isset( $this->params['exclude'] ) && is_array( $this->params['exclude'] ) ) ? $this->params['exclude'] : array()
		);

		if ( $this->can_store && ! empty( $this->params['uid'] ) ) {
			$store_attributes['uid'] = (string) $this->params['uid'];
		}

		ayg_db_store_videos( $data, $store_attributes );

		// Finally return the data
		return $data;
	}

	/**
     * Parse videos from the YouTube API response object.
     *
	 * @since  1.0.0
	 * @access private
     * @param  object  $data YouTube API response object.
     * @return mixed
     */
    private function parse_videos( $data ) {
		if ( empty( $data->items ) || ! is_array( $data->items ) ) {
			$error = $this->get_error( __( 'No videos found matching your query.', 'automatic-youtube-gallery' ) );

			// Flag an empty result set so an incremental sync can tell "nothing new" apart
			// from a genuine API failure (quota, bad source) and complete cleanly.
			$error->no_results = true;

			return $error;
		}

		$items   = $data->items;
		$videos  = array();
		$exclude = ( isset( $this->params['exclude'] ) && is_array( $this->params['exclude'] ) ) ? $this->params['exclude'] : array();

		foreach ( $items as $item ) {
			$video = new stdClass();

			// Video ID
			$video->id = '';

			if ( isset( $item->snippet->resourceId ) && isset( $item->snippet->resourceId->videoId ) ) {
				$video->id = $item->snippet->resourceId->videoId;
			} elseif ( isset( $item->contentDetails ) && isset( $item->contentDetails->videoId ) ) {
				$video->id = $item->contentDetails->videoId;
			} elseif ( isset( $item->id ) && isset( $item->id->videoId ) ) {
				$video->id = $item->id->videoId;
			} elseif ( isset( $item->id ) ) {
				$video->id = $item->id;
			}

			// Skip videos on the gallery's exclude list
			if ( ayg_is_video_excluded( $video->id, $exclude ) ) {
				continue;
			}

			// Video channel ID
			$video->channel_id = '';
			
			if ( isset( $item->snippet->channelId ) ) {
				$video->channel_id = $item->snippet->channelId;
			}

			// Video title
			$video->title = $item->snippet->title;

			// Video description
			$video->description = $item->snippet->description;

			// Video thumbnails
			if ( isset( $item->snippet->thumbnails ) ) {
				$video->thumbnails = $item->snippet->thumbnails;
			}		

			// Video publish date
			$video->published_at = $item->snippet->publishedAt;

			// Push resulting object to the main array
			$status = 'private';
			
			if ( isset( $item->status ) && ( 'public' == $item->status->privacyStatus || 'unlisted' == $item->status->privacyStatus ) ) {
				$status = 'public';				
			}

			if ( isset( $item->snippet->status ) && ( 'public' == $item->snippet->status->privacyStatus || 'unlisted' == $item->snippet->status->privacyStatus ) ) {
				$status = 'public';				
			}

			if ( 'youtube#searchResult' == $item->kind ) {
				$status = 'public';				
			}

			if ( 'public' == $status ) {
				$videos[] = $video;
			}
		}

		if ( 0 == count( $videos ) ) {
			return $this->get_error( __( 'No videos found matching your query.', 'automatic-youtube-gallery' ) );
		}

		return $videos;		
	}

	/**
	 * Enrich parsed videos with duration and live broadcast details.
	 *
	 * playlistItems.list / search.list responses lack contentDetails.duration and
	 * snippet.liveBroadcastContent, so this makes a supplementary videos.list request (1 quota
	 * unit per 50 IDs) — updating the stored rows and merging duration + video_type into $videos.
	 *
	 * @since  2.8.0
	 * @access private
	 * @param  array   $videos Parsed video objects from parse_videos().
	 * @return array           The same videos, with duration / duration_seconds / video_type set.
	 */
	private function enrich_video_details( $videos ) {
		// Index by video ID for the merge. PHP holds objects by handle, so mutating a $map
		// entry below also updates the same instance in $videos (what we return).
		$map = array();

		foreach ( $videos as $video ) {
			if ( ! empty( $video->id ) ) {
				$map[ $video->id ] = $video;
			}
		}

		if ( empty( $map ) ) {
			return $videos;
		}

		$api_url = $this->get_api_url( 'videos.list' );

		// videos.list accepts up to 50 IDs per request.
		foreach ( array_chunk( array_keys( $map ), 50 ) as $chunk ) {
			$api_params = array(
				'id'   => implode( ',', $chunk ),
				'part' => 'id,snippet,contentDetails,status'
			);

			// request_api() also stores the enriched rows (videos.list response →
			// ayg_db_store_videos() writes duration_seconds + video_type).
			$api_response = $this->request_api( $api_url, $api_params );

			if ( isset( $api_response->error ) || empty( $api_response->items ) || ! is_array( $api_response->items ) ) {
				continue;
			}

			foreach ( $api_response->items as $item ) {
				if ( empty( $item->id ) || ! isset( $map[ $item->id ] ) ) {
					continue;
				}

				$duration = isset( $item->contentDetails->duration ) ? $item->contentDetails->duration : '';

				$map[ $item->id ]->duration         = $duration;
				$map[ $item->id ]->duration_seconds = ayg_parse_duration_seconds( $duration );
				$map[ $item->id ]->video_type       = ! empty( $item->snippet->liveBroadcastContent ) ? $item->snippet->liveBroadcastContent : 'none';
			}
		}

		return $videos;
	}

	/**
     * Parse page info from the YouTube API response object.
     *
	 * @since  1.0.0
	 * @access private
     * @param  object  $data YouTube API response object.
     * @return array
     */
    private function parse_page_info( $data ) {
		$page_info = array(
			'videos_found' => 0
		);

		// Total number of videos found
		if ( isset( $data->pageInfo ) && isset( $data->pageInfo->totalResults ) ) {
			$page_info['videos_found'] = (int) $data->pageInfo->totalResults;
		}
		
		// Calculate total number of pages
		if ( $page_info['videos_found'] > 0 ) {
			if ( 'search' == $this->params['type'] ) {
				$limit = min( (int) $this->params['limit'], $page_info['videos_found'] );
				$page_info['total_pages'] = ceil( $limit / (int) $this->params['maxResults'] );
			} else {
				$max_results = (int) $this->params['maxResults'];
				$page_info['total_pages'] = ( $max_results <= 0 ) ? 1 : ceil( $page_info['videos_found'] / $max_results );
			}
		}

		// Token for the previous page
		if ( isset( $data->prevPageToken ) ) {
			$page_info['prev_page_token'] = $data->prevPageToken;
		}
		
		// Token for the next page
		if ( isset( $data->nextPageToken ) ) {
			$page_info['next_page_token'] = $data->nextPageToken;
		}

		return $page_info;
	}

	/**
	 * Combine user params with known params and fill in defaults when needed.
	 *
	 * @since  1.0.0
	 * @access private
	 * @param  array   $pairs  Entire list of supported params and their defaults.
	 * @param  array   $params User defined params.
	 * @return array   $out    Combined and filtered params array.
	*/
	private function safe_merge_params( $pairs, $params ) {
		$params = (array) $params;
		$out = array();
		
    	foreach ( $pairs as $name => $default ) {
        	if ( array_key_exists( $name, $params ) ) {
				$out[ $name ] = $params[ $name ];
			} else {
				$out[ $name ] = $default;
			}

			if ( empty( $out[ $name ] ) ) {
				unset( $out[ $name ] );
			}
		}
		
		return $out;
	}

	/**
	 * Build error object.
	 *
	 * @since  1.0.0
	 * @access private
	 * @param  string  $message Error message.
	 * @return object           Error object.
	*/
	private function get_error( $message ) {
		$obj = new stdClass();
		$obj->error = 1;
		$obj->error_message = $message;

		return $obj;
	}
	
}
