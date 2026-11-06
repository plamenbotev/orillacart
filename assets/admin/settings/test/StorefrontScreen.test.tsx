import '@testing-library/jest-dom';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { StorefrontScreen } from '../StorefrontScreen';
import { lastWrite } from '../../shared/testing';
import { hasUnsavedChanges } from '../../shared/useUnsavedChanges';
import { forgetContentLanguages } from '../../shared/Translations';
import { serve, TWO_LANGUAGES } from '../testing';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const TOAST = { selector: '.components-snackbar__content' };

const writes = () =>
	mockedFetch.mock.calls
		.map( ( [ request ] ) => request as { path: string; method?: string } )
		.filter( ( request ) => request.method === 'PUT' )
		.map( ( request ) => request.path );

jest.setTimeout( 15000 );

describe( 'StorefrontScreen', () => {
	afterEach( () => {
		mockedFetch.mockReset();
		forgetContentLanguages();
	} );

	it( 'saves who may review with the button, and nothing else when nothing else changed', async () => {
		serve( mockedFetch );
		const user = userEvent.setup();
		render( <StorefrontScreen /> );

		await user.click(
			await screen.findByLabelText(
				'Only customers who bought the product'
			)
		);
		// Nothing is sent until the button: a click can be taken back.
		expect( lastWrite( mockedFetch ) ).toBeUndefined();
		expect( hasUnsavedChanges() ).toBe( true );
		await user.click(
			screen.getByRole( 'button', { name: 'Save storefront settings' } )
		);

		expect(
			await screen.findByText( 'Storefront settings saved.', TOAST )
		).toBeVisible();
		expect( lastWrite( mockedFetch ) ).toEqual( {
			path: '/orillacart/v1/review-settings',
			method: 'PUT',
			data: { policy: 'buyers' },
		} );
		expect( writes() ).toEqual( [ '/orillacart/v1/review-settings' ] );
		await waitFor( () => expect( hasUnsavedChanges() ).toBe( false ) );
	} );

	it( 'lists untranslated products as the owner chooses, on a site in several languages', async () => {
		serve( mockedFetch, TWO_LANGUAGES );
		const user = userEvent.setup();
		render( <StorefrontScreen /> );

		await user.click(
			await screen.findByLabelText(
				'List products not translated into the language shown'
			)
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Save storefront settings' } )
		);

		await waitFor( () =>
			expect( lastWrite( mockedFetch ) ).toMatchObject( {
				path: '/orillacart/v1/storefront-settings',
				method: 'PUT',
				data: { list_untranslated: false },
			} )
		);
		expect( writes() ).toEqual( [ '/orillacart/v1/storefront-settings' ] );
	} );

	it( 'has nothing to choose about translations on a site in one language', async () => {
		serve( mockedFetch );
		render( <StorefrontScreen /> );

		expect(
			await screen.findByLabelText(
				'Leave out a shop filter with a single value'
			)
		).toBeVisible();
		expect(
			screen.queryByLabelText(
				'List products not translated into the language shown'
			)
		).not.toBeInTheDocument();
	} );

	it( 'saves the store notice in each language, with the other settings as they are', async () => {
		serve( mockedFetch, TWO_LANGUAGES );
		const user = userEvent.setup();
		render( <StorefrontScreen /> );

		await user.click(
			await screen.findByLabelText(
				'Show a notice over the shop’s pages'
			)
		);
		// Pasted whole: each typed key re-renders the whole screen, too slow for a busy CI runner.
		await user.click( screen.getByLabelText( 'Notice' ) );
		await user.paste( 'Free delivery this week' );
		await user.click( await screen.findByLabelText( 'Notice in Deutsch' ) );
		await user.paste( 'Kostenlose Lieferung' );
		await user.type( screen.getByLabelText( 'Link' ), '/sale' );
		await user.click( screen.getByLabelText( 'Shoppers may close it' ) );
		expect( lastWrite( mockedFetch ) ).toBeUndefined();
		expect( hasUnsavedChanges() ).toBe( true );

		await user.click(
			screen.getByRole( 'button', { name: 'Save storefront settings' } )
		);

		await waitFor( () =>
			expect( lastWrite( mockedFetch ) ).toMatchObject( {
				path: '/orillacart/v1/storefront-settings',
				method: 'PUT',
				data: {
					wishlist: 'guests',
					notice: true,
					notice_text: 'Free delivery this week',
					notice_link: '/sale',
					notice_link_label: '',
					notice_dismissible: false,
					notice_translations: {
						text: { de_DE: 'Kostenlose Lieferung' },
					},
				},
			} )
		);
		await waitFor( () => expect( hasUnsavedChanges() ).toBe( false ) );
	} );
} );
