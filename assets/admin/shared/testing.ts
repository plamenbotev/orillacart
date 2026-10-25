import type apiFetch from '@wordpress/api-fetch';

type Request = Parameters< typeof apiFetch >[ 0 ];

/**
 * The last request that changed something, in a test's mocked apiFetch: after a save the screen may load again,
 * so the last request of all can be a GET that came later.
 *
 * @param mock The mocked apiFetch.
 */
export function lastWrite(
	mock: jest.MockedFunction< typeof apiFetch >
): Request | undefined {
	return mock.mock.calls
		.map( ( [ request ] ) => request )
		.filter(
			( request ) => ( request.method ?? 'GET' ).toUpperCase() !== 'GET'
		)
		.pop();
}
