import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { WebhooksCard } from '../WebhooksCard';
import type { Webhook } from '../api';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const webhook = ( changes: Partial< Webhook > = {} ): Webhook => ( {
	id: 3,
	name: 'ERP',
	url: 'https://erp.example.com/hook',
	topics: [ 'order.placed' ],
	active: true,
	secret: null,
	created_at: '2030-03-01T10:00:00+00:00',
	last_delivery: null,
	owner_id: 7,
	owner: 'Mara',
	failure_limit: 10,
	failed_in_a_row: 0,
	off_reason: null,
	off_at: null,
	...changes,
} );

describe( 'WebhooksCard', () => {
	beforeEach( () => mockedFetch.mockReset() );

	it( 'shows who a webhook is saved by, and why the shop switched one off', async () => {
		mockedFetch.mockResolvedValue( {
			webhooks: [
				webhook(),
				webhook( {
					id: 4,
					name: 'Old CRM',
					active: false,
					owner: '',
					off_reason: 'owner_left',
				} ),
				webhook( {
					id: 5,
					name: 'Dead end',
					active: false,
					failed_in_a_row: 10,
					off_reason: 'failing',
				} ),
			],
			topics: { 'order.placed': 'Order placed' },
		} );
		const { container } = render( <WebhooksCard /> );
		await screen.findByText( 'User #7 (deleted)' );
		const table = container.querySelector( 'table' )?.textContent ?? '';

		expect( screen.getAllByText( 'Mara' ) ).toHaveLength( 2 );
		expect( table ).toContain(
			'the manager it was saved by no longer manages the shop'
		);
		expect( table ).toContain(
			'its address took none of the last 10 events'
		);
	} );

	it( 'saves how many failed events in a row switch a webhook off', async () => {
		mockedFetch.mockImplementation( ( options ) =>
			Promise.resolve(
				options.method === 'PUT'
					? webhook( { failure_limit: 3 } )
					: {
							webhooks: [ webhook() ],
							topics: { 'order.placed': 'Order placed' },
						}
			)
		);
		render( <WebhooksCard /> );
		await userEvent.click(
			await screen.findByRole( 'button', { name: 'Edit' } )
		);
		const limit = screen.getByLabelText(
			'Switch off after this many failed events in a row'
		);
		await userEvent.clear( limit );
		await userEvent.type( limit, '3' );
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Save the webhook' } )
		);

		expect( mockedFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				method: 'PUT',
				data: expect.objectContaining( { failure_limit: 3 } ),
			} )
		);
	} );
} );
