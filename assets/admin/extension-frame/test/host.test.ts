/**
 * Design 73: what the admin lets an extension's frame do.
 */
import { isAdminUrl, isOwnRoute } from '../host';

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
} );
