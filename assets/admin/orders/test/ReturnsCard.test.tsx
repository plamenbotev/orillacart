import '@testing-library/jest-dom';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import type { ItemReturn, Order, OrderReturns } from '../api';
import { ReturnsCard } from '../ReturnsCard';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const order = {
	number: 7,
	currency: 'EUR',
	customer_id: null,
	email: 'ada@example.com',
} as unknown as Order;

const asked: ItemReturn = {
	id: 3,
	status: 'requested',
	items: [ { line: 0, name: 'Lamp', quantity: 1 } ],
	reason: 'damaged',
	reason_label: 'It arrived damaged',
	comment: 'The shade is cracked.',
	answer: '',
	amount: '30',
	requested_at: '2026-10-20T10:00:00+00:00',
	answered_at: null,
	received_at: null,
	refunded_at: null,
};

type Request = { path: string; method?: string; data?: unknown };

describe( 'ReturnsCard', () => {
	afterEach( () => mockedFetch.mockReset() );

	it( 'approves a return with an answer, then refunds it once the items came back', async () => {
		let current = asked;
		mockedFetch.mockImplementation( ( options ) => {
			const request = options as Request;
			if ( request.method === 'POST' ) {
				current = {
					...current,
					status: request.path.endsWith( '/approve' )
						? 'approved'
						: 'received',
				};
				return Promise.resolve( current );
			}
			return Promise.resolve( {
				accepting: true,
				until: '2026-11-19',
				returnable: [],
				returns: [ current ],
			} satisfies OrderReturns );
		} );
		const onChanged = jest.fn();
		const user = userEvent.setup();
		render(
			<ReturnsCard
				order={ order }
				onChanged={ onChanged }
				onError={ jest.fn() }
			/>
		);

		expect(
			await screen.findByText(
				/It arrived damaged: “The shade is cracked\.”/
			)
		).toBeVisible();
		expect(
			screen.getByRole( 'button', { name: 'Refuse' } )
		).toBeDisabled();
		await user.type(
			screen.getByLabelText( 'Answer for the customer' ),
			'Send it to 1 Shop St.'
		);
		await user.click( screen.getByRole( 'button', { name: 'Approve' } ) );
		expect( mockedFetch ).toHaveBeenCalledWith( {
			path: '/orillacart/v1/returns/3/approve',
			method: 'POST',
			data: { answer: 'Send it to 1 Shop St.' },
		} );

		await user.click(
			await screen.findByRole( 'button', {
				name: 'The items came back',
			} )
		);
		expect(
			await screen.findByRole( 'button', { name: /Refund 30\.00/ } )
		).toBeVisible();
		expect( onChanged ).toHaveBeenCalledTimes( 2 );
		expect(
			screen.getByRole( 'checkbox', {
				name: 'Put the items back in stock',
			} )
		).not.toBeChecked();

		await user.click(
			screen.getByRole( 'radio', {
				name: 'As a gift card, sent to ada@example.com',
			} )
		);
		await user.click(
			screen.getByRole( 'button', { name: /Refund 30\.00/ } )
		);
		expect( mockedFetch ).not.toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/orillacart/v1/returns/3/refund',
			} )
		);
		await user.click(
			within( screen.getByRole( 'dialog' ) ).getByRole( 'button', {
				name: 'Refund',
			} )
		);
		expect( mockedFetch ).toHaveBeenCalledWith( {
			path: '/orillacart/v1/returns/3/refund',
			method: 'POST',
			data: { outside: false, store_credit: true, restock: false },
		} );
	} );
} );
