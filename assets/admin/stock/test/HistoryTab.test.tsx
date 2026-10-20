import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { HistoryTab } from '../HistoryTab';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const history = {
	movements: [
		{
			id: 2,
			kind: 'product',
			item_id: 20,
			product_id: 20,
			name: 'E-book',
			sku: 'BOOK',
			stockroom_id: 1,
			stockroom: 'Sofia',
			delta: -2,
			after: 3,
			reason: 'sale',
			order_id: 41,
			user: null,
			at: '2026-10-20T10:00:00+00:00',
		},
	],
	totals: [
		{ reason: 'sale', stockroomId: 1, stockroom: 'Sofia', in: 0, out: 2 },
	],
	total: 1,
	page: 1,
	pages: 1,
};

describe( 'HistoryTab', () => {
	afterEach( () => mockedFetch.mockReset() );

	it( 'lists the movements and asks again by reason', async () => {
		mockedFetch.mockImplementation( ( options ) =>
			Promise.resolve(
				( options as { path: string } ).path.startsWith(
					'/orillacart/v1/stockrooms'
				)
					? [ { id: 1, name: 'Sofia' } ]
					: history
			)
		);
		const user = userEvent.setup();
		render( <HistoryTab /> );

		expect( await screen.findByText( '-2' ) ).toBeVisible();
		expect( screen.getByRole( 'link', { name: '#41' } ) ).toHaveAttribute(
			'href',
			'admin.php?page=orillacart-orders&s=41'
		);
		await user.selectOptions( screen.getByLabelText( 'Why' ), 'return' );
		expect(
			( mockedFetch.mock.lastCall?.[ 0 ] as { path: string } ).path
		).toBe(
			'/orillacart/v1/stock-history?reason=return&page=1&per_page=50'
		);
	} );
} );
