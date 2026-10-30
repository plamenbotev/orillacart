import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { StockPage } from '../StockPage';
import { StockScreen } from '../StockScreen';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const sheet = {
	stockrooms: [
		{ id: 1, name: 'Berlin' },
		{ id: 2, name: 'Sofia' },
	],
	rows: [
		{
			kind: 'product',
			id: 8,
			product_id: 8,
			name: 'Lamp',
			owner: '',
			sku: 'LAMP-1',
			stock_mode: 'tracked',
			levels: { 1: 4 },
		},
		{
			kind: 'variation',
			id: 20,
			product_id: 5,
			name: 'Hoodie – M',
			owner: '',
			sku: 'HOOD-M',
			stock_mode: 'tracked',
			levels: {},
		},
	],
	total: 2,
	page: 1,
	pages: 1,
};

describe( 'StockScreen', () => {
	beforeEach( () =>
		mockedFetch.mockImplementation( ( options ) => {
			const { path, method } = options as {
				path: string;
				method?: string;
			};
			if ( method === 'PATCH' ) {
				return Promise.resolve( undefined );
			}
			return Promise.resolve(
				path.startsWith( '/orillacart/v1/stock-sheet' ) ? sheet : []
			);
		} )
	);
	afterEach( () => mockedFetch.mockReset() );

	it( 'shows each item with its id, SKU and the amount in every stockroom', async () => {
		render( <StockScreen /> );

		expect(
			await screen.findByRole( 'spinbutton', { name: 'Lamp in Berlin' } )
		).toHaveValue( 4 );
		expect(
			screen.getByRole( 'spinbutton', { name: 'Lamp in Sofia' } )
		).toHaveValue( null );
		expect(
			screen.getByRole( 'row', { name: /Hoodie – M/ } )
		).toHaveTextContent( /20.*HOOD-M/ );
	} );

	it( 'saves only the changed cells; an emptied one keeps no count', async () => {
		const user = userEvent.setup();
		render( <StockScreen /> );

		await user.clear(
			await screen.findByRole( 'spinbutton', { name: 'Lamp in Berlin' } )
		);
		await user.type(
			screen.getByRole( 'spinbutton', { name: 'Hoodie – M in Sofia' } ),
			'3'
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Save 2 changes' } )
		);

		expect( mockedFetch ).toHaveBeenCalledWith( {
			path: '/orillacart/v1/stock-levels',
			method: 'PATCH',
			data: {
				changes: [
					{
						kind: 'product',
						id: 8,
						stockroom_id: 1,
						quantity: null,
						was: 4,
					},
					{
						kind: 'variation',
						id: 20,
						stockroom_id: 2,
						quantity: 3,
						was: null,
					},
				],
			},
		} );
		expect(
			await screen.findByText( 'Stock saved.', {
				selector: '.components-notice__content',
			} )
		).toBeVisible();
	} );

	it( 'keeps what was typed when a sale moved a level meanwhile, and saves over it once asked', async () => {
		const patches: unknown[] = [];
		mockedFetch.mockImplementation( ( options ) => {
			const { path, method, data } = options as {
				path: string;
				method?: string;
				data?: unknown;
			};
			if ( method === 'PATCH' ) {
				patches.push( data );
				return patches.length === 1
					? Promise.reject( {
							code: 'orillacart_stock_changed',
							message:
								'The stock changed since it was loaded: sales or another save moved it.',
							data: {
								status: 409,
								changed: [
									{
										kind: 'product',
										id: 8,
										stockroom_id: 1,
										quantity: 3,
									},
								],
							},
						} )
					: Promise.resolve( undefined );
			}
			return Promise.resolve(
				path.startsWith( '/orillacart/v1/stock-sheet' ) ? sheet : []
			);
		} );
		const user = userEvent.setup();
		render( <StockScreen /> );

		const lamp = await screen.findByRole( 'spinbutton', {
			name: 'Lamp in Berlin',
		} );
		await user.clear( lamp );
		await user.type( lamp, '10' );
		await user.click(
			screen.getByRole( 'button', { name: 'Save 1 change' } )
		);

		expect(
			await screen.findByText(
				'Lamp in Berlin now holds 3; you typed 10.'
			)
		).toBeInTheDocument();
		expect(
			await screen.findByRole( 'spinbutton', { name: 'Lamp in Berlin' } )
		).toHaveValue( 10 );

		await user.click(
			screen.getByRole( 'button', { name: 'Save what I typed anyway' } )
		);
		expect( patches[ 1 ] ).toEqual( {
			changes: [
				{
					kind: 'product',
					id: 8,
					stockroom_id: 1,
					quantity: 10,
					was: 3,
				},
			],
		} );
	} );

	it( 'keeps the typed levels while the history shows', async () => {
		mockedFetch.mockImplementation( ( options ) => {
			const { path } = options as { path: string };
			if ( path.startsWith( '/orillacart/v1/stock-history' ) ) {
				return Promise.resolve( {
					movements: [],
					totals: [],
					total: 0,
					page: 1,
					pages: 1,
				} );
			}
			return Promise.resolve(
				path.startsWith( '/orillacart/v1/stock-sheet' ) ? sheet : []
			);
		} );
		const user = userEvent.setup();
		render( <StockPage /> );

		const lamp = await screen.findByRole( 'spinbutton', {
			name: 'Lamp in Berlin',
		} );
		await user.clear( lamp );
		await user.type( lamp, '7' );
		await user.click( screen.getByRole( 'tab', { name: 'History' } ) );
		await user.click( screen.getByRole( 'tab', { name: 'Levels' } ) );

		expect(
			screen.getByRole( 'spinbutton', { name: 'Lamp in Berlin' } )
		).toHaveValue( 7 );
	} );
} );
