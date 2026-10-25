import '@testing-library/jest-dom';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { JobsScreen, since } from '../JobsScreen';
import type { FailedJobPage, Overview } from '../api';
import { lastWrite } from '../../shared/testing';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const NOW = '2030-01-01T12:30:00+00:00';

const overview = ( changes: Partial< Overview > = {} ): Overview => ( {
	lanes: [
		{
			queue: 'mail',
			types: [ 'mail.order' ],
			due: 2,
			running: 0,
			later: 1,
			oldest_at: '2030-01-01T12:25:00+00:00',
			next_at: '2030-01-01T12:31:00+00:00',
			workers: 0,
		},
	],
	types: [
		{
			type: 'mail.order',
			queue: 'mail',
			due: 2,
			running: 0,
			later: 1,
			failed: 1,
		},
	],
	workers: [],
	runner: 'none',
	cron_ran_at: '2030-01-01T12:10:00+00:00',
	cron_disabled: false,
	failed: 1,
	scheduled: [],
	hours: [
		{
			at: '2030-01-01T12:00:00+00:00',
			type: 'mail.order',
			done: 4,
			retried: 1,
			failed: 1,
			average_ms: 120,
		},
	],
	days: [
		{
			at: '2030-01-01T00:00:00+00:00',
			type: 'mail.order',
			done: 4,
			retried: 1,
			failed: 1,
			average_ms: 120,
		},
	],
	now: NOW,
	...changes,
} );

const failed: FailedJobPage = {
	jobs: [
		{
			id: '7',
			queue: 'mail',
			type: 'mail.order',
			unique_key: '1042:order_received',
			payload: { order: 1042, kind: 'order_received' },
			error: 'The mail system refused it.',
			attempts: 5,
			failed_at: '2030-01-01T12:00:00+00:00',
			about: 'Order #1042: order received email',
		},
	],
	total: 1,
	page: 1,
	pages: 1,
};

type Request = { path: string; method?: string; data?: unknown };

describe( 'JobsScreen', () => {
	afterEach( () => mockedFetch.mockReset() );

	it( 'warns when nothing runs the jobs and retries a failed one', async () => {
		const requests: Request[] = [];
		mockedFetch.mockImplementation( ( options ) => {
			const request = options as Request;
			requests.push( request );
			if (
				request.path.startsWith( '/orillacart/v1/jobs/failed/retry' )
			) {
				return Promise.resolve( { retried: 1 } );
			}
			if ( request.path.startsWith( '/orillacart/v1/jobs/failed' ) ) {
				return Promise.resolve( failed );
			}
			return Promise.resolve( overview() );
		} );
		const user = userEvent.setup();
		render( <JobsScreen /> );

		expect(
			await screen.findByText( /Nothing runs the jobs/, {
				selector: '.components-notice__content',
			} )
		).toHaveTextContent( 'WP-Cron last ran 20 minutes ago' );
		const lane = screen.getByText( 'Mail' ).closest( 'tr' );
		expect( lane ).not.toBeNull();
		expect(
			within( lane as HTMLElement ).getByText( '5 minutes ago' )
		).toBeVisible();

		const job = await screen.findByText(
			'Order #1042: order received email'
		);
		expect(
			screen.getByText( 'The mail system refused it.' )
		).toBeVisible();
		await user.click( screen.getByRole( 'button', { name: 'Retry all' } ) );

		expect(
			await screen.findByText( '1 job is queued again.', {
				selector: '.components-notice__content',
			} )
		).toBeVisible();
		expect( requests ).toContainEqual( {
			path: '/orillacart/v1/jobs/failed/retry',
			method: 'POST',
			data: { all: true },
		} );
		expect( job ).toBeInTheDocument();
	} );

	it( 'says when WP-Cron is turned off and nothing else runs the jobs', async () => {
		mockedFetch.mockImplementation( ( options ) =>
			Promise.resolve(
				( options as Request ).path.startsWith(
					'/orillacart/v1/jobs/failed'
				)
					? failed
					: overview( { cron_disabled: true } )
			)
		);
		render( <JobsScreen /> );

		expect(
			await screen.findByText( /WP-Cron is turned off on this site/, {
				selector: '.components-notice__content',
			} )
		).toHaveTextContent( 'wp cron event run --due-now' );
	} );

	it( 'lists the workers and asks them to restart', async () => {
		mockedFetch.mockImplementation( ( options ) => {
			const request = options as Request;
			if ( request.path.startsWith( '/orillacart/v1/jobs/failed' ) ) {
				return Promise.resolve( { ...failed, jobs: [], total: 0 } );
			}
			return Promise.resolve(
				overview( {
					runner: 'workers',
					failed: 0,
					workers: [
						{
							name: 'web1:4242',
							queues: [ 'mail' ],
							started_at: '2030-01-01T12:00:00+00:00',
							seen_at: '2030-01-01T12:29:55+00:00',
							working: 'mail.order',
							jobs_done: 12,
							memory: 62914560,
						},
					],
				} )
			);
		} );
		const user = userEvent.setup();
		render( <JobsScreen /> );

		const worker = ( await screen.findByText( 'web1:4242' ) ).closest(
			'tr'
		);
		expect( worker ).toHaveTextContent( 'Order emails' );
		expect( worker ).toHaveTextContent( '60 MB' );
		expect(
			screen.queryByText( /Nothing runs the jobs/, {
				selector: '.components-notice__content',
			} )
		).toBeNull();

		await user.click(
			screen.getByRole( 'button', { name: 'Restart workers' } )
		);
		expect( lastWrite( mockedFetch ) ).toEqual( {
			path: '/orillacart/v1/jobs/workers/restart',
			method: 'POST',
		} );
	} );

	it( 'says how long ago or until', () => {
		expect( since( '2030-01-01T12:29:30+00:00', NOW ) ).toBe(
			'30 seconds ago'
		);
		expect( since( '2030-01-02T12:30:00+00:00', NOW ) ).toBe( 'in 1 day' );
	} );
} );
