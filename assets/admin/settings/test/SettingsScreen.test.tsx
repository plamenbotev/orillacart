import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { SettingsScreen } from '../SettingsScreen';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

// Notices also announce their text in a screen reader live region; look in the notice itself.
const NOTICE = { selector: '.components-notice__content' };

const stored = {
	currency: 'EUR',
	price_decimals: 2,
	weight_unit: 'kg',
	dimension_unit: 'cm',
	shop_country: null,
	shop_state: null,
	weight_units: [ 'kg', 'g', 'lb', 'oz' ],
	dimension_units: [ 'cm', 'm', 'mm', 'in' ],
};

const countries = [
	{ code: 'BG', name: 'Bulgaria', in_eu: true, has_states: false },
	{ code: 'US', name: 'United States', in_eu: false, has_states: true },
];

/**
 * Answers GET requests by path; PUT requests get the given answer.
 *
 * @param save What PUT /settings resolves or rejects with.
 */
function serve( save?: () => Promise< unknown > ) {
	mockedFetch.mockImplementation( ( options ) => {
		const { path, method } = options as { path: string; method?: string };
		if ( method === 'PUT' && save ) {
			return save();
		}
		if ( path === '/orillacart/v1/countries' ) {
			return Promise.resolve( countries );
		}
		if ( path === '/orillacart/v1/countries/US/states' ) {
			return Promise.resolve( [
				{ code: 'CA', name: 'California' },
				{ code: 'NY', name: 'New York' },
			] );
		}
		return Promise.resolve( stored );
	} );
}

describe( 'SettingsScreen', () => {
	afterEach( () => mockedFetch.mockReset() );

	it( 'shows the stored settings', async () => {
		serve();

		render( <SettingsScreen /> );

		expect( await screen.findByLabelText( 'Currency' ) ).toHaveValue(
			'EUR'
		);
		expect( screen.getByLabelText( 'Weight unit' ) ).toHaveValue( 'kg' );
		expect( mockedFetch ).toHaveBeenCalledWith( {
			path: '/orillacart/v1/settings',
		} );
	} );

	it( 'saves every field with PUT', async () => {
		serve( () => Promise.resolve( { ...stored, currency: 'USD' } ) );
		const user = userEvent.setup();
		render( <SettingsScreen /> );

		const currency = await screen.findByLabelText( 'Currency' );
		await user.clear( currency );
		await user.type( currency, 'usd' );
		await user.click(
			screen.getByRole( 'button', { name: 'Save settings' } )
		);

		expect(
			await screen.findByText( 'Settings saved.', NOTICE )
		).toBeVisible();
		expect( mockedFetch ).toHaveBeenLastCalledWith( {
			path: '/orillacart/v1/settings',
			method: 'PUT',
			data: {
				currency: 'USD',
				price_decimals: 2,
				weight_unit: 'kg',
				dimension_unit: 'cm',
				shop_country: null,
				shop_state: null,
			},
		} );
	} );

	it( 'offers the states of the shop country', async () => {
		serve( () => Promise.resolve( stored ) );
		const user = userEvent.setup();
		render( <SettingsScreen /> );

		await user.selectOptions(
			await screen.findByLabelText( 'Shop country' ),
			'US'
		);
		// The states load once the country is chosen.
		await screen.findByRole( 'option', { name: 'New York' } );
		await user.selectOptions( screen.getByLabelText( 'Shop state' ), 'NY' );
		await user.click(
			screen.getByRole( 'button', { name: 'Save settings' } )
		);

		expect( mockedFetch ).toHaveBeenLastCalledWith(
			expect.objectContaining( {
				method: 'PUT',
				data: expect.objectContaining( {
					shop_country: 'US',
					shop_state: 'NY',
				} ),
			} )
		);
	} );

	it( 'shows what the server refused next to the field', async () => {
		serve( () =>
			Promise.reject( {
				code: 'orillacart_invalid_input',
				message: 'Some fields are not valid.',
				data: {
					status: 422,
					violations: [
						{
							field: 'currency',
							message:
								'Use a three-letter currency code such as EUR.',
						},
					],
				},
			} )
		);
		const user = userEvent.setup();
		render( <SettingsScreen /> );

		await screen.findByLabelText( 'Currency' );
		await user.click(
			screen.getByRole( 'button', { name: 'Save settings' } )
		);

		expect(
			await screen.findByText( 'Some fields are not valid.', NOTICE )
		).toBeVisible();
		expect(
			screen.getByText( 'Use a three-letter currency code such as EUR.' )
		).toBeVisible();
	} );

	it( 'reports when the settings cannot be loaded', async () => {
		mockedFetch.mockRejectedValue( {
			code: 'orillacart_forbidden',
			message: 'You are not allowed to do this.',
		} );

		render( <SettingsScreen /> );

		expect(
			await screen.findByText( 'You are not allowed to do this.', NOTICE )
		).toBeVisible();
	} );
} );
