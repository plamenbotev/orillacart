import { api, forgetNonce } from '../api';
import { formBody } from '../dom';
import { bindAddToCart, setCartCount } from '../product';

type Call = { url: string; init: RequestInit };

/**
 * Plays the server: answers each call in turn and records what was sent.
 *
 * @param answers The responses, in order: [status, JSON body].
 */
function serve( answers: [ number, unknown ][] ): Call[] {
	const calls: Call[] = [];
	// jsdom has no fetch or Response: the parts the script reads are enough.
	window.fetch = jest.fn( ( url: RequestInfo | URL, init?: RequestInit ) => {
		calls.push( { url: String( url ), init: init ?? {} } );
		const [ status, body ] = answers.shift() ?? [ 500, null ];
		return Promise.resolve( {
			status,
			ok: status >= 200 && status < 300,
			json: () => Promise.resolve( body ),
		} as Response );
	} ) as typeof fetch;
	return calls;
}

function header( call: Call | undefined, name: string ): string | undefined {
	return ( call?.init.headers as Record< string, string > | undefined )?.[
		name
	];
}

const flush = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

beforeEach( () => {
	window.orillacartStorefront = {
		api: 'https://shop.test/wp-json/orillacart/v1',
	};
	forgetNonce();
	document.body.innerHTML = '';
} );

describe( 'api', () => {
	it( 'asks for a nonce before the first change, and reuses it', async () => {
		const calls = serve( [
			[ 200, { nonce: 'n1' } ],
			[ 201, { items: 1 } ],
			[ 201, { items: 2 } ],
		] );

		await api( 'POST', '/cart/items', { product_id: 1 } );
		await api( 'POST', '/cart/items', { product_id: 1 } );

		expect( calls.map( ( call ) => call.url ) ).toEqual( [
			'https://shop.test/wp-json/orillacart/v1/session',
			'https://shop.test/wp-json/orillacart/v1/cart/items',
			'https://shop.test/wp-json/orillacart/v1/cart/items',
		] );
		expect( header( calls[ 1 ], 'X-WP-Nonce' ) ).toBe( 'n1' );
		expect( header( calls[ 2 ], 'X-WP-Nonce' ) ).toBe( 'n1' );
	} );

	it( 'gets a fresh nonce once when the server refuses the old one', async () => {
		const calls = serve( [
			[ 200, { nonce: 'old' } ],
			[ 403, { code: 'orillacart_invalid_nonce', message: 'Expired.' } ],
			[ 200, { nonce: 'new' } ],
			[ 201, { items: 1 } ],
		] );

		const result = await api( 'POST', '/cart/items', {} );

		expect( result.ok ).toBe( true );
		expect( header( calls[ 3 ], 'X-WP-Nonce' ) ).toBe( 'new' );
	} );

	it( 'tells the shopper to reload when the fresh nonce is refused too', async () => {
		serve( [
			[ 200, { nonce: 'a' } ],
			[
				403,
				{
					code: 'rest_cookie_invalid_nonce',
					message: 'Cookie check failed',
				},
			],
			[ 200, { nonce: 'b' } ],
			[
				403,
				{
					code: 'rest_cookie_invalid_nonce',
					message: 'Cookie check failed',
				},
			],
		] );

		const result = await api( 'POST', '/cart/items', {} );

		expect( result ).toMatchObject( {
			ok: false,
			message: 'This page has expired. Reload it and try again.',
		} );
	} );

	it( 'uses the nonce a logged-in page carries, and sends none to read', async () => {
		window.orillacartStorefront = { api: '/api', nonce: 'mine' };
		const calls = serve( [
			[ 200, { items: 0 } ],
			[ 201, { items: 1 } ],
		] );

		await api( 'GET', '/cart' );
		await api( 'POST', '/cart/items', {} );

		expect( calls.map( ( call ) => call.url ) ).toEqual( [
			'/api/cart',
			'/api/cart/items',
		] );
		expect( header( calls[ 0 ], 'X-WP-Nonce' ) ).toBeUndefined();
		expect( header( calls[ 1 ], 'X-WP-Nonce' ) ).toBe( 'mine' );
	} );

	it( 'returns the problems the server found', async () => {
		serve( [
			[ 200, { nonce: 'n' } ],
			[
				422,
				{
					code: 'orillacart_invalid_input',
					message: 'Some fields are not valid.',
					data: {
						violations: [
							{ field: 'billing.city', message: 'Blank.' },
						],
					},
				},
			],
		] );

		expect( await api( 'POST', '/checkout', {} ) ).toEqual( {
			ok: false,
			status: 422,
			code: 'orillacart_invalid_input',
			message: 'Some fields are not valid.',
			violations: [ { field: 'billing.city', message: 'Blank.' } ],
		} );
	} );
} );

describe( 'formBody', () => {
	it( 'turns dotted names into objects and leaves out what is off', () => {
		document.body.innerHTML = `
			<form>
				<input name="email" value="ada@example.com">
				<input name="billing.city" value="Sofia">
				<select name="billing.state" disabled><option value="X" selected>X</option></select>
				<input type="checkbox" name="remember_addresses" checked>
				<input type="checkbox" name="create_account">
				<input type="hidden" name="flagged" value="1">
				<input type="radio" name="rate_id" value="3">
				<input type="radio" name="rate_id" value="4" checked>
				<select name="option_ids"><option value="7" selected>7</option></select>
				<select name="option_ids"><option value="" selected>None</option></select>
				<input name="quantity" value="2">
			</form>`;

		expect(
			formBody( document.querySelector( 'form' ) as HTMLFormElement, {
				lists: [ 'option_ids' ],
				numbers: [ 'rate_id', 'quantity' ],
				flags: [ 'flagged' ],
			} )
		).toEqual( {
			email: 'ada@example.com',
			billing: { city: 'Sofia' },
			remember_addresses: true,
			flagged: true,
			rate_id: 4,
			option_ids: [ 7 ],
			quantity: 2,
		} );
	} );
} );

describe( 'add to cart', () => {
	const variations = [
		{
			id: 1,
			option_ids: [ 5, 8 ],
			sku: 'H-S-RED',
			for_sale: true,
			available: 3,
			on_backorder: false,
			price: '<span>38.00 EUR</span>',
		},
		{
			id: 2,
			option_ids: [ 6, 8 ],
			sku: null,
			for_sale: false,
			available: 0,
			on_backorder: false,
			price: '<span>42.00 EUR</span>',
		},
	];

	function page(): HTMLFormElement {
		document.body.innerHTML = `
			<span data-orillacart-cart-count>0</span>
			<section class="orillacart-product">
				<p data-orillacart-price>From 38.00 EUR</p>
				<span data-orillacart-sku>H</span>
				<p data-orillacart-stock></p>
				<form data-orillacart-add-to-cart data-cart-url="/cart/" data-variations='${ JSON.stringify( variations ) }'>
					<input type="hidden" name="product_id" value="9">
					<select name="option_ids" data-orillacart-option><option value=""></option><option value="5">S</option><option value="6">M</option></select>
					<select name="option_ids" data-orillacart-option><option value=""></option><option value="8">Red</option></select>
					<input name="quantity" value="1">
					<button type="submit">Add</button>
					<p data-orillacart-message></p>
				</form>
			</section>`;
		const form = document.querySelector( 'form' ) as HTMLFormElement;
		bindAddToCart( form );
		return form;
	}

	function choose( form: HTMLFormElement, values: string[] ) {
		form.querySelectorAll( 'select' ).forEach( ( select, index ) => {
			select.value = values[ index ] ?? '';
		} );
		form.dispatchEvent( new Event( 'change' ) );
	}

	it( 'shows the chosen variation and refuses one that is not for sale', () => {
		const form = page();

		choose( form, [ '5', '8' ] );
		expect(
			document.querySelector( '[data-orillacart-price]' )?.textContent
		).toBe( '38.00 EUR' );
		expect(
			document.querySelector( '[data-orillacart-sku]' )?.textContent
		).toBe( 'H-S-RED' );
		expect(
			document.querySelector( '[data-orillacart-stock]' )?.textContent
		).toBe( '3 in stock' );
		expect( form.querySelector( 'input[name="quantity"]' ) ).toHaveProperty(
			'max',
			'3'
		);

		choose( form, [ '6', '8' ] );
		expect(
			document.querySelector( '[data-orillacart-stock]' )?.textContent
		).toBe( 'Not available' );
		expect( form.querySelector( 'button' ) ).toHaveProperty(
			'disabled',
			true
		);
	} );

	it( 'sends the choices and shows the new count', async () => {
		const form = page();
		const calls = serve( [
			[ 200, { nonce: 'n' } ],
			[ 201, { items: 4 } ],
		] );
		choose( form, [ '5', '8' ] );

		form.dispatchEvent( new Event( 'submit', { cancelable: true } ) );
		await flush();
		await flush();
		await flush();

		expect( JSON.parse( String( calls[ 1 ]?.init.body ) ) ).toEqual( {
			product_id: 9,
			option_ids: [ 5, 8 ],
			quantity: 1,
		} );
		expect(
			document.querySelector( '[data-orillacart-cart-count]' )
				?.textContent
		).toBe( '4' );
		expect(
			form.querySelector( '[data-orillacart-message]' )?.textContent
		).toBe( 'Added to your cart. View cart' );
	} );
} );

describe( 'mini cart', () => {
	it( 'shows the count, the line and the part that fits the cart', () => {
		document.body.innerHTML = `
			<span data-orillacart-cart-count>0</span>
			<p data-orillacart-cart-empty>Your cart is empty.</p>
			<div data-orillacart-cart-full hidden><p data-orillacart-cart-items>0 items in cart.</p></div>`;
		const part = ( name: string ) =>
			document.querySelector< HTMLElement >(
				`[data-orillacart-cart-${ name }]`
			);

		setCartCount( 1 );
		expect( part( 'count' )?.textContent ).toBe( '1' );
		expect( part( 'items' )?.textContent ).toBe( '1 item in cart.' );
		expect( [ part( 'empty' )?.hidden, part( 'full' )?.hidden ] ).toEqual( [
			true,
			false,
		] );

		setCartCount( 3 );
		expect( part( 'items' )?.textContent ).toBe( '3 items in cart.' );

		setCartCount( 0 );
		expect( [ part( 'empty' )?.hidden, part( 'full' )?.hidden ] ).toEqual( [
			false,
			true,
		] );
	} );
} );
