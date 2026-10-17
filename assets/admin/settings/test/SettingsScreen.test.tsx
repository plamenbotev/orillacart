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
	weight_units: [ 'kg', 'g', 'lb', 'oz' ],
	dimension_units: [ 'cm', 'm', 'mm', 'in' ],
};

describe( 'SettingsScreen', () => {
	afterEach( () => mockedFetch.mockReset() );

	it( 'shows the stored settings', async () => {
		mockedFetch.mockResolvedValueOnce( stored );

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
		mockedFetch
			.mockResolvedValueOnce( stored )
			.mockResolvedValueOnce( { ...stored, currency: 'USD' } );
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
			},
		} );
	} );

	it( 'shows what the server refused next to the field', async () => {
		mockedFetch.mockResolvedValueOnce( stored ).mockRejectedValueOnce( {
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
		} );
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
		mockedFetch.mockRejectedValueOnce( {
			code: 'orillacart_forbidden',
			message: 'You are not allowed to do this.',
		} );

		render( <SettingsScreen /> );

		expect(
			await screen.findByText( 'You are not allowed to do this.', NOTICE )
		).toBeVisible();
	} );
} );
