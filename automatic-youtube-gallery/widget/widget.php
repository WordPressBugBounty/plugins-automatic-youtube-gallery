<?php

/**
 * Automatic YouTube Gallery Widget.
 *
 * @link    https://plugins360.com
 * @since   2.1.0
 *
 * @package Automatic_YouTube_Gallery
 */

// Exit if accessed directly
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * AYG_Widget class.
 *
 * @since 2.1.0
 */
class AYG_Widget extends WP_Widget {

	/**
	 * Get things going.
	 *
	 * @since 2.1.0
	 */
	public function __construct() {
		$widget_slug = 'ayg-widget';

		parent::__construct(
			$widget_slug,
			__( 'Automatic YouTube Gallery', 'automatic-youtube-gallery' ),
			array(
				'classname'   => $widget_slug,
				'description' => __( 'Displays automated YouTube video gallery.', 'automatic-youtube-gallery' )
			)
		);
	}

	/**
	 * Outputs the content of the widget.
	 *
	 * @since 2.1.0
	 * @param array $args	  The array of form elements.
	 * @param array $instance The current instance of the widget.
	 */
	public function widget( $args, $instance ) {
		// Process output
		echo $args['before_widget'];
		
		if ( ! empty( $instance['title'] ) ) {
			echo $args['before_title'] . apply_filters( 'widget_title', $instance['title'] ) . $args['after_title'];
		}

		// Deprecated since v2.5.8. Retained for backward compatibility.
		if ( isset( $args['widget_id'] ) ) {
			$instance['deprecated_uid'] = md5( $args['widget_id'] );
		}

		// If popup is enabled and type is video, force theme to popup	
		if ( isset( $instance['popup'] ) && ! empty( $instance['popup'] ) ) {
			if ( isset( $instance['type'] ) && 'video' == $instance['type'] ) {
				$instance['theme'] = 'popup';
			}
		}
		
		echo ayg_build_gallery( $instance );
		
		echo $args['after_widget'];
	}

	/**
	 * Processes the widget's options to be saved.
	 *
	 * @since 2.1.0
	 * @param array $new_instance The new instance of values to be generated via the update.
	 * @param array $old_instance The previous instance of values before the update.
	 */
	public function update( $new_instance, $old_instance ) {
		$instance = array();
		$instance['title'] = isset( $new_instance['title'] ) ? strip_tags( $new_instance['title'] ) : '';

		$fields = ayg_get_editor_fields();

		foreach ( $fields as $section ) {
			foreach ( $section['fields'] as $field ) {
				$name = $field['name'];

				if ( 'checkbox' === $field['type'] ) {
					$instance[ $name ] = ! empty( $new_instance[ $name ] ) ? 1 : 0;
				} elseif ( ! isset( $new_instance[ $name ] ) ) {
					continue;
				} elseif ( 'select' === $field['type'] && isset( $field['options'] ) ) {
					$raw = sanitize_text_field( $new_instance[ $name ] );
					$instance[ $name ] = array_key_exists( $raw, $field['options'] ) ? $raw : sanitize_text_field( $field['value'] );
				} else {
					$sanitize_callback = ! empty( $field['sanitize_callback'] ) ? $field['sanitize_callback'] : 'sanitize_text_field';
					$instance[ $name ] = call_user_func( $sanitize_callback, $new_instance[ $name ] );
				}
			}
		}

		return $instance;
	}

	/**
	 * Generates the administration form for the widget.
	 *
	 * @since 2.1.0
	 * @param array $instance The array of keys and values for the widget.
	 */
	public function form( $instance ) {
		// Define the array of defaults
		$fields = ayg_get_editor_fields();

		$defaults = array(
			'title' => __( 'Automatic YouTube Gallery', 'automatic-youtube-gallery' )
		);

		foreach ( $fields as $key => $value ) {
			foreach ( $value['fields'] as $field ) {
				$defaults[ $field['name'] ] = $field['value'];
			}
		}

		// Parse incoming $instance into an array and merge it with $defaults
		$instance = wp_parse_args(
			(array) $instance,
			$defaults
		);	

		// Display the admin form
		include AYG_DIR . 'widget/templates/admin.php';
	}

}
