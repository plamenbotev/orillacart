import '@testing-library/jest-dom';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { FiltersScreen } from '../FiltersScreen';
import { lastWrite } from '../../shared/testing';
import { serve } from '../testing';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const NOTICE = { selector: '.components-notice__content' };
const TOAST = { selector: '.components-snackbar__content' };

describe( 'FiltersScreen', () => {
	afterEach( () => mockedFetch.mockReset() );

	it( 'adds a filter over attributes by name, its key made from its label', async () => {
		serve( mockedFetch );
		const user = userEvent.setup();
		render( <FiltersScreen /> );

		expect( await screen.findByText( 'f[size]' ) ).toBeVisible();
		expect( screen.getByText( 'Size, Размер' ) ).toBeVisible();
		await user.click(
			screen.getByRole( 'button', { name: 'Add filter' } )
		);
		const dialog = within( await screen.findByRole( 'dialog' ) );
		await user.type( dialog.getByLabelText( 'Label' ), 'Colour' );
		expect( dialog.getByLabelText( 'Key in the address' ) ).toHaveValue(
			'colour'
		);
		await user.type(
			dialog.getByLabelText( 'Attributes it covers' ),
			'Colour{Enter}'
		);
		await user.click(
			dialog.getByRole( 'button', { name: 'Save filter' } )
		);

		expect(
			await screen.findByText( 'Filter added.', TOAST )
		).toBeVisible();
		expect( lastWrite( mockedFetch ) ).toEqual( {
			path: '/orillacart/v1/search-filters',
			method: 'POST',
			data: {
				label: 'Colour',
				key: 'colour',
				attributes: [ 'Colour' ],
				position: 1,
				translations: {},
			},
		} );
	} );

	it( 'checks OpenSearch before switching to it', async () => {
		serve( mockedFetch );
		const user = userEvent.setup();
		render( <FiltersScreen /> );

		await user.selectOptions(
			await screen.findByLabelText( 'Search with' ),
			'opensearch'
		);
		await user.type(
			screen.getByLabelText( 'Address' ),
			'https://search.test:9200'
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Check and switch' } )
		);

		expect(
			await screen.findByText(
				'OpenSearch answered. 12 products are being indexed there.',
				NOTICE
			)
		).toBeVisible();
		expect( lastWrite( mockedFetch ) ).toEqual( {
			path: '/orillacart/v1/search-engine',
			method: 'PUT',
			data: {
				engine: 'opensearch',
				host: 'https://search.test:9200',
				username: '',
				password: '',
				index: 'orillacart',
				clear_password: false,
			},
		} );
		expect(
			screen.getByText(
				'OpenSearch’s index is being built; the shop searches its database until it is.',
				{ exact: false }
			)
		).toBeVisible();
	} );
} );
