import '@testing-library/jest-dom';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { EuSalesCard } from '../EuSalesCard';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

type Request = { path: string; method?: string; data?: unknown };

const year = ( sales: string, orders: number ) => ( {
	from: '2026-01-01',
	to: '2026-12-31',
	sales,
	orders,
} );

function serve( applies: boolean, saved: ( request: Request ) => void ) {
	mockedFetch.mockImplementation( ( options ) => {
		const request = options as Request;
		if ( request.method === 'PUT' ) {
			saved( request );
			return Promise.resolve( {} );
		}
		return Promise.resolve( {
			applies,
			currency: 'EUR',
			threshold: '10000',
			this_year: year( '12000', 14 ),
			last_year: year( '3000', 4 ),
			countries: [
				{
					country: 'FR',
					name: 'France',
					this_year: '8000',
					last_year: '0',
					collecting: false,
				},
				{
					country: 'DE',
					name: 'Germany',
					this_year: '4000',
					last_year: '3000',
					collecting: true,
				},
			],
			crossed: true,
			taxed_at_origin: true,
			converted: false,
			pending: 0,
		} );
	} );
}

describe( 'EuSalesCard', () => {
	afterEach( () => mockedFetch.mockReset() );

	it( 'warns a shop past the threshold that still taxes at its own address', async () => {
		const saved: Request[] = [];
		serve( true, ( request ) => saved.push( request ) );
		render( <EuSalesCard onSaved={ jest.fn() } onError={ jest.fn() } /> );

		expect(
			await screen.findByText( /Your sales passed the threshold/ )
		).toBeInTheDocument();
		expect( screen.getByText( 'France' ) ).toBeInTheDocument();
		expect( screen.getByText( 'No rate' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Passed' ) ).toBeInTheDocument();

		const threshold = screen.getByLabelText( 'Threshold' );
		await userEvent.clear( threshold );
		await userEvent.type( threshold, '20000' );
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Save threshold' } )
		);
		expect( saved ).toEqual( [
			expect.objectContaining( {
				path: '/orillacart/v1/tax/eu-threshold',
				data: { threshold: '20000' },
			} ),
		] );
	} );

	it( 'shows nothing to a shop outside the EU', async () => {
		serve( false, jest.fn() );
		const { container } = render(
			<EuSalesCard onSaved={ jest.fn() } onError={ jest.fn() } />
		);

		await waitFor( () => expect( mockedFetch ).toHaveBeenCalled() );
		await waitFor( () => expect( container ).toBeEmptyDOMElement() );
	} );
} );
