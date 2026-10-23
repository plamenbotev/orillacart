import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { TaxExemptions } from '../TaxExemptions';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const sent = {
	id: 5,
	customer_id: 7,
	reason: 'resale',
	certificate: 'TX-778',
	regions: [ 'US-TX' ],
	expires_on: null,
	note: '',
	created_at: '2026-10-23T10:00:00+00:00',
	status: 'pending',
	refusal: null,
	reviewed_at: null,
	file: 'certificate.pdf',
};

describe( 'TaxExemptions', () => {
	afterEach( () => mockedFetch.mockReset() );

	it( 'refuses a certificate the customer sent, telling them why', async () => {
		mockedFetch.mockImplementation( ( options ) => {
			const request = options as {
				path: string;
				method?: string;
				data?: { reason: string };
			};
			if ( request.method === 'POST' ) {
				return Promise.resolve( {
					...sent,
					status: 'refused',
					refusal: request.data?.reason,
				} );
			}
			return Promise.resolve( [ sent ] );
		} );

		render( <TaxExemptions customerId={ 7 } onError={ jest.fn() } /> );

		expect(
			await screen.findByText( 'Sent by the customer: check it' )
		).toBeInTheDocument();
		expect(
			screen.getByRole( 'link', { name: 'certificate.pdf' } )
		).toHaveAttribute(
			'href',
			expect.stringContaining( 'action=orillacart_exemption_file' )
		);
		const refuse = screen.getByRole( 'button', { name: 'Refuse' } );
		expect( refuse ).toBeDisabled();
		await userEvent.type(
			screen.getByLabelText( 'Or refuse it, telling the customer why' ),
			'Not signed'
		);
		await userEvent.click( refuse );

		expect( mockedFetch ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/orillacart/v1/customers/7/tax-exemptions/5/refuse',
				data: { reason: 'Not signed' },
			} )
		);
		expect( await screen.findByText( 'Refused' ) ).toBeInTheDocument();
		expect(
			screen.queryByRole( 'button', { name: 'Approve' } )
		).not.toBeInTheDocument();
	} );
} );
