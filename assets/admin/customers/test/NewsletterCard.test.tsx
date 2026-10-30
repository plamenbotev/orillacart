import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { NewsletterCard } from '../NewsletterCard';
import { hasUnsavedChanges } from '../../shared/useUnsavedChanges';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

type Request = { path: string; method?: string; data?: unknown };

describe( 'NewsletterCard', () => {
	afterEach( () => mockedFetch.mockReset() );

	it( 'offers the newsletter and sends the sign-ups to an add-on’s list', async () => {
		mockedFetch.mockImplementation( ( options ) => {
			const request = options as Request;
			const saved = request.data as
				| {
						offered: boolean;
						consent: string;
						lists: string[];
						confirm_new: boolean;
				  }
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
				confirm_new: saved?.confirm_new ?? true,
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
		expect(
			screen.getByLabelText( 'Confirm new subscribers by email' )
		).toBeChecked();
		await user.click(
			screen.getByLabelText( 'Confirm new subscribers by email' )
		);
		// Changes not saved keep the page from being left without asking.
		expect( hasUnsavedChanges() ).toBe( true );
		await user.click( screen.getByRole( 'button', { name: 'Save' } ) );

		expect( mockedFetch ).toHaveBeenCalledWith( {
			path: '/orillacart/v1/newsletter-settings',
			method: 'PUT',
			data: {
				offered: true,
				consent: 'News and offers.',
				confirm_new: false,
				lists: [ 'mailchimp' ],
			},
		} );
		expect( onSaved ).toHaveBeenCalled();
		expect( hasUnsavedChanges() ).toBe( false );
		expect(
			screen.getByRole( 'link', { name: 'Download them (CSV)' } )
		).toHaveAttribute(
			'href',
			expect.stringContaining( 'action=orillacart_export_subscribers' )
		);
	} );
} );
