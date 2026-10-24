import { errorMessage, fieldName } from '../api';

describe( 'refusals', () => {
	const refused = {
		code: 'orillacart_invalid_input',
		message: 'Some fields are not valid.',
		data: {
			violations: [
				{ field: 'rates[1].price', message: 'Use a number.' },
				{ field: 'price_decimals', message: 'Too many.' },
			],
		},
	};

	it( "names each field as the form shows it, with the list item's number", () => {
		expect(
			errorMessage( refused, {
				'rates[]': 'Rate %d',
				'rates[].price': 'Price',
			} )
		).toBe(
			'Some fields are not valid. Rate 2: Price: Use a number. Price decimals: Too many.'
		);
	} );

	it( "puts the API's name in words without a label", () => {
		expect( fieldName( 'rates[0].price' ) ).toBe( 'Rates 1 price' );
		expect(
			fieldName( 'rates[0].price', { 'rates[].price': 'Price' } )
		).toBe( 'Price' );
	} );
} );
