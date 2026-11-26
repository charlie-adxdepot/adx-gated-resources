(function () {
	'use strict';

	if ( typeof adxGatedResources === 'undefined' ) {
		return;
	}

	var modal = null;
	var activeResourceId = null;
	var activePageId = null;
	var activeTrigger = null;

	var IDENTITY_EMAIL = 'adx_gr_email';
	var IDENTITY_KEY = 'adx_gr_key';
	var IDENTITY_STORE = 'adxGrIdentity';

	/**
	 * The signed identity the gate form hands out via the URL after a
	 * successful page unlock (see ADX_GR_Ajax::build_page_response).
	 * Remembered in localStorage too, so it still applies after the
	 * visitor navigates to a page without it in the URL. Forwarded on
	 * every gate request so downloads are attributed to that email
	 * without re-asking for the form, even if cookies don't stick.
	 */
	function getIdentity() {
		var params = new URLSearchParams( window.location.search );
		var email = params.get( IDENTITY_EMAIL );
		var key = params.get( IDENTITY_KEY );

		if ( email && key ) {
			try {
				window.localStorage.setItem( IDENTITY_STORE, JSON.stringify( { email: email, key: key } ) );
			} catch ( err ) {}
			return { email: email, key: key };
		}

		try {
			var stored = JSON.parse( window.localStorage.getItem( IDENTITY_STORE ) || 'null' );
			if ( stored && stored.email && stored.key ) {
				return stored;
			}
		} catch ( err ) {}

		return null;
	}

	/**
	 * Reads every named field in the gate form (email plus whatever
	 * fields are configured under Gated Resources > Form Fields) and
	 * checks the ones marked required, without needing to know their
	 * names or types ahead of time.
	 */
	function collectFields( form ) {
		var fields = {};
		var missing = false;

		form.querySelectorAll( '[name]' ).forEach( function ( el ) {
			var name = el.getAttribute( 'name' );
			var value;

			if ( 'checkbox' === el.type ) {
				value = el.checked ? '1' : '';
			} else {
				value = ( el.value || '' ).trim();
			}

			fields[ name ] = value;

			if ( el.hasAttribute( 'required' ) && ! value ) {
				missing = true;
			}
		} );

		return { fields: fields, missing: missing };
	}

	function getModal() {
		if ( ! modal ) {
			modal = document.getElementById( 'adx-gr-modal' );
		}
		return modal;
	}

	function setSubmitText( submitBtn, text ) {
		var span = submitBtn.querySelector( '.adx-gr-modal__submit-text' );
		if ( span ) {
			span.textContent = text;
		} else {
			submitBtn.textContent = text;
		}
	}

	function setFieldsDisabled( form, disabled ) {
		var fields = form.querySelectorAll( 'input, select, textarea' );
		for ( var i = 0; i < fields.length; i++ ) {
			fields[ i ].disabled = disabled;
		}
	}

	function isLibraryTrigger( trigger ) {
		return !! trigger && 'library' === trigger.getAttribute( 'data-adx-gr-context' );
	}

	function submitLabel() {
		return isLibraryTrigger( activeTrigger ) ? adxGatedResources.i18n.libraryLabel : adxGatedResources.i18n.submitLabel;
	}

	function resetModalView( m ) {
		var form = m.querySelector( '.adx-gr-modal__form' );

		form.hidden = false;
		form.reset();
		setFieldsDisabled( form, false );

		var submitBtn = m.querySelector( '.adx-gr-modal__submit' );
		submitBtn.disabled = false;
		setSubmitText( submitBtn, submitLabel() );

		var note = m.querySelector( '.adx-gr-modal__note' );
		if ( note ) {
			note.textContent = isLibraryTrigger( activeTrigger ) ? adxGatedResources.i18n.libraryNote : note.getAttribute( 'data-default-text' );
		}

		return form;
	}

	function openModal( resourceId, pageId, trigger ) {
		var m = getModal();
		if ( ! m ) {
			return;
		}

		activeResourceId = resourceId;
		activePageId = pageId;
		activeTrigger = trigger;

		var form = resetModalView( m );

		var errorEl = m.querySelector( '.adx-gr-modal__error' );
		errorEl.hidden = true;
		errorEl.textContent = '';

		showModalContainer();

		window.setTimeout( function () {
			var firstField = form.querySelector( 'input, select, textarea' );
			if ( firstField ) {
				firstField.focus();
			}
		}, 50 );
	}

	function closeModal() {
		var m = getModal();
		if ( ! m ) {
			return;
		}
		m.hidden = true;
		m.setAttribute( 'aria-hidden', 'true' );
		document.body.classList.remove( 'adx-gr-modal-open' );
		activeResourceId = null;
		activePageId = null;
		activeTrigger = null;
	}

	function showError( message ) {
		var m = getModal();
		var errorEl = m.querySelector( '.adx-gr-modal__error' );
		errorEl.textContent = message;
		errorEl.hidden = false;
	}

	function showModalContainer() {
		var m = getModal();
		if ( ! m ) {
			return null;
		}
		m.hidden = false;
		m.setAttribute( 'aria-hidden', 'false' );
		document.body.classList.add( 'adx-gr-modal-open' );
		return m;
	}

	/**
	 * A single-file resource and a bundle (zipped server-side, see
	 * ADX_GR_Ajax::stream_bundle_zip) both resolve to one download_url,
	 * so both are handled identically here -- only a whole-page unlock
	 * ('page') needs different handling, since there's nothing to
	 * download, just the real page content to reveal.
	 *
	 * Setting location.href to a download URL doesn't actually navigate
	 * the browser away (it aborts the navigation and saves the file
	 * instead), so execution continues normally on this same page --
	 * the modal must be explicitly closed here, or it's left stuck
	 * showing "Verifying…" indefinitely even though the download itself
	 * already succeeded.
	 */
	function handleGateSuccess( payload ) {
		if ( ! payload ) {
			return false;
		}

		if ( 'page' === payload.gate_type ) {
			if ( payload.redirect ) {
				// Keep a #hash (e.g. #nexus-64) on same-page redirects.
				var target = payload.redirect;
				try {
					var next = new URL( payload.redirect, window.location.href );
					if ( window.location.hash && ! next.hash && next.origin === window.location.origin && next.pathname === window.location.pathname ) {
						target = next.href + window.location.hash;
					}
				} catch ( e ) {}
				window.location.href = target;
			} else {
				window.location.reload();
			}
			return true;
		}

		if ( payload.download_url ) {
			window.location.href = payload.download_url;
			closeModal();
			return true;
		}

		return false;
	}

	function submitGateRequest( action, idField, idValue, fields, onError, extra ) {
		var body = new URLSearchParams();
		body.set( 'action', action );
		body.set( 'nonce', adxGatedResources.nonce );
		body.set( idField, idValue );

		Object.keys( extra || {} ).forEach( function ( key ) {
			if ( extra[ key ] ) {
				body.set( key, extra[ key ] );
			}
		} );

		Object.keys( fields || {} ).forEach( function ( key ) {
			if ( fields[ key ] ) {
				body.set( key, fields[ key ] );
			}
		} );

		body.set( 'page_url', window.location.href );

		// Only on the silent (no form) attempt: a submitted form is a
		// new login and must not be overridden by a stored identity.
		var identity = fields && Object.keys( fields ).length ? null : getIdentity();
		if ( identity ) {
			body.set( IDENTITY_EMAIL, identity.email );
			body.set( IDENTITY_KEY, identity.key );
		}

		return fetch( adxGatedResources.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( data ) {
				if ( data && data.success && handleGateSuccess( data.data ) ) {
					return;
				}

				var message = ( data && data.data && data.data.message ) ? data.data.message : adxGatedResources.i18n.genericError;
				onError( message );
			} )
			.catch( function () {
				onError( adxGatedResources.i18n.genericError );
			} );
	}

	function requestDownload( resourceId, fields, onError ) {
		return submitGateRequest( 'adx_gr_request_download', 'resource_id', resourceId, fields, onError );
	}

	function verifyPage( pageId, fields, onError ) {
		// The lock screen is the page the visitor just arrived on, so its
		// referrer is the page they came from -- named in Sales' email.
		return submitGateRequest( 'adx_gr_verify_page', 'page_id', pageId, fields, onError, { referrer: document.referrer } );
	}

	/**
	 * Product-page library button ([adx_library_access]): the server
	 * emails Sales which product the visitor came from, then sends them
	 * on to the library page (handled like a page unlock).
	 */
	function requestLibraryAccess( trigger, fields, onError ) {
		return submitGateRequest(
			'adx_gr_library_access',
			'source_id',
			trigger.getAttribute( 'data-adx-gr-source' ),
			fields,
			onError,
			{
				product: trigger.getAttribute( 'data-adx-gr-product' ),
				product_key: trigger.getAttribute( 'data-adx-gr-product-key' )
			}
		);
	}

	document.addEventListener( 'click', function ( e ) {
		var libraryTrigger = e.target.closest( '[data-adx-gr-context="library"]' );
		if ( libraryTrigger ) {
			e.preventDefault();

			// Silent first: an already-verified visitor goes straight to
			// the library (still notifying Sales); everyone else gets the
			// form.
			libraryTrigger.classList.add( 'adx-gr-trigger--loading' );
			requestLibraryAccess( libraryTrigger, {}, function () {
				libraryTrigger.classList.remove( 'adx-gr-trigger--loading' );
				openModal( null, null, libraryTrigger );
			} );
			return;
		}

		var pageTrigger = e.target.closest( '[data-adx-gr-context="page"]' );
		if ( pageTrigger ) {
			e.preventDefault();
			var pageId = pageTrigger.getAttribute( 'data-adx-gr-page' );

			// Same silent-first approach as downloads: a visitor who is
			// already verified but was served a cached lock screen gets
			// straight in instead of being asked to fill the form again.
			pageTrigger.classList.add( 'adx-gr-trigger--loading' );
			verifyPage( pageId, {}, function () {
				pageTrigger.classList.remove( 'adx-gr-trigger--loading' );
				openModal( null, pageId, pageTrigger );
			} );
			return;
		}

		var trigger = e.target.closest( '.adx-gr-trigger' );
		if ( trigger ) {
			e.preventDefault();
			var resourceId = trigger.getAttribute( 'data-adx-gr-resource' );

			// Try the silent (cookie-based) path first; the server will
			// fall back and tell us to ask for the full form if needed.
			trigger.classList.add( 'adx-gr-trigger--loading' );
			requestDownload( resourceId, {}, function ( message ) {
				trigger.classList.remove( 'adx-gr-trigger--loading' );
				openModal( resourceId, null, trigger );
			} ).then( function () {
				trigger.classList.remove( 'adx-gr-trigger--loading' );
			} );
			return;
		}

		if ( e.target.closest( '[data-adx-gr-close]' ) ) {
			e.preventDefault();
			closeModal();
		}
	} );

	// On a gated page's lock screen, show the form straight away rather
	// than waiting for a click on "Unlock this page".
	var autoOpen = document.querySelector( '[data-adx-gr-context="page"][data-adx-gr-autoopen]' );
	if ( autoOpen ) {
		autoOpen.click();
	}

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' === e.key ) {
			closeModal();
		}
	} );

	document.addEventListener( 'submit', function ( e ) {
		var form = e.target.closest( '.adx-gr-modal__form' );
		if ( ! form ) {
			return;
		}
		e.preventDefault();

		var collected = collectFields( form );

		if ( collected.missing ) {
			showError( adxGatedResources.i18n.validationError );
			return;
		}

		var fields = collected.fields;

		var submitBtn = form.querySelector( '.adx-gr-modal__submit' );
		setFieldsDisabled( form, true );
		submitBtn.disabled = true;
		setSubmitText( submitBtn, adxGatedResources.i18n.submitting );

		function onError( message ) {
			setFieldsDisabled( form, false );
			submitBtn.disabled = false;
			setSubmitText( submitBtn, submitLabel() );
			showError( message );
		}

		if ( isLibraryTrigger( activeTrigger ) ) {
			requestLibraryAccess( activeTrigger, fields, onError );
		} else if ( activePageId ) {
			verifyPage( activePageId, fields, onError );
		} else {
			requestDownload( activeResourceId, fields, onError );
		}
	} );
})();
