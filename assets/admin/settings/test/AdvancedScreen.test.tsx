import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { AdvancedScreen } from '../AdvancedScreen';
import { lastWrite } from '../../shared/testing';
import { serve } from '../testing';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const NOTICE = { selector: '.components-notice__content' };

describe( 'AdvancedScreen', () => {
	afterEach( () => mockedFetch.mockReset() );

	it( 'keeps the way downloads are sent only when the test file went through', async () => {
		serve( mockedFetch );
		const user = userEvent.setup();
		render( <AdvancedScreen /> );

		await user.selectOptions(
			await screen.findByRole( 'combobox', {
				name: 'Who sends the files',
			} ),
			'x_sendfile'
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Test and use' } )
		);

		expect(
			await screen.findByText(
				'The web server did not send the test file.',
				NOTICE
			)
		).toBeVisible();
		expect(
			screen.getByText( 'XSendFilePath /srv/downloads' )
		).toBeVisible();
		expect( lastWrite( mockedFetch ) ).toEqual( {
			path: '/orillacart/v1/download-delivery',
			method: 'PUT',
			data: {
				method: 'x_sendfile',
				internal_prefix: '/orillacart-files/',
			},
		} );
	} );
} );
