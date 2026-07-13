<?php

/**
 * Gallery Form (add / edit).
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

$action              = isset( $_GET['action'] ) ? sanitize_text_field( $_GET['action'] ) : '';
$gallery_id          = ( 'edit' === $action ) ? absint( isset( $_GET['id'] ) ? $_GET['id'] : 0 ) : 0;
$source_value_fields = array( 'channel', 'playlist', 'username', 'search', 'video', 'videos' );
$no_schedule_types   = array( 'search', 'livestream', 'video', 'videos' );
$live_types          = array( 'search', 'livestream', 'video' ); // Rendered live at display time — nothing is imported/stored.
$date_format         = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
$is_new              = ( 0 === $gallery_id ) ? true : false;

// Get the editor fields.
$fields   = ayg_get_editor_fields();
$excluded = array();

foreach ( $fields as $key => $section ) {
	foreach ( $section['fields'] as $index => $field ) {
		// Exclude certain fields from the form
		if ( in_array( $field['name'], $excluded, true ) ) {
			unset( $fields[ $key ]['fields'][ $index ] );
			continue;
		}

		// Add the exclude + schedule fields to the end of the source section.
		if ( 'order' === $field['name'] ) {
			$fields[ $key ]['fields'][] = array(
				'name'              => 'exclude',
				'label'             => __( 'Exclude Videos', 'automatic-youtube-gallery' ),
				'description'       => __( 'Enter the video IDs or URLs you want to exclude, one per line. These videos will never be imported into this gallery.', 'automatic-youtube-gallery' ),
				'type'              => 'textarea',
				'placeholder'       => __( 'Enter one video ID or URL per line', 'automatic-youtube-gallery' ),
				'value'             => '',
				'sanitize_callback' => 'sanitize_textarea_field'
			);

			$fields[ $key ]['fields'][] = array(
				'name'              => 'schedule',
				'label'             => __( 'Import Schedule', 'automatic-youtube-gallery' ),
				'description'       => __( 'How often we automatically check your YouTube source for new videos and import them in the background. Choose "Pause Automatic Imports" to stop these background imports — you can still import on demand by saving the gallery.', 'automatic-youtube-gallery' ),
				'type'              => 'select',
				'options'           => ayg_get_import_schedule_options(),
				'value'             => 86400,
				'sanitize_callback' => 'absint'
			);
		}

		// Add the sort and duration filter fields to the gallery section.
		if ( 'gallery' === $key && 'per_page' === $field['name'] ) {
			// Builder galleries render from our own table, so "Videos per Page" can go beyond the
			// 50-video API cap here — 0 shows all videos on one page. (The shared field keeps its 50
			// limit elsewhere, e.g. the global settings page.)
			$fields[ $key ]['fields'][ $index ]['description'] = __( 'Enter the number of videos to show per page, or 0 to show all videos on a single page.', 'automatic-youtube-gallery' );
			unset( $fields[ $key ]['fields'][ $index ]['max'] );

			// Add the display-time sort fields right after the per_page field in the gallery section.
			array_splice( $fields[ $key ]['fields'], $index + 1, 0, array(
				array(
					'name'              => 'sort_by',
					'label'             => __( 'Order Videos By', 'automatic-youtube-gallery' ),
					'description'       => __( 'Select how imported videos are ordered in this gallery.', 'automatic-youtube-gallery' ),
					'type'              => 'select',
					'options'           => array(
						'date'     => __( 'Published Date', 'automatic-youtube-gallery' ),
						'title'    => __( 'Title', 'automatic-youtube-gallery' ),
						'duration' => __( 'Duration', 'automatic-youtube-gallery' ),
						'random'   => __( 'Random', 'automatic-youtube-gallery' )
					),
					'value'             => 'date',
					'sanitize_callback' => 'sanitize_key'
				),
				array(
					'name'              => 'sort_order',
					'label'             => __( 'Order', 'automatic-youtube-gallery' ),
					'description'       => __( 'Descending shows newest, Z–A or longest first. Ascending shows oldest, A–Z or shortest first.', 'automatic-youtube-gallery' ),
					'type'              => 'select',
					'options'           => array(
						'desc' => __( 'Descending', 'automatic-youtube-gallery' ),
						'asc'  => __( 'Ascending', 'automatic-youtube-gallery' )
					),
					'value'             => 'desc',
					'sanitize_callback' => 'sanitize_key'
				),
				array(
					'name'              => 'duration_filter',
					'label'             => __( 'Filter by Duration', 'automatic-youtube-gallery' ),
					'description'       => __( 'Show only videos longer or shorter than the duration below — handy for including or excluding Shorts.', 'automatic-youtube-gallery' ),
					'type'              => 'select',
					'options'           => array(
						''      => '— ' . __( 'No Filter', 'automatic-youtube-gallery' ) . ' —',
						'long'  => __( 'Longer Than', 'automatic-youtube-gallery' ),
						'short' => __( 'Shorter Than', 'automatic-youtube-gallery' )
					),
					'value'             => '',
					'sanitize_callback' => 'sanitize_key'
				),
				array(
					'name'              => 'duration',
					'label'             => __( 'Duration (Seconds)', 'automatic-youtube-gallery' ),
					'description'       => __( 'The duration threshold in seconds for the filter above. Shorts are typically up to 60 seconds long, so enter 60 to include or exclude Shorts. Has no effect when the filter is set to "No Filter".', 'automatic-youtube-gallery' ),
					'type'              => 'text',
					'value'             => '',
					'sanitize_callback' => 'ayg_sanitize_int'
				)
			) );
		}

		// Add a description to the search form field in the search section.
		if ( 'search' === $key && 'search_form' === $field['name'] ) {
			$fields[ $key ]['fields'][ $index ]['description'] = __( 'Check this option to enable the search form.', 'automatic-youtube-gallery' );
		}
	}
}

// Load gallery config when editing
$form_data = array(
	'id'               => $gallery_id,
	'title'            => '',
	'type'             => 'playlist',
	'theme'            => 'classic',
	'import_status'    => 'idle',
	'import_error'     => '',
	'import_log'       => '',
	'last_imported_at' => '',
	'next_import_at'   => '',
	'video_count'      => 0,
	'schedule'         => 86400
);

if ( $gallery_id > 0 ) {
	$gallery = ayg_get_gallery( $gallery_id );

	if ( ! $gallery ) {
		?>
		<div class="ayg-notice ayg-notice-error">
			<span class="dashicons dashicons-dismiss" aria-hidden="true"></span>
			<?php esc_html_e( 'Gallery not found.', 'automatic-youtube-gallery' ); ?>
		</div>
		<?php
		return;
	}

	// Override default values with saved gallery data	
	$form_data['title']            = $gallery->title;
	$form_data['import_status']    = $gallery->import_status;
	$form_data['import_error']     = $gallery->import_error;
	$form_data['import_log']       = $gallery->import_log;
	$form_data['last_imported_at'] = $gallery->last_imported_at;
	$form_data['next_import_at']   = $gallery->next_import_at;
	$form_data['video_count']      = absint( $gallery->video_count );

	$allowed_source_types = ayg_get_source_types();
	$source_type          = array_key_exists( $gallery->source_type, $allowed_source_types ) ? $gallery->source_type : 'playlist';
	$form_data['type']    = $source_type;

	if ( 'livestream' === $source_type ) {
		$form_data['channel']      = $gallery->source_value;
	} else {
		$form_data[ $source_type ] = $gallery->source_value;
	}

	if ( ! empty( $gallery->params ) ) {
		$params    = json_decode( $gallery->params, true ) ?: array();
		$form_data = array_merge( $form_data, $params );
	}
}

// The source locks only once videos have been imported. Until then it stays editable
// so a bad source (e.g. a failed first import) can be corrected without recreating.
// Live types (search / livestream / single video) never import, so their source never locks.
$source_locked = ( ! $is_new && $form_data['video_count'] > 0 && ! in_array( $form_data['type'], $live_types, true ) );
?>
<div id="ayg-gallery-form" class="ayg-gallery-form">
	<div class="ayg-back-to-galleries-link">
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=automatic-youtube-gallery' ) ); ?>">
			<?php esc_html_e( '← Back to Galleries', 'automatic-youtube-gallery' ); ?>
		</a>
	</div>

	<?php if ( $is_new ) : ?>
		<h1 class="ayg-gallery-form-heading"><?php esc_html_e( 'Add New Gallery', 'automatic-youtube-gallery' ); ?></h1>
	<?php else : ?>
		<h1 class="ayg-gallery-form-heading">
			<?php esc_html_e( 'Edit Gallery', 'automatic-youtube-gallery' ); ?>&nbsp;
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=automatic-youtube-gallery&action=new' ) ); ?>" class="ayg-button button button-small">
				<?php esc_html_e( 'Add New Gallery', 'automatic-youtube-gallery' ); ?>
			</a>
		</h1>

		<?php if ( isset( $_GET['saved'] ) ) : ?>
			<div class="ayg-notice ayg-notice-success">
				<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
				<?php esc_html_e( 'Gallery created successfully.', 'automatic-youtube-gallery' ); ?>
			</div>
		<?php elseif ( isset( $_GET['updated'] ) ) : ?>
			<div class="ayg-notice ayg-notice-success">
				<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
				<?php esc_html_e( 'Gallery updated successfully.', 'automatic-youtube-gallery' ); ?>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $form_data['import_error'] ) ) : ?>
			<div class="ayg-notice ayg-notice-error">
				<span class="dashicons dashicons-dismiss" aria-hidden="true"></span>
				<strong><?php esc_html_e( 'Import Error', 'automatic-youtube-gallery' ); ?>:</strong> <?php echo esc_html( $form_data['import_error'] ); ?>
			</div>
		<?php elseif ( 'running' === $form_data['import_status'] ) : ?>
			<div class="ayg-notice ayg-notice-info">
				<span class="dashicons dashicons-update" aria-hidden="true"></span>
				<?php esc_html_e( 'An import is in progress for this gallery. It continues automatically in the background and may take a few minutes for large channels. Refresh this page to check the latest status.', 'automatic-youtube-gallery' ); ?>
			</div>
		<?php endif; ?>

		<?php $shortcode = sprintf( '[automatic_youtube_gallery id="%d"]', $gallery_id ); ?>
		<div class="ayg-notice ayg-notice-info ayg-notice-shortcode">
			<div class="ayg-notice-shortcode-text">
				<span>
					<span class="dashicons dashicons-awards" aria-hidden="true"></span>
					<?php esc_html_e( 'Your gallery is ready! Copy the shortcode below and paste it into any post, page, or widget to display it.', 'automatic-youtube-gallery' ); ?>
				</span>
				<code><?php echo esc_html( $shortcode ); ?></code>
			</div>
			<button type="button" class="ayg-button ayg-button-copy-shortcode button" data-clipboard-text="<?php echo esc_attr( $shortcode ); ?>">
				<?php esc_html_e( 'Copy Shortcode', 'automatic-youtube-gallery' ); ?>
			</button>
		</div>

		<?php
		// KPI cards show for imported source types only. Live types (search / livestream / single
		// video) store nothing, so they're excluded. 'videos' (multiple) keeps the cards since the
		// imported count is useful.
		if ( ! in_array( $form_data['type'], $live_types, true ) ) :
		?>
		<div class="ayg-kpi-cards">
			<div class="ayg-card ayg-card-kpi">
				<span class="ayg-kpi-icon dashicons dashicons-dashboard" aria-hidden="true"></span>
				<div class="ayg-kpi-body">
					<?php $import_status_label = ayg_get_import_status_label( $form_data['import_status'], $form_data['video_count'], ! empty( $form_data['page_token'] ) ); ?>
					<span class="ayg-badge ayg-badge-<?php echo esc_attr( $form_data['import_status'] ); ?>"><?php echo esc_html( $import_status_label ); ?></span>
					<span class="ayg-kpi-label"><?php esc_html_e( 'Status', 'automatic-youtube-gallery' ); ?></span>
				</div>
			</div>

			<div class="ayg-card ayg-card-kpi">
				<span class="ayg-kpi-icon dashicons dashicons-editor-video" aria-hidden="true"></span>
				<div class="ayg-kpi-body">
					<strong class="ayg-kpi-value"><?php echo number_format_i18n( $form_data['video_count'] ); ?></strong>
					<span class="ayg-kpi-label"><?php esc_html_e( 'Videos Imported', 'automatic-youtube-gallery' ); ?></span>
				</div>
			</div>

			<?php if ( ! in_array( $form_data['type'], $no_schedule_types, true ) ) : ?>
				<div class="ayg-card ayg-card-kpi">
					<span class="ayg-kpi-icon dashicons dashicons-clock" aria-hidden="true"></span>
					<div class="ayg-kpi-body">
						<strong class="ayg-kpi-value ayg-kpi-value-date">
							<?php
							if ( ! empty( $form_data['last_imported_at'] ) ) {
								echo esc_html( wp_date( $date_format, strtotime( $form_data['last_imported_at'] ) ) );
							} elseif ( $form_data['video_count'] > 0 ) {
								// Videos are stored but no run has completed yet (import still in progress / was interrupted).
								esc_html_e( 'In progress', 'automatic-youtube-gallery' );
							} else {
								esc_html_e( 'Never', 'automatic-youtube-gallery' );
							}
							?>
						</strong>
						<span class="ayg-kpi-label"><?php esc_html_e( 'Last Imported', 'automatic-youtube-gallery' ); ?></span>
						<?php if ( ! empty( $form_data['next_import_at'] ) ) : ?>
							<span class="ayg-kpi-sublabel">
								<strong><?php esc_html_e( 'Next', 'automatic-youtube-gallery' ); ?>: </strong>
								<?php echo esc_html( wp_date( $date_format, strtotime( $form_data['next_import_at'] ) ) ); ?></span>
						<?php endif; ?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php endif; ?>
	<?php endif; ?>

	<form class="ayg-form ayg-form-field-type-<?php echo esc_attr( $form_data['type'] ); ?> ayg-form-field-theme-<?php echo esc_attr( $form_data['theme'] ); ?>" method="post">
		<details class="ayg-form-section-title" open>
			<summary>
				<span><?php esc_html_e( 'Gallery Title', 'automatic-youtube-gallery' ); ?></span>
			</summary>

			<div class="ayg-form-controls">
				<div class="ayg-form-control ayg-form-control-title">
					<input type="text" name="title" id="ayg-form-field-title" class="ayg-form-field ayg-form-field-title widefat" value="<?php echo esc_attr( $form_data['title'] ); ?>" placeholder="<?php esc_attr_e( 'Enter a title or leave blank to auto-generate', 'automatic-youtube-gallery' ); ?>"<?php if ( $is_new ) echo ' autofocus'; ?>>
				</div>
			</div>
		</details>

		<?php foreach ( $fields as $key => $section ) : ?>
			<details class="ayg-form-section-<?php echo esc_attr( $key ); ?>"<?php if ( 'source' == $key ) echo ' open'; ?>>
				<summary>
					<span><?php echo esc_html( $section['label'] ); ?></span>
				</summary>

				<div class="ayg-form-controls">
					<?php if ( 'source' === $key ) : ?>
						<p class="description">
							<span class="ayg-text-info dashicons dashicons-info" aria-hidden="true"></span>
							<?php
							// The live / importable text (not the locked text) is kept in sync with the
							// source-type dropdown by admin.js — the strings are mirrored in ayg_admin.i18n
							// (source_live / source_importable). Keep the three in sync when editing copy.
							?>
							<span class="ayg-source-hint"><?php
								if ( $source_locked ) {
									esc_html_e( 'The video source is locked because videos have been imported. Please create a new gallery to use a different source type.', 'automatic-youtube-gallery' );
								} elseif ( in_array( $form_data['type'], $live_types, true ) ) {
									esc_html_e( 'This is a live source — you can change it anytime. Updates take effect immediately the next time the gallery is viewed.', 'automatic-youtube-gallery' );
								} else {
									esc_html_e( 'The video source can’t be changed once videos are imported. So please make sure you select the right source before saving.', 'automatic-youtube-gallery' );
								}
								?>
							</span>
						</p>
					<?php endif; ?>

					<?php
					foreach ( $section['fields'] as $field ) :
						if ( ! isset( $field['placeholder'] ) ) {
							$field['placeholder'] = '';
						}

						// Source settings lock once videos are imported. Fields that don't define the
						// source itself stay editable: the exclude list, the import schedule, and the
						// popup display options (popup mode + its custom trigger).
						$is_locked = ( $source_locked && 'source' === $key && ! in_array( $field['name'], array( 'exclude', 'schedule', 'popup', 'content' ), true ) );

						// Required-field marker (asterisk) appended after the label.
						$required_mark = ! empty( $field['required'] ) ? ' <span class="ayg-form-required" aria-hidden="true">*</span>' : '';

						if ( isset( $form_data[ $field['name'] ] ) ) {
							$field['value'] = $form_data[ $field['name'] ];

							if ( is_array( $field['value'] ) ) {
								$field['value'] = implode( "\n", $field['value'] );
							}
						}
						?>
						<div class="ayg-form-control ayg-form-control-<?php echo esc_attr( $field['name'] ); ?>">
							<?php if ( 'text' == $field['type'] || 'url' == $field['type'] || 'number' == $field['type'] ) : ?>
								<label class="ayg-form-label" for="ayg-form-field-<?php echo esc_attr( $field['name'] ); ?>"><?php echo esc_html( $field['label'] ); echo $required_mark; ?></label>
								<input type="text" name="<?php echo esc_attr( $field['name'] ); ?>" id="ayg-form-field-<?php echo esc_attr( $field['name'] ); ?>" class="ayg-form-field ayg-form-field-<?php echo esc_attr( $field['name'] ); ?> widefat" placeholder="<?php echo esc_attr( $field['placeholder'] ); ?>" value="<?php echo esc_attr( $field['value'] ); ?>"<?php disabled( $is_locked ); ?> />
							<?php elseif ( 'textarea' == $field['type'] ) : ?>
								<label class="ayg-form-label" for="ayg-form-field-<?php echo esc_attr( $field['name'] ); ?>"><?php echo esc_html( $field['label'] ); echo $required_mark; ?></label>
								<textarea name="<?php echo esc_attr( $field['name'] ); ?>" id="ayg-form-field-<?php echo esc_attr( $field['name'] ); ?>" rows="8" class="ayg-form-field ayg-form-field-<?php echo esc_attr( $field['name'] ); ?> widefat" placeholder="<?php echo esc_attr( $field['placeholder'] ); ?>"<?php disabled( $is_locked ); ?>><?php echo esc_textarea( $field['value'] ); ?></textarea>
							<?php elseif ( 'select' == $field['type'] || 'radio' == $field['type'] ) : ?>
								<label class="ayg-form-label" for="ayg-form-field-<?php echo esc_attr( $field['name'] ); ?>"><?php echo esc_html( $field['label'] ); echo $required_mark; ?></label>
								<select name="<?php echo esc_attr( $field['name'] ); ?>" id="ayg-form-field-<?php echo esc_attr( $field['name'] ); ?>" class="ayg-form-field ayg-form-field-<?php echo esc_attr( $field['name'] ); ?> widefat"<?php disabled( $is_locked ); ?>>
									<?php
									foreach ( $field['options'] as $value => $label ) {
										printf(
											'<option value="%s"%s>%s</option>',
											esc_attr( $value ),
											selected( $value, $field['value'], false ),
											esc_html( $label )
										);
									}
									?>
								</select>
							<?php elseif ( 'checkbox' == $field['type'] ) : ?>
								<label class="ayg-form-label" for="ayg-form-field-<?php echo esc_attr( $field['name'] ); ?>">
									<input type="checkbox" name="<?php echo esc_attr( $field['name'] ); ?>" id="ayg-form-field-<?php echo esc_attr( $field['name'] ); ?>" class="ayg-form-field ayg-form-field-<?php echo esc_attr( $field['name'] ); ?>" value="1" <?php checked( $field['value'], 1 ); disabled( $is_locked ); ?> />
									<?php echo esc_html( $field['label'] ); ?>
								</label>
							<?php elseif ( 'color' == $field['type'] ) : ?>
								<label class="ayg-form-label" for="ayg-form-field-<?php echo esc_attr( $field['name'] ); ?>"><?php echo esc_html( $field['label'] ); echo $required_mark; ?></label>
								<input type="text" name="<?php echo esc_attr( $field['name'] ); ?>" id="ayg-form-field-<?php echo esc_attr( $field['name'] ); ?>" class="ayg-form-field ayg-form-field-<?php echo esc_attr( $field['name'] ); ?> ayg-color-picker widefat" value="<?php echo esc_attr( $field['value'] ); ?>"<?php disabled( $is_locked ); ?> />
							<?php endif; ?>

							<?php if ( 'source' === $key && in_array( $field['name'], $source_value_fields, true ) ) : ?>
								<span class="ayg-form-status"></span>
							<?php endif; ?>

							<?php if ( ! empty( $field['description'] ) ) : ?>
								<p class="description"><?php echo wp_kses_post( $field['description'] ); ?></p>
							<?php endif; ?>
						</div>
						<?php
					endforeach;
					?>
				</div>
			</details>
		<?php endforeach; ?>

		<?php
		$logs = ! $is_new ? json_decode( (string) $form_data['import_log'], true ) : array();
		$logs = is_array( $logs ) ? array_reverse( $logs ) : array();
		
		if ( $logs ) : ?>
			<details class="ayg-form-section-import-history">
				<summary>
					<span><?php esc_html_e( 'Import History', 'automatic-youtube-gallery' ); ?></span>
				</summary>

				<div class="ayg-form-controls">
					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th scope="col" class="column-number">#</th>
								<th scope="col" class="column-date column-primary"><?php esc_html_e( 'Date', 'automatic-youtube-gallery' ); ?></th>
								<th scope="col" class="column-videos"><?php esc_html_e( 'Videos', 'automatic-youtube-gallery' ); ?></th>
								<th scope="col" class="column-status"><?php esc_html_e( 'Status', 'automatic-youtube-gallery' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $logs as $index => $log ) : ?>
								<tr>
									<th scope="row" class="column-number"><?php echo (int) $index + 1; ?></th>
									<td class="column-date column-primary" data-colname="<?php esc_attr_e( 'Date', 'automatic-youtube-gallery' ); ?>">
										<?php echo esc_html( ! empty( $log['date'] ) ? wp_date( $date_format, strtotime( $log['date'] ) ) : '' ); ?>
										<button type="button" class="toggle-row"><span class="screen-reader-text"><?php esc_html_e( 'Show more details', 'automatic-youtube-gallery' ); ?></span></button>
									</td>
									<td class="column-videos" data-colname="<?php esc_attr_e( 'Videos', 'automatic-youtube-gallery' ); ?>">
										<ul>
											<li>
												<span><?php esc_html_e( 'Imported', 'automatic-youtube-gallery' ); ?>:</span>
												<span class="ayg-text-info"><?php echo isset( $log['imported'] ) ? (int) $log['imported'] : 0; ?></span>
											</li>
											<?php if ( ! empty( $log['updated'] ) ) : ?>
												<li>
													<span><?php esc_html_e( 'Updated', 'automatic-youtube-gallery' ); ?>:</span>
													<span class="ayg-text-info"><?php echo (int) $log['updated']; ?></span>
												</li>
											<?php endif; ?>
											<?php if ( ! empty( $log['deleted'] ) ) : ?>
												<li>
													<span><?php esc_html_e( 'Deleted', 'automatic-youtube-gallery' ); ?>:</span>
													<span class="ayg-text-info"><?php echo (int) $log['deleted']; ?></span>
												</li>
											<?php endif; ?>
										</ul>
									</td>
									<td class="column-status" data-colname="<?php esc_attr_e( 'Status', 'automatic-youtube-gallery' ); ?>">
										<?php
										$log_status = isset( $log['status'] ) ? $log['status'] : 'idle';

										// A recurring success is stored as 'idle'; render it with the green "completed"
										// badge so both success rows match. Live-state 'idle' badges (KPI card, list) are
										// unaffected — this mapping is local to the history table.
										$log_badge_class = ( 'idle' === $log_status ) ? 'completed' : $log_status;
										?>
										<span class="ayg-badge ayg-badge-<?php echo esc_attr( $log_badge_class ); ?>"><?php echo esc_html( ayg_get_import_status_label( $log_status, null, false, 'log' ) ); ?></span>
										<?php if ( ! empty( $log['error'] ) ) : ?>
											<p class="ayg-text-error"><?php echo esc_html( sanitize_text_field( $log['error'] ) ); ?></p>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</details>
		<?php endif; ?>

		<?php if ( ! $is_new && ! in_array( $form_data['type'], $live_types, true ) ) : ?>
			<p class="description">
				<label>
					<input type="checkbox" id="ayg-can-import-videos" checked />
					<?php esc_html_e( 'Import videos from YouTube now, when you save — adds new videos, updates titles/descriptions, and removes deleted ones. Uncheck to save display settings only. This is a one-time import on save and is separate from the Import Schedule above.', 'automatic-youtube-gallery' ); ?>
				</label>
			</p>
		<?php endif; ?>

		<div class="ayg-form-actions">
			<input type="hidden" name="gallery_id" value="<?php echo absint( $gallery_id ); ?>">

			<button type="button" id="ayg-button-save-gallery" class="ayg-button ayg-button-save-gallery button button-primary button-hero" data-id="<?php echo absint( $gallery_id ); ?>">
				<?php echo $is_new ? esc_html__( 'Create Gallery', 'automatic-youtube-gallery' ) : esc_html__( 'Update Gallery', 'automatic-youtube-gallery' ); ?>
			</button>

			<?php if ( ! $is_new ) : ?>
				<button type="button" id="ayg-button-delete-gallery" class="ayg-button ayg-button-delete-gallery button button-hero" data-id="<?php echo absint( $gallery_id ); ?>">
					<?php esc_html_e( 'Delete Gallery', 'automatic-youtube-gallery' ); ?>
				</button>
			<?php endif; ?>

			<span class="ayg-form-status"></span>
		</div>
	</form>
</div>
