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

	// Run backup from dashboard.
	$( '#tkvault-run-backup' ).on( 'click', function () {
		var $btn    = $( this );
		var type    = $( '#tkvault-backup-type' ).val();
		var note    = $( '#tkvault-backup-note' ).val();
		var $result = $( '#tkvault-backup-result' );

		$btn.prop( 'disabled', true ).text( tkvaultAdmin.i18n.running );
		$result.hide();

		$.post( tkvaultAdmin.ajaxUrl, {
			action : 'tkvault_run_backup',
			nonce  : tkvaultAdmin.nonce,
			type   : type,
			note   : note,
		} )
		.done( function ( res ) {
			if ( res.success ) {
				showResult( $result, res.data.message, false );
				setTimeout( function () { location.reload(); }, 1500 );
			} else {
				showResult( $result, res.data.message, true );
			}
		} )
		.fail( function () {
			showResult( $result, tkvaultAdmin.i18n.error, true );
		} )
		.always( function () {
			$btn.prop( 'disabled', false ).text( tkvaultAdmin.i18n.idle );
		} );
	} );

	// Restore from backup list.
	$( document ).on( 'click', '.tkvault-restore-btn', function () {
		if ( ! window.confirm( tkvaultAdmin.i18n.confirmRestore ) ) {
			return;
		}
		var $btn     = $( this );
		var label    = $btn.text();
		var backupId = $btn.data( 'id' );
		var $result  = $( '#tkvault-list-result' );

		$btn.prop( 'disabled', true ).text( tkvaultAdmin.i18n.running );

		$.post( tkvaultAdmin.ajaxUrl, {
			action    : 'tkvault_run_restore',
			nonce     : tkvaultAdmin.nonce,
			backup_id : backupId,
			type      : 'full',
		} )
		.done( function ( res ) {
			showResult( $result, res.data.message, ! res.success );
		} )
		.fail( function () {
			showResult( $result, tkvaultAdmin.i18n.error, true );
		} )
		.always( function () {
			$btn.prop( 'disabled', false ).text( label );
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
