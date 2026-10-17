import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { ShippingScreen } from '../ShippingScreen';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const NOTICE = { selector: '.components-notice__content' };

const courier = {
	id: 1,
	name: 'Courier',
	active: true,
	position: 1,
	rates: [
		{
			id: 7,
			name: 'Bulgaria',
			price: '5',
			per_item: false,
			regions: [ 'BG' ],
			weight: { min: '0', max: '2' },
			volume: null,
			length: null,
			width: null,
			height: null,
			subtotal: null,
			taxable: true,
			tax_group_id: null,
		},
	],
};

type Request = { path: string; method?: string; data?: unknown };

function serve() {
	mockedFetch.mockImplementation( ( options ) => {
		const request = options as Request;
		if ( request.method === undefined ) {
			return Promise.resolve(
				request.path.endsWith( '/carriers' )
					? [ courier ]
					: [ { id: 3, name: 'Standard', rates: [] } ]
			);
		}
		return Promise.resolve( { ...courier, ...( request.data as object ) } );
	} );
}

describe( 'ShippingScreen', () => {
	afterEach( () => mockedFetch.mockReset() );

	it( 'saves a carrier with its rates, regions and limits', async () => {
		serve();
		const user = userEvent.setup();
		render( <ShippingScreen /> );

		await screen.findByRole( 'heading', { name: 'Courier' } );
		const shipsTo = screen.getAllByLabelText( 'Ships to' )[ 0 ];
		if ( shipsTo === undefined ) {
			throw new Error( 'No regions field.' );
		}
		await user.clear( shipsTo );
		await user.type( shipsTo, 'bg, us-ca' );
		const weightTo = screen.getByLabelText( 'Weight to' );
		await user.clear( weightTo );
		await user.click(
			screen.getByRole( 'button', { name: 'Save Courier' } )
		);

		expect(
			await screen.findByText( 'Carrier saved.', NOTICE )
		).toBeVisible();
		const data = mockedFetch.mock.lastCall?.[ 0 ]?.data as {
			rates: { id?: number; regions: string[]; weight: unknown }[];
		};
		expect( mockedFetch.mock.lastCall?.[ 0 ] ).toMatchObject( {
			path: '/orillacart/v1/carriers/1',
			method: 'PUT',
		} );
		expect( data ).not.toHaveProperty( 'id' );
		expect( data.rates[ 0 ] ).toMatchObject( {
			id: 7,
			regions: [ 'BG', 'US-CA' ],
			// From 0 with no end is no limit at all.
			weight: null,
		} );
	} );

	it( 'adds a carrier with a new rate', async () => {
		serve();
		const user = userEvent.setup();
		render( <ShippingScreen /> );

		await screen.findByRole( 'heading', { name: 'Add a carrier' } );
		const names = screen.getAllByLabelText( 'Name' );
		const name = names[ names.length - 1 ];
		if ( name === undefined ) {
			throw new Error( 'No name field.' );
		}
		await user.type( name, 'Post' );
		const add = screen.getAllByRole( 'button', { name: 'Add a rate' } );
		await user.click( add[ add.length - 1 ] as HTMLElement );
		await user.type(
			screen.getAllByLabelText( 'Rate name' )[ 1 ] as HTMLElement,
			'Anywhere'
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Add carrier' } )
		);

		expect(
			await screen.findByText( 'Carrier added.', NOTICE )
		).toBeVisible();
		expect( mockedFetch.mock.lastCall?.[ 0 ] ).toMatchObject( {
			path: '/orillacart/v1/carriers',
			method: 'POST',
			data: {
				name: 'Post',
				rates: [ { name: 'Anywhere', regions: [] } ],
			},
		} );
	} );
} );
