( function ( window, document ) {
	'use strict';

	var config = window.GatiCrewCheckInScanner || {};
	var messages = config.messages || {};
	var state = {
		scanner: null,
		running: false,
		processing: false,
		lastToken: '',
	};

	var elements = {
		eventSelect: document.getElementById( 'gaticrew-checkin-event' ),
		startButton: document.getElementById( 'gaticrew-checkin-start' ),
		stopButton: document.getElementById( 'gaticrew-checkin-stop' ),
		reader: document.getElementById( 'gaticrew-scanner-reader' ),
		status: document.getElementById( 'gaticrew-checkin-status' ),
		result: document.getElementById( 'gaticrew-checkin-result' ),
		resultState: document.querySelector( '[data-gaticrew-result-state]' ),
		resultMessage: document.querySelector( '[data-gaticrew-result-message]' ),
		resultDetails: document.querySelector( '[data-gaticrew-result-details]' ),
		approveButton: document.getElementById( 'gaticrew-checkin-approve' ),
	};

	function getMessage( key, fallback ) {
		return messages[ key ] || fallback;
	}

	function getSelectedEventId() {
		return elements.eventSelect ? parseInt( elements.eventSelect.value, 10 ) || 0 : 0;
	}

	function setStatus( message, type ) {
		if ( ! elements.status ) {
			return;
		}

		elements.status.textContent = message || '';
		elements.status.className = 'gaticrew-checkin-dashboard__status';

		if ( type ) {
			elements.status.classList.add( 'gaticrew-checkin-dashboard__status--' + type );
		}
	}

	function setControls() {
		var hasEvent = getSelectedEventId() > 0;

		if ( elements.startButton ) {
			elements.startButton.disabled = state.running || state.processing || ! hasEvent;
		}

		if ( elements.stopButton ) {
			elements.stopButton.disabled = ! state.running;
		}
	}

	function normalizeState( resultState ) {
		var stateMap = {
			approved: 'Approved',
			already_used: 'Already Used',
			invalid: 'Invalid',
			cancelled: 'Cancelled',
			ready: 'Ready',
		};

		return stateMap[ resultState ] || 'Waiting';
	}

	function resetResult() {
		if ( ! elements.result ) {
			return;
		}

		elements.result.className = 'gaticrew-checkin-result gaticrew-checkin-result--empty';
		elements.result.removeAttribute( 'data-token' );
		elements.resultState.textContent = 'Waiting';
		elements.resultMessage.textContent = 'No ticket scanned';
		elements.resultDetails.hidden = true;
		elements.approveButton.hidden = true;
		elements.approveButton.disabled = false;
		elements.approveButton.removeAttribute( 'data-token' );
	}

	function updateAttendeeFields( attendee ) {
		var fields = elements.result.querySelectorAll( '[data-gaticrew-field]' );

		fields.forEach( function ( field ) {
			var key = field.getAttribute( 'data-gaticrew-field' );
			var value = attendee && attendee[ key ] ? attendee[ key ] : '';

			if ( Array.isArray( value ) ) {
				field.innerHTML = value.length ? '<ol class="gaticrew-checkin-result__attendees"><li>' + value.map( escapeHtml ).join( '</li><li>' ) + '</li></ol>' : '-';
				return;
			}

			field.textContent = value || '-';
		} );
	}

	function escapeHtml( value ) {
		return String( value ).replace( /[&<>"']/g, function ( character ) {
			var entities = {
				'&': '&amp;',
				'<': '&lt;',
				'>': '&gt;',
				'"': '&quot;',
				"'": '&#039;',
			};

			return entities[ character ];
		} );
	}

	function renderResult( response, token ) {
		var resultState = response && response.state ? response.state : 'invalid';
		var attendee = response && response.attendee ? response.attendee : null;
		var canApprove = Boolean( response && response.can_approve );
		var message = response && response.message ? response.message : getMessage( 'invalidQr', 'Invalid Ticket' );

		elements.result.className = 'gaticrew-checkin-result gaticrew-checkin-result--' + resultState;
		elements.result.setAttribute( 'data-token', token || '' );
		elements.resultState.textContent = normalizeState( resultState );
		elements.resultMessage.textContent = message;

		updateAttendeeFields( attendee );
		elements.resultDetails.hidden = ! attendee;

		elements.approveButton.hidden = ! canApprove;
		elements.approveButton.disabled = false;

		if ( canApprove && token ) {
			elements.approveButton.setAttribute( 'data-token', token );
		} else {
			elements.approveButton.removeAttribute( 'data-token' );
		}

		if ( resultState === 'ready' ) {
			setStatus( getMessage( 'resumeAfterScan', 'Start scanner again for the next attendee.' ), 'ready' );
		} else {
			setStatus( message, resultState );
		}
	}

	function extractToken( rawValue ) {
		var value = String( rawValue || '' ).trim();
		var routeMatch;
		var directMatch;

		if ( ! value ) {
			return '';
		}

		routeMatch = value.match( /\/(?:checkin|gaticrew-checkin)\/((?:GCQR-[A-Za-z0-9]{8,32})|(?:GC-[0-9]{4}-[A-Za-z0-9]{4,32}))\/?/i );

		if ( routeMatch && routeMatch[ 1 ] ) {
			return routeMatch[ 1 ].toUpperCase();
		}

		directMatch = value.match( /^(?:GCQR-[A-Za-z0-9]{8,32}|GC-[0-9]{4}-[A-Za-z0-9]{4,32})$/i );

		return directMatch ? value.toUpperCase() : '';
	}

	function stopScanner( announce ) {
		if ( ! state.scanner || ! state.running ) {
			state.running = false;
			setControls();
			return Promise.resolve();
		}

		return state.scanner.stop().then( function () {
			state.running = false;

			if ( announce ) {
				setStatus( getMessage( 'stopped', 'Scanner stopped.' ), 'ready' );
			}
		} ).catch( function () {
			state.running = false;
		} ).then( function () {
			setControls();
		} );
	}

	function postJson( url, payload ) {
		return window.fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce || '',
			},
			body: JSON.stringify( payload ),
		} ).then( function ( response ) {
			return response.json().then( function ( data ) {
				if ( ! response.ok ) {
					throw data;
				}

				return data;
			} );
		} );
	}

	function validateToken( token ) {
		var eventId = getSelectedEventId();

		if ( ! token ) {
			renderResult( { state: 'invalid', message: getMessage( 'invalidQr', 'Invalid Ticket' ), can_approve: false }, '' );
			return Promise.resolve();
		}

		if ( ! eventId ) {
			setStatus( getMessage( 'selectEvent', 'Select an event before scanning.' ), 'invalid' );
			setControls();
			return Promise.resolve();
		}

		state.processing = true;
		state.lastToken = token;
		setControls();
		setStatus( getMessage( 'validating', 'Validating ticket...' ), 'ready' );

		return postJson( config.validateUrl, {
			token: token,
			event_id: eventId,
		} ).then( function ( response ) {
			renderResult( response, token );
		} ).catch( function ( error ) {
			renderResult( {
				state: error && error.state ? error.state : 'invalid',
				message: error && error.message ? error.message : getMessage( 'networkError', 'Validation failed. Check your connection and try again.' ),
				attendee: error && error.attendee ? error.attendee : null,
				can_approve: false,
			}, token );
		} ).then( function () {
			state.processing = false;
			setControls();
		} );
	}

	function handleScanSuccess( decodedText ) {
		var token;

		if ( state.processing ) {
			return;
		}

		token = extractToken( decodedText );
		state.processing = true;
		setControls();

		stopScanner( false ).then( function () {
			state.processing = false;
			return validateToken( token );
		} );
	}

	function startScanner() {
		if ( ! getSelectedEventId() ) {
			setStatus( getMessage( 'selectEvent', 'Select an event before scanning.' ), 'invalid' );
			setControls();
			return;
		}

		if ( typeof window.Html5Qrcode === 'undefined' ) {
			setStatus( getMessage( 'unsupported', 'QR scanner library could not be loaded.' ), 'invalid' );
			return;
		}

		if ( state.running ) {
			return;
		}

		if ( ! state.scanner ) {
			state.scanner = new window.Html5Qrcode( 'gaticrew-scanner-reader' );
		}

		state.processing = true;
		setControls();
		setStatus( getMessage( 'starting', 'Starting camera...' ), 'ready' );

		startCamera( { facingMode: 'environment' } ).then( function () {
			state.running = true;
			state.processing = false;
			setStatus( getMessage( 'ready', 'Scanner ready.' ), 'ready' );
			setControls();
		} ).catch( function () {
			startFallbackCamera().then( function () {
				state.running = true;
				state.processing = false;
				setStatus( getMessage( 'ready', 'Scanner ready.' ), 'ready' );
				setControls();
			} ).catch( function () {
				state.running = false;
				state.processing = false;
				setStatus( getMessage( 'cameraError', 'Camera access failed. Check browser permissions and HTTPS/localhost access.' ), 'invalid' );
				setControls();
			} );
		} );
	}

	function startCamera( cameraConfig ) {
		return state.scanner.start(
			cameraConfig,
			{
				fps: 10,
				qrbox: {
					width: 250,
					height: 250,
				},
				aspectRatio: 1,
			},
			handleScanSuccess
		);
	}

	function startFallbackCamera() {
		return window.Html5Qrcode.getCameras().then( function ( cameras ) {
			if ( ! cameras || ! cameras.length ) {
				return Promise.reject();
			}

			return startCamera( cameras[ 0 ].id );
		} );
	}

	function approveCheckIn() {
		var token = elements.approveButton.getAttribute( 'data-token' ) || state.lastToken;
		var eventId = getSelectedEventId();

		if ( ! token || ! eventId || state.processing ) {
			return;
		}

		state.processing = true;
		elements.approveButton.disabled = true;
		setControls();
		setStatus( getMessage( 'approving', 'Approving check-in...' ), 'ready' );

		postJson( config.approveUrl, {
			token: token,
			event_id: eventId,
		} ).then( function ( response ) {
			renderResult( response, token );
		} ).catch( function ( error ) {
			renderResult( {
				state: error && error.state ? error.state : 'invalid',
				message: error && error.message ? error.message : getMessage( 'networkError', 'Validation failed. Check your connection and try again.' ),
				attendee: error && error.attendee ? error.attendee : null,
				can_approve: false,
			}, token );
		} ).then( function () {
			state.processing = false;
			setControls();
		} );
	}

	function bindEvents() {
		if ( elements.startButton ) {
			elements.startButton.addEventListener( 'click', startScanner );
		}

		if ( elements.stopButton ) {
			elements.stopButton.addEventListener( 'click', function () {
				stopScanner( true );
			} );
		}

		if ( elements.approveButton ) {
			elements.approveButton.addEventListener( 'click', approveCheckIn );
		}

		if ( elements.eventSelect ) {
			elements.eventSelect.addEventListener( 'change', function () {
				stopScanner( false );
				resetResult();
				setStatus( '', '' );
				setControls();
			} );
		}
	}

	if ( elements.reader && elements.result ) {
		bindEvents();
		resetResult();
		setControls();
	}
}( window, document ) );
