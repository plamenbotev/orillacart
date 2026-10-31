import { bindDeliveryEstimate, deliveryText } from '../delivery';
import { bindAddToCart, stockText } from '../product';
import { bindShare } from '../share';

beforeEach( () => {
	window.orillacartStorefront = {
		api: 'https://shop.test/wp-json/orillacart/v1',
	};
	document.body.innerHTML = '';
} );

describe( 'the product page’s own blocks', () => {
	const variations = [
		{
			id: 1,
			option_ids: [ 5 ],
			sku: 'H-S',
			for_sale: true,
			available: null,
			on_backorder: false,
			sold_out: false,
			price: '<span>38.00 EUR</span>',
			image: null,
			gallery: [],
			description: 'Small, for children.',
		},
		{
			id: 2,
			option_ids: [ 6 ],
			sku: null,
			for_sale: true,
			available: 0,
			on_backorder: false,
			sold_out: true,
			price: '<span>42.00 EUR</span>',
			image: null,
			gallery: [],
			description: '',
		},
	];

	function page(): HTMLFormElement {
		document.body.innerHTML = `
			<div class="orillacart-product-sku" data-orillacart-for="9"><p class="orillacart-product__sku">SKU: <span data-orillacart-sku>H</span></p></div>
			<div class="orillacart-product-stock" data-orillacart-for="9"><p class="orillacart-stock" data-orillacart-stock></p></div>
			<div class="orillacart-product-excerpt" data-orillacart-for="9"><p>A hoodie.</p><p data-orillacart-variation-description hidden></p></div>
			<div data-orillacart-for="9"><p class="orillacart-delivery-estimate" data-orillacart-delivery="[]"></p></div>
			<section class="orillacart-product orillacart-product--form" data-orillacart-for="9">
				<p data-orillacart-variation-description hidden></p>
				<p class="orillacart-stock" data-orillacart-stock hidden></p>
				<form data-orillacart-add-to-cart data-variations='${ JSON.stringify( variations ) }'>
					<select name="option_ids" data-orillacart-option><option value=""></option><option value="5">S</option><option value="6">M</option></select>
					<button type="submit">Add</button>
				</form>
			</section>
			<div class="orillacart-product-sku" data-orillacart-for="10"><p class="orillacart-product__sku">SKU: <span data-orillacart-sku>OTHER</span></p></div>`;
		const form = document.querySelector( 'form' ) as HTMLFormElement;
		bindAddToCart( form );
		return form;
	}

	function choose( form: HTMLFormElement, value: string ) {
		( form.querySelector( 'select' ) as HTMLSelectElement ).value = value;
		form.dispatchEvent( new Event( 'change' ) );
	}

	const all = ( selector: string ) =>
		Array.from( document.querySelectorAll< HTMLElement >( selector ) );

	it( 'shows the chosen variation’s SKU and stock in the blocks, not another product’s', () => {
		const form = page();

		choose( form, '5' );
		expect(
			all( '[data-orillacart-sku]' ).map( ( sku ) => sku.textContent )
		).toEqual( [ 'H-S', 'OTHER' ] );
		expect(
			all( '[data-orillacart-stock]' ).map(
				( stock ) => stock.textContent
			)
		).toEqual( [ 'In stock', 'In stock' ] );

		choose( form, '6' );
		expect( all( '[data-orillacart-sku]' )[ 0 ]?.textContent ).toBe( 'H' );
		expect( all( '[data-orillacart-stock]' )[ 0 ]?.textContent ).toBe(
			'Out of stock'
		);
	} );

	it( 'shows the variation’s text below the short description, not in the form too', () => {
		const form = page();

		choose( form, '5' );
		const [ apart, inForm ] = all(
			'[data-orillacart-variation-description]'
		);
		expect( apart?.hidden ).toBe( false );
		expect( apart?.textContent ).toBe( 'Small, for children.' );
		expect( inForm?.hidden ).toBe( true );

		choose( form, '' );
		expect( apart?.hidden ).toBe( true );
	} );

	it( 'hides the delivery estimate for a variation out of stock', () => {
		const form = page();
		const estimate = all( '[data-orillacart-delivery]' )[ 0 ];

		choose( form, '6' );
		expect( estimate?.hidden ).toBe( true );
		choose( form, '5' );
		expect( estimate?.hidden ).toBe( false );
	} );

	it( 'says the stock as the page does', () => {
		expect( stockText( { for_sale: true, available: 3 } ) ).toBe(
			'3 in stock'
		);
		expect( stockText( { for_sale: true, available: null } ) ).toBe(
			'In stock'
		);
		expect(
			stockText( { for_sale: true, available: 0, on_backorder: true } )
		).toBe( 'On backorder: ships when it is back in stock' );
		expect( stockText( { for_sale: false, available: null } ) ).toBe(
			'Not available'
		);
	} );
} );

describe( 'delivery estimate', () => {
	// Thursday 6 November 2026, 10:40 UTC; the cutoff at 14:00.
	const now = Date.UTC( 2026, 10, 6, 10, 40 ) / 1000;
	const cutoff = Date.UTC( 2026, 10, 6, 14, 0 ) / 1000;
	const estimates = [
		{ until: cutoff, order_by: cutoff, from: 'Friday', to: 'Friday' },
		{
			until: cutoff + 86400 * 4,
			order_by: null,
			from: 'Monday',
			to: 'Wednesday',
		},
	];

	it( 'counts down to the cutoff, then tells the next days', () => {
		expect( deliveryText( estimates, now ) ).toBe(
			'Order within 3 h 20 min to get it by Friday'
		);
		expect( deliveryText( estimates, cutoff - 59 ) ).toBe(
			'Order within 1 min to get it by Friday'
		);
		expect( deliveryText( estimates, cutoff ) ).toBe(
			'Get it between Monday and Wednesday'
		);
		expect( deliveryText( estimates, cutoff + 86400 * 5 ) ).toBeNull();
	} );

	it( 'does not count down to a cutoff days away', () => {
		expect(
			deliveryText(
				[
					{
						until: now + 86400 * 2,
						order_by: now + 86400 * 2,
						from: 'Monday',
						to: 'Monday',
					},
				],
				now
			)
		).toBe( 'Get it by Monday' );
	} );

	it( 'shows what holds when the page is read, and nothing once the page is older than it knows', () => {
		jest.useFakeTimers().setSystemTime( ( cutoff + 60 ) * 1000 );
		document.body.innerHTML = `<p data-orillacart-delivery='${ JSON.stringify(
			estimates
		) }'><span data-orillacart-delivery-text>Order within 3 h 20 min to get it by Friday</span></p>`;
		const element = document.querySelector< HTMLElement >(
			'[data-orillacart-delivery]'
		) as HTMLElement;

		bindDeliveryEstimate( element );
		expect( element.textContent ).toBe(
			'Get it between Monday and Wednesday'
		);

		jest.setSystemTime( ( cutoff + 86400 * 5 ) * 1000 );
		jest.advanceTimersByTime( 30000 );
		expect( element.hidden ).toBe( true );
		jest.useRealTimers();
	} );
} );

describe( 'share', () => {
	function box(): HTMLElement {
		document.body.innerHTML = `
			<div data-orillacart-share data-url="https://shop.test/hoodie/" data-title="Hoodie">
				<ul>
					<li hidden><button type="button" data-orillacart-share-native>Share…</button></li>
					<li hidden><button type="button" data-orillacart-share-copy>Copy link</button></li>
					<li><a href="mailto:?subject=Hoodie">Email</a></li>
				</ul>
				<p data-orillacart-message></p>
			</div>`;
		return document.querySelector(
			'[data-orillacart-share]'
		) as HTMLElement;
	}

	afterEach( () => {
		Object.assign( navigator, { share: undefined, clipboard: undefined } );
	} );

	it( 'offers only what the browser can do', () => {
		const element = box();
		bindShare( element );

		expect(
			element
				.querySelector( '[data-orillacart-share-native]' )
				?.closest( 'li' )?.hidden
		).toBe( true );
		expect(
			element
				.querySelector( '[data-orillacart-share-copy]' )
				?.closest( 'li' )?.hidden
		).toBe( true );
	} );

	it( 'shares with the browser, and copies the link', async () => {
		const share = jest.fn( () => Promise.resolve() );
		const writeText = jest.fn( () => Promise.resolve() );
		Object.assign( navigator, { share, clipboard: { writeText } } );
		const element = box();
		bindShare( element );

		element
			.querySelector< HTMLButtonElement >(
				'[data-orillacart-share-native]'
			)
			?.click();
		expect( share ).toHaveBeenCalledWith( {
			url: 'https://shop.test/hoodie/',
			title: 'Hoodie',
		} );

		element
			.querySelector< HTMLButtonElement >(
				'[data-orillacart-share-copy]'
			)
			?.click();
		await Promise.resolve();
		expect( writeText ).toHaveBeenCalledWith( 'https://shop.test/hoodie/' );
		expect(
			element.querySelector( '[data-orillacart-message]' )?.textContent
		).toBe( 'Link copied.' );
	} );
} );
