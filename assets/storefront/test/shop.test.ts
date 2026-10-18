import { bindShop, filtersUrl } from '../shop';

const page = ( grid: string ) => `
	<div class="orillacart-products" data-orillacart-region="products">
		<form method="get" action="https://shop.test/shop/" data-orillacart-filters>
			<input type="search" name="q" value="lamp">
			<input type="checkbox" name="f[size][]" value="m">
			<input type="checkbox" name="in_stock" value="1">
			<input type="number" name="price_min" value="">
		</form>
		<ul class="orillacart-products__grid">${ grid }</ul>
	</div>`;

const flush = () => new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

describe( 'the shop page', () => {
	it( 'puts only the fields given in the address', () => {
		document.body.innerHTML = page( '' );
		const form = document.querySelector( 'form' ) as HTMLFormElement;
		(
			form.querySelector( '[name="f[size][]"]' ) as HTMLInputElement
		 ).checked = true;

		expect( filtersUrl( form ) ).toBe(
			'https://shop.test/shop/?q=lamp&f%5Bsize%5D%5B%5D=m'
		);
	} );

	it( 'shows the matching products when a filter changes, and remembers the address', async () => {
		document.body.innerHTML = page( '<li>Before</li>' );
		// The filters change the products on this very page.
		window.history.replaceState( {}, '', '/shop/' );
		const fetched: string[] = [];
		window.fetch = jest.fn( ( url: RequestInfo | URL ) => {
			fetched.push( String( url ) );
			return Promise.resolve( {
				text: () => Promise.resolve( page( '<li>After</li>' ) ),
			} as Response );
		} ) as typeof fetch;
		const pushState = jest
			.spyOn( window.history, 'pushState' )
			.mockImplementation( () => undefined );
		bindShop();

		const stock = document.querySelector(
			'[name="in_stock"]'
		) as HTMLInputElement;
		stock.checked = true;
		stock.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		await flush();
		await flush();

		expect( fetched ).toEqual( [
			'https://shop.test/shop/?q=lamp&in_stock=1',
		] );
		expect( pushState ).toHaveBeenCalledWith(
			{ orillacart: true },
			'',
			'https://shop.test/shop/?q=lamp&in_stock=1'
		);
		expect(
			document.querySelector( '.orillacart-products__grid' )?.textContent
		).toBe( 'After' );
	} );
} );
