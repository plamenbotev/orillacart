import '@testing-library/jest-dom';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { PagesScreen } from '../PagesScreen';
import { lastWrite } from '../../shared/testing';
import { hasUnsavedChanges } from '../../shared/useUnsavedChanges';
import { serve } from '../testing';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const TOAST = { selector: '.components-snackbar__content' };

const writes = () =>
	mockedFetch.mock.calls
		.map( ( [ request ] ) => request as { path: string; method?: string } )
		.filter( ( request ) => request.method === 'PUT' )
		.map( ( request ) => request.path );

describe( 'PagesScreen', () => {
	afterEach( () => mockedFetch.mockReset() );

	it( 'saves the page each part of the shop is on, and only that', async () => {
		serve( mockedFetch );
		const user = userEvent.setup();
		render( <PagesScreen /> );

		const cart = await screen.findByRole( 'combobox', { name: 'Cart' } );
		expect( cart ).toHaveDisplayValue( 'Cart & more' );
		await user.selectOptions( cart, '' );
		await user.selectOptions(
			screen.getByRole( 'combobox', { name: 'Checkout' } ),
			'10'
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Save pages' } )
		);

		expect(
			await screen.findByText( 'Pages saved.', TOAST )
		).toBeVisible();
		expect( mockedFetch ).toHaveBeenCalledWith( {
			path: '/orillacart/v1/shop-pages',
			method: 'PUT',
			data: { shop: 9, cart: null, checkout: 10, account: null },
		} );
		// The legal settings did not change: they are not sent.
		expect( writes() ).toEqual( [ '/orillacart/v1/shop-pages' ] );
		await waitFor( () => expect( hasUnsavedChanges() ).toBe( false ) );
	} );

	it( 'chooses the legal pages and what checkout asks', async () => {
		serve( mockedFetch );
		const user = userEvent.setup();
		render( <PagesScreen /> );

		await user.selectOptions(
			await screen.findByRole( 'combobox', { name: 'Terms of sale' } ),
			'10'
		);
		await user.click(
			screen.getByRole( 'checkbox', {
				name: 'Checkout asks customers to accept the terms of sale',
			} )
		);
		await user.selectOptions(
			screen.getByRole( 'combobox', { name: 'The order button says' } ),
			'buy_now'
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Save pages' } )
		);

		expect(
			await screen.findByText( 'Pages saved.', TOAST )
		).toBeVisible();
		expect( lastWrite( mockedFetch ) ).toEqual( {
			path: '/orillacart/v1/legal-settings',
			method: 'PUT',
			data: {
				terms: 10,
				withdrawal: null,
				delivery: null,
				legal_notice: null,
				withdrawal_form: null,
				accept_terms: true,
				order_button: 'buy_now',
			},
		} );
		expect( writes() ).toEqual( [ '/orillacart/v1/legal-settings' ] );
	} );

	it( 'asks before leaving the page with a choice changed', async () => {
		serve( mockedFetch );
		const user = userEvent.setup();
		render( <PagesScreen /> );

		const shop = await screen.findByRole( 'combobox', { name: 'Shop' } );
		expect( hasUnsavedChanges() ).toBe( false );
		await user.selectOptions( shop, '' );

		expect( hasUnsavedChanges() ).toBe( true );
	} );

	it( 'says what went wrong when a save fails', async () => {
		serve( mockedFetch, [], ( { method } ) =>
			method === 'PUT'
				? Promise.reject( {
						code: 'orillacart_invalid_input',
						message: 'The cart page must hold the Cart block.',
					} )
				: undefined
		);
		const user = userEvent.setup();
		render( <PagesScreen /> );

		await user.selectOptions(
			await screen.findByRole( 'combobox', { name: 'Cart' } ),
			'9'
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Save pages' } )
		);

		expect(
			await screen.findByText(
				'The cart page must hold the Cart block.',
				{
					selector: '.components-notice__content',
				}
			)
		).toBeVisible();
		expect( hasUnsavedChanges() ).toBe( true );
	} );
} );
