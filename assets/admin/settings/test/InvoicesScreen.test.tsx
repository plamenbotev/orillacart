import '@testing-library/jest-dom';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { InvoicesScreen } from '../InvoicesScreen';
import { lastWrite } from '../../shared/testing';
import { hasUnsavedChanges } from '../../shared/useUnsavedChanges';
import { serve } from '../testing';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const TOAST = { selector: '.components-snackbar__content' };

describe( 'InvoicesScreen', () => {
	afterEach( () => mockedFetch.mockReset() );

	it( 'saves the seller’s details and the numbering with one button', async () => {
		serve( mockedFetch );
		const user = userEvent.setup();
		render( <InvoicesScreen /> );

		const name = await screen.findByLabelText( 'Legal name' );
		expect( hasUnsavedChanges() ).toBe( false );
		await user.type( name, 'Lamp Shop Ltd' );
		expect( hasUnsavedChanges() ).toBe( true );
		await user.click(
			screen.getByLabelText( 'Start the numbers again every year' )
		);
		// The example number follows the choice.
		expect(
			screen.getByText( 'The next one reads like INV-00001.' )
		).toBeVisible();
		await user.click(
			screen.getByRole( 'button', { name: 'Save invoice settings' } )
		);

		expect(
			await screen.findByText( 'Invoice settings saved.', TOAST )
		).toBeVisible();
		expect( lastWrite( mockedFetch ) ).toMatchObject( {
			path: '/orillacart/v1/invoice-settings',
			method: 'PUT',
			data: { legal_name: 'Lamp Shop Ltd', yearly: false },
		} );
		await waitFor( () => expect( hasUnsavedChanges() ).toBe( false ) );
	} );

	it( 'asks for the Peppol ID only for UBL e-invoices', async () => {
		serve( mockedFetch );
		const user = userEvent.setup();
		render( <InvoicesScreen /> );

		const kind = await screen.findByLabelText( 'E-invoices' );
		expect(
			screen.queryByLabelText( 'Peppol ID' )
		).not.toBeInTheDocument();
		await user.selectOptions( kind, 'ubl' );
		await user.type(
			screen.getByLabelText( 'Peppol ID' ),
			'0208:0123456789'
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Save invoice settings' } )
		);

		await waitFor( () =>
			expect( lastWrite( mockedFetch ) ).toMatchObject( {
				data: { e_invoices: 'ubl', peppol_id: '0208:0123456789' },
			} )
		);
	} );
} );
