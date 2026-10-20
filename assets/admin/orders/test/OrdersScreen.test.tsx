import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { OrderDetail } from '../OrderDetail';
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
	fees: [],
	fees_total: '0',
	delivery_date: null,
	delivery_slot: null,
	fees_tax: '0',
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
	shipping_option: null,
	pickup_point_id: null,
	pickup_point: null,
	refunded_total: '0',
	shipping_refunded: false,
	discount_total: '0',
	discounts: [],
	fields: [ { id: 'vat_number', label: 'VAT number', answer: 'BG123' } ],
	anonymised: false,
};

type Request = { path: string; method?: string; data?: unknown };

describe( 'OrdersScreen', () => {
	afterEach( () => mockedFetch.mockReset() );

	it( 'lists orders and moves one along', async () => {
		mockedFetch.mockImplementation( ( options ) => {
			const request = options as Request;
			if ( request.path.includes( '/customers' ) ) {
				return Promise.resolve( { customers: [], total: 0 } );
			}
			if ( request.path.startsWith( '/orillacart/v1/customers' ) ) {
				return Promise.resolve( {
					customers: [],
					total: 0,
					page: 1,
					pages: 0,
				} );
			}
			if ( request.path.endsWith( '/actions' ) ) {
				return Promise.resolve( [ { id: 'export', label: 'Export' } ] );
			}
			if ( request.path.endsWith( '/actions/export' ) ) {
				return Promise.resolve( {
					message: 'Exported #7.',
					order,
				} );
			}
			if ( request.path.endsWith( '/refunds' ) ) {
				return Promise.resolve( {
					refundable: '0',
					currency: 'EUR',
					paid: false,
					provider: null,
					refunds: [],
				} );
			}
			if ( request.path === '/orillacart/v1/payment-methods' ) {
				return Promise.resolve( [] );
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
				next_statuses: [ 'shipped', 'completed' ],
			} );
		} );
		const user = userEvent.setup();
		render( <OrdersScreen /> );

		await user.click(
			await screen.findByRole( 'button', { name: /Open/ } )
		);
		expect(
			mockedFetch.mock.calls.map( ( [ options ] ) => options?.path )
		).toContain( '/orillacart/v1/orders?page=1&per_page=20' );

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
		// The add-ons' actions are asked again for the new status, after the change.
		expect(
			mockedFetch.mock.calls.map( ( call ) => call[ 0 ] )
		).toContainEqual( {
			path: '/orillacart/v1/orders/7/status',
			method: 'PUT',
			data: {
				status: 'processing',
				note: 'Paid at the bank.',
			},
		} );
		expect(
			screen.getByRole( 'heading', { name: 'Order #7 — Processing' } )
		).toBeVisible();
	} );

	it( "shows the answers to add-ons' questions and runs their actions", async () => {
		mockedFetch.mockImplementation( ( options ) => {
			const request = options as Request;
			if ( request.path.includes( '/customers' ) ) {
				return Promise.resolve( { customers: [], total: 0 } );
			}
			if ( request.path.endsWith( '/actions' ) ) {
				return Promise.resolve( [ { id: 'export', label: 'Export' } ] );
			}
			if ( request.path.endsWith( '/actions/export' ) ) {
				return Promise.resolve( { message: 'Exported #7.', order } );
			}
			if ( request.path.endsWith( '/refunds' ) ) {
				return Promise.resolve( {
					refundable: '0',
					currency: 'EUR',
					paid: false,
					provider: null,
					refunds: [],
				} );
			}
			return Promise.resolve( order );
		} );
		const user = userEvent.setup();
		render( <OrderDetail id={ 7 } onBack={ () => {} } /> );

		expect( await screen.findByLabelText( 'VAT number' ) ).toHaveValue(
			'BG123'
		);
		await user.click(
			await screen.findByRole( 'button', { name: 'Export' } )
		);
		expect(
			await screen.findByText( 'Exported #7.', NOTICE )
		).toBeVisible();
		// The customer picker may look customers up after the click: the action is among the calls, not last.
		expect(
			mockedFetch.mock.calls.map( ( [ call ] ) => call )
		).toContainEqual( {
			path: '/orillacart/v1/orders/7/actions/export',
			method: 'POST',
		} );
	} );

	it( 'erases the customer’s details once the shop agrees', async () => {
		const erased = {
			...order,
			email: 'anonymised@invalid',
			billing: { ...address, first_name: 'Anonymised', last_name: '' },
			anonymised: true,
		};
		mockedFetch.mockImplementation( ( options ) => {
			const request = options as Request;
			if ( request.path.includes( '/customers' ) ) {
				return Promise.resolve( { customers: [], total: 0 } );
			}
			if ( request.path.endsWith( '/anonymise' ) ) {
				return Promise.resolve( erased );
			}
			if ( request.path.endsWith( '/actions' ) ) {
				return Promise.resolve( [] );
			}
			if ( request.path.endsWith( '/refunds' ) ) {
				return Promise.resolve( {
					refundable: '0',
					currency: 'EUR',
					paid: false,
					provider: null,
					refunds: [],
				} );
			}
			return Promise.resolve( order );
		} );
		const user = userEvent.setup();
		render( <OrderDetail id={ 7 } onBack={ () => {} } /> );

		await user.click(
			await screen.findByRole( 'button', {
				name: 'Erase the customer’s details',
			} )
		);
		await user.click( screen.getByRole( 'button', { name: 'Erase' } ) );

		expect(
			await screen.findByText(
				'The customer’s details were erased from the order.',
				NOTICE
			)
		).toBeVisible();
		expect(
			mockedFetch.mock.calls.some(
				( [ request ] ) =>
					( request as Request ).path ===
						'/orillacart/v1/orders/7/anonymise' &&
					( request as Request ).method === 'POST'
			)
		).toBe( true );
		expect(
			screen.queryByRole( 'button', {
				name: 'Erase the customer’s details',
			} )
		).not.toBeInTheDocument();
	} );

	it( 'puts the items of an order refunded by amount back in stock', async () => {
		const refunded = {
			...order,
			status: 'refunded',
			paid_at: '2026-10-17T12:00:00+00:00',
			next_statuses: [],
			lines: [ { ...order.lines[ 0 ], quantity: 2, restockable: 2 } ],
		};
		mockedFetch.mockImplementation( ( options ) => {
			const request = options as Request;
			if ( request.path.includes( '/customers' ) ) {
				return Promise.resolve( { customers: [], total: 0 } );
			}
			if ( request.path.endsWith( '/restock' ) ) {
				return Promise.resolve( {
					...refunded,
					lines: [ { ...refunded.lines[ 0 ], restockable: 1 } ],
				} );
			}
			if ( request.path.endsWith( '/actions' ) ) {
				return Promise.resolve( [] );
			}
			if ( request.path.endsWith( '/refunds' ) ) {
				return Promise.resolve( {
					refundable: '0',
					currency: 'EUR',
					paid: false,
					provider: null,
					refunds: [],
				} );
			}
			if ( request.path.endsWith( '/invoices' ) ) {
				return Promise.resolve( [] );
			}
			return Promise.resolve( refunded );
		} );
		const user = userEvent.setup();
		render( <OrderDetail id={ 7 } onBack={ () => {} } /> );

		await user.type(
			await screen.findByLabelText( 'Guide (up to 2)' ),
			'1'
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Put back in stock' } )
		);

		expect(
			await screen.findByText( 'The items went back in stock.', NOTICE )
		).toBeVisible();
		expect(
			mockedFetch.mock.calls.find(
				( [ request ] ) =>
					( request as Request ).path ===
					'/orillacart/v1/orders/7/restock'
			)?.[ 0 ]
		).toEqual( {
			path: '/orillacart/v1/orders/7/restock',
			method: 'POST',
			data: { lines: [ { line: 0, quantity: 1 } ] },
		} );
	} );
} );
