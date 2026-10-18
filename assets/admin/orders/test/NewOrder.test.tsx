import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { NewOrder } from '../NewOrder';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

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

const quote = {
	lines: [
		{
			name: 'Lamp',
			options: [],
			quantity: 1,
			unit_price: '30',
			total: '30',
			tax: '6',
			backordered: 0,
		},
	],
	needs_shipping: true,
	shipping_options: [
		{
			id: 'rate:3',
			carrier: 'Courier',
			name: 'Bulgaria',
			price: '5',
			tax: '1',
			total: '6',
		},
	],
	shipping_option: 'rate:3',
	pickup_points: [],
	payment_options: [
		{ gateway: 'bank_transfer', name: 'Bank transfer', instructions: '' },
	],
	taxes: [],
	subtotal: '30',
	items_tax: '6',
	shipping: '5',
	shipping_tax: '1',
	total: '42',
	discount: '0',
	discounts: [],
	currency: 'EUR',
};

type Request = { path: string; method?: string; data?: unknown };

describe( 'NewOrder', () => {
	// The combobox scrolls its highlighted option into view, which jsdom does not do.
	beforeAll( () => {
		Element.prototype.scrollIntoView = jest.fn();
	} );
	afterEach( () => mockedFetch.mockReset() );

	it( 'takes an order for a customer, priced as it is entered', async () => {
		mockedFetch.mockImplementation( ( options ) => {
			const request = options as Request;
			if ( request.path.startsWith( '/orillacart/v1/customers' ) ) {
				return Promise.resolve( {
					customers: [
						{
							id: 4,
							email: 'ada@example.com',
							first_name: 'Ada',
							last_name: 'Lovelace',
							registered_at: '2026-10-01T00:00:00+00:00',
							billing: address,
							shipping_address: null,
							orders: 1,
						},
					],
					total: 1,
					page: 1,
					pages: 1,
				} );
			}
			if ( request.path.startsWith( '/wp/v2/orillacart_product' ) ) {
				return Promise.resolve( [
					{ id: 10, title: { rendered: 'Lamp' } },
				] );
			}
			if ( request.path === '/orillacart/v1/products/10' ) {
				return Promise.resolve( {
					id: 10,
					name: 'Lamp',
					offered: [],
					variations: [],
				} );
			}
			if ( request.path === '/orillacart/v1/orders/quote' ) {
				return Promise.resolve( quote );
			}
			return Promise.resolve( { number: 12 } );
		} );
		const onCreated = jest.fn();
		const user = userEvent.setup();
		render( <NewOrder onCreated={ onCreated } onBack={ jest.fn() } /> );

		await user.type( screen.getByLabelText( 'Account' ), 'Ada' );
		await user.click(
			await screen.findByRole( 'option', { name: /Ada Lovelace/ } )
		);
		expect( screen.getByLabelText( 'Email' ) ).toHaveValue(
			'ada@example.com'
		);
		expect( screen.getByLabelText( 'City' ) ).toHaveValue( 'Sofia' );

		await user.type( screen.getByLabelText( 'Add a product' ), 'Lam' );
		await user.click(
			await screen.findByRole( 'option', { name: 'Lamp' } )
		);
		await user.click( screen.getByRole( 'button', { name: 'Add' } ) );

		expect(
			await screen.findByText(
				'Items 30.00 EUR, their tax 6.00 EUR, shipping 6.00 EUR with its tax. Total 42.00 EUR.',
				{ exact: false }
			)
		).toBeVisible();
		await user.selectOptions(
			screen.getByLabelText( 'Payment method' ),
			'bank_transfer'
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Create the order' } )
		);

		expect( mockedFetch.mock.lastCall?.[ 0 ] ).toEqual( {
			path: '/orillacart/v1/orders',
			method: 'POST',
			data: {
				email: 'ada@example.com',
				billing: address,
				shipping_address: null,
				items: [
					{
						product_id: 10,
						option_ids: [],
						bundle_choices: [],
						quantity: 1,
						unit_price: null,
					},
				],
				customer_id: 4,
				shipping_option: 'rate:3',
				payment: 'bank_transfer',
				paid: false,
				note: '',
				email_customer: false,
				pickup_point: null,
			},
		} );
		expect( onCreated ).toHaveBeenCalledWith( 12 );
	} );
} );
