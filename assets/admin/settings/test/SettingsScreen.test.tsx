import '@testing-library/jest-dom';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { SettingsScreen } from '../SettingsScreen';
import { lastWrite } from '../../shared/testing';
import { hasUnsavedChanges } from '../../shared/useUnsavedChanges';
import { serve as serveAll, stored } from '../testing';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

// Notices also announce their text in a screen reader live region; look in the notice itself.
const NOTICE = { selector: '.components-notice__content' };
const TOAST = { selector: '.components-snackbar__content' };

/**
 * Answers the screen's requests; PUT /settings gets the given answer.
 *
 * @param save What PUT /settings resolves or rejects with.
 */
function serve( save?: () => Promise< unknown > ) {
	serveAll( mockedFetch, [], ( { path, method } ) =>
		save && method === 'PUT' && path === '/orillacart/v1/settings'
			? save()
			: undefined
	);
}

// The whole General screen renders in each test, and role queries over it are slow in jsdom: a busy CI
// runner took over Jest's default 5 seconds.
jest.setTimeout( 15000 );

describe( 'SettingsScreen', () => {
	afterEach( () => mockedFetch.mockReset() );

	it( 'shows the stored settings', async () => {
		serve();

		render( <SettingsScreen /> );

		expect( await screen.findByLabelText( 'Currency' ) ).toHaveValue(
			'EUR'
		);
		expect( screen.getByLabelText( 'Weight unit' ) ).toHaveValue( 'kg' );
		expect(
			await screen.findByLabelText( 'Offered currency' )
		).toHaveValue( 'USD' );
		expect( mockedFetch ).toHaveBeenCalledWith( {
			path: '/orillacart/v1/settings',
		} );
	} );

	it( 'saves every field with PUT', async () => {
		serve( () => Promise.resolve( { ...stored, currency: 'USD' } ) );
		const user = userEvent.setup();
		render( <SettingsScreen /> );

		const currency = await screen.findByLabelText( 'Currency' );
		await user.selectOptions(
			screen.getByLabelText( 'Price decimals' ),
			'0'
		);
		await user.clear( currency );
		await user.type( currency, 'usd' );
		await user.click(
			screen.getByRole( 'button', { name: 'Save settings' } )
		);

		expect(
			await screen.findByText( 'Settings saved.', TOAST )
		).toBeVisible();
		expect( mockedFetch ).toHaveBeenLastCalledWith( {
			path: '/orillacart/v1/settings',
			method: 'PUT',
			data: {
				currency: 'USD',
				fewer_decimals: 0,
				weight_unit: 'kg',
				dimension_unit: 'cm',
				shop_country: null,
				shop_state: null,
				shop_postcode: null,
				currency_sign: '€',
				currency_position: 'after_space',
				catalog_only: false,
				hide_prices: false,
				show_lowest_price: false,
				sell_to: [ 'BG' ],
				history_days: 365,
			},
		} );
	} );

	it( 'saves the other currencies with the settings, only when they changed', async () => {
		serve();
		const user = userEvent.setup();
		render( <SettingsScreen /> );

		await screen.findByLabelText( 'Currency' );
		await user.click(
			screen.getByRole( 'button', { name: 'Save settings' } )
		);
		await screen.findByText( 'Settings saved.', TOAST );
		expect(
			mockedFetch.mock.calls.filter(
				( [ request ] ) =>
					( request as { path: string } ).path ===
						'/orillacart/v1/currencies' &&
					( request as { method?: string } ).method === 'PUT'
			)
		).toHaveLength( 0 );

		const rate = screen.getByLabelText( '1 EUR is … USD' );
		await user.clear( rate );
		await user.type( rate, '1,10' );
		expect( hasUnsavedChanges() ).toBe( true );
		await user.click(
			screen.getByRole( 'button', { name: 'Save settings' } )
		);

		await waitFor( () =>
			expect( lastWrite( mockedFetch ) ).toEqual( {
				path: '/orillacart/v1/currencies',
				method: 'PUT',
				data: {
					offered: [
						{ code: 'USD', rate: '1.10', rounding: 'decimals' },
					],
				},
			} )
		);
		await waitFor( () => expect( hasUnsavedChanges() ).toBe( false ) );
	} );

	it( 'loads the settings anew after a file is imported, so a save does not put the old ones back', async () => {
		let current = stored;
		serveAll( mockedFetch, [], ( { path, method } ) => {
			if ( path === '/orillacart/v1/settings' && method === undefined ) {
				return Promise.resolve( current );
			}
			if ( path === '/orillacart/v1/settings-archive' ) {
				current = { ...stored, currency: 'USD' };
				return Promise.resolve( {
					imported: [ 'general' ],
					refused: {},
				} );
			}
			return undefined;
		} );
		const user = userEvent.setup();
		render( <SettingsScreen /> );

		expect( await screen.findByLabelText( 'Currency' ) ).toHaveValue(
			'EUR'
		);
		const file = new File( [ '{"general":{}}' ], 'settings.json', {
			type: 'application/json',
		} );
		Object.defineProperty( file, 'text', {
			value: () => Promise.resolve( '{"general":{}}' ),
		} );
		await user.upload(
			screen.getByLabelText( 'Import a settings file' ),
			file
		);

		expect(
			await screen.findByText( 'Imported: general.', TOAST )
		).toBeVisible();
		await waitFor( () =>
			expect( screen.getByLabelText( 'Currency' ) ).toHaveValue( 'USD' )
		);
	} );

	it( 'asks before leaving the page with a currency changed', async () => {
		serve();
		const user = userEvent.setup();
		render( <SettingsScreen /> );

		const field = await screen.findByLabelText( 'Offered currency' );
		expect( hasUnsavedChanges() ).toBe( false );
		await user.clear( field );
		await user.type( field, 'GBP' );

		expect( hasUnsavedChanges() ).toBe( true );
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

		// Once, for the page: the general settings and the currencies load together.
		expect(
			await screen.findAllByText(
				'You are not allowed to do this.',
				NOTICE
			)
		).toHaveLength( 1 );
	} );
} );
