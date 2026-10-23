import '@testing-library/jest-dom';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { SettingsScreen } from '../SettingsScreen';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

// Notices also announce their text in a screen reader live region; look in the notice itself.
const NOTICE = { selector: '.components-notice__content' };

const stored = {
	currency: 'EUR',
	price_decimals: 2,
	fewer_decimals: null,
	currency_decimals: 2,
	weight_unit: 'kg',
	dimension_unit: 'cm',
	shop_country: null,
	shop_state: null,
	shop_postcode: null,
	currency_sign: '€',
	currency_position: 'after_space',
	catalog_only: false,
	hide_prices: false,
	show_lowest_price: false,
	sell_to: [ 'BG' ],
	weight_units: [ 'kg', 'g', 'lb', 'oz' ],
	dimension_units: [ 'cm', 'm', 'mm', 'in' ],
	currency_positions: [ 'before', 'before_space', 'after', 'after_space' ],
};

const pages = { shop: 9, cart: 10, checkout: null, account: null };

const countries = [
	{ code: 'BG', name: 'Bulgaria', in_eu: true, has_states: false },
	{ code: 'US', name: 'United States', in_eu: false, has_states: true },
];

/**
 * Answers GET requests by path; PUT requests get the given answer.
 *
 * @param save What PUT /settings resolves or rejects with.
 */
function serve( save?: () => Promise< unknown > ) {
	mockedFetch.mockImplementation( ( options ) => {
		const { path, method } = options as { path: string; method?: string };
		if ( path === '/orillacart/v1/download-delivery' ) {
			return Promise.resolve(
				method === 'PUT'
					? {
							method: 'php',
							internal_prefix: '/orillacart-files/',
							works: false,
							message:
								'The web server did not send the test file.',
							server_rule: 'XSendFilePath /srv/downloads',
						}
					: {
							method: 'php',
							internal_prefix: '/orillacart-files/',
							works: null,
							message: '',
							server_rule: '',
						}
			);
		}
		if ( path.startsWith( '/orillacart/v1/search-engine' ) ) {
			return Promise.resolve( {
				engine: method === 'PUT' ? 'opensearch' : 'database',
				host: method === 'PUT' ? 'https://search.test:9200' : '',
				username: '',
				password_set: false,
				index: 'orillacart',
				ready: false,
				due: method === 'PUT' ? 12 : 0,
				later: 1,
				parked: {},
				next_at: null,
				works: method === 'PUT' ? true : null,
				message:
					method === 'PUT'
						? 'OpenSearch answered. 12 products are being indexed there.'
						: '',
				workers: 0,
			} );
		}
		if ( path.startsWith( '/orillacart/v1/search-filters' ) ) {
			if ( path.endsWith( '/attributes' ) ) {
				return Promise.resolve( [ 'Colour', 'Size' ] );
			}
			return Promise.resolve(
				method === 'POST'
					? { id: 2, ...( options as { data: object } ).data }
					: [
							{
								id: 1,
								label: 'Size',
								key: 'size',
								attributes: [ 'Size', 'Размер' ],
								position: 0,
							},
						]
			);
		}
		if ( method === 'PUT' && save ) {
			return save();
		}
		if ( path === '/orillacart/v1/countries' ) {
			return Promise.resolve( countries );
		}
		if ( path === '/orillacart/v1/shop-pages' ) {
			return Promise.resolve( pages );
		}
		if ( path === '/orillacart/v1/invoice-settings' ) {
			return Promise.resolve( {
				legal_name: '',
				address: '',
				vat_number: '',
				company_number: '',
				bank_details: '',
				footer: '',
				invoice_prefix: 'INV-',
				credit_note_prefix: 'CN-',
				yearly: true,
				attach_to_emails: true,
				e_invoices: 'none',
			} );
		}
		if ( path === '/orillacart/v1/storefront-settings' ) {
			return Promise.resolve( {
				structured_data: true,
				sharing_tags: 'auto',
				wishlist: 'guests',
				recently_viewed: true,
				data_layer: false,
				hide_single_value_filters: true,
			} );
		}
		if ( path === '/orillacart/v1/currencies' ) {
			return Promise.resolve( {
				offered: [
					{ code: 'USD', rate: '1.08', rounding: 'decimals' },
				],
			} );
		}
		if ( path === '/orillacart/v1/review-settings' ) {
			return Promise.resolve( { policy: 'anyone' } );
		}
		if ( path.startsWith( '/wp/v2/pages' ) ) {
			return Promise.resolve( [
				{ id: 9, title: { rendered: 'Shop' } },
				{ id: 10, title: { rendered: 'Cart &amp; more' } },
			] );
		}
		if ( path === '/orillacart/v1/countries/US/states' ) {
			return Promise.resolve( [
				{ code: 'CA', name: 'California' },
				{ code: 'NY', name: 'New York' },
			] );
		}
		return Promise.resolve( stored );
	} );
}

describe( 'SettingsScreen', () => {
	afterEach( () => mockedFetch.mockReset() );

	it( 'shows the stored settings', async () => {
		serve();

		render( <SettingsScreen /> );

		expect( await screen.findByLabelText( 'Currency' ) ).toHaveValue(
			'EUR'
		);
		expect( screen.getByLabelText( 'Weight unit' ) ).toHaveValue( 'kg' );
		expect(
			await screen.findByLabelText( 'Offered currency' )
		).toHaveValue( 'USD' );
		expect( mockedFetch ).toHaveBeenCalledWith( {
			path: '/orillacart/v1/settings',
		} );
	} );

	it( 'saves every field with PUT', async () => {
		serve( () => Promise.resolve( { ...stored, currency: 'USD' } ) );
		const user = userEvent.setup();
		render( <SettingsScreen /> );

		const currency = await screen.findByLabelText( 'Currency' );
		await user.selectOptions(
			screen.getByLabelText( 'Price decimals' ),
			'0'
		);
		await user.clear( currency );
		await user.type( currency, 'usd' );
		await user.click(
			screen.getByRole( 'button', { name: 'Save settings' } )
		);

		expect(
			await screen.findByText( 'Settings saved.', NOTICE )
		).toBeVisible();
		expect( mockedFetch ).toHaveBeenLastCalledWith( {
			path: '/orillacart/v1/settings',
			method: 'PUT',
			data: {
				currency: 'USD',
				fewer_decimals: 0,
				weight_unit: 'kg',
				dimension_unit: 'cm',
				shop_country: null,
				shop_state: null,
				shop_postcode: null,
				currency_sign: '€',
				currency_position: 'after_space',
				catalog_only: false,
				hide_prices: false,
				show_lowest_price: false,
				sell_to: [ 'BG' ],
			},
		} );
	} );

	it( 'offers the states of the shop country', async () => {
		serve( () => Promise.resolve( stored ) );
		const user = userEvent.setup();
		render( <SettingsScreen /> );

		await user.selectOptions(
			await screen.findByLabelText( 'Shop country' ),
			'US'
		);
		// The states load once the country is chosen.
		await screen.findByRole( 'option', { name: 'New York' } );
		await user.selectOptions( screen.getByLabelText( 'Shop state' ), 'NY' );
		await user.click(
			screen.getByRole( 'button', { name: 'Save settings' } )
		);

		expect( mockedFetch ).toHaveBeenLastCalledWith(
			expect.objectContaining( {
				method: 'PUT',
				data: expect.objectContaining( {
					shop_country: 'US',
					shop_state: 'NY',
				} ),
			} )
		);
	} );

	it( 'shows what the server refused next to the field', async () => {
		serve( () =>
			Promise.reject( {
				code: 'orillacart_invalid_input',
				message: 'Some fields are not valid.',
				data: {
					status: 422,
					violations: [
						{
							field: 'currency',
							message:
								'Use a three-letter currency code such as EUR.',
						},
					],
				},
			} )
		);
		const user = userEvent.setup();
		render( <SettingsScreen /> );

		await screen.findByLabelText( 'Currency' );
		await user.click(
			screen.getByRole( 'button', { name: 'Save settings' } )
		);

		expect(
			await screen.findByText( 'Some fields are not valid.', NOTICE )
		).toBeVisible();
		expect(
			screen.getByText( 'Use a three-letter currency code such as EUR.' )
		).toBeVisible();
	} );

	it( 'reports when the settings cannot be loaded', async () => {
		mockedFetch.mockRejectedValue( {
			code: 'orillacart_forbidden',
			message: 'You are not allowed to do this.',
		} );

		render( <SettingsScreen /> );

		// Every card says so: the settings, the shop pages, the invoices, reviews, sharing, the downloads, filters and search.
		expect(
			await screen.findAllByText(
				'You are not allowed to do this.',
				NOTICE
			)
		).toHaveLength( 8 );
	} );

	it( 'saves the page each part of the shop is on', async () => {
		const save = jest.fn( () =>
			Promise.resolve( { ...pages, checkout: 10, cart: null } )
		);
		serve( save );
		const user = userEvent.setup();
		render( <SettingsScreen /> );

		const cart = await screen.findByRole( 'combobox', { name: 'Cart' } );
		expect( cart ).toHaveDisplayValue( 'Cart & more' );
		await user.selectOptions( cart, '' );
		await user.selectOptions(
			screen.getByRole( 'combobox', { name: 'Checkout' } ),
			'10'
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Save shop pages' } )
		);

		expect(
			await screen.findByText( 'Shop pages saved.', NOTICE )
		).toBeVisible();
		expect( mockedFetch ).toHaveBeenCalledWith( {
			path: '/orillacart/v1/shop-pages',
			method: 'PUT',
			data: { shop: 9, cart: null, checkout: 10, account: null },
		} );
	} );

	it( 'keeps the way downloads are sent only when the test file went through', async () => {
		serve();
		const user = userEvent.setup();
		render( <SettingsScreen /> );

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
		expect( mockedFetch.mock.lastCall?.[ 0 ] ).toEqual( {
			path: '/orillacart/v1/download-delivery',
			method: 'PUT',
			data: {
				method: 'x_sendfile',
				internal_prefix: '/orillacart-files/',
			},
		} );
	} );

	it( 'adds a filter over attributes by name, its key made from its label', async () => {
		serve();
		const user = userEvent.setup();
		render( <SettingsScreen /> );

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
			await screen.findByText( 'Filter added.', {
				selector: '.components-notice__content',
			} )
		).toBeVisible();
		expect( mockedFetch.mock.lastCall?.[ 0 ] ).toEqual( {
			path: '/orillacart/v1/search-filters',
			method: 'POST',
			data: {
				label: 'Colour',
				key: 'colour',
				attributes: [ 'Colour' ],
				position: 1,
			},
		} );
	} );

	it( 'checks OpenSearch before switching to it', async () => {
		serve();
		const user = userEvent.setup();
		render( <SettingsScreen /> );

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
		expect( mockedFetch.mock.lastCall?.[ 0 ] ).toEqual( {
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
				'OpenSearch’s index is being built; the shop searches its database until it is.'
			)
		).toBeVisible();
	} );
} );
