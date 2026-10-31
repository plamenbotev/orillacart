import { forgetNonce } from '../api';
import { startWishlist } from '../wishlist';

const LIST = `<section class="orillacart-wishlist-list">
	<ul data-orillacart-wishlist-list></ul>
	<p data-orillacart-wishlist-empty hidden>Nothing saved yet.</p>
	<p data-orillacart-wishlist-login hidden>Log in to keep a wishlist. <a href="/account">Log in</a></p>
</section>`;

const shown = ( selector: string ) =>
	document.querySelector< HTMLElement >( selector )?.hidden === false;

beforeEach( () => {
	forgetNonce();
	window.localStorage.clear();
	window.fetch = jest.fn( () =>
		Promise.resolve( {
			status: 200,
			ok: true,
			json: () => Promise.resolve( { html: '', ids: [] } ),
		} as Response )
	) as typeof fetch;
	document.body.innerHTML = LIST;
} );

describe( 'the Wishlist block', () => {
	it( 'asks a visitor to log in where only customers keep a wishlist', async () => {
		window.orillacartStorefront = {
			api: '/wp-json/orillacart/v1',
			wishlist: 'accounts',
		};

		await startWishlist();

		expect( shown( '[data-orillacart-wishlist-login]' ) ).toBe( true );
		expect( shown( '[data-orillacart-wishlist-empty]' ) ).toBe( false );
		expect( window.fetch ).not.toHaveBeenCalled();
	} );

	it( 'shows a visitor’s own list where visitors keep one', async () => {
		window.orillacartStorefront = {
			api: '/wp-json/orillacart/v1',
			wishlist: 'guests',
		};

		await startWishlist();

		expect( shown( '[data-orillacart-wishlist-login]' ) ).toBe( false );
		expect( shown( '[data-orillacart-wishlist-empty]' ) ).toBe( true );
	} );
} );
