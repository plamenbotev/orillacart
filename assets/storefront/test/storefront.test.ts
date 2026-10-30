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

	it( 'sends the nonce a logged-in page carries with every call, reads too', async () => {
		window.orillacartStorefront = { api: '/api', nonce: 'mine' };
		const calls = serve( [
			[ 200, { product_ids: [] } ],
			[ 201, { items: 1 } ],
		] );

		// Without it WordPress answers a logged-in customer's read as a visitor's: 401.
		await api( 'GET', '/account/wishlist' );
		await api( 'POST', '/cart/items', {} );

		expect( calls.map( ( call ) => call.url ) ).toEqual( [
			'/api/account/wishlist',
			'/api/cart/items',
		] );
		expect( header( calls[ 0 ], 'X-WP-Nonce' ) ).toBe( 'mine' );
		expect( header( calls[ 1 ], 'X-WP-Nonce' ) ).toBe( 'mine' );
	} );

	it( 'reads without a nonce on a page for visitors', async () => {
		window.orillacartStorefront = { api: '/api', nonce: null };
		const calls = serve( [ [ 200, { items: 0 } ] ] );

		await api( 'GET', '/cart' );

		expect( calls.map( ( call ) => call.url ) ).toEqual( [ '/api/cart' ] );
		expect( header( calls[ 0 ], 'X-WP-Nonce' ) ).toBeUndefined();
	} );

	it( 'tells the shop the page’s language with every call', async () => {
		window.orillacartStorefront = {
			api: '/api',
			nonce: null,
			locale: 'de_DE',
		};
		const calls = serve( [ [ 200, { items: 0 } ] ] );

		await api( 'GET', '/cart' );

		expect( header( calls[ 0 ], 'X-OrillaCart-Locale' ) ).toBe( 'de_DE' );
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

	it( 'answers a network that fails as a result, never by throwing', async () => {
		window.orillacartStorefront = {
			api: 'https://shop.test/wp-json/orillacart/v1',
			nonce: 'n1',
		};
		window.fetch = jest.fn( () =>
			Promise.reject( new TypeError( 'Failed to fetch' ) )
		) as typeof fetch;

		const result = await api( 'POST', '/checkout', {} );

		expect( result ).toMatchObject( {
			ok: false,
			status: 0,
			code: 'orillacart_network',
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

	function page( priceApart = false ): HTMLFormElement {
		const price = '<p data-orillacart-price>From 38.00 EUR</p>';
		document.body.innerHTML = `
			<span data-orillacart-cart-count>0</span>
			${
				priceApart
					? `<div class="orillacart-product-price" data-orillacart-for="9">${ price }</div><div class="orillacart-product-price" data-orillacart-for="10"><p data-orillacart-price>12.00 EUR</p></div>`
					: ''
			}
			<section class="orillacart-product" data-orillacart-for="9">
				${ priceApart ? '' : price }
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

	it( "changes the price where the page's template put it, not another product's", () => {
		const form = page( true );

		choose( form, [ '5', '8' ] );
		const prices = document.querySelectorAll( '[data-orillacart-price]' );
		expect( prices[ 0 ]?.textContent ).toBe( '38.00 EUR' );
		expect( prices[ 1 ]?.textContent ).toBe( '12.00 EUR' );
	} );

	it( 'shows the product’s own price, SKU and stock again when no variation is chosen', () => {
		const form = page();
		const text = ( selector: string ) =>
			document.querySelector( selector )?.textContent;

		choose( form, [ '5', '8' ] );
		expect( text( '[data-orillacart-sku]' ) ).toBe( 'H-S-RED' );
		choose( form, [ '', '8' ] );

		expect( text( '[data-orillacart-price]' ) ).toBe( 'From 38.00 EUR' );
		expect( text( '[data-orillacart-sku]' ) ).toBe( 'H' );
		expect( text( '[data-orillacart-stock]' ) ).toBe( '' );
		expect( form.querySelector( 'input[name="quantity"]' ) ).toHaveProperty(
			'max',
			''
		);

		// A variation without a SKU of its own is sold under the product's.
		choose( form, [ '6', '8' ] );
		expect( text( '[data-orillacart-sku]' ) ).toBe( 'H' );
	} );

	it( 'shows a variation’s SKU when the product has none', () => {
		document.body.innerHTML = `
			<section class="orillacart-product">
				<p class="orillacart-product__sku" hidden>SKU: <span data-orillacart-sku></span></p>
				<p data-orillacart-stock></p>
				<form data-orillacart-add-to-cart data-variations='${ JSON.stringify(
					variations
				) }'>
					<select name="option_ids" data-orillacart-option><option value=""></option><option value="5">S</option><option value="6">M</option></select>
					<select name="option_ids" data-orillacart-option><option value=""></option><option value="8">Red</option></select>
					<button type="submit">Add</button>
				</form>
			</section>`;
		const form = document.querySelector( 'form' ) as HTMLFormElement;
		bindAddToCart( form );
		const line = document.querySelector< HTMLElement >(
			'.orillacart-product__sku'
		);

		choose( form, [ '5', '8' ] );
		expect( line?.hidden ).toBe( false );
		expect( line?.textContent ).toBe( 'SKU: H-S-RED' );

		choose( form, [ '6', '8' ] );
		expect( line?.hidden ).toBe( true );
	} );

	it( 'keeps Add to cart off for a combination not sold once a file is sent', async () => {
		document.body.innerHTML = `
			<section class="orillacart-product">
				<p data-orillacart-stock></p>
				<form data-orillacart-add-to-cart data-variations='${ JSON.stringify(
					variations
				) }'>
					<input type="hidden" name="product_id" value="9">
					<select name="option_ids" data-orillacart-option><option value=""></option><option value="5">S</option><option value="6">M</option></select>
					<select name="option_ids" data-orillacart-option><option value=""></option><option value="8">Red</option></select>
					<p><input type="file" data-orillacart-upload="logo"><input type="hidden" name="fields.logo"><span data-orillacart-upload-status></span></p>
					<button type="submit">Add</button>
				</form>
			</section>`;
		const form = document.querySelector( 'form' ) as HTMLFormElement;
		bindAddToCart( form );
		window.orillacartStorefront = {
			api: 'https://shop.test/wp-json/orillacart/v1',
			nonce: 'n',
		};
		serve( [ [ 201, { token: 't1', name: 'logo.png' } ] ] );
		choose( form, [ '6', '8' ] );
		const button = form.querySelector( 'button' ) as HTMLButtonElement;
		expect( button.disabled ).toBe( true );

		const upload = form.querySelector(
			'[data-orillacart-upload]'
		) as HTMLInputElement;
		Object.defineProperty( upload, 'files', {
			value: [ new File( [ 'x' ], 'logo.png' ) ],
		} );
		upload.dispatchEvent( new Event( 'change' ) );
		await flush();
		await flush();

		expect(
			form.querySelector< HTMLInputElement >( '[name="fields.logo"]' )
				?.value
		).toBe( 't1' );
		expect( button.disabled ).toBe( true );
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
			bundle_choices: [],
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

describe( 'add to cart without a network', () => {
	it( 'says so and lets the customer try again', async () => {
		document.body.innerHTML = `
			<form data-orillacart-add-to-cart data-variations="[]">
				<input type="hidden" name="product_id" value="9">
				<input name="quantity" value="1">
				<button type="submit">Add</button>
				<p data-orillacart-message></p>
			</form>`;
		const form = document.querySelector( 'form' ) as HTMLFormElement;
		bindAddToCart( form );
		window.orillacartStorefront = {
			api: 'https://shop.test/wp-json/orillacart/v1',
			nonce: 'n1',
		};
		window.fetch = jest.fn( () =>
			Promise.reject( new TypeError( 'Failed to fetch' ) )
		) as typeof fetch;

		form.dispatchEvent( new Event( 'submit', { cancelable: true } ) );
		await flush();
		await flush();

		expect( form.querySelector( 'button' )?.disabled ).toBe( false );
		expect(
			form.querySelector( '[data-orillacart-message]' )?.textContent
		).toContain( 'could not be reached' );
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

describe( 'a bundle whose price cannot be worked out', () => {
	it( 'says why Add to cart is off', async () => {
		window.orillacartStorefront = {
			api: 'https://shop.test/wp-json/orillacart/v1',
			nonce: 'n',
		};
		serve( [ [ 500, null ] ] );
		document.body.innerHTML = `
			<section class="orillacart-product">
				<form data-orillacart-add-to-cart data-variations="[]" data-bundle="9">
					<input type="hidden" name="product_id" value="9">
					<button type="submit">Add</button>
					<p data-orillacart-message></p>
				</form>
			</section>`;
		const form = document.querySelector( 'form' ) as HTMLFormElement;
		bindAddToCart( form );
		await flush();
		await flush();

		expect( form.querySelector( 'button' )?.disabled ).toBe( true );
		expect(
			form.querySelector( '[data-orillacart-message]' )?.textContent
		).toContain( 'could not be worked out' );
	} );
} );

describe( 'an answer that is not JSON', () => {
	it( 'is an error, not an answer without data', async () => {
		window.orillacartStorefront = {
			api: 'https://shop.test/wp-json/orillacart/v1',
			nonce: 'n',
		};
		window.fetch = jest.fn( () =>
			Promise.resolve( {
				status: 200,
				ok: true,
				json: () => Promise.reject( new SyntaxError( 'Unexpected <' ) ),
			} as Response )
		) as typeof fetch;

		const result = await api( 'POST', '/checkout', {} );

		expect( result.ok ).toBe( false );
		expect( result ).toMatchObject( { code: 'orillacart_unreadable' } );
	} );
} );
