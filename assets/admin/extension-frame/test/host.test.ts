/**
 * Design 73: what the admin lets an extension's frame do.
 */
import apiFetch from '@wordpress/api-fetch';

import { attach, isAdminUrl, isOwnRoute } from '../host';
import { FROM_FRAME } from '../protocol';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );

describe( 'a frame', () => {
	it( "calls only its own extension's routes", () => {
		expect( isOwnRoute( 'loyalty', '/x/loyalty' ) ).toBe( true );
		expect( isOwnRoute( 'loyalty', '/x/loyalty/points/use' ) ).toBe( true );
		expect( isOwnRoute( 'loyalty', '/x/loyalty?page=2' ) ).toBe( true );
		expect( isOwnRoute( 'loyalty', '/x/loyalty-two/points' ) ).toBe(
			false
		);
		expect( isOwnRoute( 'loyalty', '/x/other/points' ) ).toBe( false );
		expect( isOwnRoute( 'loyalty', '/orders' ) ).toBe( false );
		expect( isOwnRoute( 'loyalty', '/x/loyalty/../../orders' ) ).toBe(
			false
		);
		expect( isOwnRoute( 'loyalty', '/x/loyalty/..%2f..%2forders' ) ).toBe(
			false
		);
	} );

	it( "opens only the admin's screens", () => {
		const admin = 'https://shop.example/wp-admin/';
		expect(
			isAdminUrl( 'https://shop.example/wp-admin/edit.php', admin )
		).toBe( true );
		expect( isAdminUrl( 'admin.php?page=orillacart', admin ) ).toBe( true );
		expect( isAdminUrl( 'https://shop.example/cart/', admin ) ).toBe(
			false
		);
		expect( isAdminUrl( 'https://evil.example/wp-admin/', admin ) ).toBe(
			false
		);
		expect( isAdminUrl( 'javascript:alert(1)', admin ) ).toBe( false );
	} );

	it( 'tells the manager when its extension was asked too much', async () => {
		( apiFetch as unknown as jest.Mock ).mockRejectedValueOnce( {
			status: 429,
			json: async () => ( {
				code: 'orillacart_too_many_requests',
				message: 'This extension was asked a lot at once.',
				data: { retry_after: 42 },
			} ),
		} );
		const holder = document.createElement( 'div' );
		const element = document.createElement( 'iframe' );
		holder.appendChild( element );
		document.body.appendChild( holder );
		const post = jest.fn();
		Object.defineProperty( element.contentWindow, 'postMessage', {
			value: post,
		} );
		const detach = attach( { element, extension: 'loyalty' } );

		window.dispatchEvent(
			new MessageEvent( 'message', {
				source: element.contentWindow,
				data: {
					source: FROM_FRAME,
					id: 1,
					kind: 'api',
					payload: { method: 'GET', path: '/x/loyalty/points' },
				},
			} )
		);
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

		expect( holder.querySelector( '.notice-warning' )?.textContent ).toBe(
			'This extension was asked a lot at once.'
		);
		expect( post ).toHaveBeenLastCalledWith(
			expect.objectContaining( {
				id: 1,
				ok: true,
				value: expect.objectContaining( {
					ok: false,
					status: 429,
					retryAfter: 42,
				} ),
			} ),
			'*'
		);
		detach();
	} );
} );
