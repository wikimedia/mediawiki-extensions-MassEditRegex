( function () {

	function getDataFromPath( data, path ) {
		path = path.split( '.' );
		for ( let x = 0; x < path.length; x++ ) {
			const dataKeys = [];
			for ( const k in data ) {
				dataKeys.push( k );
			}
			if ( !dataKeys.includes( path[ x ] ) ) {
				return false;
			}
			data = data[ path[ x ] ];
		}
		return data;
	}

	function makeAPICall( data, callback, c ) {
		let waitForCallback = false;

		if ( c !== undefined ) {
			data.params[ data.continueKey ] = c;
		}

		$.ajax( {
			url: mw.util.wikiScript( 'api' ),
			data: data.params,
			dataType: 'json',
			type: 'POST',
			success: function ( response ) {
				let result = getDataFromPath( response, data.resultPath );
				if ( result ) {
					if ( data.continuePath ) {
						const continueData = getDataFromPath( response, data.continuePath );
						if ( continueData ) {
							waitForCallback = true;
							makeAPICall( data, ( r ) => {
								waitForCallback = false;
								result = result.concat( r );
							}, continueData );
						}
						var timerId = setInterval( () => {
							if ( !waitForCallback ) {
								callback( result );
								clearInterval( timerId );
							}
						}, 10 );
					} else {
						callback( result );
					}
				} else if ( response && response.error ) {
					alert( mw.msg( 'masseditregex-js-mwapi-api-error',
						response.error.code, response.error.info ) );
				} else {
					alert( mw.msg( 'masseditregex-js-mwapi-general-error' ) );
				}
			},
			error: function () {
				alert( mw.msg( 'masseditregex-js-mwapi-unknown-error' ) );
			}
		} );
	}

	function getCategoryPages( page, callback ) {
		const data = {
			resultPath: 'query.categorymembers',
			continuePath: 'query-continue.categorymembers.cmcontinue',
			continueKey: 'cmcontinue',
			params: {
				format: 'json',
				action: 'query',
				list: 'categorymembers',
				cmtitle: 'Category:' + page,
				cmlimit: 100,
				rawcontinue: 1
			}
		};
		makeAPICall( data, callback );
	}

	function getBackLinkPages( page, callback ) {
		const data = {
			resultPath: 'query.backlinks',
			continuePath: 'query-continue.backlinks.blcontinue',
			continueKey: 'cmcontinue',
			params: {
				format: 'json',
				action: 'query',
				list: 'backlinks',
				bltitle: page,
				bllimit: 100,
				rawcontinue: 1
			}
		};
		makeAPICall( data, callback );
	}

	function getAllPrefixPages( page, callback ) {
		const data = {
			resultPath: 'query.namespaces',
			params: {
				format: 'json',
				action: 'query',
				meta: 'siteinfo',
				siprop: 'namespaces'
			}
		};
		makeAPICall( data, ( namespaces ) => {
			let data;
			let nsId;
			const result = {
				pages: []
			};
			let size = 0;
			let count = 0;
			let key;

			// Count number of elements
			for ( key in namespaces ) {
				if ( namespaces[ key ].id > 0 ) {
					size++;
				}
			}

			// Iterate through the namespaces
			for ( key in namespaces ) {
				nsId = namespaces[ key ].id;
				if ( nsId >= 0 ) {
					data = {
						resultPath: 'query.allpages',
						continuePath: 'query-continue.allpages.apcontinue',
						continueKey: 'apcontinue',
						params: {
							format: 'json',
							action: 'query',
							list: 'allpages',
							apnamespace: nsId,
							apprefix: page,
							aplimit: 100,
							rawcontinue: 1
						}
					};

					( function ( data, result, callback ) {
						makeAPICall( data, ( pages ) => {
							result.pages = result.pages.concat( pages );
							if ( callback !== null ) {
								callback( result.pages );
							}
						} );
					}( data, result, count++ === size ? callback : null ) );

				}
			}
		} );
	}

	function getPages( pages, callback ) {
		const data = {
			resultPath: 'query.pages',
			params: {
				format: 'json',
				action: 'query',
				titles: pages.join( '|' ),
				rawcontinue: 1
			}
		};
		makeAPICall( data, ( data ) => {
			// Convert from object to array
			pages = [];
			for ( const key in data ) {
				pages.push( data[ key ] );
			}
			callback( pages );
		} );
	}

	function editPages( pages, search, replace, summary, cb ) {
		const rObj = { remaining: pages.length };
		if ( pages.length === 0 ) {
			cb( { error: 'No pages found!' } );
			return;
		}

		for ( let x = 0; x < pages.length; x++ ) {
			( function ( page, search, replace, cb, rObj ) {
				const pageId = page.pageid;

				if ( pageId === undefined ) {
					cb( { error: mw.msg( 'masseditregex-js-pagenotexist', page.title ) } );
				} else {
					$.ajax( {
						url: mw.util.wikiScript(),
						data: {
							action: 'ajax',
							rs: 'MassEditRegexAPI::edit',
							rsargs: [ pageId, search, replace, summary ]
						},
						dataType: 'json',
						type: 'POST'
					} ).done( ( response ) => {
						rObj.remaining--;
						cb( page, response, rObj.remaining );
					} );
				}
			}( pages[ x ], search, replace, cb, rObj ) );
		}
	}

	function doEdit( pages ) {
		const search = $( '#wpMatch' ).val();
		const replace = $( '#wpReplace' ).val();
		const summary = $( '#wpSummary' ).val();
		const $content = $( '<div>' );
		const $heading = $( '<h1>' );
		$content.append( $heading );
		$heading.text( mw.msg( 'masseditregex-js-working', '?' ) );
		const $list = $( '<ul>' );
		$content.append( $list );

		$content.dialog( {
			height: $( window ).height() * 0.8,
			width: $( window ).width() * 0.8,
			modal: true
		} );

		editPages( pages, search, replace, summary,
			( page, response, remaining ) => {
				let $li = $( '<li>' );

				if ( page.error || response.error ) {
					$li.text( page.title + ': ' + page.error ? page.error : response.error );
				} else {
					$li.text( mw.msg( 'masseditregex-num-changes', page.title,
						response.changes ) );
					$heading.text( mw.msg( 'masseditregex-js-working', remaining ) );
				}

				$list.prepend( $li );

				if ( remaining === 0 ) {
					$li = $( '<li>' );
					$li.text( mw.msg( 'masseditregex-js-jobdone' ) );
					$list.prepend( $li );
					$heading.text( mw.msg( 'masseditregex-js-jobdone' ) );
				}
			}
		);
	}

	function executeMassEdit() {
		const pages = $( '#wpPageList' ).val().split( '\n' );
		const type = $( 'input[name="wpPageListType"]:checked' ).val();

		let x;
		switch ( type ) {
			case 'pagenames':
				getPages( pages, doEdit );
				break;
			case 'pagename-prefixes':
				for ( x = 0; x < pages.length; x++ ) {
					getAllPrefixPages( pages[ x ], doEdit );
				}
				break;
			case 'backlinks':
				for ( x = 0; x < pages.length; x++ ) {
					getBackLinkPages( pages[ x ], doEdit );
				}
				break;
			case 'categories':
				for ( x = 0; x < pages.length; x++ ) {
					getCategoryPages( pages[ x ], doEdit );
				}
				break;
		}
	}

	$( () => {
		$( '#wpSave' ).on( 'click', () => {
			if ( $( '#wpClientSide' ).is( ':checked' ) ) {
				executeMassEdit();
				return false;
			}
			return true;
		} );
	} );

}() );
