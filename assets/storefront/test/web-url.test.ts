import { isWebUrl } from '../web-url';

describe( 'isWebUrl', () => {
	it( 'takes http(s) and paths of the site', () => {
		for ( const url of [
			'https://checkout.stripe.com/c/pay/1',
			'HTTPS://www.paypal.com/checkoutnow?token=1',
			'http://shop.test/receipt/',
			'/receipt/1?key=a',
		] ) {
			expect( isWebUrl( url ) ).toBe( true );
		}
	} );

	it( 'refuses what a browser would run or read another way', () => {
		for ( const url of [
			'javascript:alert(1)',
			'JavaScript:alert(1)',
			'data:text/html,<script>1</script>',
			'vbscript:msgbox(1)',
			'java\tscript:alert(1)',
			' javascript:alert(1)',
			'https:\\\\evil.test',
			'',
		] ) {
			expect( isWebUrl( url ) ).toBe( false );
		}
	} );
} );
