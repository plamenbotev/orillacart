import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { NEW_COUPON } from '../api';
import { CouponsScreen, discountLabel } from '../CouponsScreen';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

type Request = { path: string; method?: string; data?: unknown };

const spring = {
	...NEW_COUPON,
	id: 3,
	code: 'SPRING',
	description: 'Spring sale',
	usage_limit: 100,
	uses: 12,
};

describe( 'CouponsScreen', () => {
	afterEach( () => mockedFetch.mockReset() );

	it( 'says what each coupon takes off', () => {
		expect(
			discountLabel( { ...NEW_COUPON, amount: '15', cap: '20' } )
		).toBe( '15 %, at most 20' );
		expect(
			discountLabel( { ...NEW_COUPON, kind: 'item_amount', amount: '2' } )
		).toBe( '2 off each item' );
	} );

	it( 'lists the coupons and adds one', async () => {
		const requests: Request[] = [];
		mockedFetch.mockImplementation( ( options ) => {
			const request = options as Request;
			requests.push( request );
			if ( request.path.startsWith( '/wp/v2/' ) ) {
				return Promise.resolve( [] );
			}
			if ( request.method === 'POST' ) {
				return Promise.resolve( {
					...spring,
					...( request.data as object ),
					id: 4,
				} );
			}
			return Promise.resolve( {
				coupons: [ spring ],
				total: 1,
				page: 1,
				pages: 1,
			} );
		} );
		const user = userEvent.setup();
		render( <CouponsScreen /> );

		expect( await screen.findByText( '12 / 100' ) ).toBeVisible();
		await user.click(
			screen.getByRole( 'button', { name: 'Add a coupon' } )
		);
		await user.type( screen.getByLabelText( 'Code' ), 'welcome' );
		await user.click( screen.getByLabelText( 'First orders only' ) );
		await user.click(
			screen.getByRole( 'button', { name: 'Save the coupon' } )
		);

		expect(
			await screen.findByText( 'The coupon WELCOME was saved.', {
				selector: '.components-notice__content',
			} )
		).toBeVisible();
		const saved = requests.find( ( request ) => request.method === 'POST' );
		expect( saved?.path ).toBe( '/orillacart/v1/coupons' );
		expect( saved?.data ).toMatchObject( {
			code: 'WELCOME',
			kind: 'percent',
			amount: '10',
			first_order_only: true,
		} );
	} );
} );
