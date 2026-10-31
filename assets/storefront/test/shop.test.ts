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
				ok: true,
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

	it( 'shows the products of the last change, whichever answer comes last', async () => {
		document.body.innerHTML = page( '<li>Before</li>' );
		window.history.replaceState( {}, '', '/shop/' );
		const pending: ( ( html: string ) => void )[] = [];
		window.fetch = jest.fn(
			() =>
				new Promise( ( resolve ) => {
					pending.push( ( html ) =>
						resolve( {
							ok: true,
							text: () => Promise.resolve( html ),
						} as Response )
					);
				} )
		) as typeof fetch;
		jest.spyOn( window.history, 'pushState' ).mockImplementation(
			() => undefined
		);
		bindShop();

		const stock = document.querySelector(
			'[name="in_stock"]'
		) as HTMLInputElement;
		stock.checked = true;
		stock.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		stock.checked = false;
		stock.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		await flush();
		// The answer to the last change comes first, the older one after it.
		pending[ pending.length - 1 ]?.( page( '<li>Last</li>' ) );
		await flush();
		await flush();
		pending[ 0 ]?.( page( '<li>Older</li>' ) );
		await flush();
		await flush();

		expect(
			document.querySelector( '.orillacart-products__grid' )?.textContent
		).toBe( 'Last' );
	} );

	it( 'keeps the sort, count, pages and active filters placed apart in step with the products', async () => {
		const listing = ( count: string, chips: string, grid: string ) => `
			<div data-orillacart-region="products-sort">
				<form method="get" action="https://shop.test/shop/" data-orillacart-filters>
					<input type="hidden" name="in_stock" value="1">
					<select name="sort"><option value="order">Recommended</option><option value="name">Name</option></select>
					<button type="submit" data-orillacart-apply>Sort</button>
				</form>
			</div>
			<p data-orillacart-region="products-count">${ count }</p>
			<div data-orillacart-region="active-filters">${ chips }</div>
			${ page( grid ) }
			<div data-orillacart-region="products-pagination"></div>`;
		document.body.innerHTML = listing(
			'3 products',
			'<a href="https://shop.test/shop/" data-orillacart-shop-link>In stock only</a>',
			'<li>Before</li>'
		);
		window.history.replaceState( {}, '', '/shop/?in_stock=1' );
		const fetched: string[] = [];
		window.fetch = jest.fn( ( url: RequestInfo | URL ) => {
			fetched.push( String( url ) );
			return Promise.resolve( {
				ok: true,
				text: () =>
					Promise.resolve(
						String( url ).includes( 'sort=name' )
							? listing( '3 products', '', '<li>By name</li>' )
							: listing( '9 products', '', '<li>After</li>' )
					),
			} as Response );
		} ) as typeof fetch;
		jest.spyOn( window.history, 'pushState' ).mockImplementation(
			() => undefined
		);
		bindShop();

		expect(
			(
				document.querySelector(
					'[data-orillacart-apply]'
				) as HTMLElement
			 ).hidden
		).toBe( true );
		const sort = document.querySelector(
			'[name="sort"]'
		) as HTMLSelectElement;
		sort.value = 'name';
		sort.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		await flush();
		await flush();
		// Each test before binds the document too.
		expect( [ ...new Set( fetched ) ] ).toEqual( [
			'https://shop.test/shop/?in_stock=1&sort=name',
		] );
		expect(
			document.querySelector( '.orillacart-products__grid' )?.textContent
		).toBe( 'By name' );
		fetched.length = 0;

		document.body.innerHTML = listing(
			'3 products',
			'<a href="https://shop.test/shop/" data-orillacart-shop-link>In stock only</a>',
			'<li>Before</li>'
		);
		(
			document.querySelector(
				'[data-orillacart-shop-link]'
			) as HTMLAnchorElement
		 ).click();
		await flush();
		await flush();

		expect( [ ...new Set( fetched ) ] ).toEqual( [
			'https://shop.test/shop/',
		] );
		expect(
			document.querySelector(
				'[data-orillacart-region="products-count"]'
			)?.textContent
		).toBe( '9 products' );
		expect(
			document.querySelector( '[data-orillacart-shop-link]' )
		).toBeNull();
		expect(
			document.querySelector( '.orillacart-products__grid' )?.textContent
		).toBe( 'After' );
	} );
} );
