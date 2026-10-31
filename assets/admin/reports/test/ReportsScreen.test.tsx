import '@testing-library/jest-dom';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { ReportsScreen } from '../ReportsScreen';
import type { RankingReport, ProductRow, SalesReport, Totals } from '../api';
import { toCsv } from '../csv';
import { change, day, presetOf } from '../periods';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

// Charts measure their box; jsdom has none.
globalThis.ResizeObserver = class {
	observe() {}
	unobserve() {}
	disconnect() {}
};

const frame = {
	from: '2030-03-01',
	to: '2030-03-31',
	interval: 'day' as const,
	previous_from: '2030-01-29',
	previous_to: '2030-02-28',
	currency: 'EUR',
	other_currencies: [ 'USD' ],
	pending: 3,
};

const totals = ( changes: Partial< Totals > = {} ): Totals => ( {
	orders: 10,
	items: 25,
	gross: '1100',
	discounts: '50',
	refunds: '50',
	shipping: '40',
	taxes: '200',
	total: '1290',
	customers: 8,
	new_customers: 5,
	net: '1000',
	total_sales: '1240',
	average_order: '129',
	...changes,
} );

const sales: SalesReport = {
	frame,
	totals: totals(),
	previous: totals( { net: '800', orders: 8, refunds: '0' } ),
	series: [
		{
			date: '2030-03-01',
			orders: 4,
			net: '400',
			total: '500',
			refunds: '0',
			previous_orders: 3,
			previous_net: '300',
		},
		{
			date: '2030-03-02',
			orders: 6,
			net: '600',
			total: '740',
			refunds: '50',
			previous_orders: 5,
			previous_net: '500',
		},
	],
	payments: [ { key: 'Bank transfer', orders: 10, total: '1290' } ],
	countries: [ { key: 'BG', orders: 10, total: '1290' } ],
	shipping: [ { key: '', orders: 10, total: '1290' } ],
};

const products: RankingReport< ProductRow > = {
	frame,
	rows: [
		{
			product_id: 47,
			name: 'Desk Lamp',
			quantity: 5,
			given: 1,
			net: '295',
			orders: 4,
			previous_quantity: 2,
			previous_net: '118',
		},
	],
};

describe( 'ReportsScreen', () => {
	beforeEach( () => {
		window.history.replaceState(
			null,
			'',
			'/wp-admin/admin.php?page=orillacart-reports&from=2030-03-01&to=2030-03-31'
		);
		mockedFetch.mockReset();
		mockedFetch.mockImplementation( ( options ) =>
			Promise.resolve(
				String( options.path ).includes( '/reports/products' )
					? products
					: sales
			)
		);
	} );

	it( 'shows the period’s figures against the days before, and what it leaves out', async () => {
		render( <ReportsScreen /> );

		const net = (
			await screen.findByText( 'Net sales', {
				selector: '.orillacart-reports__kpi-label',
			} )
		).closest( '.orillacart-reports__kpi' ) as HTMLElement;
		expect( within( net ).getByText( '▲ 25.0 %' ) ).toBeInTheDocument();
		const refunds = screen
			.getByText( 'Refunds', {
				selector: '.orillacart-reports__kpi-label',
			} )
			.closest( '.orillacart-reports__kpi' ) as HTMLElement;
		expect( within( refunds ).getByText( 'new' ) ).toBeInTheDocument();
		expect(
			screen.getAllByText( /3 orders are still being counted/ ).length
		).toBeGreaterThan( 0 );
		// Notices are also read out, in a hidden region.
		expect(
			screen.getAllByText( /Orders paid in USD are left out/ ).length
		).toBeGreaterThan( 0 );
		expect( screen.getByText( 'Not shipped' ) ).toBeInTheDocument();
		expect( mockedFetch.mock.calls[ 0 ]?.[ 0 ].path ).toBe(
			'/orillacart/v1/reports/sales?from=2030-03-01&to=2030-03-31&compare=previous&limit=10&sort=net'
		);
	} );

	it( 'ranks products and keeps the report in the address', async () => {
		const user = userEvent.setup();
		render( <ReportsScreen /> );
		await screen.findByText( 'Bank transfer' );

		await user.click( screen.getByRole( 'tab', { name: 'Products' } ) );

		const row = (
			await screen.findByRole( 'link', { name: 'Desk Lamp' } )
		).closest( 'tr' ) as HTMLElement;
		expect( within( row ).getByText( '+150.0 %' ) ).toBeInTheDocument();
		expect( window.location.search ).toContain( 'report=products' );

		await user.selectOptions(
			screen.getByLabelText( 'Compare with' ),
			'year'
		);
		expect( mockedFetch.mock.lastCall?.[ 0 ].path ).toContain(
			'compare=year'
		);
	} );
} );

describe( 'report helpers', () => {
	it( 'writes CSV that spreadsheets open safely', () => {
		expect(
			toCsv(
				[ 'name', 'net' ],
				[
					[ 'Lamp, large', '-5' ],
					[ '=HYPERLINK("x")', null ],
				]
			)
		).toBe( '﻿name,net\r\n"Lamp, large",-5\r\n"\'=HYPERLINK(""x"")",' );
	} );

	it( 'names the period chosen and the change from the one before', () => {
		const today = new Date( 2030, 2, 31 );
		expect( presetOf( '2030-03-02', '2030-03-31', today ) ).toBe( '30d' );
		expect( presetOf( '2030-03-01', '2030-03-31', today ) ).toBe( 'month' );
		expect( presetOf( '2030-03-05', '2030-03-31', today ) ).toBe(
			'custom'
		);
		expect( day( new Date( 2030, 0, 5 ) ) ).toBe( '2030-01-05' );
		expect( [
			change( 120, 100 ),
			change( 5, 0 ),
			change( 0, 0 ),
			change( 5, null ),
		] ).toEqual( [ 20, null, 0, null ] );
	} );
} );
