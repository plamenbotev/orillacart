import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { NewsletterCard } from '../NewsletterCard';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

type Request = { path: string; method?: string; data?: unknown };

describe( 'NewsletterCard', () => {
	afterEach( () => mockedFetch.mockReset() );

	it( 'offers the newsletter and sends the sign-ups to an add-on’s list', async () => {
		mockedFetch.mockImplementation( ( options ) => {
			const request = options as Request;
			const saved = request.data as
				| { offered: boolean; consent: string; lists: string[] }
				| undefined;
			return Promise.resolve( {
				offered: saved?.offered ?? false,
				consent: saved?.consent ?? 'News and offers.',
				lists: [
					{
						id: 'mailchimp',
						name: 'Mailchimp: Customers',
						on: saved?.lists.includes( 'mailchimp' ) ?? false,
					},
				],
				subscribers: 3,
			} );
		} );
		const onSaved = jest.fn();
		const user = userEvent.setup();
		render(
			<NewsletterCard
				onSaved={ onSaved }
				onError={ ( error ) => {
					throw error;
				} }
			/>
		);

		expect( await screen.findByText( /3 subscribers\./ ) ).toBeVisible();
		await user.click(
			screen.getByLabelText(
				'Offer the newsletter at checkout and in the account'
			)
		);
		await user.click( screen.getByLabelText( 'Mailchimp: Customers' ) );
		await user.click( screen.getByRole( 'button', { name: 'Save' } ) );

		expect( mockedFetch ).toHaveBeenCalledWith( {
			path: '/orillacart/v1/newsletter-settings',
			method: 'PUT',
			data: {
				offered: true,
				consent: 'News and offers.',
				lists: [ 'mailchimp' ],
			},
		} );
		expect( onSaved ).toHaveBeenCalled();
		expect(
			screen.getByRole( 'link', { name: 'Download them (CSV)' } )
		).toHaveAttribute(
			'href',
			expect.stringContaining( 'action=orillacart_export_subscribers' )
		);
	} );
} );
