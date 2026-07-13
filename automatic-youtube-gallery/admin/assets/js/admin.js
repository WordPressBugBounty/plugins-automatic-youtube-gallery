(function( $ ) {
	'use strict';

	/**
 	 * Display the media uploader.
 	 *
 	 * @since 1.0.0
 	 */
	function renderMediaUploader( $elem ) { 
    	let file_frame, attachment;
 
     	// If an instance of file_frame already exists, then we can open it rather than creating a new instance
    	if ( file_frame ) {
        	file_frame.open();
        	return;
    	}; 

     	// Use the wp.media library to define the settings of the media uploader
    	file_frame = wp.media.frames.file_frame = wp.media({
        	frame: 'post',
        	state: 'insert',
        	multiple: false
    	});
 
     	// Setup an event handler for what to do when a media has been selected
    	file_frame.on( 'insert', function() { 
        	// Read the JSON data returned from the media uploader
    		attachment = file_frame.state().get( 'selection' ).first().toJSON();
		
			// First, make sure that we have the URL of the media to display
    		if ( 0 > $.trim( attachment.url.length ) ) {
        		return;
    		};
		
			// Set the data
			$elem.prev( '.ayg-settings-url' ).val( attachment.url ); 
    	});
 
    	// Now display the actual file_frame
    	file_frame.open(); 
	};

	/**
 	 * Widget: Initiate color picker
 	 *
 	 * @since 1.0.0
 	 */
	function initWidgetColorPicker( widget ) {
		widget.find( '.ayg-color-picker' ).wpColorPicker( {
			change: _.throttle( function() { // For Customizer
				$( this ).trigger( 'change' );
			}, 3000 )
		});
	}

	function onWidgetUpdate( event, widget ) {
		initWidgetColorPicker( widget );
	}

	/**
	 * Gallery Builder: Run the import batch loop for a gallery.
	 *
	 * Calls the ayg_import_gallery AJAX action repeatedly, passing back the
	 * page token returned by the previous call, until the server reports done.
	 *
	 * @since 2.8.0
	 */
	function importGallery( id, callbacks ) {
		const runBatch = function( pageToken ) {
			const data = {
				action: 'ayg_import_gallery',
				id: id,
				page_token: pageToken,
				security: ayg_admin.ajax_nonce
			};

			$.post( ajaxurl, data, function( response ) {
				if ( ! response.success ) {
					callbacks.error( response.data || {} );
					return;
				}

				callbacks.progress( response.data );

				if ( response.data.done ) {
					callbacks.complete( response.data );
				} else {
					runBatch( response.data.next_page_token );
				}
			} ).fail( function() {
				callbacks.error( {} );
			} );
		};

		runBatch( '' );
	}

	/**
	 * Called when the page has loaded.
	 *
	 * @since 1.0.0
	 */
	$(function() {
		// Common: Initialize the color picker
		$( '.ayg-color-picker' ).wpColorPicker();

		// Dashboard: Save API key
		$( '#ayg-button-save-api-key' ).on( 'click', function( event ) {
			event.preventDefault();

			const $button  = $( this );
			const $field   = $( '#ayg-form-field-api-key' );
			const $control = $field.closest( '.ayg-form-control' );
			const $status  = $button.siblings( '.ayg-form-status' );

			const data = {
				'action': 'ayg_save_api_key',
				'api_key': $field.val(),
				'security': ayg_admin.ajax_nonce
			};

			// Empty validation: Show the message inside the field control, then stop
			if ( ! $.trim( data.api_key ) ) {
				$control.addClass( 'ayg-form-invalid' );
				$control.find( '.ayg-form-status' ).html( '<span class="ayg-text-error"><span class="dashicons dashicons-dismiss" aria-hidden="true"></span>' + ayg_admin.i18n.invalid_api_key + '</span>' );
				$field.trigger( 'focus' );
				return;
			}

			$control.removeClass( 'ayg-form-invalid' );
			$control.find( '.ayg-form-status' ).html( '' );

			// Disable the button and show a spinner while saving
			$button.prop( 'disabled', true ).prepend( '<span class="spinner"></span>' );
			$status.html( '' );

			// Perform AJAX request to save the API key
			$.post( ajaxurl, data, function( response ) {
				if ( ! response.success ) {
					const message = ( response.data && response.data.message ) ? response.data.message : ayg_admin.i18n.save_failed;

					$button.prop( 'disabled', false ).find( '.spinner' ).remove();
					$status.html( '<span class="ayg-text-error"><span class="dashicons dashicons-dismiss" aria-hidden="true"></span>' + message + '</span>' );
					return;
				}

				window.location.reload(); // Reload document
			} ).fail( function() {
				$button.prop( 'disabled', false ).find( '.spinner' ).remove();
				$status.html( '<span class="ayg-text-error"><span class="dashicons dashicons-dismiss" aria-hidden="true"></span>' + ayg_admin.i18n.save_failed + '</span>' );
			} );
		});

		// Dashboard: Clear the API key field error as the user types
		$( '#ayg-form-field-api-key' ).on( 'input', function() {
			const $control = $( this ).closest( '.ayg-form-control' );

			if ( $.trim( $( this ).val() ).length > 0 ) {
				$control.removeClass( 'ayg-form-invalid' );
				$control.find( '.ayg-form-status' ).html( '' );
			}
		});

		// Gallery Form: Warn before leaving with unsaved changes, or while an import is still running
		// (leaving mid-import leaves the gallery in a "running" state until cron resumes it).
		let form_dirty = false;
		let importing  = false;

		$( '#ayg-gallery-form' ).on( 'change input', ':input', function() {
			form_dirty = true;
		} );

		$( window ).on( 'beforeunload', function( event ) {
			if ( form_dirty || importing ) {
				// Modern browsers show their own generic prompt and ignore a custom message
				// string; they require preventDefault() and/or returnValue to be set to fire it.
				event.preventDefault();
				( event.originalEvent || event ).returnValue = ayg_admin.i18n.unsaved_changes;
				return ayg_admin.i18n.unsaved_changes;
			}
		} );

		// Gallery Form: Validate / clear a source field error as the user edits it.
		// The @handle pattern is deterministic, so the channel field (shared by channel
		// and livestream) re-checks it live; the empty check stays submit-only.
		$( '#ayg-gallery-form' ).on( 'input', '.ayg-form-section-source .ayg-form-field', function() {
			const $field   = $( this );
			const $control = $field.closest( '.ayg-form-control' );

			if ( 'ayg-form-field-channel' === $field.attr( 'id' ) && $field.val().indexOf( '@' ) !== -1 ) {
				$control.addClass( 'ayg-form-invalid' );

				const $error = $( '<span class="ayg-text-error"><span class="dashicons dashicons-dismiss" aria-hidden="true"></span></span>' );
				$error.append( document.createTextNode( ayg_admin.i18n.channel_handle ) );
				$control.find( '.ayg-form-status' ).html( '' ).append( $error );
				return;
			}

			if ( $.trim( $( this ).val() ).length > 0 ) {
				$control.removeClass( 'ayg-form-invalid' );
				$control.find( '.ayg-form-status' ).html( '' );
			}
		} );

		// Gallery Form: Create / Update Gallery, then import videos inline
		$( '#ayg-button-save-gallery' ).on( 'click', function( event ) {
			event.preventDefault();

			const $button  = $( this );
			const $form    = $button.closest( 'form' );
			const $status  = $button.siblings( '.ayg-form-status' );
			const $actions = $button.closest( '.ayg-form-actions' );
			const isNew    = '0' === $form.find( 'input[name="gallery_id"]' ).val();

			// Validate the active source field, unless it is locked (disabled). The source
			// type maps to a single value field; livestream reuses the channel field.
			const type           = $form.find( '#ayg-form-field-type' ).val();
			const sourceFieldMap = { playlist: 'playlist', channel: 'channel', livestream: 'channel', username: 'username', search: 'search', video: 'video', videos: 'videos' };
			const sourceField    = sourceFieldMap[ type ];
			const $sourceField   = $form.find( '#ayg-form-field-' + sourceField );

			if ( $sourceField.length && ! $sourceField.prop( 'disabled' ) ) {
				const $control = $sourceField.closest( '.ayg-form-control' );
				const value    = $.trim( $sourceField.val() );

				// Show a field-level message, focus and scroll to the field, then stop
				const showSourceError = function( message ) {
					$control.addClass( 'ayg-form-invalid' );

					const $error = $( '<span class="ayg-text-error"><span class="dashicons dashicons-dismiss" aria-hidden="true"></span></span>' );
					$error.append( document.createTextNode( message ) );
					$control.find( '.ayg-form-status' ).html( '' ).append( $error );

					const field = $sourceField.get( 0 );
					field.focus( { preventScroll: true } );
					field.scrollIntoView( { behavior: 'smooth', block: 'center' } );
				};

				if ( ! value ) {
					showSourceError( ayg_admin.i18n.source_required[ type ] );
					return;
				}

				// @handle URLs can't be resolved for channel / livestream sources
				if ( ( 'channel' === type || 'livestream' === type ) && value.indexOf( '@' ) !== -1 ) {
					showSourceError( ayg_admin.i18n.channel_handle );
					return;
				}
			}

			// Disable the buttons and show a live status (spinner + green message + animated dots).
			// Reuses the existing message element when present so the spinner and dots keep
			// animating across updates instead of restarting on each call.
			const showStatus = function( message ) {
				$actions.find( 'button' ).prop( 'disabled', true );

				let $message = $status.find( '.ayg-text-success' );

				if ( ! $message.length ) {
					$message = $( '<span class="ayg-text-success"></span>' ).append( '<span class="ayg-animate-dots"></span>' );
					$status.html( '<span class="spinner"></span>' ).append( $message );
				}

				// Update only the leading text node, leaving the trailing dots span intact.
				const node = $message.get( 0 ).firstChild;

				if ( node && 3 === node.nodeType ) {
					node.nodeValue = message;
				} else {
					$message.prepend( document.createTextNode( message ) );
				}
			};

			// Restore the buttons and show an error message in the form status
			const showError = function( message ) {
				$actions.find( 'button' ).prop( 'disabled', false );

				const $error = $( '<span class="ayg-text-error"><span class="dashicons dashicons-dismiss" aria-hidden="true"></span></span>' );
				$error.append( document.createTextNode( message ) );
				$status.html( '' ).append( $error );
			};

			// Fill a status template's %imported% / %updated% / %deleted% tokens from an import response.
			const fillCounts = function( template, data ) {
				return template
					.replace( '%imported%', data && data.imported ? data.imported : 0 )
					.replace( '%updated%', data && data.updated ? data.updated : 0 )
					.replace( '%deleted%', data && data.deleted ? data.deleted : 0 );
			};

			// Stage 1: "Processing..."
			showStatus( ayg_admin.i18n.processing );

			// Perform AJAX request to save the gallery
			const data = $form.serialize() + '&action=ayg_save_gallery&security=' + encodeURIComponent( ayg_admin.ajax_nonce );

			$.post( ajaxurl, data, function( response ) {
				if ( ! response.success ) {
					showError( ( response.data && response.data.message ) ? response.data.message : ayg_admin.i18n.save_failed );
					return;
				}

				form_dirty = false;

				const id      = response.data.gallery_id;
				const editUrl = ayg_admin.admin_url + '?page=automatic-youtube-gallery&action=edit&id=' + id;
				const doneUrl = editUrl + ( isNew ? '&saved=1' : '&updated=1' );

				// "Import videos" unchecked = display-only edit; save the config but skip the import.
				const $canImport = $form.find( '#ayg-can-import-videos' );
				if ( $canImport.length && ! $canImport.is( ':checked' ) ) {
					window.location.href = doneUrl;
					return;
				}

				// Paused galleries skip importing — saving must not re-trigger the import,
				// which would flip import_status back from 'paused' to 'idle'.
				if ( 'paused' === $form.find( '[name="schedule"]' ).val() ) {
					window.location.href = doneUrl;
					return;
				}

				// Live galleries (search / livestream / single video) query the live API at display time — nothing to import
				const sourceType = $form.find( '#ayg-form-field-type' ).val();
				if ( 'search' === sourceType || 'livestream' === sourceType || 'video' === sourceType ) {
					window.location.href = doneUrl;
					return;
				}

				// Point the form at the saved gallery before importing, so a refresh or a
				// retry after an import error updates this gallery instead of creating a new one
				$form.find( 'input[name="gallery_id"]' ).val( id );
				window.history.replaceState( null, '', editUrl );

				// Block the leave-warning's "import in progress" guard for the duration of the run.
				importing = true;

				importGallery( id, {
					progress: function( data ) {
						// Stage 2: Running "Imported: X, Updated: Y..." breakdown as batches return.
						showStatus( fillCounts( ayg_admin.i18n.import_progress, data ) );
					},
					complete: function( data ) {
						// Stage 3: Final breakdown, then redirect.
						importing = false;
						showStatus( fillCounts( ayg_admin.i18n.import_complete, data ) );
						window.location.href = doneUrl;
					},
					error: function( data ) {
						importing = false;
						showError( data.message || ( data.quota_exceeded ? ayg_admin.i18n.quota_exceeded : ayg_admin.i18n.import_failed ) );

						// The gallery exists now — clicking the button again updates and resumes
						if ( isNew ) {
							$button.text( ayg_admin.i18n.update_gallery );
						}
					}
				} );
			} ).fail( function() {
				showError( ayg_admin.i18n.save_failed );
			} );
		} );

		// Gallery List: Confirm before bulk deleting galleries
		$( '#ayg-gallery-list' ).on( 'submit', '.ayg-form', function( event ) {
			const action = $( this ).find( 'select[name="action"], select[name="action2"]' ).filter( function() {
				return 'delete' === $( this ).val();
			} );

			if ( action.length && ! window.confirm( ayg_admin.i18n.confirm_bulk_delete ) ) {
				event.preventDefault();
			}
		} );

		// Gallery List / Form: Delete Gallery
		$( document ).on( 'click', '.ayg-button-delete-gallery', function( event ) {
			event.preventDefault();

			if ( ! window.confirm( ayg_admin.i18n.confirm_delete ) ) {
				return;
			}

			const $button = $( this );
			const id      = $button.data( 'id' );

			// Disable the button while deleting
			$button.prop( 'disabled', true );

			// Perform AJAX request to delete the gallery
			const data = {
				action: 'ayg_delete_gallery',
				id: id,
				security: ayg_admin.ajax_nonce
			};

			$.post( ajaxurl, data, function( response ) {
				if ( ! response.success ) {
					const message = ( response.data && response.data.message ) ? response.data.message : ayg_admin.i18n.delete_failed;
					window.alert( message );
					$button.prop( 'disabled', false );
					return;
				}

				form_dirty = false;

				const doneUrl = ayg_admin.admin_url + '?page=automatic-youtube-gallery&deleted=1';
				window.location.href = doneUrl;
			} ).fail( function() {
				window.alert( ayg_admin.i18n.delete_failed );
				$button.prop( 'disabled', false );
			} );
		} );

		// Gallery List / Form: Copy Shortcode button
		$( document ).on( 'click', '.ayg-button-copy-shortcode', function() {
			const text = $( this ).data( 'clipboard-text' );

			// Confirm the copy with an alert that echoes the copied shortcode.
			const showCopied = function() {
				window.alert( ayg_admin.i18n.shortcode_copied + '\n\n' + text );
			}

			// Use the modern Clipboard API if available, otherwise fall back to the legacy method
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( text ).then( showCopied );
			} else {
				const $temp = $( '<textarea>' ).css( { position: 'fixed', opacity: 0 } ).val( text ).appendTo( 'body' );
				$temp.get( 0 ).select();
				document.execCommand( 'copy' );
				$temp.remove();

				showCopied();
			}
		} );

		// Editor: Toggle fields based on the source type
		$( document ).on( 'change', '.ayg-form-field-type', function() {
			const $wrapper = $( this ).closest( '.ayg-form' );			
			const value    = $( this ).val();			

			$wrapper.removeClass(function( index, classes ) {
				const matches = classes.match( /\ayg-form-field-type-\S+/ig );
				return ( matches ) ? matches.join( ' ' ) : '';
			});

			$wrapper.addClass( 'ayg-form-field-type-' + value );

			// Gallery Form: keep the source hint in sync with the selected type (live vs importable).
			// No-op where the hint isn't present (e.g. the widget form), so it stays safe globally.
			const $hint = $wrapper.find( '.ayg-source-hint' );
			if ( $hint.length ) {
				$hint.text( ayg_admin.live_types.indexOf( value ) !== -1 ? ayg_admin.i18n.source_live : ayg_admin.i18n.source_importable );
			}
		});

		// Editor: Toggle fields based on the theme
		$( document ).on( 'change', '.ayg-form-field-theme', function() {
			const $wrapper = $( this ).closest( '.ayg-form' );			
			const value    = $( this ).val();			

			$wrapper.removeClass(function( index, classes ) {
				const matches = classes.match( /\ayg-form-field-theme-\S+/ig );
				return ( matches ) ? matches.join( ' ' ) : '';
			});

			$wrapper.addClass( 'ayg-form-field-theme-' + value );
		});	

		// Settings: Toggle fields based on the theme
		$( '#ayg-settings' ).on( 'change', 'tr.theme select', function() {
			const $wrapper = $( '#ayg-settings' );		
			const value    = $( this ).val();			

			$wrapper.removeClass(function( index, classes ) {
				const matches = classes.match( /theme-\S+/ig );
				return ( matches ) ? matches.join( ' ' ) : '';
			});

			$wrapper.addClass( 'theme-' + value );
		});

		// Settings: Toggle fields based on the player type
		$( '#ayg-settings' ).on( 'change', 'tr.player_type input[type="radio"]', function() {	
			const $wrapper = $( '#ayg-settings' );		
			const value    = $wrapper.find( 'tr.player_type input[type="radio"]:checked' ).val();			

			$wrapper.removeClass(function( index, classes ) {
				const matches = classes.match( /player_type-\S+/ig );
				return ( matches ) ? matches.join( ' ' ) : '';
			});

			$wrapper.addClass( 'player_type-' + value );
		});

		// Settings: Upload button
		$( '.ayg-button-upload-media' ).on( 'click', function( event ) {																	  
			event.preventDefault();			
			renderMediaUploader( $( this ) );			
		});
		
		// Settings: Delete cache
		$( '#ayg-button-delete-cache' ).on( 'click', function( event ) {
			event.preventDefault();

			const $button = $( this );

			// Disable the button and show a spinner while deleting cache
			$button.prop( 'disabled', true );
			$( '.ayg-form-status', '#ayg-table-delete-cache' ).html( '<span class="spinner"></span>' );

			// Perform AJAX request to delete the cache
			const data = {
				'action': 'ayg_delete_cache',
				'security': ayg_admin.ajax_nonce
			};

			$.post( ajaxurl, data, function( response ) {
				if ( ! response.success ) {
					const message = ( response.data && response.data.message ) ? response.data.message : ayg_admin.i18n.delete_failed;

					$button.prop( 'disabled', false );
					$( '.ayg-form-status', '#ayg-table-delete-cache' ).html( '<span class="ayg-text-error"><span class="dashicons dashicons-dismiss" aria-hidden="true"></span>' + message + '</span>' );
					return;
				}

				$button.prop( 'disabled', false );
				$( '.ayg-form-status', '#ayg-table-delete-cache' ).html( '<span class="ayg-text-success"><span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>' + ayg_admin.i18n.cache_cleared + '</span>' );
			} ).fail( function() {
				$button.prop( 'disabled', false );
				$( '.ayg-form-status', '#ayg-table-delete-cache' ).html( '<span class="ayg-text-error"><span class="dashicons dashicons-dismiss" aria-hidden="true"></span>' + ayg_admin.i18n.delete_failed + '</span>' );
			} );
		});

		// Widget: Initiate color picker 
		$( '#widgets-right .widget:has(.ayg-color-picker)' ).each(function() {
			initWidgetColorPicker( $( this ) );
		});

		$( document ).on( 'widget-added widget-updated', onWidgetUpdate );
	});

})( jQuery );
