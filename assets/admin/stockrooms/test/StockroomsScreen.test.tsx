import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { StockroomsScreen } from '../StockroomsScreen';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const NOTICE = { selector: '.components-notice__content' };

const sofia = {
	id: 1,
	name: 'Sofia',
	description: '',
	min_delivery_days: 1,
	max_delivery_days: 2,
	active: true,
};

/**
 * @param elements Elements in document order.
 * @return The last one; the test fails when there is none.
 */
function last( elements: HTMLElement[] ): HTMLElement {
	const element = elements[ elements.length - 1 ];
	if ( element === undefined ) {
		throw new Error( 'No such element.' );
	}

	return element;
}

type Request = { path: string; method?: string; data?: unknown };

/**
 * @param write Answers POST, PUT and DELETE requests.
 */
function serve( write: ( request: Request ) => Promise< unknown > ) {
	mockedFetch.mockImplementation( ( options ) => {
		const request = options as Request;
		return request.method === undefined
			? Promise.resolve( [ sofia ] )
			: write( request );
	} );
}

describe( 'StockroomsScreen', () => {
	afterEach( () => mockedFetch.mockReset() );

	it( 'adds a stockroom with its delivery time, given in weeks and kept in days', async () => {
		serve( ( request ) =>
			Promise.resolve( { id: 2, ...( request.data as object ) } )
		);
		const user = userEvent.setup();
		render( <StockroomsScreen /> );

		await screen.findByRole( 'heading', { name: 'Sofia' } );
		await user.type( last( screen.getAllByLabelText( 'Name' ) ), 'Berlin' );
		await user.selectOptions(
			last( screen.getAllByLabelText( 'Delivery time in' ) ),
			'weeks'
		);
		const longest = last( screen.getAllByLabelText( 'At most' ) );
		await user.clear( longest );
		await user.type( longest, '2' );
		await user.click(
			screen.getByRole( 'button', { name: 'Add stockroom' } )
		);

		expect(
			await screen.findByText( 'Stockroom added.', NOTICE )
		).toBeVisible();
		expect( mockedFetch ).toHaveBeenLastCalledWith( {
			path: '/orillacart/v1/stockrooms',
			method: 'POST',
			data: {
				name: 'Berlin',
				description: '',
				min_delivery_days: 0,
				max_delivery_days: 14,
				active: true,
				delivery_unit: 'weeks',
			},
		} );
		expect(
			await screen.findByRole( 'heading', { name: 'Berlin' } )
		).toBeVisible();
	} );

	it( 'deletes a stockroom after a second click', async () => {
		serve( () => Promise.resolve( null ) );
		const user = userEvent.setup();
		render( <StockroomsScreen /> );

		await user.click(
			await screen.findByRole( 'button', { name: 'Delete stockroom' } )
		);
		await user.click(
			screen.getByRole( 'button', {
				name: 'Delete the stockroom and its stock',
			} )
		);

		expect( mockedFetch ).toHaveBeenLastCalledWith( {
			path: '/orillacart/v1/stockrooms/1',
			method: 'DELETE',
		} );
		expect(
			screen.queryByRole( 'heading', { name: 'Sofia' } )
		).not.toBeInTheDocument();
	} );
} );
