import apiFetch from '@wordpress/api-fetch';

import { saveRule } from '../../price-rules/api';
import { saveCoupon, type Coupon } from '../api';

jest.mock( '@wordpress/api-fetch', () =>
	jest.fn( () => Promise.resolve( {} ) )
);

describe( 'saving what was loaded', () => {
	it( 'sends a coupon without its id and uses, which the API refuses', async () => {
		await saveCoupon( 7, {
			id: 7,
			uses: 3,
			code: 'SPRING',
		} as unknown as Coupon );
		expect( apiFetch ).toHaveBeenLastCalledWith(
			expect.objectContaining( {
				method: 'PUT',
				data: { code: 'SPRING' },
			} )
		);
	} );

	it( 'sends a price rule without its id', async () => {
		await saveRule( 4, { id: 4, name: 'Trade' } as unknown as Parameters<
			typeof saveRule
		>[ 1 ] );
		expect( apiFetch ).toHaveBeenLastCalledWith(
			expect.objectContaining( {
				method: 'PUT',
				data: { name: 'Trade' },
			} )
		);
	} );
} );
