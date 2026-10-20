import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { ImportCard } from '../ImportCard';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const ready = {
	id: 3,
	filename: 'products.csv',
	status: 'ready',
	columns: [
		{
			id: 'sku',
			label: 'SKU',
			add_on: false,
			chosen: true,
			importable: true,
		},
		{
			id: 'id',
			label: 'Product ID',
			add_on: false,
			chosen: true,
			importable: false,
		},
	],
	ignored: [ 'colour' ],
	rows: 2,
	done: 0,
	created: 0,
	updated: 0,
	failed: 0,
	errors: [],
	created_at: '2026-10-20T10:00:00+00:00',
	finished_at: null,
};

describe( 'ImportCard', () => {
	afterEach( () => mockedFetch.mockReset() );

	it( 'uploads a file, starts it and shows what came of its rows', async () => {
		let polls = 0;
		mockedFetch.mockImplementation( ( options ) => {
			const { path, method } = options as {
				path: string;
				method?: string;
			};
			if ( path.endsWith( '/latest' ) ) {
				return Promise.resolve( { import: null } );
			}
			if ( method === 'POST' && path.endsWith( '/start' ) ) {
				return Promise.resolve( {
					...ready,
					status: 'running',
					done: 1,
				} );
			}
			if ( method === 'POST' ) {
				return Promise.resolve( ready );
			}
			polls++;
			return Promise.resolve( {
				...ready,
				status: 'done',
				done: 2,
				updated: 1,
				failed: 1,
				errors: [
					'Row 3: price: "lots" is not an amount such as 19.90.',
				],
			} );
		} );
		const user = userEvent.setup();
		const { container } = render( <ImportCard interval={ 10 } /> );

		const input = container.querySelector(
			'input[type="file"]'
		) as HTMLInputElement;
		await user.upload(
			input,
			new File( [ 'sku,price\nX,1\n' ], 'products.csv', {
				type: 'text/csv',
			} )
		);

		expect(
			await screen.findByText( 'products.csv has 2 rows. It changes:' )
		).toBeVisible();
		expect(
			screen.getByText( 'Product ID (finds the row)' )
		).toBeVisible();
		expect( screen.getByText( 'Left as they are: colour.' ) ).toBeVisible();
		await user.click(
			screen.getByRole( 'button', { name: 'Start the import' } )
		);

		expect(
			await screen.findByText(
				'products.csv is imported: 0 added, 1 updated, 1 refused.'
			)
		).toBeVisible();
		expect(
			screen.getByText(
				'Row 3: price: "lots" is not an amount such as 19.90.'
			)
		).toBeVisible();
		expect( polls ).toBe( 1 );
	} );
} );
