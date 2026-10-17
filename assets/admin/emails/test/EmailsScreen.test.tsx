import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { EmailsScreen } from '../EmailsScreen';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const settings = {
	from_name: null,
	from_address: null,
	shop_address: null,
	disabled: [],
	default_name: 'Lamp Shop',
	default_address: 'owner@shop.test',
	kinds: [ 'order_received', 'new_order' ],
};

type Request = { path: string; method?: string; data?: unknown };

describe( 'EmailsScreen', () => {
	afterEach( () => mockedFetch.mockReset() );

	it( 'shows the site defaults and turns an email off', async () => {
		mockedFetch.mockImplementation( ( options ) => {
			const request = options as Request;
			return Promise.resolve(
				request.method === undefined
					? settings
					: { ...settings, ...( request.data as object ) }
			);
		} );
		const user = userEvent.setup();
		render( <EmailsScreen /> );

		const name = await screen.findByLabelText( 'From name' );
		expect( name ).toHaveAttribute( 'placeholder', 'Lamp Shop' );
		await user.type(
			screen.getByLabelText( 'The shop’s own emails go to' ),
			'orders@shop.test'
		);
		await user.click( screen.getByLabelText( 'New order (to the shop)' ) );
		await user.click( screen.getByRole( 'button', { name: 'Save' } ) );

		expect(
			await screen.findByText( 'Email settings saved.', {
				selector: '.components-notice__content',
			} )
		).toBeVisible();
		expect( mockedFetch.mock.lastCall?.[ 0 ] ).toEqual( {
			path: '/orillacart/v1/email-settings',
			method: 'PUT',
			data: {
				from_name: null,
				from_address: null,
				shop_address: 'orders@shop.test',
				disabled: [ 'new_order' ],
			},
		} );
	} );
} );
