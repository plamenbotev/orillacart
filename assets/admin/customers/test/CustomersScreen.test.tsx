import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { CustomersScreen } from '../CustomersScreen';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

type Request = { path: string; method?: string; data?: unknown };

describe( 'CustomersScreen', () => {
	afterEach( () => mockedFetch.mockReset() );

	it( 'lists customers and requires accounts', async () => {
		mockedFetch.mockImplementation( ( options ) => {
			const request = options as Request;
			if (
				request.path.startsWith( '/orillacart/v1/customer-settings' )
			) {
				return Promise.resolve( {
					accounts:
						( request.data as { accounts?: string } | undefined )
							?.accounts ?? 'optional',
					modes: [ 'optional', 'required', 'disabled' ],
				} );
			}
			if (
				request.path.startsWith( '/orillacart/v1/newsletter-settings' )
			) {
				return Promise.resolve( {
					offered: false,
					consent: 'News.',
					lists: [],
					subscribers: 0,
				} );
			}
			return Promise.resolve( {
				customers: [
					{
						id: 3,
						email: 'ada@example.com',
						first_name: 'Ada',
						last_name: 'Lovelace',
						registered_at: '2026-10-17T12:00:00+00:00',
						billing: null,
						shipping_address: null,
						orders: 2,
					},
				],
				total: 1,
				page: 1,
				pages: 1,
			} );
		} );
		const user = userEvent.setup();
		render( <CustomersScreen /> );

		expect( await screen.findByText( 'ada@example.com' ) ).toBeVisible();
		await user.click(
			screen.getByLabelText(
				'Customers must log in or make an account to order'
			)
		);

		expect(
			await screen.findByText( 'Saved.', {
				selector: '.components-notice__content',
			} )
		).toBeVisible();
		expect( mockedFetch ).toHaveBeenCalledWith( {
			path: '/orillacart/v1/customer-settings',
			method: 'PUT',
			data: { accounts: 'required' },
		} );
	} );
} );
