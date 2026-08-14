/* global tkvaultAdmin, jQuery */
( function ( $ ) {
	'use strict';

	function showResult( $el, message, isError ) {
		$el
			.removeClass( 'is-success is-error' )
			.addClass( isError ? 'is-error' : 'is-success' )
			.text( message )
			.show();
	}

	// Settings: keep the destination un-saveable while the user is being asked
	// to confirm a publicly readable directory. The server refuses it too -
	// this only stops the click from looking like it worked.
	( function () {
		var $accept = $( '#tkvault-accept-public' );
		if ( ! $accept.length ) {
			return;
		}

		var $submit = $accept.closest( 'form' ).find( ':submit' );
		var $choice = $( 'input[name="tkvault_public_choice"]' );

		function sync() {
			var accepting = $( '#tkvault-choice-accept' ).is( ':checked' );
			$accept.prop( 'disabled', ! accepting );
			if ( ! accepting ) {
				$accept.prop( 'checked', false );
			}
			$submit.prop( 'disabled', accepting && ! $accept.is( ':checked' ) );
		}

		$choice.on( 'change', sync );
		$accept.on( 'change', sync );
		sync();
	} )();

	// Job progress. Polling is not only for display: each poll advances the
	// job server-side, which is what carries it to completion on hosts where
	// loopback requests are blocked.
	$( '.tkvault-job' ).each( function () {
		var $panel = $( this );

		var $start    = $panel.find( '.tkvault-job-start' );
		var $cancel   = $panel.find( '.tkvault-job-cancel' );
		var $progress = $panel.find( '.tkvault-progress' );
		var $bar      = $panel.find( '.tkvault-progress-bar span' );
		var $text     = $panel.find( '.tkvault-progress-text' );
		var timer     = null;

		function format( template, a, b ) {
			return template.replace( '%1$d', a ).replace( '%2$d', b );
		}

		function render( job ) {
			$bar.css( 'width', ( job.percent || 0 ) + '%' );

			if ( 'complete' === job.status ) {
				$text.text( format( tkvaultAdmin.i18n.jobComplete, job.processed, job.total ) );
			} else if ( 'failed' === job.status ) {
				$text.text( tkvaultAdmin.i18n.jobFailed + ' ' + ( job.message || '' ) );
			} else if ( 'cancelled' === job.status ) {
				$text.text( tkvaultAdmin.i18n.jobCancelled );
			} else {
				$text.text( format( tkvaultAdmin.i18n.jobRunning, job.processed, job.total ) );
			}

			var finished = [ 'complete', 'failed', 'cancelled', 'missing' ].indexOf( job.status ) !== -1;
			if ( finished ) {
				stop();
			}
		}

		function stop() {
			if ( timer ) {
				window.clearTimeout( timer );
				timer = null;
			}
			$start.prop( 'disabled', false ).text( idleLabel );
			$cancel.hide();
		}

		function poll() {
			var id = parseInt( $panel.attr( 'data-job' ), 10 );
			if ( ! id ) {
				return;
			}

			$.post( tkvaultAdmin.ajaxUrl, {
				action : 'tkvault_job_poll',
				nonce  : tkvaultAdmin.nonce,
				job    : id,
			} )
			.done( function ( res ) {
				if ( res.success ) {
					render( res.data );
					if ( timer !== null || [ 'pending', 'running' ].indexOf( res.data.status ) !== -1 ) {
						timer = window.setTimeout( poll, 1000 );
					}
				}
			} )
			.fail( function () {
				timer = window.setTimeout( poll, 3000 );
			} );
		}

		var idleLabel = $start.text();

		$start.on( 'click', function ( e ) {
			e.preventDefault();
			$start.prop( 'disabled', true ).text( tkvaultAdmin.i18n.jobStarting );
			$progress.show();
			$cancel.show();

			$.post( tkvaultAdmin.ajaxUrl, {
				action        : $panel.data( 'start-action' ),
				nonce         : tkvaultAdmin.nonce,
				chunks        : 20,
				note          : $( $panel.data( 'note-field' ) ).val() || '',
				type          : $( $panel.data( 'type-field' ) ).val() || 'db',
				backup_id     : $panel.attr( 'data-backup-id' ) || 0,
				confirm_space : $panel.attr( 'data-confirm-space' ) || '',
			} )
			.done( function ( res ) {
				if ( ! res.success ) {
					$text.text( res.data.message );
					stop();
					return;
				}
				$panel.attr( 'data-job', res.data.id );
				render( res.data );
				timer = window.setTimeout( poll, 500 );
			} )
			.fail( function () {
				$text.text( tkvaultAdmin.i18n.error );
				stop();
			} );
		} );

		$cancel.on( 'click', function ( e ) {
			e.preventDefault();
			$.post( tkvaultAdmin.ajaxUrl, {
				action : 'tkvault_cancel_job',
				nonce  : tkvaultAdmin.nonce,
				job    : parseInt( $panel.attr( 'data-job' ), 10 ),
			} )
			.done( function ( res ) {
				if ( res.success ) {
					render( res.data );
				}
			} );
		} );
	} );

	// Restore from the backup list. The row button only points the shared job
	// panel at a backup; the panel does the work and shows progress.
	$( document ).on( 'click', '.tkvault-restore-btn', function ( e ) {
		e.preventDefault();
		if ( ! window.confirm( tkvaultAdmin.i18n.confirmRestore ) ) {
			return;
		}

		var $panel = $( '.tkvault-job[data-start-action="tkvault_start_restore"]' );
		$panel.attr( 'data-backup-id', $( this ).data( 'id' ) );
		$panel.attr( 'data-confirm-space', '1' );
		$panel.find( '.tkvault-job-start' ).trigger( 'click' );
	} );

	// The restore panel has no start button of its own, so give it one the
	// row buttons can trigger.
	$( '.tkvault-job[data-start-action="tkvault_start_restore"]' ).each( function () {
		if ( ! $( this ).find( '.tkvault-job-start' ).length ) {
			$( this ).prepend( '<button class="button tkvault-job-start" style="display:none;"></button>' );
		}
	} );

	$( document ).on( 'click', '.tkvault-undo-restore', function ( e ) {
		e.preventDefault();
		var $btn    = $( this );
		var $result = $( '#tkvault-list-result' );

		$btn.prop( 'disabled', true ).text( tkvaultAdmin.i18n.running );

		$.post( tkvaultAdmin.ajaxUrl, {
			action : 'tkvault_undo_restore',
			nonce  : tkvaultAdmin.nonce,
			kind   : $btn.data( 'kind' ) || 'db',
		} )
		.done( function ( res ) {
			showResult( $result, res.data.message, ! res.success );
			if ( res.success ) {
				setTimeout( function () { location.reload(); }, 1500 );
			}
		} )
		.fail( function () {
			showResult( $result, tkvaultAdmin.i18n.error, true );
		} )
		.always( function () {
			$btn.prop( 'disabled', false );
		} );
	} );

	// Delete backup.
	$( document ).on( 'click', '.tkvault-delete-btn', function () {
		if ( ! window.confirm( tkvaultAdmin.i18n.confirmDelete ) ) {
			return;
		}
		var $btn     = $( this );
		var backupId = $btn.data( 'id' );
		var $row     = $btn.closest( 'tr' );
		var $result  = $( '#tkvault-list-result' );

		$btn.prop( 'disabled', true );

		$.post( tkvaultAdmin.ajaxUrl, {
			action    : 'tkvault_delete_backup',
			nonce     : tkvaultAdmin.nonce,
			backup_id : backupId,
		} )
		.done( function ( res ) {
			if ( res.success ) {
				$row.fadeOut( 300, function () { $( this ).remove(); } );
			} else {
				showResult( $result, res.data.message, true );
			}
		} )
		.fail( function () {
			showResult( $result, tkvaultAdmin.i18n.error, true );
		} )
		.always( function () {
			$btn.prop( 'disabled', false );
		} );
	} );

} )( jQuery );
