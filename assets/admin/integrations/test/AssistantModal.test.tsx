import apiFetch from '@wordpress/api-fetch';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';

import { AssistantModal } from '../AssistantModal';
import type { ApiKey, AssistantCatalogue } from '../api';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const key: ApiKey = {
	id: 3,
	name: 'Claude',
	user_id: 1,
	user: 'admin',
	scope: 'read_write',
	prefix: 'oc_12345678',
	created_at: '2026-10-26T08:00:00+00:00',
	last_used_at: null,
	client: null,
};

const catalogue: AssistantCatalogue = {
	endpoint: 'https://shop.example/wp-json/orillacart/v1/mcp',
	oauth: true,
	areas: [
		{
			area: 'Ordering',
			add_on: false,
			tools: [
				{
					name: 'list_orders',
					title: 'List orders',
					description: 'A page of orders.',
					risk: null,
					read_only: true,
				},
			],
		},
		{
			area: 'Tax',
			add_on: false,
			tools: [
				{
					name: 'show_tax_settings',
					title: 'Show tax settings',
					description: 'How the shop taxes.',
					risk: null,
					read_only: true,
				},
			],
		},
		{
			area: 'Parcel Co',
			add_on: true,
			tools: [
				{
					name: 'parcelco_book_pickup',
					title: 'Book a pickup',
					description: 'Asks Parcel Co to collect.',
					risk: null,
					read_only: false,
				},
			],
		},
	],
};

describe( 'AssistantModal', () => {
	it( 'keeps what the owner leaves out and what they want to be asked about', async () => {
		mockedFetch.mockImplementation( ( options ) =>
			Promise.resolve(
				options.method === 'PUT'
					? options.data
					: {
							off_areas: [],
							off: [],
							list: 'all',
							ask: [
								'money',
								'deleting',
								'customers',
								'opening',
							],
						}
			)
		);
		const onClose = jest.fn();
		render(
			<AssistantModal
				apiKey={ key }
				catalogue={ catalogue }
				onClose={ onClose }
			/>
		);

		const tax =
			await screen.findByLabelText< HTMLInputElement >( 'Tax (1 tools)' );
		expect( tax.checked ).toBe( true );
		// An add-on's tools are listed one by one under its name.
		expect(
			screen.getByLabelText< HTMLInputElement >( 'Book a pickup' ).checked
		).toBe( true );

		fireEvent.click( tax );
		fireEvent.click( screen.getByLabelText( 'Book a pickup' ) );
		fireEvent.click(
			screen.getByLabelText(
				'Money moves: refunds, orders marked paid, gift card balances'
			)
		);
		fireEvent.click( screen.getByRole( 'button', { name: 'Save' } ) );

		await waitFor( () => expect( onClose ).toHaveBeenCalled() );
		expect( mockedFetch ).toHaveBeenLastCalledWith( {
			path: '/orillacart/v1/api-keys/3/assistant',
			method: 'PUT',
			data: {
				off_areas: [ 'Tax' ],
				off: [ 'parcelco_book_pickup' ],
				list: 'all',
				ask: [ 'deleting', 'customers', 'opening' ],
			},
		} );
	} );
} );
