/* global wpVault, jQuery */
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
	$( '#wpvault-run-backup' ).on( 'click', function () {
		var $btn    = $( this );
		var type    = $( '#wpvault-backup-type' ).val();
		var note    = $( '#wpvault-backup-note' ).val();
		var $result = $( '#wpvault-backup-result' );

		$btn.prop( 'disabled', true ).text( wpVault.i18n.running );
		$result.hide();

		$.post( wpVault.ajaxUrl, {
			action : 'wpvault_run_backup',
			nonce  : wpVault.nonce,
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
			showResult( $result, wpVault.i18n.error, true );
		} )
		.always( function () {
			$btn.prop( 'disabled', false ).text( wpVault.i18n.running.replace( '...', '' ) );
		} );
	} );

	// Restore from backup list.
	$( document ).on( 'click', '.wpvault-restore-btn', function () {
		if ( ! window.confirm( wpVault.i18n.confirmRestore ) ) {
			return;
		}
		var $btn      = $( this );
		var backupId  = $btn.data( 'id' );
		var $result   = $( '#wpvault-list-result' );

		$btn.prop( 'disabled', true ).text( wpVault.i18n.running );

		$.post( wpVault.ajaxUrl, {
			action    : 'wpvault_run_restore',
			nonce     : wpVault.nonce,
			backup_id : backupId,
			type      : 'full',
		} )
		.done( function ( res ) {
			showResult( $result, res.success ? res.data.message : res.data.message, ! res.success );
		} )
		.fail( function () {
			showResult( $result, wpVault.i18n.error, true );
		} )
		.always( function () {
			$btn.prop( 'disabled', false ).text( wpVault.i18n.running.replace( '...', '' ) );
		} );
	} );

	// Delete backup.
	$( document ).on( 'click', '.wpvault-delete-btn', function () {
		if ( ! window.confirm( wpVault.i18n.confirmDelete ) ) {
			return;
		}
		var $btn     = $( this );
		var backupId = $btn.data( 'id' );
		var $row     = $btn.closest( 'tr' );
		var $result  = $( '#wpvault-list-result' );

		$btn.prop( 'disabled', true );

		$.post( wpVault.ajaxUrl, {
			action    : 'wpvault_delete_backup',
			nonce     : wpVault.nonce,
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
			showResult( $result, wpVault.i18n.error, true );
		} )
		.always( function () {
			$btn.prop( 'disabled', false );
		} );
	} );

} )( jQuery );
