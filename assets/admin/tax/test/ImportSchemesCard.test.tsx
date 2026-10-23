import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { ImportSchemesCard } from '../ImportSchemesCard';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

type Request = { path: string; method?: string; data?: unknown };

const known = [
	{
		scheme: 'ioss',
		label: 'EU Import One-Stop-Shop (IOSS)',
		legal_limit: '150',
		currency: 'EUR',
		per_item: false,
		example: 'IM2760000001',
	},
	{
		scheme: 'uk',
		label: 'United Kingdom',
		legal_limit: '135',
		currency: 'GBP',
		per_item: false,
		example: 'GB123456789',
	},
];

describe( 'ImportSchemesCard', () => {
	afterEach( () => mockedFetch.mockReset() );

	it( 'lists a scheme with the law’s limit in the shop’s currency and saves its number', async () => {
		const saved: Request[] = [];
		mockedFetch.mockImplementation( ( options ) => {
			const request = options as Request;
			if ( request.method === 'PUT' ) {
				saved.push( request );
			}
			return Promise.resolve( {
				shop_currency: 'EUR',
				known,
				schemes: [],
			} );
		} );
		render(
			<ImportSchemesCard onSaved={ jest.fn() } onError={ jest.fn() } />
		);

		await userEvent.click(
			await screen.findByLabelText( 'EU Import One-Stop-Shop (IOSS)' )
		);
		await userEvent.type(
			screen.getByPlaceholderText( 'IM2760000001' ),
			'IM2760000001'
		);
		await userEvent.click( screen.getByLabelText( 'United Kingdom' ) );
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Save schemes' } )
		);

		expect( saved ).toEqual( [
			expect.objectContaining( {
				path: '/orillacart/v1/tax/import-schemes',
				data: {
					schemes: [
						{
							scheme: 'ioss',
							number: 'IM2760000001',
							limit: '150',
						},
						{ scheme: 'uk', number: null, limit: '' },
					],
				},
			} ),
		] );
	} );
} );
