import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import type { Order, OrderShipments, Shipment } from '../api';
import { ShipmentsCard } from '../OrderCards';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const order = { number: 7 } as unknown as Order;

const sent: Shipment = {
	id: 3,
	number: 'PS1',
	carrier: 'Bulgarian Post',
	tracking_url: 'https://post.test/PS1',
	status: 'on_the_way',
	tracking: '',
	booked_at: '2026-10-20T10:00:00+00:00',
	items: [ { line: 0, name: 'Lamp', quantity: 2 } ],
	parcels: [
		{ number: 'PS1', tracking_url: 'https://post.test/PS1' },
		{ number: 'PS2', tracking_url: null },
	],
	sent_by_shop: true,
};

const waiting: OrderShipments = {
	bookable: false,
	carrier: null,
	shipments: [],
	left: [
		{ line: 0, name: 'Lamp', quantity: 3 },
		{ line: 1, name: 'Shade', quantity: 1 },
	],
	sendable: true,
	pickup: null,
	collectable: false,
};

type Request = { path: string; method?: string; data?: unknown };

describe( 'ShipmentsCard', () => {
	afterEach( () => mockedFetch.mockReset() );

	it( 'records a shipment of some of the items, in two parcels with their tracking numbers', async () => {
		let state = waiting;
		mockedFetch.mockImplementation( ( options ) => {
			const request = options as Request;
			if ( request.method === 'POST' ) {
				state = {
					...waiting,
					shipments: [ sent ],
					left: [
						{ line: 0, name: 'Lamp', quantity: 1 },
						{ line: 1, name: 'Shade', quantity: 1 },
					],
				};
				return Promise.resolve( sent );
			}
			return Promise.resolve( state );
		} );
		const user = userEvent.setup();
		render( <ShipmentsCard order={ order } onError={ jest.fn() } /> );

		const lamps = await screen.findByLabelText( 'Lamp (3 left)' );
		await user.clear( lamps );
		await user.type( lamps, '2' );
		await user.clear( screen.getByLabelText( 'Shade (1 left)' ) );
		await user.type(
			screen.getByLabelText( 'Sent with' ),
			'Bulgarian Post'
		);
		await user.type(
			screen.getByLabelText( 'Tracking number of parcel 1' ),
			'PS1'
		);
		await user.type(
			screen.getByLabelText( 'Tracking link of parcel 1' ),
			'https://post.test/PS1'
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Add a parcel' } )
		);
		await user.type(
			screen.getByLabelText( 'Tracking number of parcel 2' ),
			'PS2'
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Record the shipment' } )
		);

		expect( mockedFetch ).toHaveBeenCalledWith( {
			path: '/orillacart/v1/orders/7/shipments/sent',
			method: 'POST',
			data: {
				carrier: 'Bulgarian Post',
				parcels: [
					{ number: 'PS1', tracking_url: 'https://post.test/PS1' },
					{ number: 'PS2', tracking_url: null },
				],
				items: [ { line: 0, quantity: 2 } ],
			},
		} );
		expect(
			await screen.findByText( '2 × Lamp · Parcels: PS1, PS2' )
		).toBeVisible();
		expect( screen.getByLabelText( 'Lamp (1 left)' ) ).toHaveValue( 1 );
		await user.click(
			screen.getByRole( 'button', { name: 'It arrived' } )
		);
		expect( mockedFetch ).toHaveBeenCalledWith( {
			path: '/orillacart/v1/shipments/3/arrived',
			method: 'POST',
			data: { status: 'delivered' },
		} );
	} );
} );
