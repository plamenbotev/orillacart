import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { NexusCard } from '../NexusCard';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

type Request = { path: string; method?: string; data?: unknown };

const sold = ( sales: string, orders: number ) => ( { sales, orders } );

function serve( saved: ( request: Request ) => void ) {
	mockedFetch.mockImplementation( ( options ) => {
		const request = options as Request;
		if ( request.method === 'PUT' ) {
			saved( request );
			return Promise.resolve( request.data );
		}
		switch ( request.path ) {
			case '/orillacart/v1/reports/nexus':
				return Promise.resolve( {
					currency: 'USD',
					states: [
						{
							state: 'TX',
							name: 'Texas',
							last_twelve_months: sold( '650000', 900 ),
							this_year: sold( '500000', 700 ),
							last_year: sold( '300000', 400 ),
							threshold: { sales: '500000', orders: null },
							crossed: true,
							collecting: false,
						},
						{
							state: 'CA',
							name: 'California',
							last_twelve_months: sold( '20000', 30 ),
							this_year: sold( '10000', 10 ),
							last_year: sold( '0', 0 ),
							threshold: { sales: '500000', orders: null },
							crossed: false,
							collecting: true,
						},
					],
					tax_service: false,
					pending: 0,
				} );
			case '/orillacart/v1/tax/nexus-thresholds':
				return Promise.resolve( {
					everywhere: { sales: '100000', orders: null },
					states: [],
				} );
			case '/orillacart/v1/countries/US/states':
				return Promise.resolve( [
					{ code: 'CA', name: 'California' },
					{ code: 'TX', name: 'Texas' },
				] );
		}
		return Promise.reject( new Error( `Unexpected ${ request.path }` ) );
	} );
}

describe( 'NexusCard', () => {
	afterEach( () => mockedFetch.mockReset() );

	it( 'puts the states the shop may owe and collects nothing in first', async () => {
		serve( () => undefined );

		render( <NexusCard onSaved={ jest.fn() } onError={ jest.fn() } /> );

		expect(
			await screen.findByText( 'Crossed: register and collect' )
		).toBeInTheDocument();
		const rows = screen.getAllByRole( 'row' );
		expect( rows[ 1 ] ).toHaveTextContent( 'Texas' );
		expect( rows[ 2 ] ).toHaveTextContent( 'California' );
		expect( rows[ 2 ] ).toHaveTextContent( 'Below' );
	} );

	it( 'saves a state with its own threshold', async () => {
		const saved = jest.fn();
		serve( saved );
		const onSaved = jest.fn();

		render( <NexusCard onSaved={ onSaved } onError={ jest.fn() } /> );
		await screen.findByText( 'Crossed: register and collect' );
		await userEvent.selectOptions(
			screen.getByLabelText( 'A state with its own threshold' ),
			'TX'
		);
		const sales = screen.getAllByLabelText( 'Sales in a year' );
		await userEvent.clear( sales[ 1 ]! );
		await userEvent.type( sales[ 1 ]!, '500000' );
		await userEvent.click(
			screen.getByRole( 'button', { name: 'Save thresholds' } )
		);

		expect( saved ).toHaveBeenCalledWith(
			expect.objectContaining( {
				data: {
					everywhere: { sales: '100000', orders: null },
					states: [ { state: 'TX', sales: '500000', orders: null } ],
				},
			} )
		);
		expect( onSaved ).toHaveBeenCalled();
	} );
} );
