import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import type { Order, OrderRefunds } from '../api';
import { RefundsCard, suggestedAmount } from '../RefundsCard';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const order = {
	number: 7,
	status: 'processing',
	lines: [
		{
			name: 'Lamp',
			quantity: 3,
			total: '30',
			tax: '6',
			refunded_quantity: 1,
		},
	],
	shipping: 'Post – Standard',
	shipping_total: '5',
	shipping_tax: '1',
	shipping_refunded: false,
	refunded_total: '12',
	currency: 'EUR',
	payment_name: 'PayPal',
} as unknown as Order;

const refunds: OrderRefunds = {
	refundable: '30',
	currency: 'EUR',
	paid: true,
	provider: 'PayPal',
	refunds: [
		{
			id: 4,
			method: 'provider',
			status: 'done',
			amount: '12',
			currency: 'EUR',
			lines: [ { line: 0, quantity: 1 } ],
			shipping: false,
			restock: true,
			reason: 'Arrived broken.',
			provider_reference: 'REFUND-1',
			error: null,
			created_at: '2026-10-17T12:00:00+00:00',
			settled_at: '2026-10-17T12:00:00+00:00',
		},
	],
};

type Request = { path: string; method?: string; data?: unknown };

describe( 'RefundsCard', () => {
	afterEach( () => mockedFetch.mockReset() );

	it( 'suggests what the chosen items and shipping cost, tax included', () => {
		expect( suggestedAmount( order, { 0: 2 }, true ) ).toBe( 30 );
		expect( suggestedAmount( order, {}, false ) ).toBe( 0 );
	} );

	it( 'lists the refunds and refunds two items through PayPal', async () => {
		const requests: Request[] = [];
		mockedFetch.mockImplementation( ( options ) => {
			const request = options as Request;
			requests.push( request );
			return Promise.resolve(
				request.method === 'POST'
					? { ...refunds, refundable: '6' }
					: refunds
			);
		} );
		const refunded = jest.fn();
		const user = userEvent.setup();
		render(
			<RefundsCard
				order={ order }
				onRefunded={ refunded }
				onError={ jest.fn() }
			/>
		);

		expect( await screen.findByText( 'Arrived broken.' ) ).toBeVisible();
		const items = screen.getByLabelText( 'Lamp: items to refund (of 2)' );
		await user.clear( items );
		await user.type( items, '2' );
		expect( screen.getByLabelText( 'Amount to refund' ) ).toHaveValue(
			'24'
		);
		await user.type(
			screen.getByLabelText( 'Reason (for the history)' ),
			'Returned.'
		);
		await user.click(
			screen.getByRole( 'button', { name: /Refund .* through PayPal/ } )
		);

		expect( requests ).toContainEqual( {
			path: '/orillacart/v1/orders/7/refunds',
			method: 'POST',
			data: {
				amount: '24',
				lines: [ { line: 0, quantity: 2 } ],
				shipping: false,
				restock: true,
				reason: 'Returned.',
				outside: false,
			},
		} );
		expect( refunded ).toHaveBeenCalled();
	} );

	it( 'offers to try a refund the provider did not answer again', async () => {
		mockedFetch.mockResolvedValue( {
			...refunds,
			refunds: [
				{
					...refunds.refunds[ 0 ],
					status: 'pending',
					error: 'PayPal: timed out',
				},
			],
		} );
		const user = userEvent.setup();
		render(
			<RefundsCard
				order={ order }
				onRefunded={ jest.fn() }
				onError={ jest.fn() }
			/>
		);

		expect( await screen.findByText( 'PayPal: timed out' ) ).toBeVisible();
		await user.click( screen.getByRole( 'button', { name: 'Try again' } ) );
		expect( mockedFetch.mock.calls[ 1 ]?.[ 0 ] ).toEqual( {
			path: '/orillacart/v1/refunds/4/retry',
			method: 'POST',
		} );
	} );
} );
