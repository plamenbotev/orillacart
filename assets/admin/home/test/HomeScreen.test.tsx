import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import apiFetch from '@wordpress/api-fetch';

import { HomeScreen, lineText } from '../HomeScreen';
import { adminUrl, type Home } from '../api';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const figures = { orders: 3, net: '120.00', average_order: '40.00', items: 5 };
const home: Home = {
	today: figures,
	week: {
		...figures,
		orders: 12,
		net: '480.00',
		series: [ 1, 2, 3, 4, 5, 6, 7 ].map( ( day ) => ( {
			date: `2026-11-0${ day }`,
			net: String( day * 10 ),
			orders: day,
		} ) ),
	},
	currency: 'USD',
	needs_you: [
		{
			kind: 'attention',
			text: '',
			count: 2,
			url: 'orders?attention=yes',
			tone: 'attention',
		},
		{
			kind: 'low_stock',
			text: '',
			count: 1,
			url: 'stock',
			tone: 'warning',
		},
		{
			kind: 'acme',
			text: '3 returns to accept',
			count: 0,
			url: 'https://shop.test/wp-admin/admin.php?page=acme',
			tone: 'info',
		},
	],
	setup_finished: false,
	recent: [
		{
			number: 165,
			status: 'on_hold',
			customer: 'Ada Lovelace',
			email: 'ada@example.com',
			customer_id: null,
			items: 2,
			total: '285.20',
			currency: 'USD',
			payment_name: 'Bank transfer',
			placed_at: '2026-11-05T10:00:00+00:00',
			needs_attention: false,
			paid: false,
		},
	],
	tiles: [
		{
			label: 'Returns',
			value: '3',
			detail: 'open',
			url: 'https://shop.test/returns',
		},
	],
};

describe( 'HomeScreen', () => {
	beforeAll( () => {
		window.orillacartMoney = {
			currency: 'USD',
			sign: '$',
			position: 'before',
			decimals: 2,
			thousands: ',',
			point: '.',
		};
	} );
	afterAll( () => {
		delete window.orillacartMoney;
	} );
	// The site's own hosts, as AdminPageRegistrar prints them: full links go only there.
	beforeEach( () => {
		window.orillacartAdmin = {
			page: 'home',
			group: 'Home',
			tabs: [],
			hosts: [ 'shop.test' ],
		};
	} );
	afterEach( () => {
		mockedFetch.mockReset();
		delete window.orillacartAdmin;
	} );

	it( 'shows the figures, what needs the shop manager, the setup and the latest orders', async () => {
		mockedFetch.mockResolvedValue( home );
		render( <HomeScreen /> );

		expect( await screen.findByText( '$480.00' ) ).toBeVisible();
		expect( screen.getByText( '12 orders' ) ).toBeVisible();
		expect(
			screen.getByRole( 'link', { name: '2 orders need attention' } )
		).toHaveAttribute(
			'href',
			'admin.php?page=orillacart-orders&attention=yes'
		);
		expect(
			screen.getByRole( 'link', { name: '1 item low in stock' } )
		).toHaveAttribute( 'href', 'admin.php?page=orillacart-stock' );
		expect(
			screen.getByRole( 'link', { name: '3 returns to accept' } )
		).toHaveAttribute(
			'href',
			'https://shop.test/wp-admin/admin.php?page=acme'
		);
		expect(
			screen.getByRole( 'link', { name: 'Continue the setup' } )
		).toBeVisible();
		expect(
			screen.getByRole( 'link', { name: 'Returns 3 open' } )
		).toBeVisible();
		expect( screen.getByRole( 'link', { name: '#165' } ) ).toHaveAttribute(
			'href',
			'admin.php?page=orillacart-orders&order=165'
		);
		expect( screen.getByText( 'On hold' ) ).toHaveClass(
			'oc-badge--warning'
		);
	} );

	it( 'says nothing needs you when nothing does, and hides the setup once done', async () => {
		mockedFetch.mockResolvedValue( {
			...home,
			needs_you: [],
			setup_finished: true,
			recent: [],
			tiles: [],
		} );
		render( <HomeScreen /> );

		expect( await screen.findByText( 'Nothing needs you.' ) ).toBeVisible();
		expect(
			screen.queryByRole( 'link', { name: 'Continue the setup' } )
		).not.toBeInTheDocument();
		expect( screen.getByText( 'No orders yet.' ) ).toBeVisible();
	} );

	it( 'words each line by what waits and turns a page into its admin address', () => {
		expect(
			lineText( {
				kind: 'ship',
				text: '',
				count: 1,
				url: '',
				tone: 'info',
			} )
		).toBe( '1 order to ship' );
		expect(
			lineText( {
				kind: 'failed_jobs',
				text: '',
				count: 4,
				url: '',
				tone: 'warning',
			} )
		).toBe( '4 background jobs failed' );
		expect( adminUrl( 'orders?status=processing' ) ).toBe(
			'admin.php?page=orillacart-orders&status=processing'
		);
		expect( adminUrl( 'jobs' ) ).toBe( 'admin.php?page=orillacart-jobs' );
		expect( adminUrl( '' ) ).toBe( '' );
	} );

	it( 'links only to the site, a page or an http(s) URL', () => {
		expect( adminUrl( 'admin.php?page=acme' ) ).toBe(
			'admin.php?page=acme'
		);
		expect( adminUrl( '/wp-admin/edit.php' ) ).toBe( '/wp-admin/edit.php' );
		expect( adminUrl( 'https://shop.test/returns' ) ).toBe(
			'https://shop.test/returns'
		);
		for ( const url of [
			'javascript:alert(1)//.php',
			'JavaScript:alert(1)',
			'data:text/html,<script>1</script>',
			'//evil.test/x.php',
			'/\\evil.test',
			'/\t/evil.test',
			'java\nscript:alert(1)',
			'https:/\\evil.test',
			'x.php:alert(1)',
			'https://evil.test/returns',
			'https://shop.test.evil.test/',
			'https://shop.test@evil.test/',
			'http://user@shop.test/',
		] ) {
			expect( adminUrl( url ) ).toBe( '' );
		}
		expect( adminUrl( 'HTTP://Shop.Test:8080/x' ) ).toBe(
			'HTTP://Shop.Test:8080/x'
		);
	} );
} );
