import type { View } from '@wordpress/dataviews';

import { ordersQuery, viewArgs, viewFromArgs } from '../listView';

const DEFAULT: View = {
	type: 'table',
	page: 1,
	perPage: 20,
	search: '',
	filters: [],
	fields: [],
};

describe( 'the orders list view', () => {
	beforeEach( () => {
		window.orillacartTimeZone = 'Europe/Sofia';
	} );

	it( 'asks the API for the shop’s days, the payment, the total and the sort', () => {
		const view: View = {
			...DEFAULT,
			search: 'ada',
			page: 2,
			sort: { field: 'total', direction: 'asc' },
			filters: [
				{
					field: 'placed_at',
					operator: 'between',
					value: [ '2026-03-01', '2026-03-31' ],
				},
				{ field: 'payment', operator: 'is', value: 'paypal' },
				{ field: 'total', operator: 'greaterThanOrEqual', value: 50 },
				{ field: 'placed_by', operator: 'is', value: 'guest' },
			],
		};

		expect( ordersQuery( view ) ).toEqual( {
			status: undefined,
			attention: undefined,
			payment: 'paypal',
			placed_by: 'guest',
			search: 'ada',
			page: 2,
			per_page: 20,
			placed_from: '2026-02-28T22:00:00+00:00',
			placed_before: '2026-03-31T21:00:00+00:00',
			min_total: '50',
			order_by: 'total',
			order: 'asc',
		} );
	} );

	it( 'keeps the view in the address and reads it back', () => {
		const view: View = {
			...DEFAULT,
			search: 'ada',
			page: 3,
			sort: { field: 'number', direction: 'asc' },
			filters: [
				{ field: 'status', operator: 'is', value: 'processing' },
				{
					field: 'placed_at',
					operator: 'between',
					value: [ '2026-03-01', '2026-03-31' ],
				},
				{ field: 'total', operator: 'lessThanOrEqual', value: 20 },
			],
		};
		const args = viewArgs( view );

		expect( args ).toEqual( {
			status: 'processing',
			placed: 'between:2026-03-01..2026-03-31',
			total: 'lessThanOrEqual:20',
			s: 'ada',
			paged: '3',
			orderby: 'number',
			order: 'asc',
		} );
		expect(
			viewFromArgs( new URLSearchParams( args ).toString(), DEFAULT )
		).toEqual( view );
		expect( viewFromArgs( '?page=orillacart', DEFAULT ) ).toEqual(
			DEFAULT
		);
	} );
} );
