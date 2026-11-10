import '@testing-library/jest-dom';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { CustomersScreen } from '../CustomersScreen';
import { CustomerSettingsScreen } from '../CustomerSettingsScreen';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

type Request = { path: string; method?: string; data?: unknown };

describe( 'CustomersScreen', () => {
	afterEach( () => mockedFetch.mockReset() );

	it( 'shows the invitations waiting, sends one again and withdraws another', async () => {
		const invitation = {
			id: 7,
			email: 'grace@example.com',
			first_name: 'Grace',
			last_name: 'Hopper',
			group_ids: [],
			invited_by: 1,
			sent_at: '2026-11-01T12:00:00+00:00',
			expires_at: '2026-11-08T12:00:00+00:00',
			expired: true,
			may_resend: true,
		};
		const recent = {
			...invitation,
			id: 8,
			email: 'alan@example.com',
			first_name: 'Alan',
			last_name: 'Turing',
			expired: false,
			may_resend: false,
		};
		const calls: Request[] = [];
		mockedFetch.mockImplementation( ( options ) => {
			const request = options as Request;
			calls.push( request );
			if ( request.path.startsWith( '/orillacart/v1/customer-groups' ) ) {
				return Promise.resolve( [] );
			}
			if ( request.path === '/orillacart/v1/customer-invitations' ) {
				return Promise.resolve( [ invitation, recent ] );
			}
			if (
				request.path === '/orillacart/v1/customer-invitations/7/resend'
			) {
				return Promise.resolve( {
					...invitation,
					expired: false,
					may_resend: false,
				} );
			}
			if ( request.path === '/orillacart/v1/customer-invitations/8' ) {
				return Promise.resolve( null );
			}
			return Promise.resolve( {
				customers: [],
				total: 0,
				page: 1,
				pages: 1,
			} );
		} );
		const user = userEvent.setup();
		render( <CustomersScreen /> );

		expect( await screen.findByText( 'grace@example.com' ) ).toBeVisible();
		expect( screen.getByText( 'Stopped working' ) ).toBeVisible();
		const buttons = screen.getAllByRole( 'button', {
			name: 'Send again',
		} );
		expect( buttons ).toHaveLength( 2 );
		expect( buttons[ 1 ] ).toBeDisabled();

		await user.click( buttons[ 0 ] as HTMLElement );
		await waitFor( () =>
			expect(
				calls.some(
					( call ) =>
						call.path ===
							'/orillacart/v1/customer-invitations/7/resend' &&
						call.method === 'POST'
				)
			).toBe( true )
		);
		await waitFor( () =>
			expect( screen.queryByText( 'Stopped working' ) ).toBeNull()
		);

		await user.click(
			screen.getAllByRole( 'button', {
				name: 'Withdraw',
			} )[ 1 ] as HTMLElement
		);
		await waitFor( () =>
			expect( screen.queryByText( 'alan@example.com' ) ).toBeNull()
		);
		expect(
			calls.some(
				( call ) =>
					call.path === '/orillacart/v1/customer-invitations/8' &&
					call.method === 'DELETE'
			)
		).toBe( true );
	} );

	it( 'lists customers and opens one as its own view', async () => {
		const ada = {
			id: 3,
			email: 'ada@example.com',
			first_name: 'Ada',
			last_name: 'Lovelace',
			registered_at: '2026-10-17T12:00:00+00:00',
			billing: null,
			shipping_address: null,
			orders: 2,
			lifetime_value: null,
			group_ids: [],
		};
		mockedFetch.mockImplementation( ( options ) => {
			const request = options as Request;
			if ( request.path.startsWith( '/orillacart/v1/customer-groups' ) ) {
				return Promise.resolve( [] );
			}
			if ( request.path === '/orillacart/v1/customer-invitations' ) {
				return Promise.resolve( [] );
			}
			if ( request.path.endsWith( '/store-credit' ) ) {
				return Promise.resolve( {
					card: { id: null, balance: '0', currency: 'USD' },
					entries: [],
				} );
			}
			if ( request.path === '/orillacart/v1/record-fields/customers' ) {
				return Promise.resolve( {
					sections: [
						{
							extension: 'loyalty',
							name: 'Loyalty',
							fields: [
								{
									name: 'points',
									kind: 'number',
									label: 'Points',
									help: null,
									required: false,
									default: 0,
									of: [],
									readonly: false,
									filter: false,
									column: true,
								},
							],
						},
					],
				} );
			}
			if ( request.path === '/orillacart/v1/customers/3' ) {
				// The record's own view: the one with the extensions' fields.
				return Promise.resolve( {
					...ada,
					extensions: { loyalty: { points: 100 } },
				} );
			}
			if ( request.path.startsWith( '/orillacart/v1/customers/' ) ) {
				return Promise.resolve( [] );
			}
			return Promise.resolve( {
				customers: [ ada ],
				total: 1,
				page: 1,
				pages: 1,
			} );
		} );
		const user = userEvent.setup();
		render( <CustomersScreen /> );

		expect( await screen.findByText( 'ada@example.com' ) ).toBeVisible();
		await user.click(
			screen.getByRole( 'button', { name: 'Ada Lovelace' } )
		);

		expect(
			await screen.findByRole( 'heading', {
				level: 1,
				name: 'Ada Lovelace',
			} )
		).toBeVisible();
		// The list's row has no extensions' fields: the whole customer is read for the screen (PLAN 359).
		expect(
			await screen.findByRole( 'spinbutton', { name: 'Points' } )
		).toHaveValue( 100 );
		await user.click(
			screen.getByRole( 'button', { name: '← All customers' } )
		);
		expect(
			await screen.findByRole( 'heading', {
				level: 1,
				name: 'Customers',
			} )
		).toBeVisible();
	} );
} );

describe( 'CustomerSettingsScreen', () => {
	afterEach( () => mockedFetch.mockReset() );

	it( 'requires accounts', async () => {
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
					guest_orders:
						(
							request.data as
								{ guest_orders?: boolean } | undefined
						 )?.guest_orders ?? true,
					roles: ( request.data as { roles?: string[] } | undefined )
						?.roles ?? [ 'orillacart_customer', 'subscriber' ],
					available_roles: [
						{ name: 'orillacart_customer', label: 'Shop customer' },
						{ name: 'subscriber', label: 'Subscriber' },
						{ name: 'author', label: 'Author' },
					],
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
			return Promise.resolve( [] );
		} );
		const user = userEvent.setup();
		render( <CustomerSettingsScreen /> );

		await user.click(
			await screen.findByLabelText(
				'Customers must log in or make an account to order'
			)
		);

		expect(
			await screen.findByText( 'Saved.', {
				selector: '.components-snackbar__content',
			} )
		).toBeVisible();
		expect( mockedFetch ).toHaveBeenCalledWith( {
			path: '/orillacart/v1/customer-settings',
			method: 'PUT',
			data: {
				accounts: 'required',
				guest_orders: true,
				roles: [ 'orillacart_customer', 'subscriber' ],
			},
		} );

		// Who counts as a customer is the shop's to choose; its own role always does.
		expect( screen.getByLabelText( 'Shop customer' ) ).toBeDisabled();
		await user.click( screen.getByLabelText( 'Author' ) );
		await waitFor( () =>
			expect( mockedFetch ).toHaveBeenCalledWith( {
				path: '/orillacart/v1/customer-settings',
				method: 'PUT',
				data: {
					accounts: 'required',
					guest_orders: true,
					roles: [ 'orillacart_customer', 'subscriber', 'author' ],
				},
			} )
		);
	} );
} );
