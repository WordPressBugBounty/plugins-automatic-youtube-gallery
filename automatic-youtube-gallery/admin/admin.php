<?php

/**
 * The admin-specific functionality of the plugin.
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
 * AYG_Admin class.
 *
 * @since 1.0.0
 */
class AYG_Admin {

	/**
	 * Gallery list table instance, shared between bulk-action processing
	 * and rendering, to avoid instantiating it twice per request.
	 *
	 * @since 2.8.0
	 * @var   AYG_Admin_Gallery_List_Table
	 */
	private $gallery_list_table;

	/**
	 * Insert missing plugin options.
	 *
	 * @since 1.6.4
	 */
	public function insert_missing_options() {		
		if ( AYG_VERSION !== get_option( 'ayg_version' ) ) {	
			$defaults = ayg_get_default_settings();				

			// Insert the gallery settings
			$gallery_settings = get_option( 'ayg_gallery_settings' );

			if ( ! is_array( $gallery_settings ) || empty( $gallery_settings ) ) {
				$gallery_settings = $defaults['ayg_gallery_settings'];
				update_option( 'ayg_gallery_settings', $gallery_settings );
			}

			// Insert the strings settings
			if ( false == get_option( 'ayg_strings_settings' ) ) {
				$strings_settings = array(
					'more_button_label'     => ! empty( $gallery_settings['more_button_label'] ) ? $gallery_settings['more_button_label'] : $defaults['ayg_strings_settings']['more_button_label'],
					'previous_button_label' => ! empty( $gallery_settings['previous_button_label'] ) ? $gallery_settings['previous_button_label'] : $defaults['ayg_strings_settings']['previous_button_label'],
					'next_button_label'     => ! empty( $gallery_settings['next_button_label'] ) ? $gallery_settings['next_button_label'] : $defaults['ayg_strings_settings']['next_button_label'],
					'show_more_label'       => $defaults['ayg_strings_settings']['show_more_label'],
					'show_less_label'       => $defaults['ayg_strings_settings']['show_less_label'],
				);

				add_option( 'ayg_strings_settings', $strings_settings );
			}

			// Update the player settings
			$player_settings = get_option( 'ayg_player_settings' );

			if ( ! is_array( $player_settings ) || empty( $player_settings ) ) {
				$player_settings = $defaults['ayg_player_settings'];
				update_option( 'ayg_player_settings', $player_settings );
			}

			if ( ! array_key_exists( 'player_type', $player_settings ) ) {
				$player_settings['player_type']  = $defaults['ayg_player_settings']['player_type'];
				$player_settings['player_color'] = $defaults['ayg_player_settings']['player_color'];

				update_option( 'ayg_player_settings', $player_settings );
			}

			// Insert the livestream settings			
			if ( false == get_option( 'ayg_livestream_settings' ) ) {
				add_option( 'ayg_livestream_settings', $defaults['ayg_livestream_settings'] );
			}

			// Insert the privacy settings			
			if ( false == get_option( 'ayg_privacy_settings' ) ) {
				add_option( 'ayg_privacy_settings', $defaults['ayg_privacy_settings'] );
			}

			// Create custom database tables
			ayg_db_create_custom_tables();

			// Delete the plugin cache
			ayg_delete_cache();
			
			// Update the plugin version		
			update_option( 'ayg_version', AYG_VERSION );
		}
	}

	/**
	 * Enqueue styles for the admin area.
	 *
	 * @since 1.0.0
	 */
	public function enqueue_styles() {
		wp_enqueue_style( 'wp-color-picker' );

		wp_enqueue_style(
			AYG_SLUG . '-admin',
			AYG_URL . 'admin/assets/css/admin.min.css',
			array(),
			AYG_VERSION,
			'all'
		);
	}

	/**
	 * Enqueue scripts for the admin area.
	 *
	 * @since 1.0.0
	 */
	public function enqueue_scripts() {
		wp_enqueue_media();
		wp_enqueue_script( 'wp-color-picker' );

		wp_enqueue_script(
			AYG_SLUG . '-admin',
			AYG_URL . 'admin/assets/js/admin.min.js',
			array( 'jquery' ),
			AYG_VERSION,
			false
		);

		wp_localize_script(
			AYG_SLUG . '-admin',
			'ayg_admin',
			array(
				'ajax_nonce' => wp_create_nonce( 'ayg_ajax_nonce' ),
				'admin_url'  => admin_url( 'admin.php' ),
				'live_types' => array( 'search', 'livestream', 'video' ), // Rendered live — source stays editable (mirrors gallery-form.php).
				'i18n'       => array(
					// API key
					'invalid_api_key'     => __( 'Please enter your YouTube API key.', 'automatic-youtube-gallery' ),

					// Settings
					'cache_cleared'       => __( 'Cleared', 'automatic-youtube-gallery' ),

					// Common
					'save_failed'         => __( 'Save failed. Please try again.', 'automatic-youtube-gallery' ),
					'delete_failed'       => __( 'Delete failed. Please try again.', 'automatic-youtube-gallery' ),

					// Gallery: Shortcode
					'shortcode_copied'    => __( 'Shortcode copied! Paste it into any post, page, or widget to display your gallery.', 'automatic-youtube-gallery' ),

					// Gallery: Delete confirmations
					'confirm_delete'      => __( 'Are you sure you want to delete this gallery? This action cannot be undone.', 'automatic-youtube-gallery' ),
					'confirm_bulk_delete' => __( 'Are you sure you want to delete the selected galleries? This action cannot be undone.', 'automatic-youtube-gallery' ),

					// Gallery Form
					'unsaved_changes'     => __( 'You have unsaved changes. Are you sure you want to leave?', 'automatic-youtube-gallery' ),
					'update_gallery'      => __( 'Update Gallery', 'automatic-youtube-gallery' ),

					// Gallery Form: Source validation
					'source_required'     => array(
						'channel'    => __( 'A YouTube channel ID (or) a video URL from the channel is required.', 'automatic-youtube-gallery' ),
						'playlist'   => __( 'A YouTube playlist ID (or) URL is required.', 'automatic-youtube-gallery' ),						
						'username'   => __( 'A YouTube account username is required.', 'automatic-youtube-gallery' ),
						'search'     => __( 'A search keyword is required.', 'automatic-youtube-gallery' ),
						'livestream' => __( 'A YouTube channel ID (or) a video URL from the channel is required.', 'automatic-youtube-gallery' ),
						'video'      => __( 'A YouTube video ID (or) URL is required.', 'automatic-youtube-gallery' ),
						'videos'     => __( 'At least one YouTube video ID (or) URL is required.', 'automatic-youtube-gallery' )						
					),
					'channel_handle'      => __( 'YouTube @handle URLs aren’t supported here. Please enter a channel ID, a /channel/ URL, or a video URL from the channel.', 'automatic-youtube-gallery' ),

					// Source hint text, swapped live as the source-type dropdown changes. Must match
					// the strings rendered in gallery-form.php's .ayg-source-hint (live / importable).
					'source_live'         => __( 'This is a live source — you can change it anytime. Updates take effect immediately the next time the gallery is viewed.', 'automatic-youtube-gallery' ),
					'source_importable'   => __( 'The video source can’t be changed once videos are imported. So please make sure you select the right source before saving.', 'automatic-youtube-gallery' ),

					// Gallery Form: Import progress
					'processing'          => __( 'Processing', 'automatic-youtube-gallery' ),
					'import_progress'     => __( 'Imported: %imported%, Updated: %updated%. Please do not close this window until you see a success or error message', 'automatic-youtube-gallery' ),
					'import_complete'     => __( 'Done! Imported: %imported%, Updated: %updated%, Deleted: %deleted%. Refreshing the page', 'automatic-youtube-gallery' ),
					'import_failed'       => __( 'Import failed. Please try again.', 'automatic-youtube-gallery' ),
					'quota_exceeded'      => __( 'YouTube API quota exceeded. The import has been paused — click "Update Gallery" to resume after the quota resets.', 'automatic-youtube-gallery' )
				)
			)
		);
	}	

	/**
	 * Add dashboard page link on the plugins menu.
	 *
	 * @since  1.0.0
	 * @param  array  $links An array of plugin action links.
	 * @return string $links Array of filtered plugin action links.
	 */
	public function plugin_action_links( $links ) {
		$dashboard_link = sprintf( 
			'<a href="%s">%s</a>', 
			admin_url( 'admin.php?page=automatic-youtube-gallery' ), 
			__( 'Build Gallery', 'automatic-youtube-gallery' ) 
		);
		
        array_unshift( $links, $dashboard_link );
		
    	return $links;
	}

	/**
	 * Add "Dashboard" menu.
	 *
	 * @since 1.3.0
	 */
	public function admin_menu() {
		$hook = add_menu_page(
			__( 'Automatic YouTube Gallery', 'automatic-youtube-gallery' ),
			__( 'YouTube Gallery', 'automatic-youtube-gallery' ),
			'manage_options',
			'automatic-youtube-gallery',
			array( $this, 'display_dashboard_content' ),
			'dashicons-format-video',
			10
		);

		// Process list table bulk actions before any HTML is output to allow wp_redirect().
		add_action( 'load-' . $hook, array( $this, 'process_list_table_bulk_actions' ) );

		add_submenu_page(
			'automatic-youtube-gallery',
			__( 'Dashboard', 'automatic-youtube-gallery' ),
			__( 'Dashboard', 'automatic-youtube-gallery' ),
			'manage_options',
			'automatic-youtube-gallery',
			array( $this, 'display_dashboard_content' )
		);
	}

	/**
	 * Runs before the dashboard page HTML is output: hides the Screen Options tab
	 * and processes list-table bulk actions on the list view.
	 *
	 * @since 2.8.0
	 */
	public function process_list_table_bulk_actions() {
		$general_settings = ayg_get_option( 'ayg_general_settings' );		

		// Hide the Screen Options tab entirely. The list table would otherwise
		// auto-populate a "Columns" section there, which we don't want on our
		// custom dashboard header.
		add_filter( 'screen_options_show_screen', '__return_false' );

		// No list table is rendered on the setup screen or the gallery form view.
		$action = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : '';
		if ( empty( $general_settings['api_key'] ) || 'new' === $action || 'edit' === $action ) {
			return;
		}

		// Strip the GET search form's leftover bulk-action fields from the URL.
		add_filter( 'removable_query_args', array( $this, 'add_removable_query_args' ) );

		// Process bulk actions
		require_once AYG_DIR . 'admin/galleries.php';

		$this->gallery_list_table = new AYG_Admin_Gallery_List_Table();
		$this->gallery_list_table->process_bulk_action();
	}

	/**
	 * Add the gallery list table's bulk-action fields to the list of query args
	 * WordPress strips from the URL via wp_admin_canonical_url().
	 *
	 * @since  2.8.0
	 * @param  array $args Removable query argument names.
	 * @return array
	 */
	public function add_removable_query_args( $args ) {
		return array_merge( $args, array( 'action', 'action2', 'bulk_action', '_wpnonce', '_wp_http_referer' ) );
	}

	/**
	 * Display dashboard content.
	 *
	 * @since 1.3.0
	 */
	public function display_dashboard_content() {
		require_once AYG_DIR . 'admin/templates/dashboard.php';
	}

	/**
	 * Prints admin screen notices.
	 *
	 * @since 2.0.0
	 */
	public function admin_notices() {
		$screen  = get_current_screen();
		$on_page = $screen && 'toplevel_page_automatic-youtube-gallery' === $screen->id;

		// Show on all admin pages except our own gallery form (action=new|edit).
		if ( $on_page ) {
			$action = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : '';
			if ( 'new' === $action || 'edit' === $action ) {
				return;
			}
		}

		$general_settings = ayg_get_option( 'ayg_general_settings' );

		if ( ! empty( $general_settings['development_mode'] ) ) {
			// WordPress relocates non-inline notices on our custom dashboard header, hiding them.
			// Add the "inline" class only there so the notice stays put; leave it off elsewhere.
			$classes = 'notice notice-info' . ( $on_page ? ' inline' : '' );
			?>
			<div class="<?php echo esc_attr( $classes ); ?>">
                <p>
					<?php 
					printf(
						__( '<strong>Automatic YouTube Gallery:</strong> You have <a href="%s">development mode</a> enabled. We do not cache API results in this mode. While this is ok when you are testing the plugin, we strongly recommend disabling this option when your site goes live.', 'automatic-youtube-gallery' ),
						esc_url( admin_url( 'admin.php?page=automatic-youtube-gallery-settings' ) )
					); 
					?>
				</p>
            </div>
			<?php
		}
	}

	/**
	 * Save API Key.
	 *
	 * @since 1.3.0
	 */
	public function ajax_callback_save_api_key() {
		check_ajax_referer( 'ayg_ajax_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'automatic-youtube-gallery' ) ) );
		}

		$general_settings = ayg_get_option( 'ayg_general_settings' );

		$general_settings['api_key'] = sanitize_text_field( $_POST['api_key'] );
		update_option( 'ayg_general_settings', $general_settings );

		wp_send_json_success();
	}

	/**
	 * Save a gallery (insert or update).
	 *
	 * @since 2.8.0
	 */
	public function ajax_callback_save_gallery() {
		check_ajax_referer( 'ayg_ajax_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'automatic-youtube-gallery' ) ) );
		}

		$gallery_id    = absint( isset( $_POST['gallery_id'] ) ? $_POST['gallery_id'] : 0 );
		$gallery_title = sanitize_text_field( isset( $_POST['title'] ) ? $_POST['title'] : '' );

		$params        = array();
		$source_locked = false;
		$source_type   = '';
		$source_value  = '';

		if ( $gallery_id > 0 ) {
			$existing = ayg_get_gallery( $gallery_id );

			if ( ! $existing ) {
				wp_send_json_error( array( 'message' => __( 'Gallery not found.', 'automatic-youtube-gallery' ) ) );
			}

			// Seed params from the saved row so values from disabled inputs and runtime
			// keys (page_token, last_video_published_at) survive the update.
			$saved_params = json_decode( (string) $existing->params, true );
			if ( is_array( $saved_params ) ) {
				$params = $saved_params;
			}

			// The source locks only once videos have been imported. While the gallery
			// still has no videos (e.g. the first import failed on a bad source), the
			// source stays editable so it can be corrected in place. Live sources
			// (search / livestream / single video) never import, so they never lock.
			$source_locked = ( (int) $existing->video_count > 0 && ! in_array( $existing->source_type, array( 'search', 'livestream', 'video' ), true ) );

			if ( $source_locked ) {
				$source_type  = $existing->source_type;
				$source_value = $existing->source_value;
			}
		}

		// Read the source from POST for a new gallery, or for an update before the first
		// import. A re-edited source starts a clean import, so drop the runtime keys.
		if ( ! $source_locked ) {
			unset( $params['page_token'], $params['last_video_published_at'] );

			$allowed_source_types = ayg_get_source_types();
			$source_type = sanitize_key( isset( $_POST['type'] ) ? $_POST['type'] : 'playlist' );
			if ( ! array_key_exists( $source_type, $allowed_source_types ) ) {
				$source_type = 'playlist';
			}

			switch ( $source_type ) {
				case 'channel':
				case 'livestream':
					$source_value = sanitize_text_field( isset( $_POST['channel'] ) ? $_POST['channel'] : '' );
					break;

				case 'playlist':
					$source_value = sanitize_text_field( isset( $_POST['playlist'] ) ? $_POST['playlist'] : '' );
					break;

				case 'username':
					$source_value = sanitize_text_field( isset( $_POST['username'] ) ? $_POST['username'] : '' );
					break;

				case 'search':
					$source_value = sanitize_text_field( isset( $_POST['search'] ) ? $_POST['search'] : '' );
					break;

				case 'video':
					$source_value = sanitize_text_field( isset( $_POST['video'] ) ? $_POST['video'] : '' );
					break;

				case 'videos':
					$source_value = sanitize_textarea_field( isset( $_POST['videos'] ) ? $_POST['videos'] : '' );
					break;

				default:
					$source_value = '';
			}
		}

		$exclude = sanitize_textarea_field( isset( $_POST['exclude'] ) ? $_POST['exclude'] : '' );
		if ( ! empty( $exclude ) ) $exclude = array_values( array_filter( array_map( 'trim', explode( "\n", $exclude ) ) ) );
		$params['exclude'] = $exclude;

		// 'paused' is a non-numeric sentinel (stop automatic imports); every other value is seconds.
		$schedule           = sanitize_text_field( isset( $_POST['schedule'] ) ? $_POST['schedule'] : 86400 );
		$params['schedule'] = ( 'paused' === $schedule ) ? 'paused' : absint( $schedule );

		// Display-time sort (gallery-form-only fields). Validated against whitelists; get_videos_from_db()
		// maps them into a safe ORDER BY. Defaults reproduce the prior hardcoded "newest first".
		$sort_by              = sanitize_key( isset( $_POST['sort_by'] ) ? $_POST['sort_by'] : '' );
		$params['sort_by']    = in_array( $sort_by, array( 'date', 'title', 'duration', 'random' ), true ) ? $sort_by : 'date';

		$sort_order           = sanitize_key( isset( $_POST['sort_order'] ) ? $_POST['sort_order'] : '' );
		$params['sort_order'] = ( 'asc' === $sort_order ) ? 'asc' : 'desc';

		// Display-time duration filter. get_videos_from_db() applies it only when a direction is set.
		$duration_filter           = sanitize_key( isset( $_POST['duration_filter'] ) ? $_POST['duration_filter'] : '' );
		$params['duration_filter'] = in_array( $duration_filter, array( 'long', 'short' ), true ) ? $duration_filter : '';
		$params['duration']        = absint( isset( $_POST['duration'] ) ? $_POST['duration'] : 0 );

		// Fields stored in dedicated DB columns, not in params. 'cache' is kept (saved to params) so
		// the live 'search' source type can control its API cache duration.
		$fields   = ayg_get_editor_fields();
		$excluded = array( 'type', 'channel', 'playlist', 'username', 'search', 'video', 'videos' );

		foreach ( $fields as $section ) {
			foreach ( $section['fields'] as $field ) {
				$name = $field['name'];

				if ( in_array( $name, $excluded, true ) ) {
					continue;
				}

				if ( 'checkbox' === $field['type'] ) {
					$params[ $name ] = isset( $_POST[ $name ] ) ? 1 : 0;
				} elseif ( ! isset( $_POST[ $name ] ) ) {
					continue;
				} elseif ( 'select' === $field['type'] && isset( $field['options'] ) ) {
					$raw = sanitize_text_field( $_POST[ $name ] );
					$params[ $name ] = array_key_exists( $raw, $field['options'] ) ? $raw : sanitize_text_field( $field['value'] );
				} else {
					$sanitize_callback = ! empty( $field['sanitize_callback'] ) ? $field['sanitize_callback'] : 'sanitize_text_field';
					$params[ $name ]   = call_user_func( $sanitize_callback, $_POST[ $name ] );
				}
			}
		}

		global $wpdb;
		$table = $wpdb->prefix . 'ayg_galleries';

		$now = current_time( 'mysql' );

		$data = array(
			'title'      => $gallery_title,
			'params'     => wp_json_encode( $params ),
			'updated_at' => $now
		);

		// Persist the source columns whenever they aren't locked (new gallery, or an
		// update before the first import). All columns here are strings, so the format
		// is left to default ('%s') instead of tracking a positional list.
		if ( ! $source_locked ) {
			$data['source_type']  = $source_type;
			$data['source_value'] = $source_value;
		}

		// A "paused" schedule stops automatic imports: flag the gallery paused, clear any prior
		// import error (so a user pause is distinguishable from a quota pause, which keeps its
		// message), and drop it from the cron queue. The JS save flow skips the import for this case.
		if ( 'paused' === $params['schedule'] ) {
			$data['import_status']  = 'paused';
			$data['import_error']   = '';
			$data['next_import_at'] = null;
		} elseif ( 0 === $params['schedule'] ) {
			// "Only Once" is non-recurring: ensure no future cron run stays queued. Switching here from a
			// recurring schedule may have left a stale next_import_at behind; clear it so a display-only
			// save (import skipped) can't trigger one more unexpected background import. A save that does
			// import will have import_batch() finalize the status / next run on completion anyway.
			$data['next_import_at'] = null;
		}

		if ( $gallery_id > 0 ) {
			if ( empty( $gallery_title ) ) {
				$gallery_title = sprintf( __( 'Gallery %d', 'automatic-youtube-gallery' ), $gallery_id );
				$data['title'] = $gallery_title;
			}

			// Drop any newly-excluded videos from this gallery's links (the videos table is left intact —
			// rows may be shared by other galleries) and fold the refreshed count into this same update.
			if ( ayg_delete_excluded_relationships( $gallery_id, $params['exclude'] ) > 0 ) {
				$data['video_count'] = (int) $wpdb->get_var(
					$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}ayg_gallery_relationships WHERE gallery_id = %s", strval( $gallery_id ) )
				);
			}

			$wpdb->update(
				$table,
				$data,
				array( 'id' => $gallery_id ),
				null,
				array( '%d' )
			);
		} else {
			$data['created_at'] = $now;

			$wpdb->insert( $table, $data );

			$gallery_id = (int) $wpdb->insert_id;

			if ( empty( $gallery_title ) ) {
				$gallery_title = sprintf( __( 'Gallery %d', 'automatic-youtube-gallery' ), $gallery_id );

				$wpdb->update(
					$table,
					array( 'title' => $gallery_title ),
					array( 'id' => $gallery_id ),
					array( '%s' ),
					array( '%d' )
				);
			}
		}

		// Live galleries (search / livestream / single video) render from the API and cache responses. Clear
		// this gallery's cache on save so the front-end reflects the latest results right after "Update Gallery".
		if ( in_array( $source_type, array( 'search', 'livestream', 'video' ), true ) ) {
			ayg_delete_cache( strval( $gallery_id ) );
		}

		wp_send_json_success( array(
			'gallery_id' => $gallery_id,
			'shortcode'  => sprintf( '[automatic_youtube_gallery id="%d"]', $gallery_id ),
		) );
	}

	/**
	 * Import one batch of videos into a gallery via AJAX.
	 *
	 * The client calls this in a loop, passing back the page_token returned by
	 * the previous call, until done = true.
	 *
	 * @since 2.8.0
	 */
	public function ajax_callback_import_gallery() {
		check_ajax_referer( 'ayg_ajax_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'automatic-youtube-gallery' ) ) );
		}

		$gallery_id = absint( isset( $_POST['id'] ) ? $_POST['id'] : 0 );
		$page_token = sanitize_text_field( isset( $_POST['page_token'] ) ? $_POST['page_token'] : '' );

		// The browser-driven import is always a manual run: full re-scan, uncapped, prunes deletions.
		$importer = new AYG_Import();
		$result   = $importer->import_batch( $gallery_id, $page_token, true );

		if ( isset( $result['error'] ) ) {
			wp_send_json_error( array(
				'message'        => $result['error'],
				'quota_exceeded' => ! empty( $result['quota_exceeded'] )
			) );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Delete a gallery via AJAX.
	 *
	 * @since 2.8.0
	 */
	public function ajax_callback_delete_gallery() {
		check_ajax_referer( 'ayg_ajax_nonce', 'security' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'automatic-youtube-gallery' ) ) );
		}

		require_once AYG_DIR . 'admin/galleries.php';

		$id = absint( isset( $_POST['id'] ) ? $_POST['id'] : 0 );
		AYG_Admin_Gallery_List_Table::delete_gallery( $id );

		wp_send_json_success();
	}

}
