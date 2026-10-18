import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { NEW_RULE } from '../api';
import { PriceRulesScreen, tiersLabel } from '../PriceRulesScreen';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

type Request = { path: string; method?: string; data?: unknown };

describe( 'PriceRulesScreen', () => {
	afterEach( () => mockedFetch.mockReset() );

	it( 'says what a rule charges', () => {
		expect(
			tiersLabel( [
				{ min_quantity: 1, kind: 'percent', value: '10' },
				{ min_quantity: 10, kind: 'fixed_price', value: '9' },
			] )
		).toBe( '10 % off; from 10: 9' );
	} );

	it( 'lists the rules with their groups and adds one for a group', async () => {
		const requests: Request[] = [];
		mockedFetch.mockImplementation( ( options ) => {
			const request = options as Request;
			requests.push( request );
			if ( request.path.startsWith( '/wp/v2/' ) ) {
				return Promise.resolve( [] );
			}
			if ( request.path.startsWith( '/orillacart/v1/coupons' ) ) {
				return Promise.resolve( {
					coupons: [],
					total: 0,
					page: 1,
					pages: 1,
				} );
			}
			if ( request.path === '/orillacart/v1/customer-groups' ) {
				return Promise.resolve( [
					{ id: 2, name: 'Wholesale', members: 3 },
				] );
			}
			if ( request.method === 'POST' ) {
				return Promise.resolve( {
					...( request.data as object ),
					id: 9,
				} );
			}
			return Promise.resolve( [
				{ ...NEW_RULE, id: 1, name: 'Trade', group_ids: [ 2 ] },
			] );
		} );
		const user = userEvent.setup();
		render( <PriceRulesScreen /> );

		expect( await screen.findByText( 'Wholesale' ) ).toBeVisible();
		await user.click(
			screen.getByRole( 'button', { name: 'Add a rule' } )
		);
		await user.type( screen.getByLabelText( 'Name' ), 'Bulk' );
		await user.click(
			screen.getByRole( 'button', { name: 'Add a price for more items' } )
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Save the rule' } )
		);

		expect(
			await screen.findByText( /The rule Bulk was saved/, {
				selector: '.components-notice__content',
			} )
		).toBeVisible();
		const saved = requests.find( ( request ) => request.method === 'POST' );
		expect( saved?.data ).toMatchObject( {
			name: 'Bulk',
			tiers: [
				{ min_quantity: 1, kind: 'percent', value: '10' },
				{ min_quantity: 10, kind: 'percent', value: '15' },
			],
		} );
	} );
} );
