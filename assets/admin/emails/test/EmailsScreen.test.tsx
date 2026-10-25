import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { EmailsScreen } from '../EmailsScreen';
import { lastWrite } from '../../shared/testing';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const settings = {
	from_name: null,
	from_address: null,
	shop_address: null,
	disabled: [],
	default_name: 'Lamp Shop',
	default_address: 'owner@shop.test',
	kinds: [ 'order_received', 'new_order' ],
	reminder_days: 3,
	cart_reminder_hours: 4,
	cart_reminder_coupon: null,
};

const shipped = {
	name: 'order-shipped',
	edited: false,
	previews: 'order_shipped',
	updated_at: null,
	source: '{% block subject %}Shipped{% endblock %}',
	file_source: '{% block subject %}Shipped{% endblock %}',
};

type Request = { path: string; method?: string; data?: unknown };

function serve( answer: ( request: Request ) => unknown ) {
	mockedFetch.mockImplementation( ( options ) =>
		Promise.resolve( answer( options as Request ) )
	);
}

describe( 'EmailsScreen', () => {
	afterEach( () => mockedFetch.mockReset() );

	it( 'shows the site defaults and turns an email off', async () => {
		serve( ( request ) => {
			if ( request.path.startsWith( '/orillacart/v1/email-templates' ) ) {
				return [];
			}
			if ( request.path.startsWith( '/orillacart/v1/coupons' ) ) {
				return { coupons: [ { code: 'COMEBACK' } ], total: 1 };
			}
			return request.method === undefined
				? settings
				: { ...settings, ...( request.data as object ) };
		} );
		const user = userEvent.setup();
		render( <EmailsScreen /> );

		const name = await screen.findByLabelText( 'From name' );
		expect( name ).toHaveAttribute( 'placeholder', 'Lamp Shop' );
		await user.type(
			screen.getByLabelText( 'The shop’s own emails go to' ),
			'orders@shop.test'
		);
		await user.click( screen.getByLabelText( 'New order (to the shop)' ) );
		// A search box over all the codes, not the newest hundred. jsdom does not scroll lists.
		Element.prototype.scrollIntoView = jest.fn();
		await user.type(
			screen.getByLabelText( 'Coupon in the cart reminder' ),
			'COME'
		);
		await user.click(
			await screen.findByRole( 'option', { name: 'COMEBACK' } )
		);
		await user.click( screen.getByRole( 'button', { name: 'Save' } ) );

		expect(
			await screen.findByText( 'Email settings saved.', {
				selector: '.components-notice__content',
			} )
		).toBeVisible();
		expect( lastWrite( mockedFetch ) ).toEqual( {
			path: '/orillacart/v1/email-settings',
			method: 'PUT',
			data: {
				from_name: null,
				from_address: null,
				shop_address: 'orders@shop.test',
				disabled: [ 'new_order' ],
				reminder_days: 3,
				cart_reminder_hours: 4,
				cart_reminder_coupon: 'COMEBACK',
			},
		} );
	} );

	it( 'previews an edited template, then saves it', async () => {
		serve( ( request ) => {
			switch ( request.path ) {
				case '/orillacart/v1/email-templates':
					return [ shipped ];
				case '/orillacart/v1/email-templates/order-shipped':
					return request.method === 'PUT'
						? {
								...shipped,
								edited: true,
								source: ( request.data as { source: string } )
									.source,
							}
						: shipped;
				case '/orillacart/v1/email-templates/order-shipped/preview':
					return {
						to: 'ada@example.com',
						subject: 'On its way',
						html: '<p>On its way</p>',
						text: 'On its way',
					};
				default:
					return settings;
			}
		} );
		const user = userEvent.setup();
		render( <EmailsScreen /> );

		await user.click(
			await screen.findByRole( 'button', { name: 'Edit' } )
		);
		const source = await screen.findByLabelText( 'Template (Twig)' );
		expect( screen.getByRole( 'button', { name: 'Save' } ) ).toBeDisabled();
		await user.clear( source );
		await user.type( source, 'On its way' );
		await user.click( screen.getByRole( 'button', { name: 'Preview' } ) );

		expect( await screen.findByTitle( 'Preview' ) ).toHaveAttribute(
			'srcdoc',
			'<p>On its way</p>'
		);
		expect( lastWrite( mockedFetch ) ).toEqual( {
			path: '/orillacart/v1/email-templates/order-shipped/preview',
			method: 'POST',
			data: { source: 'On its way', kind: null, order: null },
		} );
		await user.click( screen.getByRole( 'button', { name: 'Save' } ) );
		expect(
			await screen.findByText( 'Template saved.', {
				selector: '.components-notice__content',
			} )
		).toBeVisible();
		expect(
			screen.getByRole( 'button', { name: 'Reset to the file' } )
		).toBeVisible();
	} );
} );
