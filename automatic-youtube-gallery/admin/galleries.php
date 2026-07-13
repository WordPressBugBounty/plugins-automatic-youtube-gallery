<?php

/**
 * Gallery List Table.
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

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Renders the saved-gallery list table on the Dashboard page.
 *
 * @since 2.8.0
 */
class AYG_Admin_Gallery_List_Table extends WP_List_Table {

	/**
	 * Set up the list table arguments.
	 *
	 * @since 2.8.0
	 */
	public function __construct() {
		parent::__construct( array(
			'singular' => 'ayg-gallery',
			'plural'   => 'ayg-galleries',
			'ajax'     => false
		) );
	}

	/**
	 * Define the table columns.
	 *
	 * @since  2.8.0
	 * @return array Column ID => label pairs.
	 */
	public function get_columns() {
		return array(
			'cb'        => '<input type="checkbox">',
			'title'     => __( 'Title', 'automatic-youtube-gallery' ),
			'source'    => __( 'Source', 'automatic-youtube-gallery' ),
			'videos'    => __( 'Videos', 'automatic-youtube-gallery' ),
			'status'    => __( 'Status', 'automatic-youtube-gallery' ),
			'schedule'  => __( 'Next Import', 'automatic-youtube-gallery' ),
			'shortcode' => __( 'Shortcode', 'automatic-youtube-gallery' )
		);
	}

	/**
	 * Define which columns are sortable and which database column they map to.
	 *
	 * @since  2.8.0
	 * @return array Column ID => [ db_column, is_default_sort ] pairs.
	 */
	protected function get_sortable_columns() {
		return array(
			'title'  => array( 'title', false ),
			'source' => array( 'source_type', false ),
			'videos' => array( 'video_count', false )
		);
	}

	/**
	 * Register available bulk actions.
	 *
	 * @since  2.8.0
	 * @return array Action key => label pairs.
	 */
	protected function get_bulk_actions() {
		return array(
			'delete' => __( 'Delete', 'automatic-youtube-gallery' )
		);
	}

	/**
	 * Suppress the top and bottom navigation bars when there are no items.
	 *
	 * @since 2.8.0
	 * @param string $which 'top' or 'bottom'.
	 */
	protected function display_tablenav( $which ) {
		if ( empty( $this->items ) ) {
			return;
		}

		parent::display_tablenav( $which );
	}

	/**
	 * Output a message when the table is empty.
	 *
	 * @since 2.8.0
	 */
	public function no_items() {
		esc_html_e( 'No galleries found.', 'automatic-youtube-gallery' );
	}

	/**
	 * Render the checkbox column used for bulk selection.
	 *
	 * @since  2.8.0
	 * @param  object $item Gallery row object.
	 * @return string
	 */
	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="gallery_ids[]" value="%d">', absint( $item->id ) );
	}

	/**
	 * Render the title column with an edit link and row actions (Edit, Delete).
	 *
	 * @since  2.8.0
	 * @param  object $item Gallery row object.
	 * @return string
	 */
	protected function column_title( $item ) {
		$base_url = admin_url( 'admin.php?page=automatic-youtube-gallery' );
		$edit_url = add_query_arg( array( 'action' => 'edit', 'id' => $item->id ), $base_url );

		$title = sprintf(
			'<a href="%s"><strong>%s</strong></a>',
			esc_url( $edit_url ),
			esc_html( $item->title )
		);

		$actions = array(
			'edit' => sprintf(
				'<a href="%s">%s</a>',
				esc_url( $edit_url ),
				__( 'Edit', 'automatic-youtube-gallery' )
			),
		);

		$actions['delete'] = sprintf(
			'<a href="#" class="ayg-button-delete-gallery" data-id="%d">%s</a>',
			absint( $item->id ),
			__( 'Delete', 'automatic-youtube-gallery' )
		);

		return $title . $this->row_actions( $actions );
	}

	/**
	 * Render the Source column showing the source type label.
	 *
	 * @since  2.8.0
	 * @param  object $item Gallery row object.
	 * @return string
	 */
	protected function column_source( $item ) {
		$type  = ! empty( $item->source_type ) ? $item->source_type : 'playlist';
		$label = ayg_get_source_type_label( $type );

		return esc_html( $label );
	}

	/**
	 * Render the Videos column showing the denormalised video count.
	 *
	 * @since  2.8.0
	 * @param  object $item Gallery row object.
	 * @return string
	 */
	protected function column_videos( $item ) {
		// Live sources (search / livestream / single video) store nothing — they render on the fly —
		// so a "0" count would be misleading. Show a dash instead.
		if ( in_array( $item->source_type, array( 'search', 'livestream', 'video' ), true ) ) {
			return '&mdash;';
		}

		return number_format_i18n( absint( $item->video_count ) );
	}

	/**
	 * Render the Status column as a badge.
	 *
	 * @since  2.8.0
	 * @param  object $item Gallery row object.
	 * @return string
	 */
	protected function column_status( $item ) {
		// Live sources (search / livestream / single video) never import — they query the API at
		// display time — so the import-status labels ("Pending Import", etc.) don't apply. Show "Live".
		if ( in_array( $item->source_type, array( 'search', 'livestream', 'video' ), true ) ) {
			return '<span class="ayg-badge ayg-badge-live">' . esc_html__( 'Live Source', 'automatic-youtube-gallery' ) . '</span>';
		}

		$status = ! empty( $item->import_status ) ? $item->import_status : 'idle';
		$params = json_decode( (string) $item->params, true );
		$label  = ayg_get_import_status_label( $status, $item->video_count, ! empty( $params['page_token'] ) );

		return sprintf(
			'<span class="ayg-badge ayg-badge-%s">%s</span>',
			esc_attr( $status ),
			esc_html( $label )
		);
	}

	/**
	 * Render the Schedule column: The next scheduled import date.
	 *
	 * @since  2.8.0
	 * @param  object $item Gallery row object.
	 * @return string
	 */
	protected function column_schedule( $item ) {
		if ( empty( $item->next_import_at ) ) {
			return '&mdash;';
		}

		static $date_format = null;
		if ( null === $date_format ) {
			$date_format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		}

		return esc_html( wp_date( $date_format, strtotime( $item->next_import_at ) ) );
	}

	/**
	 * Render the Shortcode column as a one-click copy chip.
	 *
	 * @since  2.8.0
	 * @param  object $item Gallery row object.
	 * @return string
	 */
	protected function column_shortcode( $item ) {
		$shortcode = sprintf( '[automatic_youtube_gallery id="%d"]', absint( $item->id ) );

		return sprintf(
			'<button type="button" class="ayg-button ayg-button-copy-shortcode button button-small" title="%1$s" data-clipboard-text="%1$s">%2$s</button>',
			esc_attr( $shortcode ),
			esc_html__( 'Copy Shortcode', 'automatic-youtube-gallery' )
		);
	}

	/**
	 * Fallback renderer for any column that does not have a dedicated method.
	 *
	 * @since  2.8.0
	 * @param  object $item        Gallery row object.
	 * @param  string $column_name Column identifier.
	 * @return string
	 */
	public function column_default( $item, $column_name ) {
		return '';
	}

	/**
	 * Query galleries from the database and populate $this->items with the current page.
	 *
	 * @since 2.8.0
	 */
	public function prepare_items() {
		$this->_column_headers = array(
			$this->get_columns(),
			array(),
			$this->get_sortable_columns(),
		);

		global $wpdb;
		$table = $wpdb->prefix . 'ayg_galleries';

		/**
		 * Filters the number of galleries listed per page in the admin list table.
		 *
		 * @since 2.8.0
		 * @param int   $per_page Number of galleries per page. Default 20.
		 */
		$per_page = (int) apply_filters( 'ayg_galleries_per_page', 20 );
		if ( $per_page < 1 ) {
			$per_page = 20;
		}
		$curr_page = $this->get_pagenum();
		$offset    = ( $curr_page - 1 ) * $per_page;

		$search = isset( $_REQUEST['s'] ) ? sanitize_text_field( $_REQUEST['s'] ) : '';

		$allowed_orderby = array( 'id', 'title', 'source_type', 'video_count', 'last_imported_at' );
		$orderby = ( isset( $_REQUEST['orderby'] ) && in_array( $_REQUEST['orderby'], $allowed_orderby, true ) ) ? $_REQUEST['orderby'] : 'id';
		$order   = ( isset( $_REQUEST['order'] ) && 'asc' === strtolower( $_REQUEST['order'] ) ) ? 'ASC' : 'DESC';

		if ( ! empty( $search ) ) {
			$like = '%' . $wpdb->esc_like( $search ) . '%';

			$total_items = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE `title` LIKE %s", $like )
			);

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM `{$table}` WHERE `title` LIKE %s ORDER BY `{$orderby}` {$order} LIMIT %d OFFSET %d",
					$like,
					$per_page,
					$offset
				)
			);
		} else {
			$total_items = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$this->items = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM `{$table}` ORDER BY `{$orderby}` {$order} LIMIT %d OFFSET %d",
					$per_page,
					$offset
				)
			);
		}

		$this->set_pagination_args( array(
			'total_items' => $total_items,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( $total_items / $per_page )
		) );
	}

	/**
	 * Process the bulk Delete action, then redirect back to the list with a count notice.
	 *
	 * @since 2.8.0
	 */
	public function process_bulk_action() {
		if ( 'delete' !== $this->current_action() ) {
			return;
		}

		check_admin_referer( 'bulk-ayg-galleries' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( -1 );
		}

		$ids = isset( $_REQUEST['gallery_ids'] ) ? array_map( 'absint', (array) $_REQUEST['gallery_ids'] ) : array();
		if ( empty( $ids ) ) {
			return;
		}

		foreach ( $ids as $id ) {
			self::delete_gallery( $id );
		}

		$redirect_to = remove_query_arg(
			array( 'action', 'action2', 'bulk_action', '_wpnonce', '_wp_http_referer', 'gallery_ids', 'deleted' )
		);

		wp_redirect( add_query_arg( 'deleted', count( $ids ), $redirect_to ) );
		exit;
	}

	/**
	 * Delete a gallery, its pivot rows, and any orphaned videos.
	 *
	 * Orphaned videos are removed in 200-row chunks via LEFT JOIN to keep
	 * table locks short on large datasets.
	 *
	 * Called by process_bulk_action() and by AYG_Admin::ajax_callback_delete_gallery().
	 *
	 * @since 2.8.0
	 * @param int   $id Gallery ID.
	 */
	public static function delete_gallery( $id ) {
		global $wpdb;

		$id = absint( $id );
		if ( ! $id ) {
			return;
		}

		$videos_table = $wpdb->prefix . 'ayg_videos';
		$rel_table    = $wpdb->prefix . 'ayg_gallery_relationships';		
		$gal_table    = $wpdb->prefix . 'ayg_galleries';

		$wpdb->delete( $rel_table, array( 'gallery_id' => strval( $id ) ), array( '%s' ) );
		$wpdb->delete( $gal_table, array( 'id' => $id ), array( '%d' ) );

		do {
			// Multi-table DELETE with LIMIT is not valid in MySQL/MariaDB.
			// Use a derived subquery so the outer DELETE targets a single table by id.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$deleted = $wpdb->query(
				"DELETE FROM `{$videos_table}`
				 WHERE `id` IN (
				     SELECT `id` FROM (
				         SELECT v.`id`
				         FROM `{$videos_table}` v
				         LEFT JOIN `{$rel_table}` r ON v.video_id = r.video_id
				         WHERE r.video_id IS NULL
				         LIMIT 200
				     ) AS orphans
				 )"
			);
		} while ( $deleted > 0 );
	}

}
