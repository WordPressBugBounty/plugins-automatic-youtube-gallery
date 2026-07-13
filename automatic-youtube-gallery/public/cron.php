<?php

/**
 * Cron Jobs.
 *
 * @link    https://plugins360.com
 * @since   2.3.0
 *
 * @package Automatic_YouTube_Gallery
 */

// Exit if accessed directly
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * AYG_Public_Cron class.
 *
 * @since 2.3.0
 */
class AYG_Public_Cron {

	/**
	 * Register the plugin's custom cron intervals.
	 *
	 * @since  2.3.0
	 * @param  array $schedules An array of non-default cron schedules.
	 * @return array $schedules Filtered array of non-default cron schedules.
	 */
	public function cron_schedules( $schedules ) {
		$schedules['ayg_every_five_minutes'] = array(
			'interval' => 5 * MINUTE_IN_SECONDS,
			'display'  => __( 'Every 5 Minutes', 'automatic-youtube-gallery' )
		);

		return $schedules;
	}

    /**
	 * Schedule the plugin's custom cron event if it's not already scheduled.
	 *
	 * @since 2.3.0
	 */
	public function schedule_events() {
		// Migrate away from the pre-2.8.0 weekly event — its cleanup work now runs through
		// the single ayg_cron_schedule dispatcher below.
		if ( wp_next_scheduled( 'ayg_schedule_weekly' ) ) {
			wp_clear_scheduled_hook( 'ayg_schedule_weekly' );
		}

		if ( ! wp_next_scheduled( 'ayg_cron_schedule' ) ) {
			wp_schedule_event( time(), 'ayg_every_five_minutes', 'ayg_cron_schedule' );
		}
	}

    /**
	 * Master scheduled-task dispatcher. Fired every 5 minutes by the ayg_cron_schedule event.
	 *
	 * @since 2.3.0
	 */
	public function cron_event() {
		// Gallery Builder sync — every fire (sync() itself picks the single due gallery).
		$this->sync_galleries();

		// Legacy transient-cache cleanup — heavier, so throttled to roughly once a week.
		$last_cleanup = (int) get_option( 'ayg_last_transient_cleanup', 0 );

		if ( time() - $last_cleanup >= WEEK_IN_SECONDS ) {
			$this->cleanup_transients();
			update_option( 'ayg_last_transient_cleanup', time(), false );
		}
	}

	/**
	 * Run the Gallery Builder scheduled sync.
	 *
	 * @since  2.8.0
	 * @access private
	 */
	private function sync_galleries() {
		$importer = new AYG_Import();
		$importer->sync();
	}

	/**
	 * Drop expired keys from the legacy transient-cache key list.
	 *
	 * @since  2.3.0
	 * @access private
	 */
	private function cleanup_transients() {
		$existing_keys = ayg_get_option( 'ayg_transient_keys' );

		$filtered_keys = array();

		foreach ( $existing_keys as $key ) {
			if ( get_transient( $key ) ) {
				$filtered_keys[] = $key;
			}
		}

		// Save it to the DB (autoload=no: this list can grow large and is not needed on every page load)
		update_option( 'ayg_transient_keys', $filtered_keys, false );
	}

}
