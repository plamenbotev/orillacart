import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { CustomerCard } from '../CustomerCard';
import type { Customer } from '../api';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

type Request = { path: string; method?: string; data?: unknown };

const HOME = {
	first_name: 'Ada',
	last_name: 'Lovelace',
	street: '1 Vitosha Blvd',
	city: 'Sofia',
	postcode: '1000',
	country: 'BG',
	phone: '+359 2 000 0000',
	state: null,
};

const ADA: Customer = {
	id: 3,
	email: 'ada@example.com',
	first_name: 'Ada',
	last_name: 'Lovelace',
	registered_at: '2026-10-17T12:00:00+00:00',
	billing: HOME,
	shipping_address: HOME,
	orders: 2,
	group_ids: [],
	addresses: [
		{ id: 'ab12cd34', address: HOME, billing: true, shipping: true },
	],
	lifetime_value: {
		currency: 'EUR',
		spent: '25',
		paid_orders: 2,
		average: '12.5',
		first_order_at: '2026-10-17T12:00:00+00:00',
		last_order_at: '2026-11-06T12:00:00+00:00',
	},
	cart_reminders: true,
	newsletter: null,
};

const CREDIT = {
	card: {
		id: 0,
		kind: 'store_credit',
		label: 'Store credit',
		code_end: '',
		initial: '0',
		balance: '20',
		currency: 'EUR',
		expires_at: null,
		active: true,
		recipient_name: '',
		recipient_email: '',
		message: '',
		order_id: null,
		customer_id: 3,
		created_at: '2026-10-20T10:00:00+00:00',
	},
	entries: [
		{
			id: 1,
			amount: '20',
			note: 'Sorry for the wait',
			at: '2026-10-20T10:00:00+00:00',
			order_id: null,
			user: 'Mara',
		},
	],
};

describe( 'CustomerCard', () => {
	afterEach( () => mockedFetch.mockReset() );

	it( 'adds an address for shipping and removes one', async () => {
		mockedFetch.mockImplementation( ( options ) => {
			const request = options as Request;
			if ( request.path.endsWith( '/store-credit' ) ) {
				return Promise.resolve( CREDIT );
			}
			if ( request.path.startsWith( '/orillacart/v1/countries' ) ) {
				return Promise.resolve( [] );
			}
			if ( request.path.endsWith( '/notes' ) ) {
				return Promise.resolve( [] );
			}
			return Promise.resolve( ADA );
		} );
		const onSaved = jest.fn();
		const user = userEvent.setup();
		render(
			<CustomerCard
				groups={ [] }
				customer={ ADA }
				onClose={ () => undefined }
				onSaved={ onSaved }
				onError={ ( error ) => {
					throw error;
				} }
			/>
		);

		expect(
			screen.getByText(
				'Ada Lovelace, 1 Vitosha Blvd, 1000 Sofia, BG · Billing · Shipping'
			)
		).toBeVisible();
		await user.click(
			screen.getByRole( 'button', { name: 'Add an address' } )
		);
		await user.type( screen.getByLabelText( 'Street' ), '5 Shipka St' );
		await user.click(
			screen.getByLabelText( 'Checkout uses it for shipping' )
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Save address' } )
		);

		expect( mockedFetch ).toHaveBeenCalledWith( {
			path: '/orillacart/v1/customers/3/addresses',
			method: 'POST',
			data: {
				address: expect.objectContaining( { street: '5 Shipka St' } ),
				billing: false,
				shipping: true,
			},
		} );
		expect( onSaved ).toHaveBeenCalledWith( ADA );

		await user.click( screen.getByRole( 'button', { name: 'Remove' } ) );
		await user.click(
			screen.getAllByRole( 'button', { name: 'Remove' } ).at( -1 )!
		);
		expect( mockedFetch ).toHaveBeenCalledWith( {
			path: '/orillacart/v1/customers/3/addresses/ab12cd34',
			method: 'DELETE',
		} );
	} );

	it( 'shows what the customer spent and keeps the shop’s notes', async () => {
		mockedFetch.mockImplementation( ( options ) => {
			const request = options as Request;
			if ( request.path.endsWith( '/store-credit' ) ) {
				return Promise.resolve( CREDIT );
			}
			if ( request.path === '/orillacart/v1/customers/3/notes' ) {
				return Promise.resolve(
					request.method === 'POST'
						? {
								id: 2,
								text: 'Wholesale prices agreed.',
								author: 'Mara',
								created_at: '2026-10-20T10:00:00+00:00',
							}
						: [
								{
									id: 1,
									text: 'Prefers calls.',
									author: null,
									created_at: '2026-10-19T10:00:00+00:00',
								},
							]
				);
			}
			return Promise.resolve( [] );
		} );
		const user = userEvent.setup();
		render(
			<CustomerCard
				groups={ [] }
				customer={ ADA }
				onClose={ () => undefined }
				onSaved={ () => undefined }
				onError={ ( error ) => {
					throw error;
				} }
			/>
		);

		expect(
			screen.getByText( 'Paid orders' ).nextSibling
		).toHaveTextContent( '2' );
		expect( await screen.findByText( 'Prefers calls.' ) ).toBeVisible();
		expect(
			await screen.findByRole( 'heading', { name: /^Store credit: / } )
		).toBeVisible();
		expect( screen.getByText( 'Sorry for the wait' ) ).toBeVisible();
		await user.type(
			screen.getByLabelText( 'Note for the shop' ),
			'Wholesale prices agreed.'
		);
		await user.click( screen.getByRole( 'button', { name: 'Add note' } ) );

		expect(
			await screen.findByText( 'Wholesale prices agreed.' )
		).toBeVisible();
		expect( mockedFetch ).toHaveBeenCalledWith( {
			path: '/orillacart/v1/customers/3/notes',
			method: 'POST',
			data: { text: 'Wholesale prices agreed.' },
		} );
		expect( screen.getByLabelText( 'Note for the shop' ) ).toHaveValue(
			''
		);
	} );
} );
