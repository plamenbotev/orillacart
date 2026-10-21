import apiFetch from '@wordpress/api-fetch';
import { render, screen } from '@testing-library/react';

import { reportText, type Status } from '../api';
import { StatusScreen } from '../StatusScreen';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const status: Status = {
	checks: [
		{
			id: 'jobs',
			check: 'The shop’s background jobs',
			status: 'critical',
			finding: 'Nothing runs the shop’s background jobs',
			description: 'Make sure WP-Cron runs.',
			action_url: 'admin.php?page=orillacart-jobs',
			action_label: 'Background jobs',
		},
	],
	sections: [
		{ title: 'Server', fields: [ { label: 'PHP', value: '8.4.26' } ] },
	],
};

describe( 'StatusScreen', () => {
	it( 'shows each check and what the shop runs on', async () => {
		mockedFetch.mockImplementation( ( options ) =>
			Promise.resolve(
				String( options.path ).startsWith( '/orillacart/v1/change-log' )
					? {
							records: [
								{
									id: 1,
									user_id: 1,
									user: 'admin',
									area: 'payment-methods',
									route: 'PUT /payment-methods/{gateway}',
									path: '/payment-methods/paypal',
									values: { client_secret: '••••' },
									made_at: '2026-10-21T08:00:00+00:00',
								},
							],
							total: 1,
							page: 1,
							pages: 1,
							areas: [ 'payment-methods' ],
						}
					: status
			)
		);

		render( <StatusScreen /> );

		await screen.findByText( 'Nothing runs the shop’s background jobs' );
		screen.getByText( 'Needs fixing' );
		expect(
			screen
				.getByRole( 'link', { name: 'Background jobs' } )
				.getAttribute( 'href' )
		).toBe( 'admin.php?page=orillacart-jobs' );
		screen.getByText( '8.4.26' );
		await screen.findByText( 'PUT /payment-methods/{gateway}' );
	} );

	it( 'writes the report as plain text', () => {
		expect( reportText( status ) ).toBe(
			[
				'### Checks',
				'',
				'The shop’s background jobs: critical — Nothing runs the shop’s background jobs',
				'',
				'### Server',
				'',
				'PHP: 8.4.26',
			].join( '\n' )
		);
	} );
} );
