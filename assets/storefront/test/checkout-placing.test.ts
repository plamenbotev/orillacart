import { bindCheckout } from '../checkout';
import { navigate, reloadPage } from '../navigation';

jest.mock( '../navigation', () => ( {
	navigate: jest.fn(),
	reloadPage: jest.fn(),
} ) );

// jsdom has no CSS.escape: the names in these tests need none.
( globalThis as { CSS?: { escape: ( value: string ) => string } } ).CSS ??= {
	escape: ( value: string ) => value,
};

type Answer = { status: number; json?: unknown; text?: string };

/**
 * Plays the shop: the page (for the summary), and the API's calls, each answered by the function given, which may
 * hold its answer back.
 */
type Route = ( call: { url: string; body: unknown } ) => Promise< Answer >;

const sent: { method: string; url: string; body: unknown }[] = [];

function serve( routes: { page: Route; checkout?: Route } ): void {
	window.fetch = jest.fn( ( url: RequestInfo | URL, init?: RequestInit ) => {
		const method = init?.method ?? 'GET';
		const body =
			typeof init?.body === 'string' ? JSON.parse( init.body ) : null;
		const address = String( url );
		sent.push( { method, url: address, body } );
		let answer: Promise< Answer >;
		if ( address.endsWith( '/checkout' ) && method === 'POST' ) {
			answer = ( routes.checkout ?? ( () => never() ) )( {
				url: address,
				body,
			} );
		} else if ( address.includes( '/wp-json/' ) ) {
			answer = Promise.resolve( { status: 204 } );
		} else {
			answer = routes.page( { url: address, body } );
		}
		return answer.then(
			( { status, json, text } ) =>
				( {
					status,
					ok: status >= 200 && status < 300,
					json: () =>
						text === undefined
							? Promise.resolve( json ?? null )
							: Promise.reject( new SyntaxError( 'not JSON' ) ),
					text: () => Promise.resolve( text ?? '' ),
				} ) as Response
		);
	} ) as typeof fetch;
}

const never = () => new Promise< Answer >( () => undefined );

const summary = ( total: string ) => `
	<div data-orillacart-region="checkout-summary">
		<table><tr class="orillacart-total"><td>€${ total }</td></tr></table>
		<input type="hidden" name="expected_total" value="${ total }">
		<button type="submit" class="orillacart-checkout__place">Place order</button>
	</div>`;

const page = ( total: string ) => `
	<form data-orillacart-checkout data-orillacart-cart-url="/cart/" data-orillacart-account-url="/account/">
		<input name="email" value="ada@example.com" data-orillacart-field="email">
		<input name="billing.postcode" value="1000">
		<p class="orillacart-field"><input type="checkbox" name="accepts_terms" value="1" data-orillacart-field="accepts_terms"></p>
		<p data-orillacart-total-said></p>
		<p data-orillacart-message></p>
		${ summary( total ) }
	</form>`;

const settle = async () => {
	for ( let tick = 0; tick < 20; tick++ ) {
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );
	}
};

function open( total = '40.00' ): HTMLFormElement {
	document.body.innerHTML = page( total );
	const form = document.querySelector( 'form' ) as HTMLFormElement;
	bindCheckout( form );
	return form;
}

const button = ( form: HTMLFormElement ) =>
	form.querySelector( 'button[type="submit"]' ) as HTMLButtonElement;
const message = ( form: HTMLFormElement ) =>
	form.querySelector( '[data-orillacart-message]' )?.textContent ?? '';
const orders = () =>
	sent.filter(
		( call ) => call.method === 'POST' && call.url.endsWith( '/checkout' )
	);

beforeEach( async () => {
	await settle();
	sent.length = 0;
	jest.mocked( navigate ).mockClear();
	jest.mocked( reloadPage ).mockClear();
	window.orillacartStorefront = {
		api: 'https://shop.test/wp-json/orillacart/v1',
		nonce: 'n',
	};
} );

describe( 'placing the order', () => {
	it( 'does not place it for a total the customer has not seen, and shows the new one', async () => {
		let draw: ( answer: Answer ) => void = () => undefined;
		serve( {
			page: () =>
				new Promise( ( resolve ) => {
					draw = resolve;
				} ),
			checkout: () =>
				Promise.resolve( {
					status: 201,
					json: { receipt_url: '/receipt/', redirect_url: null },
				} ),
		} );
		const form = open();

		// A new postcode costs more; the customer clicks before the summary comes back.
		form.querySelector( '[name="billing.postcode"]' )?.dispatchEvent(
			new Event( 'change', { bubbles: true } )
		);
		await settle();
		form.requestSubmit();
		await settle();
		draw( { status: 200, text: page( '45.00' ) } );
		await settle();

		expect( orders() ).toHaveLength( 0 );
		expect( message( form ) ).toContain( '€45.00' );
		expect( button( form ).disabled ).toBe( false );

		// Placed again, it goes for the total now shown.
		form.requestSubmit();
		await settle();
		expect( orders() ).toHaveLength( 1 );
		expect( orders()[ 0 ]?.body ).toMatchObject( {
			expected_total: '45.00',
		} );
		expect( navigate ).toHaveBeenCalledWith( '/receipt/' );
	} );

	it( 'keeps the button off while placing, even when a summary drawn meanwhile brings a new one', async () => {
		let draw: ( answer: Answer ) => void = () => undefined;
		serve( {
			page: () =>
				new Promise( ( resolve ) => {
					draw = resolve;
				} ),
			checkout: never,
		} );
		const form = open();

		form.querySelector( '[name="billing.postcode"]' )?.dispatchEvent(
			new Event( 'change', { bubbles: true } )
		);
		await settle();
		form.requestSubmit();
		await settle();
		draw( { status: 200, text: page( '40.00' ) } );
		await settle();

		expect( orders() ).toHaveLength( 1 );
		expect( button( form ).disabled ).toBe( true );
	} );

	it( 'loads the page anew when the cart changed and the checkout cannot show it', async () => {
		serve( {
			page: () =>
				Promise.resolve( {
					status: 200,
					text: '<p class="orillacart-notice">An item is no longer sold.</p>',
				} ),
			checkout: () =>
				Promise.resolve( {
					status: 409,
					json: {
						code: 'orillacart_cart_changed',
						message: 'An item is no longer sold.',
					},
				} ),
		} );
		const form = open();

		form.requestSubmit();
		await settle();

		expect( reloadPage ).toHaveBeenCalled();
	} );

	it( 'goes to the cart when the cart emptied', async () => {
		serve( {
			page: () =>
				Promise.resolve( { status: 200, text: page( '40.00' ) } ),
			checkout: () =>
				Promise.resolve( {
					status: 422,
					json: {
						code: 'orillacart_cart_empty',
						message: 'The cart is empty.',
					},
				} ),
		} );
		open();

		( document.querySelector( 'form' ) as HTMLFormElement ).requestSubmit();
		await settle();

		expect( navigate ).toHaveBeenCalledWith( '/cart/' );
	} );

	it( 'says the order may exist when the answer cannot be read, with the way to the orders', async () => {
		serve( {
			page: () =>
				Promise.resolve( { status: 200, text: page( '40.00' ) } ),
			checkout: () =>
				Promise.resolve( { status: 200, text: '<html>Notice</html>' } ),
		} );
		const form = open();

		form.requestSubmit();
		await settle();

		expect( navigate ).not.toHaveBeenCalled();
		expect( message( form ) ).toContain( 'may have been placed' );
		expect(
			form
				.querySelector( '[data-orillacart-message] a' )
				?.getAttribute( 'href' )
		).toBe( '/account/' );
		expect( button( form ).disabled ).toBe( false );
	} );

	it( 'marks the terms box when the terms were not accepted', async () => {
		serve( {
			page: () =>
				Promise.resolve( { status: 200, text: page( '40.00' ) } ),
			checkout: () =>
				Promise.resolve( {
					status: 422,
					json: {
						code: 'orillacart_terms_not_accepted',
						message: 'Accept the terms of sale to order.',
					},
				} ),
		} );
		const form = open();

		form.requestSubmit();
		await settle();

		const terms = form.querySelector( '[name="accepts_terms"]' );
		expect( terms?.getAttribute( 'aria-invalid' ) ).toBe( 'true' );
		expect( terms?.parentElement?.textContent ).toContain(
			'Accept the terms of sale'
		);
	} );

	it( 'turns the button back on and prices again when the browser shows the kept page', async () => {
		serve( {
			page: () =>
				Promise.resolve( { status: 200, text: page( '41.00' ) } ),
			checkout: never,
		} );
		const form = open();
		form.requestSubmit();
		await settle();
		expect( button( form ).disabled ).toBe( true );

		const shown = new Event( 'pageshow' ) as PageTransitionEvent;
		Object.defineProperty( shown, 'persisted', { value: true } );
		window.dispatchEvent( shown );
		await settle();

		expect( button( form ).disabled ).toBe( false );
		expect(
			form.querySelector< HTMLInputElement >( '[name="expected_total"]' )
				?.value
		).toBe( '41.00' );
	} );
} );
