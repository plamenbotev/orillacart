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
						was: 2,
					},
				],
			},
		} );
	} );

	it( 'shows a level a sale moved meanwhile, keeps what was typed, and saves over it once asked', async () => {
		const patches: unknown[] = [];
		mockedFetch.mockImplementation( ( options ) => {
			const request = options as Request;
			if ( request.method === 'PATCH' ) {
				patches.push( request.data );
				return patches.length === 1
					? Promise.reject( {
							code: 'orillacart_stock_changed',
							message: 'The stock changed since it was loaded.',
							data: {
								status: 409,
								changed: [
									{
										kind: 'product',
										id: 10,
										stockroom_id: 1,
										quantity: 1,
									},
								],
							},
						} )
					: Promise.resolve( undefined );
			}
			return Promise.resolve( {
				item: 'product:10',
				levels: [ { stockroom_id: 1, quantity: 2 } ],
				available: 2,
			} );
		} );
		const onError = jest.fn();
		const user = userEvent.setup();
		render(
			<StockLevels
				kind="product"
				id={ 10 }
				stockrooms={ [ room( 1, 'Sofia' ) ] }
				onError={ onError }
			/>
		);

		const sofia = await screen.findByLabelText( 'Sofia' );
		await user.clear( sofia );
		await user.type( sofia, '5' );
		await user.click(
			screen.getByRole( 'button', { name: 'Save stock' } )
		);

		expect(
			await screen.findByText( 'Sofia now holds 1; you typed 5.' )
		).toBeInTheDocument();
		expect( sofia ).toHaveValue( 5 );
		expect( onError ).not.toHaveBeenCalled();

		await user.click(
			screen.getByRole( 'button', { name: 'Save what I typed anyway' } )
		);
		expect( patches[ 1 ] ).toEqual( {
			changes: [
				{
					kind: 'product',
					id: 10,
					stockroom_id: 1,
					quantity: 5,
					was: 1,
				},
			],
		} );
	} );
} );
