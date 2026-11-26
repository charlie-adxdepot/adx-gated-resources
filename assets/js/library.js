(function () {
	'use strict';

	/**
	 * Drives one [adx_gated_library] page: parent-category chips, series
	 * chips (single-select) and a free-text search, combined with AND
	 * logic and applied client-side to each card's data-* attributes.
	 * A series' description card shows whenever any of its downloads do.
	 * Downloading itself is handled by the shared .adx-gr-trigger click
	 * handler in frontend.js -- this file only shows and hides cards.
	 */
	function initLibrary( root ) {
		var parentChips = Array.prototype.slice.call( root.querySelectorAll( '[data-adx-gr-parent]' ) );
		var seriesChips = Array.prototype.slice.call( root.querySelectorAll( '[data-adx-gr-series]' ) );
		var cards = Array.prototype.slice.call( root.querySelectorAll( '.adx-gr-lib__card' ) );
		var searchInput = root.querySelector( '[data-adx-gr-library-search]' );
		var clearButton = root.querySelector( '[data-adx-gr-library-clear]' );
		var countEl = root.querySelector( '[data-adx-gr-library-count]' );
		var emptyEl = root.querySelector( '[data-adx-gr-library-empty]' );

		var activeParent = '';
		var activeSeries = '';
		var terms = [];

		function setPressed( chip, on ) {
			chip.classList.toggle( 'is-active', on );
			chip.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
		}

		function renderChips() {
			parentChips.forEach( function ( chip ) {
				setPressed( chip, chip.getAttribute( 'data-adx-gr-parent' ) === activeParent );
			} );

			seriesChips.forEach( function ( chip ) {
				var key = chip.getAttribute( 'data-adx-gr-series' );
				// "All" always shows; a series chip only under its own parent.
				chip.hidden = '' !== key && '' !== activeParent && chip.getAttribute( 'data-parent' ) !== activeParent;
				setPressed( chip, key === activeSeries );
			} );
		}

		function matchesSearch( card ) {
			if ( ! terms.length ) {
				return true;
			}
			var haystack = card.getAttribute( 'data-search' ) || '';
			return terms.every( function ( term ) {
				return haystack.indexOf( term ) !== -1;
			} );
		}

		function apply() {
			var visibleGroups = {};
			var visibleCount = 0;

			cards.forEach( function ( card ) {
				if ( 'desc' === card.getAttribute( 'data-kind' ) ) {
					return;
				}

				var visible = ( '' === activeParent || card.getAttribute( 'data-parent' ) === activeParent ) &&
					( '' === activeSeries || card.getAttribute( 'data-group' ) === activeSeries ) &&
					matchesSearch( card );

				card.hidden = ! visible;

				if ( visible ) {
					visibleGroups[ card.getAttribute( 'data-group' ) ] = true;
					visibleCount++;
				}
			} );

			cards.forEach( function ( card ) {
				if ( 'desc' === card.getAttribute( 'data-kind' ) ) {
					card.hidden = ! visibleGroups[ card.getAttribute( 'data-group' ) ];
				}
			} );

			if ( countEl ) {
				countEl.textContent = visibleCount + ' ' + ( 1 === visibleCount ? adxGatedLibrary.i18n.oneResult : adxGatedLibrary.i18n.manyResults );
			}

			if ( emptyEl ) {
				emptyEl.hidden = visibleCount > 0;
			}
		}

		parentChips.forEach( function ( chip ) {
			chip.addEventListener( 'click', function () {
				activeParent = chip.getAttribute( 'data-adx-gr-parent' );
				activeSeries = '';
				renderChips();
				apply();
			} );
		} );

		seriesChips.forEach( function ( chip ) {
			chip.addEventListener( 'click', function () {
				activeSeries = chip.getAttribute( 'data-adx-gr-series' );
				renderChips();
				apply();
			} );
		} );

		function onSearchInput() {
			terms = searchInput.value.toLowerCase().split( /\s+/ ).filter( Boolean );
			if ( clearButton ) {
				clearButton.hidden = '' === searchInput.value;
			}
			apply();
		}

		// The × in the search box (or Esc): back to the default view --
		// no search text, all categories, all series.
		function resetToDefault() {
			searchInput.value = '';
			activeParent = '';
			activeSeries = '';
			renderChips();
			onSearchInput();
			searchInput.focus();
		}

		if ( searchInput ) {
			searchInput.addEventListener( 'input', onSearchInput );

			searchInput.addEventListener( 'keydown', function ( event ) {
				if ( 'Escape' === event.key && '' !== searchInput.value ) {
					event.preventDefault();
					resetToDefault();
				}
			} );

			if ( clearButton ) {
				clearButton.addEventListener( 'click', resetToDefault );
			}

			// Browsers can restore typed text on Back/refresh; match it.
			if ( '' !== searchInput.value ) {
				onSearchInput();
			}
		}

		// Deep links such as /library/#nexus-64 open that series, exactly as
		// if its chip had been clicked, and scroll the library into view.
		function slug( text ) {
			return String( text ).toLowerCase().replace( /[^a-z0-9]+/g, '-' ).replace( /^-+|-+$/g, '' );
		}

		function seriesFromHash() {
			var hash = '';
			try {
				hash = slug( decodeURIComponent( window.location.hash.replace( /^#/, '' ) ) );
			} catch ( e ) {
				return null;
			}
			if ( ! hash ) {
				return null;
			}
			for ( var i = 0; i < seriesChips.length; i++ ) {
				var key = seriesChips[ i ].getAttribute( 'data-adx-gr-series' );
				if ( '' !== key && ( slug( key ) === hash || slug( seriesChips[ i ].textContent ) === hash ) ) {
					return seriesChips[ i ];
				}
			}
			return null;
		}

		function focusSeriesFromHash( scroll ) {
			var chip = seriesFromHash();
			if ( ! chip ) {
				return;
			}
			activeParent = '';
			activeSeries = chip.getAttribute( 'data-adx-gr-series' );
			renderChips();
			apply();

			if ( scroll ) {
				var target = root.querySelector( '.adx-gr-lib__card--desc[data-group="' + activeSeries + '"]' ) || root;
				var offset = parseInt( window.getComputedStyle( root ).getPropertyValue( '--adx-gr-lib-scroll-offset' ), 10 ) || 120;
				var go = function () {
					window.scrollTo( 0, Math.max( 0, target.getBoundingClientRect().top + window.pageYOffset - offset ) );
				};
				go();
				// Images and fonts shift the layout; line up again once loaded.
				window.addEventListener( 'load', go );
			}
		}

		window.addEventListener( 'hashchange', function () {
			focusSeriesFromHash( true );
		} );

		renderChips();
		apply();
		focusSeriesFromHash( true );
	}

	/**
	 * Full-width libraries: stretch exactly from the window's left edge to
	 * its right edge (excluding the scrollbar, which the CSS-only 100vw
	 * includes), wherever the theme's content column happens to sit.
	 * Re-run on resize, which also covers zooming in and out.
	 */
	function fitFullWidth( root ) {
		var viewport = document.documentElement.clientWidth;

		if ( ! viewport ) {
			return;
		}

		// Size it, drop the CSS-only offset, then shift it by however far
		// from the window's left edge it actually sits. Measuring the real
		// position (rather than relying on margins) also works in themes
		// that force their own margins on page content.
		root.style.width = viewport + 'px';
		root.style.marginLeft = '0px';
		root.style.marginRight = '0px';
		root.style.left = '0px';
		root.style.left = ( -root.getBoundingClientRect().left ) + 'px';
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		if ( typeof adxGatedLibrary === 'undefined' ) {
			return;
		}

		var libraries = document.querySelectorAll( '[data-adx-gr-library]' );
		libraries.forEach( initLibrary );

		var fullWidth = Array.prototype.slice.call( document.querySelectorAll( '.adx-gr-lib--full' ) );

		if ( fullWidth.length ) {
			var refit = function () {
				fullWidth.forEach( fitFullWidth );
			};
			var timer;

			refit();
			window.addEventListener( 'resize', function () {
				window.clearTimeout( timer );
				timer = window.setTimeout( refit, 100 );
			} );
			// Fonts and images loading can shift the column; line up again.
			window.addEventListener( 'load', refit );
		}
	} );
})();
