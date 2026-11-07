import type apiFetch from '@wordpress/api-fetch';

type Mocked = jest.MockedFunction< typeof apiFetch >;
type Request = { path: string; method?: string; data?: unknown };

/** The general settings as GET /settings returns them. */
export const stored = {
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
	history_days: 365,
	weight_units: [ 'kg', 'g', 'lb', 'oz' ],
	dimension_units: [ 'cm', 'm', 'mm', 'in' ],
	currency_positions: [ 'before', 'before_space', 'after', 'after_space' ],
};

export const pages = { shop: 9, cart: 10, checkout: null, account: null };

export const countries = [
	{ code: 'BG', name: 'Bulgaria', in_eu: true, has_states: false },
	{ code: 'US', name: 'United States', in_eu: false, has_states: true },
];

export const legal = {
	terms: null,
	withdrawal: null,
	delivery: null,
	legal_notice: null,
	withdrawal_form: null,
	accept_terms: false,
	order_button: 'place_order',
	order_buttons: [ 'place_order', 'buy_now', 'order_and_pay' ],
};

export const invoices = {
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
	phone: '',
	email: '',
	peppol_id: '',
};

export const storefront = {
	structured_data: true,
	sharing_tags: 'auto',
	wishlist: 'guests',
	recently_viewed: true,
	data_layer: false,
	hide_single_value_filters: true,
	list_untranslated: true,
	notice: false,
	notice_text: '',
	notice_link: '',
	notice_link_label: '',
	notice_dismissible: true,
	// PHP's empty array.
	notice_translations: [],
};

export const TWO_LANGUAGES = [
	{ code: 'en', locale: 'en_US', name: 'English', default: true },
	{ code: 'de', locale: 'de_DE', name: 'Deutsch', default: false },
];

const data = ( request: Request ) => request.data as object;

/**
 * Answers every request the settings screens make, like the shop's REST API: GET requests by path, and a PUT
 * with what it was sent, over what was stored. A test overrides what it is about.
 *
 * @param mocked    The mocked apiFetch.
 * @param languages The site's content languages; one, by default.
 * @param override  Answers a request before the defaults do; undefined to leave it to them.
 */
export function serve(
	mocked: Mocked,
	languages: unknown[] = [],
	override?: ( request: Request ) => Promise< unknown > | undefined
) {
	mocked.mockImplementation( ( options ) => {
		const request = options as Request;
		const { path, method } = request;
		const overridden = override?.( request );
		if ( overridden !== undefined ) {
			return overridden;
		}
		if ( path.endsWith( '/content-languages' ) ) {
			return Promise.resolve( languages );
		}
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
					? { id: 2, ...data( request ) }
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
		if ( path === '/orillacart/v1/countries' ) {
			return Promise.resolve( countries );
		}
		if ( path === '/orillacart/v1/countries/US/states' ) {
			return Promise.resolve( [
				{ code: 'CA', name: 'California' },
				{ code: 'NY', name: 'New York' },
			] );
		}
		if ( path.startsWith( '/wp/v2/pages' ) ) {
			return Promise.resolve( [
				{ id: 9, title: { rendered: 'Shop' } },
				{ id: 10, title: { rendered: 'Cart &amp; more' } },
			] );
		}
		const saved = method === 'PUT' ? data( request ) : {};
		switch ( path ) {
			case '/orillacart/v1/shop-pages':
				return Promise.resolve( { ...pages, ...saved } );
			case '/orillacart/v1/legal-settings':
				return Promise.resolve( { ...legal, ...saved } );
			case '/orillacart/v1/invoice-settings':
				return Promise.resolve( { ...invoices, ...saved } );
			case '/orillacart/v1/storefront-settings':
				return Promise.resolve( { ...storefront, ...saved } );
			case '/orillacart/v1/review-settings':
				return Promise.resolve( { policy: 'anyone', ...saved } );
			case '/orillacart/v1/currencies':
				return Promise.resolve( {
					offered: [
						{ code: 'USD', rate: '1.08', rounding: 'decimals' },
					],
					...saved,
				} );
			default:
				return Promise.resolve( { ...stored, ...saved } );
		}
	} );
}
