import { exemptionFileUrl, exportUrl, orderUploadUrl } from '../exports';

describe( 'download addresses', () => {
	beforeEach( () => {
		window.orillacartExport = {
			url: 'https://shop.test/wp-admin/admin-post.php',
			nonces: {
				orillacart_export_subscribers: 'subscribers',
				orillacart_exemption_file: 'exemption',
				orillacart_order_upload: 'upload',
			},
		};
	} );

	it( 'carry the nonce of their own action', () => {
		expect( exportUrl( 'subscribers', 'csv', {} ) ).toBe(
			'https://shop.test/wp-admin/admin-post.php?format=csv&action=orillacart_export_subscribers&_wpnonce=subscribers'
		);
		expect( exemptionFileUrl( 3, 4 ) ).toBe(
			'https://shop.test/wp-admin/admin-post.php?customer=3&exemption=4&action=orillacart_exemption_file&_wpnonce=exemption'
		);
		expect( orderUploadUrl( 12, 34 ) ).toBe(
			'https://shop.test/wp-admin/admin-post.php?order=12&upload=34&action=orillacart_order_upload&_wpnonce=upload'
		);
	} );

	it( 'carry no other action’s nonce', () => {
		expect( exportUrl( 'orders', 'csv', {} ) ).toBe(
			'https://shop.test/wp-admin/admin-post.php?format=csv&action=orillacart_export_orders&_wpnonce='
		);
	} );
} );
