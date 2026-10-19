import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { PaymentsScreen } from '../PaymentsScreen';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const NOTICE = { selector: '.components-notice__content' };

const paypal = {
	gateway: 'paypal',
	name: 'PayPal',
	active: true,
	position: 4,
	countries: [],
	instructions: '',
	receipt_note: '',
	settings: {
		client_id: 'abc',
		client_secret: '',
		sandbox: true,
		webhook_id: '',
	},
	secrets_set: [ 'client_secret' ],
	fields: [
		{
			key: 'client_id',
			label: 'Client ID',
			type: 'text',
			required: true,
			help: '',
		},
		{
			key: 'client_secret',
			label: 'Secret',
			type: 'secret',
			required: true,
			help: '',
		},
		{
			key: 'sandbox',
			label: 'Sandbox',
			type: 'boolean',
			required: false,
			help: '',
		},
		{
			key: 'webhook_id',
			label: 'Webhook ID',
			type: 'text',
			required: false,
			help: '',
		},
	],
	not_ready: null,
	notification_url:
		'https://shop.test/wp-json/orillacart/v1/payments/paypal/webhook',
};

const cash = {
	...paypal,
	gateway: 'cash_on_delivery',
	name: 'Cash on delivery',
	position: 1,
	settings: {},
	secrets_set: [],
	fields: [],
	notification_url: null,
};

type Request = { path: string; method?: string; data?: unknown };

describe( 'PaymentsScreen', () => {
	afterEach( () => mockedFetch.mockReset() );

	it( 'sets up a method without ever showing a saved secret', async () => {
		mockedFetch.mockImplementation( ( options ) => {
			const request = options as Request;
			return request.method === undefined
				? Promise.resolve( [ cash, paypal ] )
				: Promise.resolve( {
						...paypal,
						...( request.data as object ),
					} );
		} );
		const user = userEvent.setup();
		render( <PaymentsScreen /> );

		await screen.findByRole( 'heading', { name: 'PayPal' } );
		expect( screen.getByLabelText( 'Secret' ) ).toHaveValue( '' );
		expect(
			screen.getByText( 'Saved. Leave empty to keep it.' )
		).toBeVisible();
		expect(
			screen.getByText( paypal.notification_url, { selector: 'code' } )
		).toBeVisible();
		expect( screen.getAllByText( 'Webhook address:' ) ).toHaveLength( 1 );

		const countries = screen.getAllByLabelText( 'Billing countries' )[ 0 ];
		await user.type( countries as HTMLElement, 'bg, ro' );
		const cashOn = screen.getAllByLabelText( 'Active' )[ 0 ];
		await user.click( cashOn as HTMLElement );
		await user.click(
			screen.getByRole( 'button', { name: 'Save Cash on delivery' } )
		);

		expect(
			await screen.findByText( 'Cash on delivery saved.', NOTICE )
		).toBeVisible();
		expect( mockedFetch.mock.lastCall?.[ 0 ] ).toMatchObject( {
			path: '/orillacart/v1/payment-methods/cash_on_delivery',
			method: 'PUT',
			data: { active: false, countries: [ 'BG', 'RO' ] },
		} );
	} );
} );
