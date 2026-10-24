import { currencyDecimals, money } from '../labels';

describe( 'money', () => {
	beforeEach( () => {
		window.orillacartMoney = {
			currency: 'EUR',
			sign: '€',
			position: 'after_space',
			decimals: 2,
			thousands: '.',
			point: ',',
		};
	} );

	it( "shows the shop's amounts with its decimals and the site language's separators", () => {
		expect( money( '1234567.5', 'EUR' ) ).toBe( '1.234.567,50 €' );
		expect( money( '-3.456', 'EUR' ) ).toBe( '-3,46 €' );
	} );

	it( 'shows another currency with its own decimals and code, as the storefront does', () => {
		expect( currencyDecimals( 'JPY' ) ).toBe( 0 );
		expect( money( '1500.4', 'JPY' ) ).toBe( '1.500 JPY' );
		expect( money( '12.3456', 'KWD' ) ).toBe( '12,346 KWD' );
	} );
} );
