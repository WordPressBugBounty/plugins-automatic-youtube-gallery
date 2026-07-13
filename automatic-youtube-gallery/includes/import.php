<?php

/**
 * Gallery builder import engine.
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

/**
 * AYG_Import class.
 *
 * @since 2.8.0
 */
class AYG_Import {

	/**
	 * Import a single batch of videos for the given gallery.
	 *
	 * @since  2.8.0
	 * @param  int    $gallery_id Gallery ID.
	 * @param  string $page_token Page token to resume from. Empty string starts from the
	 *                            saved token (if any) or the first page.
	 * @param  bool   $manual     True for a manual "Create / Update Gallery" run: full re-scan,
	 *                            no per-run cap, and prune videos no longer in the source. Cron
	 *                            passes false (incremental, capped, never prunes).
	 * @return array              { imported, total_so_far, next_page_token, done }
	 *                            on success, or { error, quota_exceeded } on failure.
	 */
	public function import_batch( $gallery_id, $page_token = '', $manual = false ) {
		global $wpdb;

		$galleries_table = $wpdb->prefix . 'ayg_galleries';
		$rel_table       = $wpdb->prefix . 'ayg_gallery_relationships';

		$gallery = ayg_get_gallery( $gallery_id );

		if ( ! $gallery ) {
			return array( 'error' => __( 'Gallery not found.', 'automatic-youtube-gallery' ) );
		}

		if ( in_array( $gallery->source_type, array( 'search', 'livestream', 'video' ), true ) ) {
			return array( 'error' => __( 'Live galleries (search / livestream / single video) query the live API at display time and cannot be imported.', 'automatic-youtube-gallery' ) );
		}

		$params = json_decode( (string) $gallery->params, true );
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		// Empty incoming token = first batch of a run (the loop passes the returned token back).
		$is_first_batch = ( '' === $page_token );

		// A genuinely fresh start has neither an incoming nor a saved token (a resume has a saved one).
		$is_fresh_start = ( $is_first_batch && empty( $params['page_token'] ) );

		// Resume from the saved token when the client does not supply one (e.g. after a quota pause).
		if ( '' === $page_token && ! empty( $params['page_token'] ) ) {
			$page_token = (string) $params['page_token'];
		}

		// Manual "Update Gallery" runs a full refresh: full re-scan (no early-stop), no per-run cap
		// (the browser completes it in one go), metadata refreshed via upsert, and a prune of videos
		// no longer in the source on the final batch. Cron stays incremental + capped + never prunes.
		//
		// On a fresh start, reset the run's counters and (for manual) stamp the run's start time. Each
		// batch then marks the links it re-stores with this timestamp; on the final batch any link not
		// re-stamped (i.e. removed at the source) is pruned. A resume keeps both so totals + the prune
		// marker stay correct across the whole run.
		if ( $is_fresh_start ) {
			$params['count_imported'] = 0;
			$params['count_updated']  = 0;

			if ( $manual ) {
				$params['sync_run_start'] = current_time( 'mysql' );
			}
		}

		// Completed gallery syncs incrementally; a new / never-finished one does a full scan.
		// Stable for the whole run: last_imported_at only flips on this run's final batch.
		$incremental = ! empty( $gallery->last_imported_at );

		$now = current_time( 'mysql' );

		if ( 'running' !== $gallery->import_status ) {
			$wpdb->update(
				$galleries_table,
				array(
					'import_status' => 'running',
					'updated_at'    => $now
				),
				array( 'id' => $gallery->id ),
				array( '%s', '%s' ),
				array( '%d' )
			);
		}

		// Relationship count before this page is stored. Used by the channel/username early-stop and to
		// split this batch into new (imported) vs already-linked (updated) videos.
		$rel_count_before = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM $rel_table WHERE gallery_id = %s", strval( $gallery->id ) )
		);

		// Fetch one page. The query carries uid + exclude + advanced mode, so request_videos() also
		// stores the video rows + relationships and enriches duration / video_type as a side effect.
		$response = $this->request_videos( $gallery, $params, $page_token );

		if ( isset( $response->error ) ) {
			// A real API failure stops the run. But an incremental sync that simply found an empty
			// result set (nothing to import) is a clean, complete run — fall through with an empty
			// video set so the normal completion path finalizes it.
			if ( ! $incremental || empty( $response->no_results ) ) {
				return $this->handle_api_error( $gallery, $params, $response, $page_token );
			}

			$videos = array();
		} else {
			$videos = $response->videos;
		}

		// Manual full refresh: stamp this batch's links with the run's start time so the final batch can
		// prune links not seen this run. One UPDATE per batch — no growing id list, scales to any size.
		if ( $manual && ! empty( $videos ) ) {
			$batch_ids = array();
			
			foreach ( $videos as $video ) {
				if ( ! empty( $video->id ) ) {
					$batch_ids[] = $video->id;
				}
			}

			if ( $batch_ids ) {
				$run_start    = isset( $params['sync_run_start'] ) ? (string) $params['sync_run_start'] : $now;
				$placeholders = implode( ',', array_fill( 0, count( $batch_ids ), '%s' ) );

				$wpdb->query(
					$wpdb->prepare(
						"UPDATE $rel_table SET synced_at = %s WHERE gallery_id = %s AND video_id IN ( $placeholders )",
						array_merge( array( $run_start, strval( $gallery->id ) ), $batch_ids )
					)
				);
			}
		}

		$video_count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM $rel_table WHERE gallery_id = %s", strval( $gallery->id ) )
		);

		// New links added this batch (imported). Accumulate across batches for the run total.
		$new_this_batch           = max( 0, $video_count - $rel_count_before );
		$params['count_imported'] = ( isset( $params['count_imported'] ) ? (int) $params['count_imported'] : 0 ) + $new_this_batch;

		// "Updated" (existing videos re-synced) is only counted for a manual full refresh. Cron's
		// incremental re-fetch of the newest page would otherwise log a misleading "updated" every run
		// even when nothing changed; cron keeps updated = 0 and reports only genuinely new imports.
		if ( $manual ) {
			$updated_this_batch      = max( 0, count( $videos ) - $new_this_batch );
			$params['count_updated'] = ( isset( $params['count_updated'] ) ? (int) $params['count_updated'] : 0 ) + $updated_this_batch;
		}

		$next_page_token = '';
		if ( isset( $response->page_info ) && ! empty( $response->page_info['next_page_token'] ) ) {
			$next_page_token = (string) $response->page_info['next_page_token'];
		}

		// Incremental channel / username sync stops at the first already-stored video instead of
		// re-paging the whole channel: the uploads playlist is reverse-chronological and never
		// reordered, so a known video means all older ones are known. A fully-new page (cron stalled,
		// > 50 new) keeps paging so the backlog isn't lost. Overlap = fewer new rows than page videos.
		if ( $incremental && ! $manual && in_array( $gallery->source_type, array( 'channel', 'username' ), true ) ) {
			if ( ( $video_count - $rel_count_before ) < count( $videos ) ) {
				$next_page_token = '';
			}
		}

		// No next page token means the run is done — either the source has no more pages, or
		// the early-stop above cleared the token after reaching an already-stored video.
		$done = ( '' === $next_page_token );

		// Per-run safety cap: a run (one browser loop or one cron fire) processes at most
		// max_videos_per_run() videos, then pauses with the token saved so cron finishes the rest.
		$run_processed           = ( $is_first_batch ? 0 : (int) ( isset( $params['run_processed'] ) ? $params['run_processed'] : 0 ) ) + count( $videos );
		$params['run_processed'] = $run_processed;

		// Manual runs are uncapped — the browser completes the whole scan in one session.
		$capped = ( ! $manual && ! $done && $run_processed >= $this->max_videos_per_run() );

		$params['page_token'] = $done ? '' : $next_page_token;

		// Run totals (accumulated across batches): imported = new, updated = existing re-synced,
		// deleted = pruned on the final manual batch.
		$imported = isset( $params['count_imported'] ) ? (int) $params['count_imported'] : 0;
		$updated  = isset( $params['count_updated'] ) ? (int) $params['count_updated'] : 0;
		$deleted  = 0;

		$data = array( 'updated_at' => $now );

		if ( $done ) {
			$schedule     = isset( $params['schedule'] ) ? absint( $params['schedule'] ) : 0;
			$is_recurring = $schedule > 0 && ! in_array( $gallery->source_type, array( 'video', 'videos' ), true );

			$data['import_status']    = $is_recurring ? 'idle' : 'completed';
			$data['import_error']     = '';
			$data['last_imported_at'] = $now;
			$data['next_import_at']   = $is_recurring ? date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + $schedule ) : null;

			unset( $params['run_processed'] );

			// Manual full refresh: this run scanned the whole source and stamped every link it saw with
			// $run_start, so prune the gallery's links NOT stamped this run (removed at the source), then
			// recount. Cron never does this. Guard: only prune when the run actually stamped something
			// (imported + updated > 0) so an empty/failed scan can't wipe the gallery.
			if ( $manual ) {
				if ( ( $imported + $updated ) > 0 && ! empty( $params['sync_run_start'] ) ) {
					$deleted = $this->prune_deleted_videos( $gallery, (string) $params['sync_run_start'] );

					$video_count = (int) $wpdb->get_var(
						$wpdb->prepare( "SELECT COUNT(*) FROM $rel_table WHERE gallery_id = %s", strval( $gallery->id ) )
					);
				}

				unset( $params['sync_run_start'] );
			}

			// One log entry per completed run, recording this run's imported / updated / deleted totals.
			$data['import_log'] = $this->append_log_entry( $gallery, $imported, $updated, $deleted, $data['import_status'], '' );

			unset( $params['count_imported'], $params['count_updated'] );
		} elseif ( $capped ) {
			// Hand off to cron: idle + due now so the next fire resumes from the saved token.
			// last_imported_at stays unset, so the resume keeps the same import mode.
			$data['import_status']  = 'idle';
			$data['next_import_at'] = $now;
		}

		// params / video_count are appended last so their values reflect the cleanup above.
		$data['params']      = wp_json_encode( $params );
		$data['video_count'] = $video_count;

		$wpdb->update(
			$galleries_table,
			$data,
			array( 'id' => $gallery->id ),
			array(),
			array( '%d' )
		);

		return array(
			'imported'        => $imported, // Running run totals; final on the done batch.
			'updated'         => $updated,
			'deleted'         => $deleted,
			'total_so_far'    => $video_count,
			'next_page_token' => $next_page_token,
			'capped'          => $capped,
			'done'            => $done || $capped // A capped run reports done so the browser / cron loop stops; cron continues it.
		);
	}

	/**
	 * Scheduled sync entry point. Invoked by the ayg_cron_schedule cron dispatcher.
	 *
	 * @since 2.8.0
	 */
	public function sync() {
		$this->recover_stuck_syncs();

		$gallery_id = $this->get_due_gallery_id();
		if ( ! $gallery_id ) {
			return;
		}

		// Cron has no client to drive the batches, so loop here. import_batch() self-caps each
		// fire and reports done; the guard is just a backstop against a non-terminating loop.
		$page_token = '';
		$guard      = 0;

		do {
			$result = $this->import_batch( $gallery_id, $page_token );

			if ( isset( $result['error'] ) ) {
				break;
			}

			$page_token = $result['next_page_token'];
		} while ( empty( $result['done'] ) && ++$guard < 1000 );
	}

	/**
	 * Fetch and store one page of videos from the YouTube API for the gallery source.
	 *
	 * @since  2.8.0
	 * @access private
	 * @param  object  $gallery    Gallery row from wp_ayg_galleries.
	 * @param  array   $params     Decoded gallery params.
	 * @param  string  $page_token Page token to fetch.
	 * @return mixed               Response object, or error object from the API class.
	 */
	private function request_videos( $gallery, $params, $page_token ) {
		$query = array(
			'type'       => $gallery->source_type,
			'src'        => $gallery->source_value,
			'uid'        => strval( $gallery->id ),
			'exclude'    => ( isset( $params['exclude'] ) && is_array( $params['exclude'] ) ) ? $params['exclude'] : array(),
			'mode'       => 'advanced',
			'maxResults' => 50, // YouTube's max per call; one quota unit regardless, so always page at 50
			'pageToken'  => $page_token,
			'cache'      => 0
		);

		$api = new AYG_YouTube_API();

		// query() also persists the videos + relationships (see note above), not just fetch.
		return $api->query( $query );
	}

	/**
	 * Maximum number of videos to process in a single run before pausing for cron.
	 *
	 * @since  2.8.0
	 * @access private
	 * @return int     Videos per run (always >= 1).
	 */
	private function max_videos_per_run() {
		$limit = (int) apply_filters( 'ayg_import_max_videos_per_run', 1000 );
		return $limit > 0 ? $limit : 1000;
	}

	/**
	 * Prune a gallery's videos that were not seen during a manual full scan.
	 *
	 * A complete manual run stamps every link it re-stores with $run_start (see import_batch). This
	 * removes the gallery's links NOT stamped this run (i.e. removed at the source), then deletes any
	 * wp_ayg_videos rows left orphaned (not linked by ANY gallery — shared rows are kept). The caller
	 * only invokes this after a non-empty scan, so a transient API failure can't empty the gallery.
	 *
	 * @since  2.8.0
	 * @access private
	 * @param  object $gallery   Gallery row.
	 * @param  string $run_start Timestamp this run stamped its links with (MySQL datetime).
	 * @return int               Number of gallery links removed.
	 */
	private function prune_deleted_videos( $gallery, $run_start ) {
		global $wpdb;

		if ( '' === (string) $run_start ) {
			return 0;
		}

		$rel_table    = $wpdb->prefix . 'ayg_gallery_relationships';
		$videos_table = $wpdb->prefix . 'ayg_videos';

		// 1) Drop this gallery's links not re-stamped by this run (NULL = never synced, or an older run).
		$deleted = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM $rel_table WHERE gallery_id = %s AND ( synced_at IS NULL OR synced_at < %s )",
				strval( $gallery->id ),
				$run_start
			)
		);

		// 2) Delete video rows now orphaned (linked by no gallery). Rows shared by others are kept.
		$wpdb->query(
			"DELETE v FROM $videos_table v
			 LEFT JOIN $rel_table r ON v.video_id = r.video_id
			 WHERE r.video_id IS NULL"
		);

		return $deleted;
	}

	/**
	 * Maximum number of run entries kept in a gallery's import_log JSON.
	 *
	 * @since  2.8.0
	 * @access private
	 * @return int     Log entries to keep (always >= 1).
	 */
	private function max_log_entries() {
		$max = (int) apply_filters( 'ayg_import_max_log_entries', 25 );
		return $max > 0 ? $max : 25;
	}

	/**
	 * Recover galleries left stuck in the 'running' state.
	 *
	 * @since  2.8.0
	 * @access private
	 */
	private function recover_stuck_syncs() {
		global $wpdb;

		$galleries_table = $wpdb->prefix . 'ayg_galleries';

		$now       = current_time( 'mysql' );
		$threshold = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 10 * MINUTE_IN_SECONDS );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE $galleries_table
				 SET import_status = 'idle', next_import_at = %s, updated_at = %s
				 WHERE import_status = 'running' AND updated_at < %s",
				$now,
				$now,
				$threshold
			)
		);
	}

	/**
	 * Find the earliest gallery that is due for a scheduled sync.
	 *
	 * @since  2.8.0
	 * @access private
	 * @return int|null Gallery ID, or null when none are due.
	 */
	private function get_due_gallery_id() {
		global $wpdb;

		$galleries_table = $wpdb->prefix . 'ayg_galleries';

		$gallery_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM $galleries_table
				 WHERE next_import_at IS NOT NULL
				 AND next_import_at < %s
				 AND import_status NOT IN ( 'running', 'paused', 'error' )
				 AND source_type NOT IN ( 'search', 'livestream', 'video' )
				 ORDER BY next_import_at ASC
				 LIMIT 1",
				current_time( 'mysql' )
			)
		);

		return $gallery_id ? (int) $gallery_id : null;
	}

	/**
	 * Handle an API error response for the current batch.
	 *
	 * @since  2.8.0
	 * @access private
	 * @param  object  $gallery    Gallery row from wp_ayg_galleries.
	 * @param  array   $params     Decoded gallery params.
	 * @param  object  $response   Error object from the API class.
	 * @param  string  $page_token Page token the failed batch was fetching.
	 * @return array               { error, quota_exceeded }
	 */
	private function handle_api_error( $gallery, $params, $response, $page_token ) {
		global $wpdb;

		$message        = wp_strip_all_tags( $response->error_message );
		$quota_exceeded = ( false !== stripos( $message, 'quotaExceeded' ) );
		$status         = $quota_exceeded ? 'paused' : 'error';

		// Log what earlier batches imported/updated before the error (deleted = 0: the prune runs only on
		// a clean completion). Then reset the counters so a resume logs only its own delta rather than
		// re-counting these. sync_run_start + page_token are kept so a resume continues from the same
		// point with the same prune marker; only the per-run cap counter restarts.
		$imported = isset( $params['count_imported'] ) ? (int) $params['count_imported'] : 0;
		$updated  = isset( $params['count_updated'] ) ? (int) $params['count_updated'] : 0;

		$params['page_token']     = $page_token;
		$params['count_imported'] = 0;
		$params['count_updated']  = 0;
		unset( $params['run_processed'] );

		$wpdb->update(
			$wpdb->prefix . 'ayg_galleries',
			array(
				'params'        => wp_json_encode( $params ),
				'import_status' => $status,
				'import_error'  => $message,
				'updated_at'    => current_time( 'mysql' ),
				'import_log'    => $this->append_log_entry( $gallery, $imported, $updated, 0, $status, $message ) // Record the stopped run (counts so far + message) so it shows in the log.
			),
			array( 'id' => $gallery->id ),
			array(),
			array( '%d' )
		);

		return array(
			'error'          => $message,
			'quota_exceeded' => $quota_exceeded
		);
	}

	/**
	 * Append a run entry to the gallery's import_log JSON and return the encoded result.
	 *
	 * @since  2.8.0
	 * @access private
	 * @param  object  $gallery  Gallery row from wp_ayg_galleries.
	 * @param  int     $imported Number of new videos added by this run.
	 * @param  int     $updated  Number of already-linked videos re-synced this run.
	 * @param  int     $deleted  Number of videos pruned this run (removed at the source).
	 * @param  string  $status   Final run status ('idle' / 'completed' / 'paused' / 'error').
	 * @param  string  $error    Error message for failed runs, empty otherwise.
	 * @return string            JSON-encoded import_log.
	 */
	private function append_log_entry( $gallery, $imported, $updated, $deleted, $status, $error = '' ) {
		$logs = json_decode( (string) $gallery->import_log, true );
		if ( ! is_array( $logs ) ) {
			$logs = array();
		}

		$entry = array(
			'date'     => current_time( 'mysql' ),
			'imported' => (int) $imported,
			'updated'  => (int) $updated,
			'deleted'  => (int) $deleted,
			'status'   => $status
		);

		// Record the error message on failed runs so it can be shown in the log.
		if ( '' !== $error ) {
			$entry['error'] = $error;
		}

		$logs[] = $entry;

		$max = $this->max_log_entries();
		if ( count( $logs ) > $max ) {
			$logs = array_slice( $logs, -$max );
		}

		return wp_json_encode( $logs );
	}

}
