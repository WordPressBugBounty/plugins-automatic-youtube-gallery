<?php

/**
 * Fired during plugin deactivation
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
 * AYG_Deactivator class.
 *
 * @since 1.0.0
 */
class AYG_Deactivator {

	/**
	 * Called when the plugin is deactivated.
	 *
	 * @since 1.0.0
	 */
	public static function deactivate() {
		// Clear the plugin's scheduled cron events so they don't keep firing while the
		// plugin is inactive.
		if ( wp_next_scheduled( 'ayg_schedule_weekly' ) ) {
			wp_clear_scheduled_hook( 'ayg_schedule_weekly' );
		}

		if ( wp_next_scheduled( 'ayg_cron_schedule' ) ) {
			wp_clear_scheduled_hook( 'ayg_cron_schedule' );
		}
	}

}
