import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { ShippingScreen } from '../ShippingScreen';
import { lastWrite } from '../../shared/testing';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const NOTICE = { selector: '.components-notice__content' };

const courier = {
	id: 1,
	name: 'Courier',
	active: true,
	position: 1,
	method: null,
	method_settings: {},
	secrets_set: [],
	method_taxable: true,
	method_tax_group_id: null,
	pickup_locations: [],
	delivery_schedule: null,
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
			items: null,
			taxable: true,
			tax_group_id: null,
			shipping_classes: [],
			postcodes: [],
			excluded_regions: [],
			excluded_postcodes: [],
		},
	],
};

type Request = { path: string; method?: string; data?: unknown };

function serve() {
	mockedFetch.mockImplementation( ( options ) => {
		const request = options as Request;
		if ( request.path.endsWith( '/shipping-methods' ) ) {
			return Promise.resolve( [
				{
					id: 'test_ship',
					name: 'Test Ship',
					fields: [
						{
							key: 'account',
							label: 'Account',
							type: 'text',
							required: true,
							help: '',
							choices: {},
						},
					],
				},
			] );
		}
		if ( request.path.includes( '/shipping-classes' ) ) {
			return Promise.resolve(
				request.method === undefined
					? [ { id: 4, name: 'Bulky' } ]
					: { id: 5, ...( request.data as object ) }
			);
		}
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
		await user.click(
			screen.getAllByLabelText( 'Every item' )[ 0 ] as HTMLElement
		);
		await user.click( screen.getByLabelText( 'Bulky' ) );
		await user.type(
			screen.getAllByLabelText( 'Postcodes' )[ 0 ] as HTMLElement,
			'1000-1999, SW1*'
		);
		await user.type(
			screen.getAllByLabelText( 'Except to' )[ 0 ] as HTMLElement,
			'us-hi'
		);
		const weightTo = screen.getByLabelText( 'Weight to' );
		await user.clear( weightTo );
		await user.click(
			screen.getByRole( 'button', { name: 'Save Courier' } )
		);

		expect(
			await screen.findByText( 'Carrier saved.', NOTICE )
		).toBeVisible();
		const data = lastWrite( mockedFetch )?.data as {
			rates: { id?: number; regions: string[]; weight: unknown }[];
		};
		expect( lastWrite( mockedFetch ) ).toMatchObject( {
			path: '/orillacart/v1/carriers/1',
			method: 'PUT',
		} );
		expect( data ).not.toHaveProperty( 'id' );
		expect( data.rates[ 0 ] ).toMatchObject( {
			id: 7,
			regions: [ 'BG', 'US-CA' ],
			// From 0 with no end is no limit at all.
			weight: null,
			// Items without a class, and bulky ones.
			shipping_classes: [ 0, 4 ],
			postcodes: [ '1000-1999', 'SW1*' ],
			excluded_regions: [ 'US-HI' ],
		} );
		// It types into many fields of a long form: more than Jest's default 5 seconds on a busy machine.
	}, 15000 );

	it( 'prices a carrier with an add-on’s shipping method', async () => {
		serve();
		const user = userEvent.setup();
		render( <ShippingScreen /> );

		await screen.findByRole( 'heading', { name: 'Courier' } );
		await user.selectOptions(
			screen.getAllByLabelText( 'Prices from' )[ 0 ] as HTMLElement,
			'test_ship'
		);
		expect( screen.queryByLabelText( 'Rate name' ) ).toBeNull();
		await user.type( screen.getByLabelText( 'Account' ), 'A-1' );
		await user.click(
			screen.getByRole( 'button', { name: 'Save Courier' } )
		);

		expect( lastWrite( mockedFetch ) ).toMatchObject( {
			path: '/orillacart/v1/carriers/1',
			method: 'PUT',
			data: {
				rates: [],
				method: 'test_ship',
				method_settings: { account: 'A-1' },
			},
		} );
	} );

	it( 'makes a carrier the shop’s own pickup at its places', async () => {
		serve();
		const user = userEvent.setup();
		render( <ShippingScreen /> );

		await screen.findByRole( 'heading', { name: 'Courier' } );
		await user.selectOptions(
			screen.getAllByLabelText( 'Prices from' )[ 0 ] as HTMLElement,
			'__pickup'
		);
		expect( screen.queryByLabelText( 'Rate name' ) ).toBeNull();
		await user.type( screen.getByLabelText( 'Place name' ), 'Sofia shop' );
		await user.type( screen.getByLabelText( 'Address' ), '1 Vitosha Blvd' );
		await user.type(
			screen.getByLabelText( 'Opening hours' ),
			'Mon–Fri 9–18'
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Save Courier' } )
		);

		expect( lastWrite( mockedFetch ) ).toMatchObject( {
			path: '/orillacart/v1/carriers/1',
			method: 'PUT',
			data: {
				rates: [],
				method: null,
				pickup_locations: [
					{
						name: 'Sofia shop',
						address: '1 Vitosha Blvd',
						hours: 'Mon–Fri 9–18',
						fee: '0',
					},
				],
			},
		} );
		const data = lastWrite( mockedFetch )?.data as {
			pickup_locations: { id: string }[];
		};
		expect( data.pickup_locations[ 0 ]?.id ).toMatch(
			/^[a-z0-9][a-z0-9-]{0,39}$/
		);
	} );

	it( 'lets customers choose the day of delivery, in time slots', async () => {
		serve();
		const user = userEvent.setup();
		render( <ShippingScreen /> );

		await screen.findByRole( 'heading', { name: 'Courier' } );
		await user.click(
			screen.getAllByLabelText(
				'Customers choose the day of delivery'
			)[ 0 ] as HTMLElement
		);
		await user.click( screen.getByLabelText( 'Saturday' ) );
		await user.type(
			screen.getByLabelText( 'Closed on' ),
			'2026-12-25, 2027-01-01'
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Add a time slot' } )
		);
		await user.type( screen.getByLabelText( 'Slot 1: orders a day' ), '5' );
		await user.click(
			screen.getByLabelText( 'The customer must choose a day' )
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Save Courier' } )
		);

		expect( lastWrite( mockedFetch ) ).toMatchObject( {
			path: '/orillacart/v1/carriers/1',
			data: {
				delivery_schedule: {
					weekdays: [ 1, 2, 3, 4, 5, 6 ],
					lead_days: 1,
					days_ahead: 14,
					closed: [ '2026-12-25', '2027-01-01' ],
					slots: [ { from: '09:00', to: '12:00', limit: 5 } ],
					required: true,
				},
			},
		} );
	}, 15000 );

	it( 'adds a shipping class', async () => {
		serve();
		const user = userEvent.setup();
		render( <ShippingScreen /> );

		await user.type(
			await screen.findByLabelText( 'New class' ),
			'Fragile'
		);
		await user.click( screen.getByRole( 'button', { name: 'Add class' } ) );

		expect( await screen.findByDisplayValue( 'Fragile' ) ).toBeVisible();
		expect( lastWrite( mockedFetch ) ).toEqual( {
			path: '/orillacart/v1/shipping-classes',
			method: 'POST',
			data: { name: 'Fragile' },
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
		expect( lastWrite( mockedFetch ) ).toMatchObject( {
			path: '/orillacart/v1/carriers',
			method: 'POST',
			data: {
				name: 'Post',
				rates: [ { name: 'Anywhere', regions: [] } ],
			},
		} );
	} );
} );
