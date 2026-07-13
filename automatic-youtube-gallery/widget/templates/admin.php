<?php

/**
 * Widget admin form.
 *
 * @link    https://plugins360.com
 * @since   2.1.0
 *
 * @package Automatic_YouTube_Gallery
 */
?>

<div class="ayg ayg-widget ayg-widget-form">
	<div class="ayg-widget-field ayg-widget-field-title">
		<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title', 'automatic-youtube-gallery' ); ?></label> 
		<input type="text" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" class="widefat" value="<?php echo esc_attr( $instance['title'] ); ?>" />
	</div>

	<div class="ayg-form ayg-form-field-type-<?php echo esc_attr( $instance['type'] ); ?> ayg-form-field-theme-<?php echo esc_attr( $instance['theme'] ); ?>">
		<?php foreach ( $fields as $key => $value ) : ?>
			<details class="ayg-form-section-<?php echo esc_attr( $key ); ?>"<?php if ( 'source' == $key ) echo ' open'; ?>>
				<summary>
					<span><?php echo esc_html( $value['label'] ); ?></span>
				</summary>

				<div class="ayg-form-controls">
					<?php
					foreach ( $value['fields'] as $field ) :
						if ( ! isset( $field['placeholder'] ) ) {
							$field['placeholder'] = '';
						}

						$field['value'] = $instance[ $field['name'] ];
						?>
						<div class="ayg-form-control ayg-form-control-<?php echo esc_attr( $field['name'] ); ?>">
							<?php if ( 'text' == $field['type'] || 'url' == $field['type'] || 'number' == $field['type'] ) : ?>
								<label class="ayg-form-label" for="<?php echo esc_attr( $this->get_field_id( $field['name'] ) ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
								<input type="text" name="<?php echo esc_attr( $this->get_field_name( $field['name'] ) ); ?>" id="<?php echo esc_attr( $this->get_field_id( $field['name'] ) ); ?>" class="ayg-form-field ayg-form-field-<?php echo esc_attr( $field['name'] ); ?> widefat" placeholder="<?php echo esc_attr( $field['placeholder'] ); ?>" value="<?php echo esc_attr( $field['value'] ); ?>" />
							<?php elseif ( 'textarea' == $field['type'] ) : ?>
								<label class="ayg-form-label" for="<?php echo esc_attr( $this->get_field_id( $field['name'] ) ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
								<textarea name="<?php echo esc_attr( $this->get_field_name( $field['name'] ) ); ?>" id="<?php echo esc_attr( $this->get_field_id( $field['name'] ) ); ?>" rows="8" class="ayg-form-field ayg-form-field-<?php echo esc_attr( $field['name'] ); ?> widefat" placeholder="<?php echo esc_attr( $field['placeholder'] ); ?>"><?php echo esc_textarea( $field['value'] ); ?></textarea>
							<?php elseif ( 'select' == $field['type'] || 'radio' == $field['type'] ) : ?>
								<label class="ayg-form-label" for="<?php echo esc_attr( $this->get_field_id( $field['name'] ) ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
								<select name="<?php echo esc_attr( $this->get_field_name( $field['name'] ) ); ?>" id="<?php echo esc_attr( $this->get_field_id( $field['name'] ) ); ?>" class="ayg-form-field ayg-form-field-<?php echo esc_attr( $field['name'] ); ?> widefat">
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
								<label class="ayg-form-label" for="<?php echo esc_attr( $this->get_field_id( $field['name'] ) ); ?>">
									<input type="checkbox" name="<?php echo esc_attr( $this->get_field_name( $field['name'] ) ); ?>" id="<?php echo esc_attr( $this->get_field_id( $field['name'] ) ); ?>" class="ayg-form-field ayg-form-field-<?php echo esc_attr( $field['name'] ); ?>" value="1" <?php checked( $field['value'] ); ?> />
									<?php echo esc_html( $field['label'] ); ?>
								</label>
							<?php elseif ( 'color' == $field['type'] ) : ?>
								<label class="ayg-form-label" for="<?php echo esc_attr( $this->get_field_id( $field['name'] ) ); ?>"><?php echo esc_html( $field['label'] ); ?></label>
								<input type="text" name="<?php echo esc_attr( $this->get_field_name( $field['name'] ) ); ?>" id="<?php echo esc_attr( $this->get_field_id( $field['name'] ) ); ?>" class="ayg-form-field ayg-form-field-<?php echo esc_attr( $field['name'] ); ?> ayg-color-picker widefat" value="<?php echo esc_attr( $field['value'] ); ?>" />
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
	</div>
</div>
