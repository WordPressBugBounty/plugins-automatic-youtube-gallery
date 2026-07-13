<?php

/**
 * Helper Functions.
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
 * Build gallery HTML output.
 *
 * @since  1.0.0
 * @param  array $args An array of gallery options.
 * @return mixed
 */
function ayg_build_gallery( $args ) {
	$general_settings = ayg_get_option( 'ayg_general_settings' );
	$strings_settings = ayg_get_option( 'ayg_strings_settings' );

	global $post;

	// Vars
	$fields   = ayg_get_editor_fields();
	$excluded = array( 'popup' ); // Fields not part of attributes

	// Defaults not backed by an editor field (kept through shortcode_atts): internal "db" source + sort.
	$defaults = array(
		'db'              => '',
		'sort_by'         => 'date',
		'sort_order'      => 'desc',
		'sort_seed'       => '',
		'duration_filter' => '',
		'duration'        => 0
	);

	foreach ( $fields as $key => $value ) {
		foreach ( $value['fields'] as $field ) {
			if ( in_array( $field['name'], $excluded ) ) {
				continue;
			}

			$defaults[ $field['name'] ] = $field['value'];
		}
	}

	$defaults = array_merge( $defaults, (array) $strings_settings );

	// Gallery Builder mode: a numeric "id" loads the saved gallery config, layers its saved
	// settings under $args (shortcode attributes still win, except the source), and switches
	// the source to the internal "db" type so the existing build pipeline serves it from the DB.
	if ( isset( $args['id'] ) && is_numeric( $args['id'] ) && (int) $args['id'] > 0 ) {
		$config = ayg_get_gallery( (int) $args['id'] );

		if ( ! $config ) {
			return sprintf( '<div class="ayg ayg-error">%s</div>', esc_html__( 'Gallery not found.', 'automatic-youtube-gallery' ) );
		}

		$config_params = json_decode( (string) $config->params, true );
		if ( ! is_array( $config_params ) ) {
			$config_params = array();
		}

		// Saved settings act as defaults; explicit shortcode attributes override them.
		$args = array_merge( $config_params, $args );

		// The source always comes from the config and can't be overridden by the shortcode.
		// Livestream and search query the live API at display time (nothing is imported/stored);
		// everything else renders from the DB.
		if ( 'livestream' === $config->source_type ) {
			$args['type']    = 'livestream';
			$args['channel'] = $config->source_value;
		} elseif ( 'search' === $config->source_type ) {
			$args['type']   = 'search';
			$args['search'] = $config->source_value;
		} elseif ( 'video' === $config->source_type ) {
			// A single video renders live from the API (like search / livestream) — nothing is
			// imported or stored — so serve it through the live 'video' source type.
			$args['type']  = 'video';
			$args['video'] = $config->source_value;
		} else {
			$args['type'] = 'db';
			$args['db']   = strval( $config->id );
		}

		// Single video + popup mode → force the popup theme, mirroring the block and widget (which
		// apply this before calling ayg_build_gallery()).
		if ( 'video' === $config->source_type && ! empty( $args['popup'] ) ) {
			$args['theme'] = 'popup';
		}

		// Gallery Builder relationships are keyed by the config ID as a string.
		$args['uid'] = strval( $config->id );
	}

	$attributes = shortcode_atts( $defaults, $args, 'automatic_youtube_gallery' );

	$attributes['post_id'] = 0;
	if ( isset( $post->ID ) ) {
		$attributes['post_id'] = (int) $post->ID;
	}

	$attributes['columns'] = min( 12, (int) $attributes['columns'] );
	if ( empty( $attributes['columns'] ) ) {
		$attributes['columns'] = 3;
	}

	$attributes['limit'] = min( 500, (int) $attributes['limit'] );
	if ( empty( $attributes['limit'] ) ) {
		$attributes['limit'] = 500;
	}
	
	$source_type = sanitize_text_field( $attributes['type'] );

	// Videos per page. Database (Gallery Builder) galleries read from our own table, so 0 means
	// "show all videos on a single page" (no pagination). The live-API sources (search, livestream)
	// stay capped at 50 because that is YouTube's maximum number of results per request.
	if ( 'db' === $source_type ) {
		$attributes['per_page'] = max( 0, (int) $attributes['per_page'] );
	} else {
		$attributes['per_page'] = min( 50, (int) $attributes['per_page'] );
		if ( empty( $attributes['per_page'] ) ) {
			$attributes['per_page'] = 50;
		}
	}

	if ( 'livestream' == $source_type ) {
		$attributes['livestream'] = $attributes['channel'];
	}

	$attributes['lazyload'] = 0;
	if ( ! empty( $general_settings['lazyload'] ) ) {
		$attributes['lazyload'] = 1;
	}

	$source_url = sanitize_text_field( $attributes[ $source_type ] );	

	if ( isset( $args['id'] ) && ! empty( $args['id'] ) ) {
		$attributes['id'] = absint( $args['id'] );
	}

	if ( isset( $args['uid'] ) && ! empty( $args['uid'] ) ) {
		$attributes['uid'] = sanitize_text_field( $args['uid'] );
	} else {
		$attributes['uid'] = md5( $source_type . $source_url );
	}

	$attributes['uid'] = apply_filters( 'ayg_gallery_id', $attributes['uid'], $args );

	// Deprecated since v2.5.8. Retained for backward compatibility.
	$deprecated_uid = md5( $source_type . $source_url . sanitize_text_field( $attributes['theme'] ) ); // Deprecated
	if ( isset( $args['deprecated_uid'] ) && ! empty( $args['deprecated_uid'] ) ) {
		$deprecated_uid = sanitize_text_field( $args['deprecated_uid'] );
	}

	// Deeplinked video (premium): resolve before the query so DB-served galleries can pin it as
	// the first item at the SQL level, keeping the per-page count exact. API-served (legacy)
	// galleries keep the old prepend behavior below.
	$gallery_id_from_url = get_query_var( 'ayg_gallery_id' );
	$video_id_from_url   = get_query_var( 'ayg_video_id' );
	$deeplinked_video    = false;

	if ( ! empty( $video_id_from_url ) && ( $attributes['uid'] == $gallery_id_from_url || $deprecated_uid == $gallery_id_from_url ) ) {
		$deeplinked_video = ayg_db_get_video( $video_id_from_url );
	}

	if ( ! $deeplinked_video ) {
		$video_id_from_url = '';
	}

	$featured_video_id = ( 'db' === $source_type && ! empty( $video_id_from_url ) ) ? sanitize_text_field( $video_id_from_url ) : '';

	// Random ordering: generate a per-render seed (once) so RAND(seed) stays stable across this
	// gallery's own pagination/search AJAX calls. A seed hardcoded in the shortcode is respected.
	if ( 'random' === $attributes['sort_by'] && empty( $attributes['sort_seed'] ) ) {
		$attributes['sort_seed'] = wp_rand( 1, 2147483647 );
	}
	
	// Get Videos
	$api_params = array(
		'uid'               => $attributes['uid'],
		'type'              => $source_type,
		'src'               => $source_url,
		'featured_video_id' => $featured_video_id,                             // Works only when type = "db".
		'order'             => sanitize_text_field( $attributes['order'] ),    // Works only when type = "search".
		'sort_by'           => sanitize_key( $attributes['sort_by'] ),         // Works only when type = "db".
		'sort_order'        => sanitize_key( $attributes['sort_order'] ),      // Works only when type = "db".
		'sort_seed'         => absint( $attributes['sort_seed'] ),             // Works only when type = "db" + sort_by = "random".
		'duration_filter'   => sanitize_key( $attributes['duration_filter'] ), // Works only when type = "db".
		'duration'          => absint( $attributes['duration'] ),              // Works only when type = "db".
		'limit'             => $attributes['limit'],                           // Works only when type = "search".
		'maxResults'        => $attributes['per_page'],
		'cache'             => (int) $attributes['cache']
	);

	$api_params = apply_filters( 'ayg_youtube_api_request_params', $api_params, $args );

	$youtube_api = new AYG_YouTube_API();
	$response = $youtube_api->query( $api_params );

	// Process output
	if ( ! isset( $response->error ) ) {
		// Store Gallery ID
		if ( $attributes['post_id'] > 0 && isset( $attributes['deeplinking'] ) && 1 == $attributes['deeplinking'] ) {
			$pages   = ayg_get_option( 'ayg_gallery_page_ids' );
			$page_id = $attributes['post_id'];

			if ( ! in_array( $page_id, $pages ) ) {
				$pages[] = $page_id;
				update_option( 'ayg_gallery_page_ids', $pages );
			}		
		}
		
		// Gallery
		$videos = array();

		if ( ! empty( $video_id_from_url ) ) {
			// DB-served galleries pin the deeplinked video via the SQL query itself, so the result
			// set already starts with it. Fall back to prepending it only when the pin couldn't
			// apply: API-served (legacy) sources, or the video is no longer part of this gallery's
			// result set (removed from the gallery, or excluded by a duration filter).
			$is_pinned_by_sql = ! empty( $api_params['featured_video_id'] )	&& ! empty( $response->videos )	&& $response->videos[0]->id == $video_id_from_url;

			if ( ! $is_pinned_by_sql ) {
				$videos[] = $deeplinked_video;
			}
		}

		if ( isset( $response->videos ) ) {
			if ( ! empty( $videos ) ) {
				foreach ( $response->videos as $video ) {
					if ( $video->id != $video_id_from_url ) {
						$videos[] = $video;
					}
				}
			} else {
				$videos = $response->videos;
			}
		}

		// Pagination
		if ( isset( $response->page_info ) ) {
			$attributes = array_merge( $attributes, $api_params, $response->page_info );
		}

		// Theme
		$theme = 'classic';

		if ( 'video' == $source_type ) {
			$theme = 'single';
		} elseif ( 'livestream' == $source_type ) {
			$theme = 'livestream';
		} else {
			if ( 1 == count( $videos ) ) {
				$theme = 'single';
			}
		}

		// Enqueue dependencies
		wp_enqueue_style( AYG_SLUG . '-public' );

		wp_enqueue_script( AYG_SLUG . '-public' );		
		if ( $attributes['theme'] == $theme && 'classic' == $attributes['theme'] ) {
			wp_enqueue_script( AYG_SLUG . '-theme-classic' );
		}

		// Output
		ob_start();
		include ayg_get_template( AYG_DIR . "public/templates/theme-{$theme}.php", $attributes['theme'] );
		return ob_get_clean();
	} else {
		return sprintf(	'<div class="ayg ayg-error">%s</div>', wp_kses_post( $response->error_message )	);
	}
}

/**
 * Combine video attributes as a string.
 * 
 * @since 2.5.0
 * @param array  $atts Array of video attributes.
 * @param string       Combined attributes string.
 */
function ayg_combine_video_attributes( $atts ) {
	$attributes = array();
	
	foreach ( $atts as $key => $value ) {
		if ( '' === $value ) {
			$attributes[] = $key;
		} else {
			$attributes[] = sprintf( '%s="%s"', $key, $value );
		}
	}
	
	return implode( ' ', $attributes );
}

/**
 * Create custom database tables
 *
 * @since 2.1.0
 */
function ayg_db_create_custom_tables() {
	global $wpdb;

	require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
	$charset_collate = $wpdb->get_charset_collate();

	$videos_table = $wpdb->prefix . 'ayg_videos';
	$rel_table    = $wpdb->prefix . 'ayg_gallery_relationships';
	$old_table    = $wpdb->prefix . 'ayg_galleries';

	// Rename wp_ayg_galleries (old pivot) → wp_ayg_gallery_relationships.
	// Guard: only when the old table exists AND the new name does not yet exist AND
	// the old table is the pivot (identified by having a video_id column).
	$old_exists    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $old_table ) ) ) === $old_table;
	$new_not_exist = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $rel_table ) ) ) !== $rel_table;

	if ( $old_exists && $new_not_exist ) {
		$is_old_pivot = ! empty( $wpdb->get_results( "SHOW COLUMNS FROM `$old_table` LIKE 'video_id'" ) );

		if ( $is_old_pivot ) {
			$renamed = $wpdb->query( "RENAME TABLE `$old_table` TO `$rel_table`" );

			if ( false === $renamed ) {
				// Rename failed (insufficient DB privilege on some hosts).
				// This table holds auto-generated cache data, not user content — safe to drop.
				$wpdb->query( "DROP TABLE IF EXISTS `$old_table`" );
			}
		}
	}

	// Rename columns in wp_ayg_videos (NUM → id, id → video_id).
	// Each ALTER is guarded so the block is idempotent on re-runs.
	$videos_table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $videos_table ) ) ) === $videos_table;

	if ( $videos_table_exists ) {
		// Rename 'id' → 'video_id' first. Must happen before NUM → id to avoid a duplicate
		// column name error: if NUM were renamed to 'id' while the varchar 'id' still exists,
		// MySQL would reject it with "Duplicate column name 'id'".
		$old_id_col = $wpdb->get_row( "SHOW COLUMNS FROM `$videos_table` LIKE 'id'" );

		if ( $old_id_col && false !== strpos( strtolower( $old_id_col->Type ), 'varchar' ) ) {
			$wpdb->query( "ALTER TABLE `$videos_table` CHANGE `id` `video_id` varchar(100) NOT NULL" );
		}

		// Now safe to rename NUM → id (no column named 'id' exists at this point).
		$has_num = ! empty( $wpdb->get_results( "SHOW COLUMNS FROM `$videos_table` LIKE 'NUM'" ) );

		if ( $has_num ) {
			$wpdb->query( "ALTER TABLE `$videos_table` CHANGE `NUM` `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT" );
		}

		// Remove duplicate rows using the new column names (idempotent).
		$wpdb->query(
			"DELETE v1 FROM $videos_table v1
			INNER JOIN $videos_table v2
			ON v1.video_id = v2.video_id AND v1.id > v2.id"
		);
	}

	// Create/update wp_ayg_videos — dbDelta adds the 2 new columns on existing installs.
	$sql = "CREATE TABLE $videos_table (
		id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		video_id varchar(100) NOT NULL,
		title text NOT NULL,
		description text NOT NULL,
		thumbnails text NOT NULL,
		duration varchar(25) NOT NULL,
		duration_seconds int(11) UNSIGNED NOT NULL DEFAULT 0,
		video_type varchar(20) NOT NULL DEFAULT 'none',
		status varchar(100) NOT NULL,
		published_at varchar(100) NOT NULL,
		published_at_datetime datetime NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY ayg_unique_video_id (video_id),
		INDEX ayg_idx_published_at_datetime (published_at_datetime)
	) $charset_collate;";

	dbDelta( $sql );

	// Fallback: add UNIQUE KEY if dbDelta did not create it (edge case on existing installs).
	$existing_keys = $wpdb->get_results( "SHOW KEYS FROM $videos_table WHERE Key_name = 'ayg_unique_video_id'" );
	
	if ( empty( $existing_keys ) ) {
		$wpdb->query( "ALTER TABLE $videos_table ADD UNIQUE KEY ayg_unique_video_id (video_id)" );
	}

	// Fallback: add INDEX on published_at_datetime if missing (existing installs).
	$existing_dt_index = $wpdb->get_results( "SHOW KEYS FROM $videos_table WHERE Key_name = 'ayg_idx_published_at_datetime'" );
	
	if ( empty( $existing_dt_index ) ) {
		$wpdb->query( "ALTER TABLE $videos_table ADD INDEX ayg_idx_published_at_datetime (published_at_datetime)" );
	}

	// Rename NUM → id in wp_ayg_gallery_relationships (only if rename succeeded above).
	// If the table was recreated fresh by dbDelta below, it already has 'id' — no ALTER needed.
	$rel_table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $rel_table ) ) ) === $rel_table;

	if ( $rel_table_exists ) {
		$has_num_in_rel = ! empty( $wpdb->get_results( "SHOW COLUMNS FROM `$rel_table` LIKE 'NUM'" ) );
		
		if ( $has_num_in_rel ) {
			$wpdb->query( "ALTER TABLE `$rel_table` CHANGE `NUM` `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT" );
		}
	}

	// Create/update wp_ayg_gallery_relationships (fresh creation if the rename fallback dropped the old table).
	$sql = "CREATE TABLE $rel_table (
		id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		video_id varchar(100) NOT NULL,
		gallery_id varchar(100) NOT NULL,
		synced_at datetime NULL,
		PRIMARY KEY  (id),
		UNIQUE KEY ayg_unique_video_gallery (video_id, gallery_id),
		INDEX ayg_idx_gallery_id (gallery_id)
	) $charset_collate;";

	dbDelta( $sql );

	// Fallback: add the synced_at column if dbDelta did not (existing installs).
	$has_synced_at = ! empty( $wpdb->get_results( "SHOW COLUMNS FROM `$rel_table` LIKE 'synced_at'" ) );

	if ( ! $has_synced_at ) {
		$wpdb->query( "ALTER TABLE `$rel_table` ADD `synced_at` datetime NULL" );
	}

	// Create new wp_ayg_galleries entity table (one row per saved gallery config).
	$galleries_table = $wpdb->prefix . 'ayg_galleries';

	$sql = "CREATE TABLE $galleries_table (
		id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		title text NOT NULL,
		source_type varchar(50) NOT NULL DEFAULT 'channel',
		source_value text NOT NULL,
		params longtext NOT NULL,
		import_status varchar(20) NOT NULL DEFAULT 'idle',
		import_error text NULL,
		import_log longtext NULL,
		last_imported_at datetime NULL,
		next_import_at datetime NULL,
		video_count int(11) UNSIGNED NOT NULL DEFAULT 0,
		created_at datetime NOT NULL,
		updated_at datetime NOT NULL,
		PRIMARY KEY  (id),
		INDEX idx_import (next_import_at, import_status)
	) $charset_collate;";

	dbDelta( $sql );
}

/**
 * Get a single video record from our custom database table "{$wpdb->prefix}ayg_videos" 
 *
 * @since  2.1.0
 * @param  string $video_id YouTube Video ID.
 * @return mixed
 */
function ayg_db_get_video( $video_id ) {
	global $wpdb;

	$cache_key = 'ayg_'. $video_id;
	$row = wp_cache_get( $cache_key );

	if ( false === $row ) {
		$query = $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ayg_videos WHERE video_id = %s", $video_id );
		$row = $wpdb->get_row( $query );

		if ( $row ) {
			if ( ! empty( $row->thumbnails ) ) {
				$row->thumbnails = unserialize( $row->thumbnails );
			}

			// Backward compat: templates reference $video->id as the YouTube video ID.
			$row->id = $row->video_id;

			wp_cache_set( $cache_key, $row );
		}
	}

	return $row;
}

/**
 * Store videos in our custom database table "{$wpdb->prefix}ayg_videos" 
 *
 * @since 2.1.0
 * @param object $data       YouTube API response object.
 * @param array  $attributes Array of user attributes.
 */
function ayg_db_store_videos( $data, $attributes = array() ) {
	if ( AYG_VERSION !== get_option( 'ayg_version' ) ) {
		return false;
	}

	if ( isset( $data->kind ) && 'youtube#channelListResponse' == $data->kind ) {
		return false;				
	}

	if ( empty( $data->items ) || ! is_array( $data->items ) ) {
		return false;
	}

	global $wpdb;

	$items                = $data->items;
	$gallery_id           = isset( $attributes['uid'] ) ? $attributes['uid'] : '';
	$source_type          = isset( $attributes['type'] ) ? $attributes['type'] : 'videos';
	$store_gallery        = ! empty( $gallery_id ) && 'livestream' !== $source_type;
	$exclude              = ( isset( $attributes['exclude'] ) && is_array( $attributes['exclude'] ) ) ? $attributes['exclude'] : array();

	// Only videos.list responses carry contentDetails.duration and snippet.liveBroadcastContent.
	// For other responses (playlistItems.list, search.list) the duration_seconds / video_type
	// columns are left out entirely so existing values are never clobbered with defaults.
	$has_video_details    = isset( $data->kind ) && 'youtube#videoListResponse' === $data->kind;

	$videos_table         = $wpdb->prefix . 'ayg_videos';
	$galleries_table      = $wpdb->prefix . 'ayg_gallery_relationships';

	$video_placeholders   = array();
	$video_values         = array();
	$gallery_placeholders = array();
	$gallery_values       = array();

	foreach ( $items as $item ) {
		$row = array();

		// Video ID
		$row['video_id'] = '';

		if ( isset( $item->snippet->resourceId ) && isset( $item->snippet->resourceId->videoId ) ) {
			$row['video_id'] = $item->snippet->resourceId->videoId;
		} elseif ( isset( $item->contentDetails ) && isset( $item->contentDetails->videoId ) ) {
			$row['video_id'] = $item->contentDetails->videoId;
		} elseif ( isset( $item->id ) && isset( $item->id->videoId ) ) {
			$row['video_id'] = $item->id->videoId;
		} elseif ( isset( $item->id ) ) {
			$row['video_id'] = $item->id;
		}

		if ( empty( $row['video_id'] ) ) {
			continue;
		}

		// Skip videos on the gallery's exclude list (kept out of both tables)
		if ( ayg_is_video_excluded( $row['video_id'], $exclude ) ) {
			continue;
		}

		// Video title
		$row['title'] = $item->snippet->title;

		// Video description
		$row['description'] = $item->snippet->description;

		// Video thumbnails
		$row['thumbnails'] = '';
		if ( isset( $item->snippet->thumbnails ) ) {
			$row['thumbnails'] = serialize( $item->snippet->thumbnails );
		}

		// Video duration
		$row['duration'] = '';
		if ( isset( $item->contentDetails ) && isset( $item->contentDetails->duration ) ) {
			$row['duration'] = $item->contentDetails->duration;
		}

		if ( $has_video_details ) {
			$row['duration_seconds'] = ayg_parse_duration_seconds( $row['duration'] );

			// Raw liveBroadcastContent value ('live', 'upcoming', 'none'); shorts are not
			// classified at import time — filter by duration_seconds <= 60 at query time.
			$row['video_type'] = 'none';
			if ( ! empty( $item->snippet->liveBroadcastContent ) ) {
				$row['video_type'] = $item->snippet->liveBroadcastContent;
			}
		}

		// Video status
		$row['status'] = 'private';

		if ( isset( $item->status ) && ( 'public' == $item->status->privacyStatus || 'unlisted' == $item->status->privacyStatus ) ) {
			$row['status'] = 'public';
		}

		if ( isset( $item->snippet->status ) && ( 'public' == $item->snippet->status->privacyStatus || 'unlisted' == $item->snippet->status->privacyStatus ) ) {
			$row['status'] = 'public';
		}

		if ( 'youtube#searchResult' == $item->kind ) {
			$row['status'] = 'public';
		}

		// Video publish date
		$row['published_at'] = $item->snippet->publishedAt;

		$datetime = new DateTime( $item->snippet->publishedAt );
		$row['published_at_datetime'] = date_format( $datetime, 'Y-m-d H:i:s' );

		// Collect for bulk insert
		if ( $has_video_details ) {
			$video_placeholders[] = '(%s, %s, %s, %s, %s, %d, %s, %s, %s, %s)';

			array_push(
				$video_values,
				$row['video_id'],
				$row['title'],
				$row['description'],
				$row['thumbnails'],
				$row['duration'],
				$row['duration_seconds'],
				$row['video_type'],
				$row['status'],
				$row['published_at'],
				$row['published_at_datetime']
			);
		} else {
			$video_placeholders[] = '(%s, %s, %s, %s, %s, %s, %s, %s)';

			array_push(
				$video_values,
				$row['video_id'],
				$row['title'],
				$row['description'],
				$row['thumbnails'],
				$row['duration'],
				$row['status'],
				$row['published_at'],
				$row['published_at_datetime']
			);
		}

		if ( $store_gallery ) {
			$gallery_placeholders[] = '(%s, %s)';
			array_push( $gallery_values, $row['video_id'], $gallery_id );
		}
	}

	// Bulk insert videos (2 queries total instead of N×2)
	if ( ! empty( $video_placeholders ) ) {
		if ( $has_video_details ) {
			$sql = "INSERT INTO $videos_table (video_id, title, description, thumbnails, duration, duration_seconds, video_type, status, published_at, published_at_datetime)
				VALUES " . implode( ', ', $video_placeholders ) . "
				ON DUPLICATE KEY UPDATE
				title = VALUES(title),
				description = VALUES(description),
				thumbnails = VALUES(thumbnails),
				duration = VALUES(duration),
				duration_seconds = VALUES(duration_seconds),
				video_type = VALUES(video_type),
				status = VALUES(status),
				published_at = VALUES(published_at),
				published_at_datetime = VALUES(published_at_datetime)";
		} else {
			$sql = "INSERT INTO $videos_table (video_id, title, description, thumbnails, duration, status, published_at, published_at_datetime)
				VALUES " . implode( ', ', $video_placeholders ) . "
				ON DUPLICATE KEY UPDATE
				title = VALUES(title),
				description = VALUES(description),
				thumbnails = VALUES(thumbnails),
				duration = VALUES(duration),
				status = VALUES(status),
				published_at = VALUES(published_at),
				published_at_datetime = VALUES(published_at_datetime)";
		}

		$wpdb->query( $wpdb->prepare( $sql, $video_values ) );
	}

	// Bulk insert gallery relationships (2 queries total instead of N×2)
	if ( ! empty( $gallery_placeholders ) ) {
		$sql = "INSERT IGNORE INTO $galleries_table (video_id, gallery_id) VALUES " . implode( ', ', $gallery_placeholders );
		$wpdb->query( $wpdb->prepare( $sql, $gallery_values ) );
	}
}

/**
 * Dump our plugin transients.
 *
 * @since 2.1.0
 * @param string $uid Gallery uid to target. Empty string flushes all plugin transients.
 */
function ayg_delete_cache( $uid = '' ) {
	$uid  = (string) $uid;
	$keys = (array) ayg_get_option( 'ayg_transient_keys' );

	// Targeted: delete only the keys prefixed with this gallery's uid, keep the rest in the registry.
	if ( '' !== $uid ) {
		$prefix    = 'ayg_' . $uid . '_';
		$remaining = array();

		foreach ( $keys as $key ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				delete_transient( $key );
			} else {
				$remaining[] = $key;
			}
		}

		update_option( 'ayg_transient_keys', $remaining, false );
		return;
	}

	// Full flush: every transient, plus the cached gallery page-id lookup.
	delete_option( 'ayg_gallery_page_ids' );

	foreach ( $keys as $key ) {
		delete_transient( $key );
	}

	// Reset our DB value (autoload=no: not needed on every page load)
	update_option( 'ayg_transient_keys', array(), false );
}

/**
 * Remove a gallery's excluded videos from the relationship table.
 *
 * @since  2.8.0
 * @param  int   $gallery_id Gallery ID.
 * @param  array $exclude    Exclude entries (video IDs or URLs).
 * @return int               Number of relationships removed.
 */
function ayg_delete_excluded_relationships( $gallery_id, $exclude ) {
	global $wpdb;

	$gallery_id = absint( $gallery_id );

	if ( $gallery_id <= 0 || empty( $exclude ) || ! is_array( $exclude ) ) {
		return 0;
	}

	// Resolve the exclude entries (IDs or URLs) to video IDs.
	$video_ids = array();

	foreach ( $exclude as $entry ) {
		$video_id = ayg_get_youtube_video_id( $entry );

		if ( '' !== $video_id ) {
			$video_ids[] = $video_id;
		}
	}

	$video_ids = array_values( array_unique( $video_ids ) );

	if ( empty( $video_ids ) ) {
		return 0;
	}

	$rel_table    = $wpdb->prefix . 'ayg_gallery_relationships';
	$placeholders = implode( ',', array_fill( 0, count( $video_ids ), '%s' ) );

	return (int) $wpdb->query(
		$wpdb->prepare(
			"DELETE FROM $rel_table WHERE gallery_id = %s AND video_id IN ( $placeholders )",
			array_merge( array( strval( $gallery_id ) ), $video_ids )
		)
	);
}

/** 
 * Get current address bar URL.
 *
 * @since  2.1.0
 * @return string Current Page URL.
 */
function ayg_get_current_url() {
    global $wp;
	$current_url = home_url( add_query_arg( array(), $wp->request ) );
	
    return $current_url;	
}

/**
 * Get default plugin settings.
 *
 * @since  1.6.4
 * @return array $defaults Array of plugin settings.
 */
function ayg_get_default_settings() {
	$defaults = array(
		'ayg_general_settings' => array(
			'force_load_assets' => array(
				'css' => 'css'
			),
			'api_key'           => '',
			'lazyload'          => 0,
			'development_mode'  => 0
		),
		'ayg_strings_settings' => array(
			'more_button_label'     => __( 'Load More', 'automatic-youtube-gallery' ),
			'previous_button_label' => __( 'Previous', 'automatic-youtube-gallery' ),
			'next_button_label'     => __( 'Next', 'automatic-youtube-gallery' ),
			'show_more_label'       => __( 'Show More', 'automatic-youtube-gallery' ),
			'show_less_label'       => __( 'Show Less', 'automatic-youtube-gallery' )
		),
		'ayg_gallery_settings' => array(
			'theme'                 => 'classic',
			'columns'               => 3,
			'per_page'              => 12,
			'thumb_ratio'           => 56.25,
			'thumb_title'           => 1,
			'thumb_title_length'    => 0,
			'thumb_excerpt'         => 1,
			'thumb_excerpt_length'  => 75,
			'pagination'            => 1,
			'pagination_type'       => 'more',
			'scroll_top_offset'     => 10
		),
		'ayg_player_settings' => array(
			'player_type'           => 'youtube',
			'player_color'          => '#00b3ff',
			'player_width'          => '',
			'player_ratio'          => 56.25,
			'player_title'          => 1,
			'player_description'    => 1,
			'autoplay'              => 0,
			'autoadvance'           => 1,
			'loop'                  => 0,
			'muted'                 => 0,
			'controls'              => 1,
			'modestbranding'        => 1,
			'cc_load_policy'        => 0,
			'hl'                    => '',
			'cc_lang_pref'          => '',
			'privacy_enhanced_mode' => 0,
			'origin'                => 0
		),
		'ayg_livestream_settings' => array(
			'fallback_message' => __( 'Sorry, but the channel is not currently streaming live content. Please check back later.', 'automatic-youtube-gallery' )
		),
		'ayg_privacy_settings' => array(			
			'cookie_consent'  => 0,
			'consent_message' => __( 'Please accept YouTube cookies to play this video. By accepting you will be accessing content from YouTube, a service provided by an external third party.', 'automatic-youtube-gallery' ),
			'button_label'    => __( 'Accept', 'automatic-youtube-gallery' )
		)
	);

	return $defaults;
}

/**
 * Get editor fields.
 *
 * @since  1.0.0
 * @return array Array of fields.
 */
function ayg_get_editor_fields() {
	$fields = array(
		'source' => array(
			'label'  => __( 'Source Configuration', 'automatic-youtube-gallery' ),
			'fields' => array(
				array(
					'name'              => 'type',
					'label'             => __( 'Source Type', 'automatic-youtube-gallery' ),
					'description'       => '',
					'type'              => 'select',
					'options'           => ayg_get_source_types(),
					'value'             => 'playlist',
					'sanitize_callback' => 'sanitize_key'
				),
				array(
					'name'              => 'playlist',
					'label'             => __( 'YouTube Playlist ID (or) URL', 'automatic-youtube-gallery' ),					
					'description'       => sprintf( '%s: https://www.youtube.com/playlist?list=XXXXXXXXXX', __( 'Example', 'automatic-youtube-gallery' ) ),
					'type'              => 'url',
					'required'          => 1,
					'value'             => '',
					'sanitize_callback' => 'sanitize_text_field'
				),
				array(
					'name'              => 'channel',
					'label'             => __( 'YouTube Channel ID (or) a Video URL from the Channel', 'automatic-youtube-gallery' ),
					'description'       => sprintf( '%s: https://www.youtube.com/channel/XXXXXXXXXX', __( 'Example', 'automatic-youtube-gallery' ) ),
					'type'              => 'url',
					'required'          => 1,
					'value'             => '',
					'sanitize_callback' => 'sanitize_text_field'
				),
				array(
					'name'              => 'username',
					'label'             => __( 'YouTube Account Username', 'automatic-youtube-gallery' ),
					'description'       => sprintf( '%s: SanRosh', __( 'Example', 'automatic-youtube-gallery' ) ),
					'type'              => 'text',
					'required'          => 1,
					'value'             => '',
					'sanitize_callback' => 'sanitize_text_field'
				),
				array(
					'name'              => 'search',
					'label'             => __( 'Search Keywords', 'automatic-youtube-gallery' ),
					'description'       => sprintf( '%s: Cartoon (space:AND , -:NOT , |:OR)', __( 'Example', 'automatic-youtube-gallery' ) ),
					'type'              => 'text',
					'required'          => 1,
					'value'             => '',
					'sanitize_callback' => 'sanitize_text_field'
				),
				array(
					'name'              => 'video',
					'label'             => __( 'YouTube Video ID (or) URL', 'automatic-youtube-gallery' ),
					'description'       => sprintf( '%s: https://www.youtube.com/watch?v=XXXXXXXXXX', __( 'Example', 'automatic-youtube-gallery' ) ),
					'type'              => 'url',
					'required'          => 1,
					'value'             => '',
					'sanitize_callback' => 'sanitize_text_field'
				),				
				array(
					'name'              => 'videos',
					'label'             => __( 'YouTube Video IDs (or) URLs', 'automatic-youtube-gallery' ),
					'description'       => sprintf( '%s: https://www.youtube.com/watch?v=XXXXXXXXXX', __( 'Example', 'automatic-youtube-gallery' ) ),
					'type'              => 'textarea',					
					'required'          => 1,
					'placeholder'       => __( 'Enter one video per line', 'automatic-youtube-gallery' ),
					'value'             => '',
					'sanitize_callback' => 'sanitize_textarea_field'
				),				
				array(
					'name'              => 'order',
					'label'             => __( 'Search Order', 'automatic-youtube-gallery' ),
					'description'       => '',
					'type'              => 'select',
					'options' => array(
						'date'      => __( 'Date', 'automatic-youtube-gallery' ),
						'rating'    => __( 'Rating', 'automatic-youtube-gallery' ),
						'relevance' => __( 'Relevance', 'automatic-youtube-gallery' ),
						'title'     => __( 'Title', 'automatic-youtube-gallery' ),
						'viewCount' => __( 'View Count', 'automatic-youtube-gallery' )
					),
					'value'             => 'relevance',
					'sanitize_callback' => 'sanitize_text_field'
				),
				array(
					'name'              => 'limit',
					'label'             => __( 'Search Limit', 'automatic-youtube-gallery' ),
					'description'       => __( 'Enter the number of videos to display in this gallery. Set to 0 for the maximum amount (500).', 'automatic-youtube-gallery' ),
					'type'              => 'number',					
					'min'               => 0,
					'max'               => 500,
					'value'             => 0,
					'sanitize_callback' => 'intval'
				),
				array(
					'name'              => 'cache',
					'label'             => __( 'Cache Duration', 'automatic-youtube-gallery' ),
					'description'       => __( 'Specifies how frequently we should check your YouTube source for new videos/updates.', 'automatic-youtube-gallery' ),
					'type'              => 'select',
					'options' => array(
						'0'       => '— '. __( 'No Caching', 'automatic-youtube-gallery' ) . ' —',
						'900'     => __( '15 Minutes', 'automatic-youtube-gallery' ),
						'1800'    => __( '30 Minutes', 'automatic-youtube-gallery' ),
						'3600'    => __( '1 Hour', 'automatic-youtube-gallery' ),
						'86400'   => __( '1 Day', 'automatic-youtube-gallery' ),
						'604800'  => __( '1 Week', 'automatic-youtube-gallery' ),
						'2419200' => __( '1 Month', 'automatic-youtube-gallery' )
					),
					'value'             => 86400,
					'sanitize_callback' => 'intval'
				)
			)			
		),
		'gallery' => array(
			'label'  => __( 'Gallery Options', 'automatic-youtube-gallery' ),
			'fields' => ayg_get_gallery_settings_fields()
		),
		'player' => array(
			'label'  => __( 'Player Options', 'automatic-youtube-gallery' ),
			'fields' => ayg_get_player_settings_fields()
		),
		'search' => array(
			'label'  => __( 'Search Form', 'automatic-youtube-gallery' ),
			'fields' => array(
				array(
					'name'              => 'search_form',
					'label'             => __( 'Search Form', 'automatic-youtube-gallery' ),			
					'description'       => sprintf(
						__( 'Check this option to enable the search form. Having issues? <a href="%s" target="_blank" rel="noopener noreferrer">Check here</a>.', 'automatic-youtube-gallery' ),
						'https://plugins360.com/automatic-youtube-gallery/searchform/'
					),
					'type'              => 'checkbox',
					'value'             => 0,
					'sanitize_callback' => 'intval'
				)
			)
		)
	);

	return apply_filters( 'ayg_editor_fields', $fields );
}

/**
 * Fetch a single gallery row by ID.
 *
 * @since  2.8.0
 * @param  int         $gallery_id Gallery ID.
 * @return object|null
 */
function ayg_get_gallery( $gallery_id ) {
	global $wpdb;

	return $wpdb->get_row(
		$wpdb->prepare( "SELECT * FROM `{$wpdb->prefix}ayg_galleries` WHERE id = %d", absint( $gallery_id ) )
	);
}

/**
 * Get gallery settings fields.
 *
 * @since  1.0.0
 * @return array $fields Array of fields.
 */
function ayg_get_gallery_settings_fields() {
	$gallery_settings = ayg_get_option( 'ayg_gallery_settings' );

	$fields = array(
		array(
			'name'              => 'theme',
			'label'             => __( 'Select Theme (Layout)', 'automatic-youtube-gallery' ),
			'description'       => ( ayg_fs()->is_not_paying() ? sprintf( __( '<a href="%s">Upgrade Pro</a> for more themes (Popup, Inline, Slider, Playlist).', 'automatic-youtube-gallery' ), esc_url( ayg_fs()->get_upgrade_url() ) ) : '' ),
			'type'              => 'select',
			'options'           => array( 
				'classic' => __( 'Classic', 'automatic-youtube-gallery' )
			),
			'value'             => $gallery_settings['theme'],
			'sanitize_callback' => 'sanitize_key'
		),		
		array(
			'name'              => 'columns',
			'label'             => __( 'Columns', 'automatic-youtube-gallery' ),
			'description'       => __( 'Enter the number of columns you like to have in the gallery. Maximum of 12.', 'automatic-youtube-gallery' ),			
			'type'              => 'number',
			'min'               => 0,
			'max'               => 12,
			'value'             => $gallery_settings['columns'],
			'sanitize_callback' => 'intval'
		),
		array(
			'name'              => 'per_page',
			'label'             => __( 'Videos per Page', 'automatic-youtube-gallery' ),
			'description'       => __( 'Enter the number of videos to show per page. Maximum of 50.', 'automatic-youtube-gallery' ),
			'type'              => 'number',
			'min'               => 0,
			'max'               => 50,
			'value'             => $gallery_settings['per_page'],
			'sanitize_callback' => 'intval'
		),
		array(
			'name'              => 'thumb_ratio',
			'label'             => __( 'Image Height (Ratio)', 'automatic-youtube-gallery' ),
			'description'       => __( 'Select the ratio value used to calculate the image height in the gallery thumbnails.', 'automatic-youtube-gallery' ),			
			'type'              => 'select',
			'options'           => array(
				'56.25'  => __( 'Standard (16:9) — Default', 'automatic-youtube-gallery' ),
				'177.78' => __( 'Shorts / Vertical (9:16)', 'automatic-youtube-gallery' ),
				'75'     => __( 'Classic (4:3)', 'automatic-youtube-gallery' )				
			),
			'value'             => $gallery_settings['thumb_ratio'],
			'sanitize_callback' => 'floatval'
		),
		array(
			'name'              => 'thumb_title',
			'label'             => __( 'Show Video Title', 'automatic-youtube-gallery' ),			
			'description'       => __( 'Check this option to show the video title in each gallery item.', 'automatic-youtube-gallery' ),
			'type'              => 'checkbox',
			'value'             => $gallery_settings['thumb_title'],
			'sanitize_callback' => 'intval'
		),
		array(
			'name'              => 'thumb_title_length',
			'label'             => __( 'Video Title Length', 'automatic-youtube-gallery' ),			
			'description'       => __( 'Enter the number of characters you like to show in the title. Set 0 to show the whole title.', 'automatic-youtube-gallery' ),
			'type'              => 'number',
			'min'               => 0,
			'max'               => 500,
			'value'             => $gallery_settings['thumb_title_length'],
			'sanitize_callback' => 'intval'
		),
		array(
			'name'              => 'thumb_excerpt',
			'label'             => __( 'Show Video Excerpt (Short Description)', 'automatic-youtube-gallery' ),			
			'description'       => __( 'Check this option to show the short description of a video in each gallery item.', 'automatic-youtube-gallery' ),
			'type'              => 'checkbox',
			'value'             => $gallery_settings['thumb_excerpt'],
			'sanitize_callback' => 'intval'
		),
		array(
			'name'              => 'thumb_excerpt_length',
			'label'             => __( 'Video Excerpt Length', 'automatic-youtube-gallery' ),			
			'description'       => __( 'Enter the number of characters you like to have in the video excerpt. Set 0 to show the whole description.', 'automatic-youtube-gallery' ),
			'type'              => 'number',
			'min'               => 0,
			'max'               => 500,
			'value'             => $gallery_settings['thumb_excerpt_length'],
			'sanitize_callback' => 'intval'
		),
		array(
			'name'              => 'pagination',
			'label'             => __( 'Pagination', 'automatic-youtube-gallery' ),			
			'description'       => __( 'Check this option to show the pagination.', 'automatic-youtube-gallery' ),
			'type'              => 'checkbox',
			'value'             => $gallery_settings['pagination'],
			'sanitize_callback' => 'intval'
		),
		array(
			'name'              => 'pagination_type',
			'label'             => __( 'Pagination Type', 'automatic-youtube-gallery' ),			
			'type'              => 'select',
			'options'           => array(
				'more'  => __( 'More Button', 'automatic-youtube-gallery' ),
				'pager' => __( 'Pager', 'automatic-youtube-gallery' )
						
			),
			'value'             => $gallery_settings['pagination_type'],
			'sanitize_callback' => 'sanitize_key'
		)
	);

	return apply_filters( 'ayg_gallery_settings_fields', $fields );
}

/**
 * Return the schedule interval options for import scheduling.
 *
 * @since  2.8.0
 * @return array Value (seconds as string) => label pairs.
 */
function ayg_get_import_schedule_options() {
	return array(
		'paused'  => __( '— Pause Automatic Imports —', 'automatic-youtube-gallery' ),
		'0'       => __( 'Only Once', 'automatic-youtube-gallery' ),
		'3600'    => __( 'Every Hour', 'automatic-youtube-gallery' ),
		'86400'   => __( 'Every Day', 'automatic-youtube-gallery' ),
		'604800'  => __( 'Every Week', 'automatic-youtube-gallery' ),
		'2592000' => __( 'Every Month', 'automatic-youtube-gallery' )
	);
}

/**
 * Return the human-readable label for an import status key.
 *
 * @since  2.8.0
 * @param  string   $status      Import status key (idle, running, paused, error, completed).
 * @param  int|null $video_count Optional. Imported video count, used to resolve the
 *                               ambiguous "idle" status into "Pending Import" (nothing
 *                               imported yet) or "Up to Date" (waiting for the next sync).
 * @param  bool     $resuming    Optional. True when an import was capped mid-run and parked
 *                               as 'idle' for cron to resume — shown as "Importing", not idle.
 * @param  string   $context     Optional. 'log' for a per-run history entry, where a successful
 *                               run (stored as 'idle' for recurring galleries) reads "Completed"
 *                               like non-recurring runs, instead of the live-state "Idle".
 * @return string
 */
function ayg_get_import_status_label( $status, $video_count = null, $resuming = false, $context = '' ) {
	if ( 'idle' === $status ) {
		// History rows record a finished run: a recurring gallery's success is stored as 'idle',
		// so surface it as "Completed" to match non-recurring runs rather than the live "Idle".
		if ( 'log' === $context ) {
			return __( 'Completed', 'automatic-youtube-gallery' );
		}

		// If the import was capped mid-run and parked as 'idle' for cron to resume, show "Importing".
		if ( $resuming ) {
			return __( 'Importing', 'automatic-youtube-gallery' );
		}

		// If the import has never run, show "Pending Import" instead of "Idle".
		if ( null !== $video_count ) {
			return ( (int) $video_count > 0 ) ? __( 'Up to Date', 'automatic-youtube-gallery' ) : __( 'Pending Import', 'automatic-youtube-gallery' );
		}
	}

	$statuses = array(
		'idle'      => __( 'Idle', 'automatic-youtube-gallery' ),
		'running'   => __( 'Importing', 'automatic-youtube-gallery' ),
		'paused'    => __( 'Paused', 'automatic-youtube-gallery' ),
		'error'     => __( 'Error', 'automatic-youtube-gallery' ),
		'completed' => __( 'Completed', 'automatic-youtube-gallery' )
	);

	return isset( $statuses[ $status ] ) ? $statuses[ $status ] : ucfirst( $status );
}

/**
 * Retrieve a plugin option with fallback to default settings.
 *
 * @since  2.7.0
 * @param  string $option The option name to retrieve.
 * @return mixed
 */
function ayg_get_option( $option ) {
    $defaults = ayg_get_default_settings();
    $default  = isset( $defaults[ $option ] ) ? $defaults[ $option ] : array();

    $saved = get_option( $option, null );

    // Option does not exist OR corrupted
    if ( null === $saved || ! is_array( $saved ) ) {
        return $default;
    }

    // Merge saved values with defaults
    return wp_parse_args( $saved, $default );
}

/**
 * Get video description to show on top of the player.
 *
 * @since  1.0.0
 * @param  stdClass $video       YouTube video object.
 * @param  array    $attributes  Array of user attributes.
 * @param  int      $words_count Number of words to show by default.
 * @return string                Video description.
 */
function ayg_get_player_description( $video, $attributes = array(), $words_count = 30 ) {
	$description = $video->description;

	$words_array = explode( ' ', strip_tags( $description ) );	
	if ( count( $words_array ) > $words_count ) {
		$show_more_label = ! empty( $attributes['show_more_label'] ) ? $attributes['show_more_label'] : __( 'Show More', 'automatic-youtube-gallery' );
		$words_array[ $words_count ] = '<span class="ayg-player-description-dots">...</span></span><span class="ayg-player-description-more">' . $words_array[ $words_count ];

		$description  = '<span class="ayg-player-description-less">' . implode( ' ', $words_array ) . '</span>';
		$description .= '<a href="#" class="ayg-player-description-toggle-btn">' . esc_html( $show_more_label ) . '</a>';
	}

	$description = nl2br( $description );
	$description = make_clickable( $description );

	return apply_filters( 'ayg_player_description', $description, $video, $attributes, $words_count );	
}

/**
 * Get player settings fields.
 *
 * @since  1.0.0
 * @return array $fields Array of fields.
 */
function ayg_get_player_settings_fields() {
	$player_settings = ayg_get_option( 'ayg_player_settings' );

	$fields = array(
		array(
			'name'              => 'player_width',
			'label'             => __( 'Player Width', 'automatic-youtube-gallery' ),
			'description'       => __( 'In pixels. Maximum width of the player. Leave this field empty to scale 100% of its enclosing container/html element.', 'automatic-youtube-gallery' ),
			'type'              => 'text',
			'value'             => isset( $player_settings['player_width'] ) ? $player_settings['player_width'] : '',
			'sanitize_callback' => 'ayg_sanitize_int'
		),
		array(
			'name'              => 'player_ratio',
			'label'             => __( 'Player Height (Ratio)', 'automatic-youtube-gallery' ),	
			'description'       => __( 'Select the ratio value used to calculate the player height.', 'automatic-youtube-gallery' ),		
			'type'              => 'select',
			'options'           => array(
				'56.25'  => __( 'Standard (16:9) — Default', 'automatic-youtube-gallery' ),
				'177.78' => __( 'Shorts / Vertical (9:16)', 'automatic-youtube-gallery' ),
				'75'     => __( 'Classic (4:3)', 'automatic-youtube-gallery' )
			),
			'value'             => $player_settings['player_ratio'],
			'sanitize_callback' => 'floatval'
		),
		array(
			'name'              => 'player_title',
			'label'             => __( 'Show Video Title', 'automatic-youtube-gallery' ),			
			'description'       => __( 'Check this option to show the current playing video title on the bottom of the player.', 'automatic-youtube-gallery' ),
			'type'              => 'checkbox',
			'value'             => $player_settings['player_title'],
			'sanitize_callback' => 'intval'
		),
		array(
			'name'              => 'player_description',
			'label'             => __( 'Show Video Description', 'automatic-youtube-gallery' ),			
			'description'       => __( 'Check this option to show the current playing video description on the bottom of the player.', 'automatic-youtube-gallery' ),
			'type'              => 'checkbox',
			'value'             => $player_settings['player_description'],
			'sanitize_callback' => 'intval'
		),
		array(
			'name'              => 'autoplay',
			'label'             => __( 'Autoplay', 'automatic-youtube-gallery' ),			
			'description'       => __( 'Check this option to automatically start playing the initial video when the player loads.', 'automatic-youtube-gallery' ),
			'type'              => 'checkbox',
			'value'             => $player_settings['autoplay'],
			'sanitize_callback' => 'intval'
		),
		array(
			'name'              => 'autoadvance',
			'label'             => __( 'Autoplay Next Video', 'automatic-youtube-gallery' ),			
			'description'       => __( 'Check this option to automatically play the next video in the list after the previous one ends.', 'automatic-youtube-gallery' ),
			'type'              => 'checkbox',
			'value'             => $player_settings['autoadvance'],
			'sanitize_callback' => 'intval'
		),
		array(
			'name'              => 'loop',
			'label'             => __( 'Loop', 'automatic-youtube-gallery' ),			
			'description'       => __( 'Check this option to loop playback. In the case of a single video player, plays the initial video again and again. In the case of a gallery, plays the entire list and then starts again at the first video.', 'automatic-youtube-gallery' ),
			'type'              => 'checkbox',
			'value'             => $player_settings['loop'],
			'sanitize_callback' => 'intval'
		),	
		array(
			'name'              => 'muted',
			'label'             => __( 'Muted', 'automatic-youtube-gallery' ),			
			'description'       => __( 'Check this option to turn OFF the audio output of the video by default.', 'automatic-youtube-gallery' ),
			'type'              => 'checkbox',
			'value'             => isset( $player_settings['muted'] ) ? $player_settings['muted'] : 0,
			'sanitize_callback' => 'intval'
		),	
		array(
			'name'              => 'controls',
			'label'             => __( 'Show Player Controls', 'automatic-youtube-gallery' ),			
			'description'       => __( 'Uncheck this option to hide the video player controls.', 'automatic-youtube-gallery' ),
			'type'              => 'checkbox',
			'value'             => $player_settings['controls'],
			'sanitize_callback' => 'intval'
		),
		array(
			'name'              => 'modestbranding',
			'label'             => __( 'Hide YouTube Logo', 'automatic-youtube-gallery' ),			
			'description'       => __( "Check this option to prevent the YouTube logo from displaying in the control bar. Note that a small YouTube text label will still display in the upper-right corner of a paused video when the user's mouse pointer hovers over the player.", 'automatic-youtube-gallery' ),
			'type'              => 'checkbox',
			'value'             => $player_settings['modestbranding'],
			'sanitize_callback' => 'intval'
		),
		array(
			'name'              => 'cc_load_policy',
			'label'             => __( 'Force Closed Captions', 'automatic-youtube-gallery' ),			
			'description'       => __( 'Check this option to show captions by default, even if the user has turned captions off. The default behavior is based on user preference.', 'automatic-youtube-gallery' ),
			'type'              => 'checkbox',
			'value'             => $player_settings['cc_load_policy'],
			'sanitize_callback' => 'intval'
		),
		array(
			'name'              => 'hl',
			'label'             => __( 'Player Language', 'automatic-youtube-gallery' ),			
			'description'       => sprintf( 
				__( 'Specifies the player\'s interface language. Set the field\'s value to an <a href="%s" target="_blank">ISO 639-1 two-letter language code.</a>', 'automatic-youtube-gallery' ),
				'http://www.loc.gov/standards/iso639-2/php/code_list.php'
			),
			'type'              => 'text',
			'value'             => $player_settings['hl'],
			'sanitize_callback' => 'sanitize_text_field'
		),
		array(
			'name'              => 'cc_lang_pref',
			'label'             => __( 'Default Captions Language', 'automatic-youtube-gallery' ),			
			'description'       => sprintf( 
				__( 'Specifies the default language that the player will use to display captions. Set the field\'s value to an <a href="%s" target="_blank">ISO 639-1 two-letter language code.</a>', 'automatic-youtube-gallery' ),
				'http://www.loc.gov/standards/iso639-2/php/code_list.php'
			),
			'type'              => 'text',
			'value'             => $player_settings['cc_lang_pref'],
			'sanitize_callback' => 'sanitize_text_field'
		)
	);

	return $fields;
}


/**
 * Get a single video page URL.
 *
 * @since  2.1.0
 * @param  stdClass $video YouTube video object.
 * @param  array    $args  An array of gallery options.
 * @return string          Single video URL.
 */
function ayg_get_single_video_url( $video, $attributes ) {
	return apply_filters( 'ayg_single_video_url', '', $video, $attributes );
}

/**
 * Return the human-readable label for a source type key.
 *
 * @since  2.8.0
 * @param  string $type Source type key (channel, playlist, username, search, livestream, video, videos).
 * @return string
 */
function ayg_get_source_type_label( $type ) {
	$types = ayg_get_source_types();
	return isset( $types[ $type ] ) ? $types[ $type ] : ucfirst( $type );
}

/**
 * Get source types.
 *
 * @since  2.8.0
 * @return array
 */
function ayg_get_source_types() {
	return array(
		'channel'    => __( 'Channel', 'automatic-youtube-gallery' ),
		'playlist'   => __( 'Playlist', 'automatic-youtube-gallery' ),
		'username'   => __( 'Username', 'automatic-youtube-gallery' ),
		'search'     => __( 'Search Keywords', 'automatic-youtube-gallery' ),
		'livestream' => __( 'Livestream', 'automatic-youtube-gallery' ),
		'video'      => __( 'Single Video', 'automatic-youtube-gallery' ),						
		'videos'     => __( 'Custom Videos List', 'automatic-youtube-gallery' )
	);
}

/**
 * Get filtered php template file path.
 *
 * @since  1.0.0
 * @param  array  $template PHP file path.
 * @param  string $theme    Automatic YouTube Gallery Theme.
 * @return string           Filtered file path.
 */
function ayg_get_template( $template, $theme = '' ) {
	return apply_filters( 'ayg_load_template', $template, $theme );
}

/**
 * Get unique ID.
 *
 * @since  1.0.0
 * @return string Unique ID.
 */
function ayg_get_uniqid() {
	global $ayg_uniqid;

	if ( ! $ayg_uniqid ) {
		$ayg_uniqid = 0;
	}

	return uniqid() . ++$ayg_uniqid;
}

/**
 * Get YouTube domain.
 *
 * @since  2.3.0
 * @return string YouTube embed domain.
 */
function ayg_get_youtube_domain() {
	$player_settings = ayg_get_option( 'ayg_player_settings' );

	$domain = 'https://www.youtube.com';
	if ( isset( $player_settings['privacy_enhanced_mode'] ) && ! empty( $player_settings['privacy_enhanced_mode'] ) ) {
		$domain = 'https://www.youtube-nocookie.com';
	}

	return $domain;
}

/**
 * Get the YouTube embed URL.
 *
 * @since  2.5.0
 * @param  string $video_id   YouTube video ID.
 * @param  array  $attributes Array of user attributes.
 * @return string             Player embed URL.
 */
function ayg_get_youtube_embed_url( $video_id, $attributes = array() ) {
	$player_settings = ayg_get_option( 'ayg_player_settings' );

	$player_website = 'https://www.youtube.com';
	if ( isset( $player_settings['privacy_enhanced_mode'] ) && ! empty( $player_settings['privacy_enhanced_mode'] ) ) {
		$player_website = 'https://www.youtube-nocookie.com';
	}

	if ( empty( $video_id ) ) {
		return '';
	}

	$url = $player_website . '/embed/' . $video_id . '?enablejsapi=1&playsinline=1&rel=0';

	if ( isset( $player_settings['origin'] ) && ! empty( $player_settings['origin'] ) ) {
		$site_url_parts = parse_url( site_url() );
		$origin = $site_url_parts['scheme'] . '://' . $site_url_parts['host'];

		$url = add_query_arg( 'origin', $origin, $url );
	}

	if ( ! is_array( $attributes ) ) {
		$attributes = (array) $attributes;
	}

	$autoplay = isset( $attributes['autoplay'] ) ? (int) $attributes['autoplay'] : 0;
	if ( 1 == $autoplay ) {
		$url = add_query_arg( 'autoplay', 1, $url );
	}

	$loop = isset( $attributes['loop'] ) ? (int) $attributes['loop'] : 0;
	if ( 1 == $loop ) {
		$url = add_query_arg( 'playlist', $video_id, $url );
		$url = add_query_arg( 'loop', 1, $url );
	}

	$muted = isset( $attributes['muted'] ) ? (int) $attributes['muted'] : 0;
	if ( 1 == $muted ) {
		$url = add_query_arg( 'mute', 1, $url );
	}

	$controls = isset( $attributes['controls'] ) ? (int) $attributes['controls'] : 1;
	if ( 0 == $controls ) {
		$url = add_query_arg( 'controls', 0, $url );
	}

	$modestbranding = isset( $attributes['modestbranding'] ) ? (int) $attributes['modestbranding'] : 0;
	if ( 1 == $modestbranding ) {
		$url = add_query_arg( 'modestbranding', 1, $url );
	}

	$cc_load_policy = isset( $attributes['cc_load_policy'] ) ? (int) $attributes['cc_load_policy'] : 0;
	if ( 1 == $cc_load_policy ) {
		$url = add_query_arg( 'cc_load_policy', 1, $url );
	}

	if ( isset( $attributes['hl'] ) && ! empty( $attributes['hl'] ) ) {
		$url = add_query_arg( 'hl', sanitize_text_field( $attributes['hl'] ), $url );
	}

	if ( isset( $attributes['cc_lang_pref'] ) && ! empty( $attributes['cc_lang_pref'] ) ) {
		$url = add_query_arg( 'cc_lang_pref', sanitize_text_field( $attributes['cc_lang_pref'] ), $url );
	}

	return apply_filters( 'ayg_youtube_embed_url', $url, $video_id, $attributes );
}

/**
 * Resolve a YouTube video ID from a bare ID or a YouTube URL.
 *
 * @since  2.8.0
 * @param  string $string A video ID or YouTube URL.
 * @return string         The 11-char video ID, or '' if none found.
 */
function ayg_get_youtube_video_id( $string ) {
	$string = trim( (string) $string );

	if ( '' === $string ) {
		return '';
	}

	// Already a bare video ID.
	if ( preg_match( '~^[A-Za-z0-9_-]{11}$~', $string ) ) {
		return $string;
	}

	// Common YouTube URL forms.
	if ( preg_match( '~(?:v=|/(?:embed|v|shorts|live)/|youtu\.be/)([A-Za-z0-9_-]{11})~', $string, $matches ) ) {
		return $matches[1];
	}

	return '';
}

/**
 * Inserts a new associative array after another associative array key.
 *
 * @since  2.1.0
 * @param  string $key       The associative array key to insert after.
 * @param  array  $array     An array to insert in to.
 * @param  array  $new_array An array to insert.
 * @return array             Updated array.
 */
function ayg_insert_array_after( $key, $array, $new_array ) {
	if ( array_key_exists( $key, $array ) ) {
    	$new = array();

    	foreach ( $array as $k => $value ) {
      		$new[ $k ] = $value;

      		if ( $k === $key ) {
				foreach ( $new_array as $new_key => $new_value ) {
        			$new[ $new_key ] = $new_value;
				}
      		}
    	}
		
    	return $new;
  	}
		
  	return $array;  
}

/**
 * Detect if the client is using an iOS device (iPhone, iPad, or iPod).
 *
 * @return bool True if the user agent string suggests an iOS device, false otherwise.
 */
function ayg_is_ios() {
    if ( empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
        return false;
    }

    $ua = strtolower( $_SERVER['HTTP_USER_AGENT'] );

    if ( 
		strpos( $ua, 'iphone' ) !== false ||
        strpos( $ua, 'ipad' ) !== false ||
        strpos( $ua, 'ipod' ) !== false 
	) {
        return true;
    }

    return false;
}

/**
 * Check whether a video ID matches a gallery's exclude list.
 *
 * @since  2.8.0
 * @param  string $video_id YouTube video ID.
 * @param  array  $exclude  Exclude entries (video IDs or URLs).
 * @return bool             True if the video is excluded.
 */
function ayg_is_video_excluded( $video_id, $exclude ) {
	if ( empty( $video_id ) || empty( $exclude ) || ! is_array( $exclude ) ) {
		return false;
	}

	foreach ( $exclude as $entry ) {
		if ( false !== strpos( $entry, $video_id ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Parse an ISO 8601 duration (e.g. PT4M13S) into total seconds.
 *
 * @since  2.8.0
 * @param  string $iso8601 ISO 8601 duration string from the YouTube API.
 * @return int             Duration in seconds.
 */
function ayg_parse_duration_seconds( $iso8601 ) {
	if ( empty( $iso8601 ) ) {
		return 0;
	}

	try {
		$interval = new DateInterval( $iso8601 );
	} catch ( Exception $e ) {
		return 0;
	}

	return ( $interval->d * DAY_IN_SECONDS ) + ( $interval->h * HOUR_IN_SECONDS ) + ( $interval->i * MINUTE_IN_SECONDS ) + $interval->s;
}

/**
 * Sanitize the array inputs.
 *
 * @since  2.7.0
 * @param  array $value Input array.
 * @return array        Sanitized array.
 */
function ayg_sanitize_array( $value ) {
	return ! empty( $value ) ? array_map( 'sanitize_text_field', $value ) : array();
}

/**
 * Sanitize the integer inputs, accepts empty values.
 *
 * @since  1.0.0
 * @param  string|int $value Input value.
 * @return string|int        Sanitized value.
 */
function ayg_sanitize_int( $value ) {
	$value = intval( $value );
	return ( 0 == $value ) ? '' : $value;	
}

/**
 * Trims text to a certain number of characters.
 *
 * @since  2.0.0
 * @param  string $text           Text to trim.
 * @param  int    $num_characters Number of characters.
 * @param  string $append         String to append to the end of the excerpt.
 * @return string                 Trimmed text.
 */
function ayg_trim_words( $text, $num_characters, $append = '...' ) {
	$num_characters++;

	$original_text = $text;
	$text = ( $num_characters > 1 ) ? wp_strip_all_tags( $original_text, true ) : nl2br( $original_text );

	if ( $num_characters > 1 && mb_strlen( $text ) > $num_characters ) {
		$subex = mb_substr( $text, 0, $num_characters - 5 );
		$exwords = explode( ' ', $subex );
		$excut = - ( mb_strlen( $exwords[ count( $exwords ) - 1 ] ) );

		if ( $excut < 0 ) {
			$text = mb_substr( $subex, 0, $excut );
		} else {
			$text = $subex;
		}

		$text .= $append;
	}

	return apply_filters( 'ayg_trim_words', $text, $original_text, $num_characters, $append );	
}

/**
 * Gallery HTML output.
 *
 * @since 1.0.0
 * @param array $video      YouTube video object.
 * @param array $attributes Array of user attributes.
 */
function the_ayg_gallery_thumbnail( $video, $attributes ) {
	include ayg_get_template( AYG_DIR . 'public/templates/thumbnail.php' );
}

/**
 * Pagination HTML output.
 *
 * @since 1.0.0
 * @param array $attributes Array of user attributes.
 */
function the_ayg_pagination( $attributes ) {
	if ( ! empty( $attributes['pagination'] ) ) {
		include ayg_get_template( AYG_DIR . 'public/templates/pagination.php' );
	}	
}

/**
 * Search Form HTML output.
 *
 * @since 2.5.7
 * @param array $attributes Array of user attributes.
 */
function the_ayg_search_form( $attributes ) {
	if ( ! empty( $attributes['search_form'] ) ) {
		include ayg_get_template( AYG_DIR . 'public/templates/search-form.php' );
	}	
}

/**
 * Gallery HTML output.
 *
 * @since 2.5.0
 * @param array $video      YouTube video object.
 * @param array $attributes Array of user attributes.
 */
function the_ayg_player( $video, $attributes ) {
	include ayg_get_template( AYG_DIR . 'public/templates/player.php' );
}