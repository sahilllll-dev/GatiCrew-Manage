( function ( $ ) {
	'use strict';

	var selector = '.gaticrew-events-bridge-product-search';

	function getSelectPlugin() {
		if ( $.fn.selectWoo ) {
			return 'selectWoo';
		}

		if ( $.fn.select2 ) {
			return 'select2';
		}

		return null;
	}

	function initProductSearch() {
		var plugin = getSelectPlugin();

		if ( ! plugin ) {
			return;
		}

		$( selector ).each( function () {
			var $field = $( this );

			if ( $field.data( 'gaticrewEnhanced' ) ) {
				return;
			}

			$field[ plugin ]( {
				allowClear: true,
				minimumInputLength: 2,
				placeholder: $field.data( 'placeholder' ) || '',
				width: '100%',
				ajax: {
					url: window.ajaxurl,
					dataType: 'json',
					delay: 250,
					data: function ( params ) {
						return {
							action: $field.data( 'action' ),
							security: $field.data( 'security' ),
							term: params.term || ''
						};
					},
					processResults: function ( data ) {
						var results = [];

						if ( ! data || 'object' !== typeof data ) {
							return {
								results: results
							};
						}

						$.each( data || {}, function ( id, text ) {
							results.push( {
								id: id,
								text: text
							} );
						} );

						return {
							results: results
						};
					},
					cache: true
				}
			} );

			$field.data( 'gaticrewEnhanced', true );
		} );
	}

	$( initProductSearch );
	$( document.body ).on( 'wc-enhanced-select-init', initProductSearch );
} )( jQuery );
