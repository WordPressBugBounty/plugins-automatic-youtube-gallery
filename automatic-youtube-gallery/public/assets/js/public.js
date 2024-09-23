(function( $ ) {
	'use strict';

	/**
	 * Init YouTube Iframe API.
	 *
	 * @since 2.3.7
	 */
	var is_youtube_iframe_api_loaded = false;

	function init_youtube_iframe_api() {
		return new Promise(( resolve ) => { 
			if ( 'undefined' === typeof window.YT && false == is_youtube_iframe_api_loaded ) {
				is_youtube_iframe_api_loaded = true;

				var tag = document.createElement( 'script' );
				tag.src = 'https://www.youtube.com/iframe_api';
				var first_script_tag = document.getElementsByTagName( 'script' )[0];
				first_script_tag.parentNode.insertBefore( tag, first_script_tag );	
			}		

			if ( 'undefined' !== typeof window.YT && window.YT.loaded )	{
				resolve();	
			} else {		
				let interval_handler = setInterval(
					function() {
						if ( 'undefined' !== typeof window.YT && window.YT.loaded )	{
							clearInterval( interval_handler );
							resolve();	
						}
					}, 
					10 
				);
			}
		});
	}

	/**
	 * Init Classic Theme.
	 *
	 * @since 2.0.0
	 */
	function init_classic_theme( $container ) {
		++ayg_public.gallery_index;

		$container.addClass( 'ayg-theme-initialized' );		

		var params = $container.data( 'params' );

		var $current_item = $container.find( '.ayg-item' ).eq(0);

		var video_id = $current_item.find( '.ayg-thumbnail' ).data( 'id' );			
		
		var player_id = 'ayg-player-' + ayg_public.gallery_index;
		$container.find( '.ayg-player-iframe' ).attr( 'id', player_id );

		var $pagination = $container.find( '.ayg-pagination' );
		var $next_button = null;
		var pagination_type = 'none';
		if ( $pagination.length > 0 ) {
			$next_button = $pagination.find( '.ayg-pagination-next-btn' );
			pagination_type = $next_button.data( 'type' );
		}

		// Player
		var player = ayg_init_player( player_id, {			
			custom: {
				params: params,
				image: $current_item.find( '.ayg-thumbnail-image' ).attr( 'src' )
			},
			events: {
				'onStateChange': function( e ) {
					// On Playing
					if ( e.data == YT.PlayerState.PLAYING ) {
						ayg_pause_other_players( player_id );
					}

					// On Ended
					if ( e.data == YT.PlayerState.ENDED ) {
						if ( 1 == params.autoadvance ) {
							player.stop();

							if ( $current_item.is( ':visible' ) ) {
								if ( $current_item.is( ':last-child' ) ) {
									if ( 'more' == pagination_type || 'none' == pagination_type ) {
										if ( 1 == params.loop ) {
											$container.find( '.ayg-item' ).eq(0).trigger( 'click' );
										}
									} else {
										// Load Next Page
										if ( $next_button.is( ':visible' ) ) {					
											$next_button.trigger( 'click' );

											let interval_handler = setInterval(
												function() {												
													if ( 0 == $container.find( '.ayg-pagination.ayg-loading' ).length ) {
														clearInterval( interval_handler );
														$container.find( '.ayg-item' ).eq(0).trigger( 'click' );
													}												
												}, 
												1000 
											);									
										}									
									}
								} else {
									$current_item.next( '.ayg-item' ).trigger( 'click' );
								}
							} else {
								$container.find( '.ayg-item' ).eq(0).trigger( 'click' );
							}
						} else {
							if ( 1 == params.loop ) {
								player.play();
							} else {
								player.stop();
							}
						}
					}				 
				}
			}
		});

		// Grid: On thumbnail clicked
		$container.on( 'click', '.ayg-item', function() {
			$current_item = $( this );

			$container.find( '.ayg-active' ).removeClass( 'ayg-active' );			
			$current_item.addClass( 'ayg-active' );

			// Change video
			video_id = $current_item.find( '.ayg-thumbnail' ).data( 'id' );

			player.change({
				id: video_id,
				image: $current_item.find( '.ayg-thumbnail-image' ).attr( 'src' )
			});
			
			if ( 1 == params.player_title ) {
				var title = $current_item.find( '.ayg-thumbnail' ).data( 'title' );				
				$container.find( '.ayg-player-title' ).html( title );
			}

			if ( 1 == params.player_description ) {
				var description = $current_item.find( '.ayg-thumbnail-description' ).html();
				$container.find( '.ayg-player-description' ).html( description );
			}
			
			// Scroll to Top
			$( 'html, body' ).animate({
				scrollTop: $container.offset().top - ayg_public.top_offset
			}, 500, function() {
				// Change URL in Browser Address Bar
				var url = $current_item.find( '.ayg-thumbnail' ).data( 'url' );
				if ( '' != url ) {
					window.history.replaceState( null, null, url );
				}				
			});	
			
			// Load Next Page
			if ( 1 == params.autoadvance && 'more' == pagination_type ) {
				if ( $current_item.is( ':last-child' ) && $next_button.is( ':visible' ) ) {					
					$next_button.trigger( 'click' );
				}
			}
		});

		// Pagination
		if ( $pagination.length > 0 ) {
			ayg_init_pagination( $pagination );

			$pagination.on( 'gallery.updated', function() {
				if ( $container.find( '.ayg-active' ).length > 0 ) {
					return;
				}

				if ( $container.find( '.ayg-item-' + video_id ).length > 0 ) {
					$current_item = $container.find( '.ayg-item-' + video_id ).addClass( 'ayg-active' );
				}
			});
		}
	}

	/**
	 * Init Single Video.
	 *
	 * @since 2.0.0
	 */
	function init_single_video( $container ) {
		++ayg_public.gallery_index;

		$container.addClass( 'ayg-theme-initialized' );

		var params = $container.data( 'params' );		
		
		var player_id = 'ayg-player-' + ayg_public.gallery_index;
		$container.find( '.ayg-player-iframe' ).attr( 'id', player_id );

		// Player
		var player = ayg_init_player( player_id, {			
			custom: {
				params: params,
				image: $( '#' + player_id ).data( 'image' )
			},
			events: {
				'onStateChange': function( e ) {
					// On Playing
					if ( e.data == YT.PlayerState.PLAYING ) {
						ayg_pause_other_players( player_id );
					}

					// On Ended
					if ( e.data == YT.PlayerState.ENDED ) {
						if ( 1 == params.loop ) {
							player.play();
						}
					}				 
				}
			}
		});		
	}		

	/**
	 * Init AYGPlayer.
	 *
	 * @since 2.0.0
	 */
	var ayg_init_player = function( player_id, args ) {
		var $player_elem     = $( '#' +  player_id );
		var $player_wrapper  = $player_elem.closest( 'div' );
		var $privacy_wrapper = null;

		var player   = null;					
		var video_id = $player_elem.data( 'id' );
		var params   = args.custom.params;

		var init_player = function() {
			var domain = 'https://www.youtube.com';
			if ( 1 == ayg_public.privacy_enhanced_mode ) {
				domain = 'https://www.youtube-nocookie.com';
			}

			var iframe_src = domain + '/embed/' + video_id + '?enablejsapi=1&playsinline=1&rel=0';

			if ( ayg_public.origin ) {
				iframe_src += '&origin=' + ayg_public.origin;
			}

			if ( params.hasOwnProperty( 'autoplay' ) ) {
				iframe_src += ( '&autoplay=' + parseInt( params.autoplay ) );
			}

			if ( params.hasOwnProperty( 'muted' ) ) {
				iframe_src += ( '&mute=' + parseInt( params.muted ) );
			}
		
			if ( params.hasOwnProperty( 'controls' ) ) {
				iframe_src += ( '&controls=' + parseInt( params.controls ) );
			}
		
			if ( params.hasOwnProperty( 'modestbranding' ) ) {
				iframe_src += ( '&modestbranding=' + parseInt( params.modestbranding ) );
			}
		
			if ( params.hasOwnProperty( 'cc_load_policy' ) ) {
				iframe_src += ( '&cc_load_policy=' + parseInt( params.cc_load_policy ) );
			}
		
			if ( params.hasOwnProperty( 'iv_load_policy' ) ) {
				iframe_src += ( '&iv_load_policy=' + parseInt( params.iv_load_policy ) );
			}
		
			if ( params.hasOwnProperty( 'hl' ) ) {
				iframe_src += ( '&hl=' + params.hl );
			}
		
			if ( params.hasOwnProperty( 'cc_lang_pref' ) ) {
				iframe_src += ( '&cc_lang_pref=' + params.cc_lang_pref );
			}

			if ( $player_elem.prop( 'tagName' ).toLowerCase() == 'iframe' ) {
				$player_elem.attr( 'src', iframe_src );	
			} else {
				$player_elem.replaceWith( '<iframe id="' + player_id + '" class="ayg-player-iframe" width="100%" height="100%" src="' + iframe_src + '" frameborder="0" allow="accelerometer; autoplay; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>' );
				$player_elem = $( '#' +  player_id );
			}					

			init_youtube_iframe_api().then(() => {
				player = new YT.Player( player_id, { events: args.events } );
			});
		};

		var remove_cookie_consent = function() {
			if ( $privacy_wrapper ) {
				$privacy_wrapper.remove();
				$privacy_wrapper = null;
			}
		};

		if ( 1 == ayg_public.cookie_consent ) {
			var html = '<div class="ayg-privacy-wrapper" style="background-image: url(' + args.custom.image + ');">';
			html += '<div class="ayg-privacy-consent-block">';
			html += '<div class="ayg-privacy-consent-message">' + ayg_public.consent_message + '</div>';
			html += '<div class="ayg-privacy-consent-button ayg-button">' + ayg_public.button_label + '</div>';
			html += '</div>';
			html += '</div>';

			$player_wrapper.append( html );
			$privacy_wrapper = $player_wrapper.find( '.ayg-privacy-wrapper' );

			$privacy_wrapper.on( 'click', '.ayg-privacy-consent-button', function() {
				$( this ).html( '...' );

				ayg_public.cookie_consent = 0;

				params.autoplay = 1;
				init_player();

				remove_cookie_consent();

				var data = {
					'action': 'ayg_set_cookie',
					'security': ayg_public.ajax_nonce
				};
	
				$.post( ayg_public.ajax_url, data, function( response ) {
					// Do nothing
				});
			});
		} else {
			init_player();
		}	
		
		return {
			play: function() {
				if ( player && player.playVideo ) {
					player.playVideo();
				}
			},
			change: function( obj ) {
				if ( player && player.loadVideoById ) {
					player.loadVideoById( obj.id );
				} else {
					video_id = obj.id;

					if ( $privacy_wrapper ) {
						if ( 1 == ayg_public.cookie_consent ) {
							$privacy_wrapper.css( 'background-image', "url(" + obj.image + ")" );
						} else {
							params.autoplay = 1;
							init_player();

							remove_cookie_consent();
						}
					} else {
						params.autoplay = 1;
						init_player();
					}				
				}
			},
			stop: function() {
				if ( player && player.stopVideo ) {
					player.stopVideo();
				}
			},
			destroy: function() {
				if ( player ) {
					if ( player.stopVideo ) {
						player.stopVideo();
					}

					if ( player.destroy ) {
						player.destroy();
					}
				} else {
					$player_elem.remove();
				}

				if ( 1 == ayg_public.cookie_consent ) {
					remove_cookie_consent();
				}
			}
		};
	}

	window.ayg_init_player = ayg_init_player;

	/**
	 * Init Pagination.
	 *
	 * @since 2.0.0
	 */
	var ayg_init_pagination = function( $pagination ) {
		var params = $pagination.data( 'params' );

		params.action = 'ayg_load_more_videos';
		params.security = ayg_public.ajax_nonce;
		
		var total_pages = parseInt( params.total_pages );
		var paged = 1;
		var previous_page_tokens = [''];

		var $gallery = $pagination.closest( '.ayg' ).find( '.ayg-gallery' );

		// On next/more button clicked
		$pagination.on( 'click', '.ayg-pagination-next-btn', function() {
			var $this = $( this );

			$pagination.addClass( 'ayg-loading' );				

			var type = $this.data( 'type' );

			params.pageToken = params.next_page_token;
			previous_page_tokens[ paged ] = params.next_page_token;

			$.post( ayg_public.ajax_url, params, function( response ) {
				if ( response.success ) {
					paged = Math.min( paged + 1, total_pages );

					params.next_page_token = '';
					if ( paged < total_pages && response.data.next_page_token ) {
						params.next_page_token = response.data.next_page_token;
					}

					switch ( type ) {
						case 'more':
							$gallery.append( response.data.html );
							break;						
						case 'next':
							$pagination.find( '.ayg-pagination-prev-btn' ).show();
							$pagination.find( '.ayg-pagination-current-page-number' ).html( paged );		

							$gallery.html( response.data.html );
							break;
					}

					if ( '' == params.next_page_token ) {
						$this.hide();
					}

					$pagination.trigger( 'gallery.updated' );
				}

				$pagination.removeClass( 'ayg-loading' );
			});
		});

		// On previous button clicked
		$pagination.on( 'click', '.ayg-pagination-prev-btn', function() {
			var $this = $( this );

			$pagination.addClass( 'ayg-loading' );	
					
			paged = Math.max( paged - 1, 1 );

			params.pageToken = previous_page_tokens[ paged - 1 ];

			$.post( ayg_public.ajax_url, params, function( response ) {
				if ( response.success ) {
					params.next_page_token = '';
					if ( response.data.next_page_token ) {
						params.next_page_token = response.data.next_page_token;
					}

					$gallery.html( response.data.html );

					$pagination.find( '.ayg-pagination-next-btn' ).show();
					$pagination.find( '.ayg-pagination-current-page-number' ).html( paged );			

					if ( 1 == paged ) {
						$this.hide();
					}

					$pagination.trigger( 'gallery.updated' );
				}

				$pagination.removeClass( 'ayg-loading' );
			});
		});
	}

	window.ayg_init_pagination = ayg_init_pagination;

	/**
	 * Pause other players.
	 *
	 * @since 2.3.0
	 */
	var ayg_pause_other_players = function( player_id ) {
		if ( ! ayg_public.active_player_id ) {
			ayg_public.active_player_id = player_id;
		}

		if ( player_id == ayg_public.active_player_id ) {
			return false;
		}
		
		ayg_public.active_player_id = player_id;

		$( 'iframe.ayg-player-iframe:not(#' + player_id + ')' ).each(function() {
			this.contentWindow.postMessage( '{"event":"command", "func":"pauseVideo", "args":""}', '*' );
		});
	}
	
	window.ayg_pause_other_players = ayg_pause_other_players;

	/**
	 * Called when the page has loaded.
	 *
	 * @since 1.0.0
	 */
	$(function() {
		// Theme: Classic
		$( '.ayg-theme-classic' ).each(function() {
			init_classic_theme( $( this ) );
		});	
		
		// Theme: Single Video
		$( '.ayg-theme-single' ).each(function() {
			init_single_video( $( this ) );
		});

		// Theme: Livestream
		$( '.ayg-theme-livestream' ).each(function() {
			init_single_video( $( this ) );
		});

		// Locate gallery element on single video pages
		var gallery_id = ayg_public.gallery_id;

		if ( '' != gallery_id && $( '#ayg-' + gallery_id ).length ) {
			if ( history.scrollRestoration ) {
				history.scrollRestoration = 'manual';
			} else {
				window.onbeforeunload = function() {
					window.scrollTo( 0, 0 );
				}
			}
			
			$( 'html, body' ).animate({
				scrollTop: $( '#ayg-' + gallery_id ).offset().top - ayg_public.top_offset
			}, 500);	
		}

		// Toggle more/less content in the player description
		$( document ).on( 'click', '.ayg-player-description-toggle-btn', function( event ) {
			event.preventDefault();

			var $this = $( this);
			var $description = $this.closest( '.ayg-player-description' );
			var $dots = $description.find( '.ayg-player-description-dots' );
			var $more = $description.find( '.ayg-player-description-more' );

			if ( $dots.is( ':visible' ) ) {
				$this.html( ayg_public.i18n.show_less );
				$dots.hide();
				$more.fadeIn();									
			} else {					
				$more.fadeOut(function() {
					$this.html( ayg_public.i18n.show_more );
					$dots.show();					
				});								
			}	
		});		
	});

})( jQuery );