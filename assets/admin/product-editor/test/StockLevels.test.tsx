import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import type { Stockroom } from '../../inventory/api';
import { StockLevels } from '../StockLevels';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const room = ( id: number, name: string ): Stockroom => ( {
	id,
	name,
	description: '',
	min_delivery_days: 1,
	max_delivery_days: 2,
	active: true,
	delivery_unit: 'days',
	low_stock_threshold: null,
} );

type Request = { path: string; method?: string; data?: unknown };

describe( 'StockLevels', () => {
	afterEach( () => mockedFetch.mockReset() );

	it( 'saves only the stockroom changed, leaving what another owes', async () => {
		mockedFetch.mockImplementation( ( options ) => {
			const request = options as Request;
			return Promise.resolve(
				request.method === 'PATCH'
					? undefined
					: {
							item: 'product:10',
							levels: [
								{ stockroom_id: 1, quantity: 2 },
								{ stockroom_id: 2, quantity: -3 },
							],
							available: 0,
						}
			);
		} );
		const user = userEvent.setup();
		render(
			<StockLevels
				kind="product"
				id={ 10 }
				stockrooms={ [ room( 1, 'Sofia' ), room( 2, 'Berlin' ) ] }
				onError={ jest.fn() }
			/>
		);

		const sofia = await screen.findByLabelText( 'Sofia' );
		expect( screen.getByLabelText( 'Berlin' ) ).toHaveValue( -3 );
		await user.clear( sofia );
		await user.type( sofia, '5' );
		await user.click(
			screen.getByRole( 'button', { name: 'Save stock' } )
		);

		expect( mockedFetch ).toHaveBeenCalledWith( {
			path: '/orillacart/v1/stock-levels',
			method: 'PATCH',
			data: {
				changes: [
					{
						kind: 'product',
						id: 10,
						stockroom_id: 1,
						quantity: 5,
					},
				],
			},
		} );
	} );
} );
