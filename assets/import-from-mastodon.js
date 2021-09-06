jQuery( document ).ready( function ( $ ) {
	$( '.settings_page_import-from-mastodon .button-reset-settings' ).click( function( e ) {
		if ( ! confirm( import_from_mastodon_obj.message ) ) {
			e.preventDefault();
		}
	} );
} );
