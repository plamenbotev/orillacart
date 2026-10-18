import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { OrdersScreen } from '../OrdersScreen';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const NOTICE = { selector: '.components-notice__content' };

const address = {
	first_name: 'Ada',
	last_name: 'Lovelace',
	street: '1 Vitosha Blvd',
	city: 'Sofia',
	postcode: '1000',
	country: 'BG',
	phone: '+359 2 000 0000',
	state: null,
};

const order = {
	number: 7,
	key: 'a'.repeat( 32 ),
	status: 'on_hold',
	email: 'ada@example.com',
	billing: address,
	shipping_address: null,
	lines: [
		{
			product_id: 20,
			variation_id: null,
			name: 'Guide',
			sku: null,
			options: [],
			option_ids: [],
			quantity: 1,
			unit_price: '8',
			total: '8',
			tax: '0',
		},
	],
	shipping: null,
	shipping_total: '0',
	shipping_tax: '0',
	taxes: [],
	subtotal: '8',
	items_tax: '0',
	total: '8',
	currency: 'EUR',
	payment_gateway: 'bank_transfer',
	payment_name: 'Bank transfer',
	payment_note: '',
	note: '',
	placed_at: '2026-10-17T12:00:00+00:00',
	paid_at: null,
	customer_id: null,
	transaction_id: null,
	notes: [
		{
			text: 'Order placed.',
			author: 'system',
			for_customer: false,
			created_at: '2026-10-17T12:00:00+00:00',
		},
	],
	next_statuses: [ 'processing', 'cancelled' ],
	downloads: [],
	shipping_rate_id: null,
};

type Request = { path: string; method?: string; data?: unknown };

describe( 'OrdersScreen', () => {
	afterEach( () => mockedFetch.mockReset() );

	it( 'lists orders and moves one along', async () => {
		mockedFetch.mockImplementation( ( options ) => {
			const request = options as Request;
			if ( request.path.startsWith( '/orillacart/v1/customers' ) ) {
				return Promise.resolve( {
					customers: [],
					total: 0,
					page: 1,
					pages: 0,
				} );
			}
			if ( request.method === undefined ) {
				return Promise.resolve(
					request.path.startsWith( '/orillacart/v1/orders?' )
						? {
								orders: [
									{
										number: 7,
										status: 'on_hold',
										customer: 'Ada Lovelace',
										email: 'ada@example.com',
										customer_id: null,
										items: 1,
										total: '8',
										currency: 'EUR',
										payment_name: 'Bank transfer',
										placed_at: '2026-10-17T12:00:00+00:00',
									},
								],
								total: 1,
								page: 1,
								pages: 1,
							}
						: order
				);
			}
			return Promise.resolve( {
				...order,
				status: 'processing',
				next_statuses: [
					'shipped',
					'completed',
					'cancelled',
					'refunded',
				],
			} );
		} );
		const user = userEvent.setup();
		render( <OrdersScreen /> );

		expect( mockedFetch.mock.calls[ 0 ]?.[ 0 ]?.path ).toBe(
			'/orillacart/v1/orders?page=1&per_page=20'
		);
		await user.click(
			await screen.findByRole( 'button', { name: /Open/ } )
		);

		await screen.findByRole( 'heading', { name: 'Order #7 — On hold' } );
		expect( screen.getByText( 'Order placed.' ) ).toBeVisible();
		await user.type(
			screen.getByLabelText( 'Note for the history (optional)' ),
			'Paid at the bank.'
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Change status' } )
		);

		expect(
			await screen.findByText( 'The status was changed.', NOTICE )
		).toBeVisible();
		expect( mockedFetch.mock.lastCall?.[ 0 ] ).toEqual( {
			path: '/orillacart/v1/orders/7/status',
			method: 'PUT',
			data: {
				status: 'processing',
				restock: false,
				note: 'Paid at the bank.',
			},
		} );
		expect(
			screen.getByRole( 'heading', { name: 'Order #7 — Processing' } )
		).toBeVisible();
	} );
} );
