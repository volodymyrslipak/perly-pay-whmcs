/**
 * The overlay: the pay button asks pay.php for the Perly Pay invoice
 * (format=json), loads pay.js from Perly Pay with its integrity pinned,
 * and opens the checkout over the invoice page. Once paid, pay.js sends
 * the client back to the invoice. If anything fails — no fetch, pay.js
 * blocked, an error — the form is sent as it is and the client lands on
 * the payment page itself.
 */
( function () {
	function loadPayJs( form, origin ) {
		return new Promise( function ( resolve, reject ) {
			if ( window.PerlyPay && typeof window.PerlyPay.open === 'function' ) {
				resolve();
				return;
			}
			const path = form.getAttribute( 'data-script' );
			const integrity = form.getAttribute( 'data-integrity' );
			if ( ! path || ! integrity ) {
				reject( new Error( 'pay.js is not pinned' ) );
				return;
			}
			const script = document.createElement( 'script' );
			script.src = origin + path;
			script.integrity = integrity;
			script.crossOrigin = 'anonymous';
			script.onload = function () {
				resolve();
			};
			script.onerror = reject;
			document.head.appendChild( script );
		} );
	}

	function open( form ) {
		const body = new FormData( form );
		body.append( 'format', 'json' );
		return fetch( form.action, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json().then( function ( data ) {
					if ( ! response.ok || ! data.invoice || ! data.url ) {
						throw new Error( data.message || 'no invoice' );
					}
					return data;
				} );
			} )
			.then( function ( data ) {
				const origin = new URL( data.url ).origin;
				return loadPayJs( form, origin ).then( function () {
					if ( ! window.PerlyPay || typeof window.PerlyPay.open !== 'function' ) {
						window.location.assign( data.url );
						return;
					}
					window.PerlyPay.open( {
						invoice: data.invoice,
						baseUrl: origin,
						paidUrl: form.getAttribute( 'data-paid-url' ) || window.location.href,
						mode: 'auto',
					} );
				} );
			} );
	}

	function wire() {
		const forms = document.querySelectorAll( 'form.perlypay-form[data-perlypay="overlay"]' );
		Array.prototype.forEach.call( forms, function ( form ) {
			if ( form.getAttribute( 'data-wired' ) === '1' || typeof window.fetch !== 'function' ) {
				return;
			}
			form.setAttribute( 'data-wired', '1' );
			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				const button = form.querySelector( 'button' );
				if ( button ) {
					button.disabled = true;
				}
				open( form )
					.catch( function () {
						form.submit();
					} )
					.then( function () {
						if ( button ) {
							button.disabled = false;
						}
					} );
			} );
		} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', wire );
	} else {
		wire();
	}
} )();
