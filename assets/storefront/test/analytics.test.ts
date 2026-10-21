import { startAnalytics, track, trackAdded } from '../analytics';

type Page = Window & { dataLayer?: unknown[] };

const event = ( name: string, data: object ) =>
	`<script type="application/json" data-orillacart-event="${ name }">${ JSON.stringify(
		data
	) }</script>`;

describe( 'analytics events', () => {
	afterEach( () => {
		delete ( window as Page ).dataLayer;
		delete window.orillacartStorefront;
		window.localStorage.clear();
	} );

	it( 'fires orillacart events, and fills the dataLayer only when the shop says so', () => {
		const heard = jest.fn();
		document.addEventListener( 'orillacart:view_item', heard );
		window.orillacartStorefront = { api: '/api' };

		track( 'view_item', { currency: 'EUR', value: 40, items: [] } );
		expect( heard ).toHaveBeenCalledTimes( 1 );
		expect( heard.mock.calls[ 0 ][ 0 ].detail.value ).toBe( 40 );
		expect( ( window as Page ).dataLayer ).toBeUndefined();

		window.orillacartStorefront = { api: '/api', dataLayer: true };
		track( 'view_item', { currency: 'EUR', value: 40, items: [] } );
		expect( ( window as Page ).dataLayer ).toEqual( [
			{ ecommerce: null },
			{
				event: 'view_item',
				ecommerce: { currency: 'EUR', value: 40, items: [] },
			},
		] );
		document.removeEventListener( 'orillacart:view_item', heard );
	} );

	it( 'reads the page’s events, and a purchase once however often its receipt opens', () => {
		const purchases = jest.fn();
		document.addEventListener( 'orillacart:purchase', purchases );
		document.body.innerHTML =
			event( 'purchase', {
				transaction_id: '1042',
				currency: 'EUR',
				value: 45,
				items: [],
			} ) +
			'<script type="application/json" data-orillacart-event="view_item">{broken</script>';

		startAnalytics();
		startAnalytics();

		expect( purchases ).toHaveBeenCalledTimes( 1 );
		document.removeEventListener( 'orillacart:purchase', purchases );
	} );

	it( 'adds to the cart with the line the shop answered with', () => {
		const added = jest.fn();
		document.addEventListener( 'orillacart:add_to_cart', added );
		window.orillacartStorefront = { api: '/api', currency: 'EUR' };

		trackAdded(
			[
				{
					id: 1,
					product_id: 20,
					name: 'Shirt',
					sku: null,
					options: [ { attribute: 'Size', option: 'S' } ],
					unit_price: '20',
				},
				{
					id: 2,
					product_id: 20,
					name: 'Shirt',
					sku: 'SHIRT-M',
					options: [ { attribute: 'Size', option: 'M' } ],
					unit_price: '30',
				},
			],
			20,
			2
		);
		trackAdded( undefined, 20, 1 );

		expect( added ).toHaveBeenCalledTimes( 1 );
		expect( added.mock.calls[ 0 ][ 0 ].detail ).toEqual( {
			currency: 'EUR',
			value: 60,
			items: [
				{
					item_id: 'SHIRT-M',
					item_name: 'Shirt',
					item_variant: 'Size: M',
					price: 30,
					quantity: 2,
				},
			],
		} );
		document.removeEventListener( 'orillacart:add_to_cart', added );
	} );
} );
