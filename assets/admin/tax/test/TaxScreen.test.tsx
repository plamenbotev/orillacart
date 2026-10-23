import '@testing-library/jest-dom';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { TaxScreen } from '../TaxScreen';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const NOTICE = { selector: '.components-notice__content' };

const standard = {
	id: 1,
	name: 'Standard',
	rates: [
		{
			id: 10,
			country: 'US',
			state: 'CA',
			percent: '7.25',
			name: 'California',
			priority: 1,
			postcodes: [],
			compound: false,
			code: null,
		},
		{
			id: 11,
			country: 'US',
			state: null,
			percent: '2',
			name: 'Federal',
			priority: 2,
			postcodes: [],
			compound: false,
			code: null,
		},
	],
};

type Request = { path: string; method?: string; data?: unknown };

/**
 * Answers the screen's requests; writes go to the given handler.
 *
 * @param write Answers POST, PUT and DELETE requests.
 */
function serve( write: ( request: Request ) => Promise< unknown > ) {
	mockedFetch.mockImplementation( ( options ) => {
		const request = options as Request;
		if ( request.method !== undefined ) {
			return write( request );
		}
		switch ( request.path ) {
			case '/orillacart/v1/tax/groups':
				return Promise.resolve( [ standard ] );
			case '/orillacart/v1/tax/settings':
				return Promise.resolve( {
					basis: 'shipping_address',
					default_group_id: null,
					prices_include_tax: false,
					reverse_charge: false,
					rounds_each_line: false,
					origin_regions: [],
					provider: null,
					when_provider_fails: 'shop_rates',
					providers: [],
					accepts_certificates: false,
					shown_in_shop: 'as_entered',
					shown_in_cart: 'as_entered',
					price_suffix: '',
					same_price_everywhere: true,
					shipping_taxed_as_items: false,
					bases: [
						'shipping_address',
						'billing_address',
						'shop_address',
						'eu_origin',
						'eu_destination',
					],
				} );
			case '/orillacart/v1/countries':
				return Promise.resolve( [
					{
						code: 'DE',
						name: 'Germany',
						in_eu: true,
						has_states: false,
					},
					{
						code: 'US',
						name: 'United States',
						in_eu: false,
						has_states: true,
					},
				] );
			case '/orillacart/v1/tax/nexus-thresholds':
				return Promise.resolve( {
					everywhere: { sales: '100000', orders: null },
					states: [],
				} );
			case '/orillacart/v1/reports/nexus':
				return Promise.resolve( {
					currency: 'USD',
					states: [],
					tax_service: false,
					pending: 0,
				} );
			case '/orillacart/v1/countries/US/states':
				return Promise.resolve( [
					{ code: 'CA', name: 'California' },
				] );
		}
		return Promise.reject( new Error( `Unexpected ${ request.path }` ) );
	} );
}

describe( 'TaxScreen', () => {
	afterEach( () => mockedFetch.mockReset() );

	it( 'lists the rates of the first group', async () => {
		serve( () => Promise.resolve( {} ) );

		render( <TaxScreen /> );

		const table = await screen.findByRole( 'table' );
		const rows = within( table ).getAllByRole( 'row' );
		expect( rows[ 1 ] ).toHaveTextContent(
			'United StatesCAAll postcodes7.25 %California1'
		);
		expect( rows[ 2 ] ).toHaveTextContent(
			'United StatesAll statesAll postcodes2 %Federal2'
		);
	} );

	it( 'saves the basis and the default group', async () => {
		const write = jest.fn( ( request: Request ) =>
			Promise.resolve( {
				...( request.data as object ),
				bases: [],
				providers: [],
			} )
		);
		serve( write );
		const user = userEvent.setup();
		render( <TaxScreen /> );
		const untaxed =
			'There is no default tax group: products and shipping rates without their own group are sold without tax.';
		expect( await screen.findByText( untaxed, NOTICE ) ).toBeVisible();

		await user.selectOptions(
			await screen.findByLabelText( 'Tax is based on' ),
			'eu_origin'
		);
		await user.selectOptions(
			screen.getByLabelText( 'Default tax group' ),
			'1'
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Save tax settings' } )
		);

		expect(
			await screen.findByText( 'Tax settings saved.', NOTICE )
		).toBeVisible();
		expect( write ).toHaveBeenCalledWith( {
			path: '/orillacart/v1/tax/settings',
			method: 'PUT',
			data: {
				basis: 'eu_origin',
				default_group_id: 1,
				prices_include_tax: false,
				reverse_charge: false,
				rounds_each_line: false,
				origin_regions: [],
				provider: null,
				when_provider_fails: 'shop_rates',
				accepts_certificates: false,
				shown_in_shop: 'as_entered',
				shown_in_cart: 'as_entered',
				price_suffix: '',
				same_price_everywhere: true,
				shipping_taxed_as_items: false,
			},
		} );
		expect( screen.queryByText( untaxed, NOTICE ) ).toBeNull();
	} );

	it( 'adds a rate and shows what the server refused', async () => {
		const write = jest.fn().mockRejectedValueOnce( {
			code: 'orillacart_invalid_input',
			message: 'Some fields are not valid.',
			data: {
				status: 422,
				violations: [
					{ field: 'percent', message: 'Use a number such as 7.25.' },
				],
			},
		} );
		serve( write );
		const user = userEvent.setup();
		render( <TaxScreen /> );

		await user.click(
			await screen.findByRole( 'button', { name: 'Add rate' } )
		);
		const dialog = screen.getByRole( 'dialog' );
		await user.selectOptions(
			within( dialog ).getByLabelText( 'Country' ),
			'DE'
		);
		await user.type(
			within( dialog ).getByLabelText( 'Rate (%)' ),
			'19,5'
		);
		await user.click(
			within( dialog ).getByRole( 'button', { name: 'Save rate' } )
		);

		expect(
			await within( dialog ).findByText( 'Use a number such as 7.25.' )
		).toBeVisible();
		expect( write ).toHaveBeenCalledWith( {
			path: '/orillacart/v1/tax/groups/1/rates',
			method: 'POST',
			data: {
				country: 'DE',
				state: null,
				percent: '19,5',
				name: '',
				priority: 0,
				postcodes: [],
				compound: false,
				code: null,
			},
		} );
	} );

	it( 'asks before deleting a group', async () => {
		const write = jest.fn( () => Promise.resolve( null ) );
		serve( write );
		const user = userEvent.setup();
		render( <TaxScreen /> );

		await user.click(
			await screen.findByRole( 'button', { name: 'Delete group' } )
		);
		expect( write ).not.toHaveBeenCalled();
		await user.click(
			screen.getByRole( 'button', {
				name: 'Delete the group and its rates',
			} )
		);

		expect( write ).toHaveBeenCalledWith( {
			path: '/orillacart/v1/tax/groups/1',
			method: 'DELETE',
		} );
	} );
} );
