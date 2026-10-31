import { api, forgetNonce } from '../api';
import {
	bindStoreNotices,
	fillPromotions,
	startFreeShipping,
	startPromotions,
} from '../shop-blocks';

type Call = { url: string; init: RequestInit };

/**
 * Plays the server: answers each call in turn and records what was sent.
 *
 * @param answers The responses, in order: [status, JSON body].
 */
function serve( answers: [ number, unknown ][] ): Call[] {
	const calls: Call[] = [];
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

const flush = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

const PROGRESS =
	'<p class="orillacart-free-shipping-progress__message">Spend €22.00 more for free shipping.</p>';

beforeEach( () => {
	window.orillacartStorefront = {
		api: 'https://shop.test/wp-json/orillacart/v1',
	};
	forgetNonce();
	window.localStorage.clear();
	document.body.innerHTML = '';
} );

describe( 'free shipping progress', () => {
	it( 'fills a visitor’s blocks from their cart, and again when the cart changes', async () => {
		document.body.innerHTML =
			'<div data-orillacart-free-shipping hidden></div><div data-orillacart-free-shipping hidden></div>';
		const calls = serve( [
			[ 200, { html: PROGRESS } ],
			[ 200, { nonce: 'n1' } ],
			[ 201, { items: 2 } ],
			[ 200, { html: '<p>Your order ships free.</p>' } ],
		] );

		startFreeShipping();
		await flush();

		const blocks = Array.from(
			document.querySelectorAll< HTMLElement >(
				'[data-orillacart-free-shipping]'
			)
		);
		expect( calls[ 0 ]?.url ).toBe(
			'https://shop.test/wp-json/orillacart/v1/cart/free-shipping'
		);
		expect( blocks.map( ( block ) => block.hidden ) ).toEqual( [
			false,
			false,
		] );
		expect( blocks[ 0 ]?.textContent ).toBe(
			'Spend €22.00 more for free shipping.'
		);

		await api( 'POST', '/cart/items', { product_id: 10 } );
		await flush();

		expect( calls.map( ( call ) => call.url.split( '/v1' )[ 1 ] ) ).toEqual(
			[
				'/cart/free-shipping',
				'/session',
				'/cart/items',
				'/cart/free-shipping',
			]
		);
		expect( blocks[ 1 ]?.textContent ).toBe( 'Your order ships free.' );
	} );

	it( 'leaves a logged-in customer’s as the server drew it until the cart changes, and hides one with nothing to say', async () => {
		window.orillacartStorefront = {
			api: 'https://shop.test/wp-json/orillacart/v1',
			nonce: 'logged-in',
		};
		document.body.innerHTML = `<div data-orillacart-free-shipping>${ PROGRESS }</div>`;
		const calls = serve( [
			[ 200, { items: 0 } ],
			[ 200, { html: '' } ],
		] );

		startFreeShipping();
		await flush();
		expect( calls ).toHaveLength( 0 );

		await api( 'DELETE', '/cart/items/abc' );
		await flush();

		const block = document.querySelector< HTMLElement >(
			'[data-orillacart-free-shipping]'
		);
		expect( block?.hidden ).toBe( true );
		expect( block?.innerHTML ).toBe( '' );
	} );

	it( 'asks nothing on a page without the block, nor after a read of the cart', async () => {
		const calls = serve( [ [ 200, { items: 1 } ] ] );

		startFreeShipping();
		await api( 'GET', '/cart' );
		await flush();

		expect( calls ).toHaveLength( 1 );
	} );
} );

describe( 'promotion progress', () => {
	const item = ( kind: 'applied' | 'offer', text: string ) =>
		`<li data-orillacart-promotion="${ kind }"><p>${ text }</p></li>`;
	const PROMOTIONS = `<ul>${ [
		item( 'applied', 'Buy 2, get 1 free — applied, you save €9.00.' ),
		item( 'offer', 'Spend €18.00 more to get 10% off.' ),
		item( 'offer', 'Add 2 more items from Garden to get free shipping.' ),
		item( 'offer', 'Spend €40.00 more to get a free Tote bag.' ),
	].join( '' ) }</ul>`;
	const texts = ( block: Element | null | undefined ) =>
		Array.from( block?.querySelectorAll( 'li' ) ?? [] ).map(
			( found ) => found.textContent
		);

	it( 'fills each of a visitor’s blocks as it is set, and again when the cart changes', async () => {
		document.body.innerHTML =
			'<div data-orillacart-promotions data-show-applied="true" data-count="3" hidden></div>' +
			'<div data-orillacart-promotions data-show-applied="false" data-count="1" hidden></div>';
		const calls = serve( [
			[ 200, { html: PROMOTIONS } ],
			[ 200, { nonce: 'n1' } ],
			[ 201, { items: 3 } ],
			[ 200, { html: `<ul>${ item( 'applied', 'Applied.' ) }</ul>` } ],
		] );

		startPromotions();
		await flush();

		const blocks = Array.from(
			document.querySelectorAll< HTMLElement >(
				'[data-orillacart-promotions]'
			)
		);
		expect( calls[ 0 ]?.url ).toBe(
			'https://shop.test/wp-json/orillacart/v1/cart/promotions'
		);
		expect( texts( blocks[ 0 ] ) ).toEqual( [
			'Buy 2, get 1 free — applied, you save €9.00.',
			'Spend €18.00 more to get 10% off.',
			'Add 2 more items from Garden to get free shipping.',
			'Spend €40.00 more to get a free Tote bag.',
		] );
		expect( texts( blocks[ 1 ] ) ).toEqual( [
			'Spend €18.00 more to get 10% off.',
		] );
		expect( blocks.map( ( block ) => block.hidden ) ).toEqual( [
			false,
			false,
		] );

		await api( 'POST', '/cart/items', { product_id: 10 } );
		await flush();

		expect( calls[ 3 ]?.url.split( '/v1' )[ 1 ] ).toBe(
			'/cart/promotions'
		);
		expect( texts( blocks[ 0 ] ) ).toEqual( [ 'Applied.' ] );
		expect( blocks[ 1 ]?.hidden ).toBe( true );
		expect( blocks[ 1 ]?.innerHTML ).toBe( '' );
	} );

	it( 'shows only as many still to get as the block says', () => {
		const block = document.createElement( 'div' );
		block.dataset.count = '2';
		fillPromotions( block, PROMOTIONS );

		expect( texts( block ) ).toHaveLength( 3 );
		expect( block.hidden ).toBe( false );

		fillPromotions( block, '' );
		expect( block.hidden ).toBe( true );
	} );

	it( 'leaves a logged-in customer’s as the server drew it until the cart changes', async () => {
		window.orillacartStorefront = {
			api: 'https://shop.test/wp-json/orillacart/v1',
			nonce: 'logged-in',
		};
		document.body.innerHTML = `<div data-orillacart-promotions>${ PROMOTIONS }</div>`;
		const calls = serve( [] );

		startPromotions();
		await flush();

		expect( calls ).toHaveLength( 0 );
		expect(
			texts( document.querySelector( '[data-orillacart-promotions]' ) )
		).toHaveLength( 4 );
	} );
} );

describe( 'store notice', () => {
	const notice = ( key: string ) =>
		`<div data-orillacart-store-notice="${ key }" hidden><p>Free delivery this week</p><button type="button" data-orillacart-store-notice-dismiss aria-label="Dismiss this notice">×</button></div>`;

	it( 'shows a notice until the shopper closes it, every copy of it, and remembers', () => {
		document.body.innerHTML = notice( 'a1' ) + notice( 'a1' );
		bindStoreNotices();
		const copies = Array.from(
			document.querySelectorAll< HTMLElement >(
				'[data-orillacart-store-notice]'
			)
		);
		expect( copies.map( ( copy ) => copy.hidden ) ).toEqual( [
			false,
			false,
		] );

		copies[ 0 ]?.querySelector< HTMLButtonElement >( 'button' )?.click();
		expect( copies.map( ( copy ) => copy.hidden ) ).toEqual( [
			true,
			true,
		] );

		document.body.innerHTML = notice( 'a1' );
		bindStoreNotices();
		expect(
			document.querySelector< HTMLElement >(
				'[data-orillacart-store-notice]'
			)?.hidden
		).toBe( true );
	} );

	it( 'shows a new notice although an older one was closed', () => {
		window.localStorage.setItem(
			'orillacart_store_notices_closed',
			JSON.stringify( [ 'old' ] )
		);
		document.body.innerHTML = notice( 'new' );
		bindStoreNotices();

		expect(
			document.querySelector< HTMLElement >(
				'[data-orillacart-store-notice]'
			)?.hidden
		).toBe( false );
	} );

	it( 'closes for the page where the browser keeps nothing', () => {
		const setItem = jest
			.spyOn( Storage.prototype, 'setItem' )
			.mockImplementation( () => {
				throw new DOMException( 'Storage is off.', 'SecurityError' );
			} );
		document.body.innerHTML = notice( 'a1' );
		bindStoreNotices();
		const shown = document.querySelector< HTMLElement >(
			'[data-orillacart-store-notice]'
		);

		expect( () =>
			shown?.querySelector< HTMLButtonElement >( 'button' )?.click()
		).not.toThrow();
		expect( shown?.hidden ).toBe( true );
		setItem.mockRestore();
	} );

	it( 'leaves a notice shoppers may not close as the server drew it', () => {
		document.body.innerHTML =
			'<div data-orillacart-store-notice=""><p>Closed on Sunday</p></div>';
		bindStoreNotices();

		expect(
			document.querySelector< HTMLElement >(
				'[data-orillacart-store-notice]'
			)?.hidden
		).toBe( false );
	} );
} );
