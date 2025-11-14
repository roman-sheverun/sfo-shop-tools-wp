jQuery( document ).ready( function ( $ ) {
	$( '#woocommerce_easyship_es_oauth_ajax' ).on( 'click', function ( e ) {
		e.preventDefault();

		// Obtain the previous nonce from local parameters.
		let nonce = oauth_action_button_es_params.nonce;

		let data = {
			action: 'oauth_es',
			nonce: nonce, // Add the previous nonce to the application data.
		};

		$.ajax( {
			url: oauth_action_button_es_params.url,
			type: 'POST',
			dataType: 'json',
			data: data,
			success: function ( response ) {
				if ( response.error ) {
					console.log( response );
				} else {
					window.open( response.redirect_url, '_self' );
				}
			}
		} );
	} );

	$( '#woocommerce_easyship_es_ajax_disabled' ).on( 'click', function ( e ) {
		e.preventDefault();

		// Obtain the previous nonce from local parameters.
		let nonce = oauth_action_button_es_params.nonce;

		let data = {
			action: 'es_disabled',
			nonce: nonce, // Add the previous nonce to the application data.
		};

		$.ajax( {
			url: oauth_action_button_es_params.url,
			type: 'POST',
			dataType: 'json',
			data: data,
			success: function ( response ) {
				if ( response.error ) {
					console.log( response );
				} else {
					location.reload();
				}
			}
		} );
	} );
} );
