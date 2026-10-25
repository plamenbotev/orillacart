import '@testing-library/jest-dom';
import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { SetupScreen } from '../SetupScreen';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const NOTICE = { selector: '.components-notice__content' };

const germany = {
	country: 'DE',
	in_eu: true,
	currency: 'EUR',
	weight_unit: 'kg',
	dimension_unit: 'cm',
	tax_system: 'vat',
	tax_name: 'MwSt.',
	standard: '19',
	reduced: [ '7' ],
	super_reduced: null,
	regional: [],
	prices_include_tax: true,
	small_business_note: 'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.',
	checked_on: '2026-10-25',
	withdrawal_days: 14,
	legal_notice: true,
	legal_notice_title: 'Impressum',
	consumer_checkout: true,
	e_invoices: null,
	currencies: [ 'EUR', 'USD' ],
	iban: true,
};

const business = {
	legal_name: 'Acme GmbH',
	street: 'Hauptstraße 1',
	city: 'München',
	postcode: '80331',
	country: 'DE',
	state: null,
	email: 'shop@acme.test',
	phone: '',
	vat_number: '',
	company_number: '',
	currency: 'EUR',
};

type Answers = Record< string, Record< string, unknown > | null >;

/**
 * A server for the setup: the answers kept as they are given, the plan, the readiness report.
 *
 * @param answers   The answers kept already.
 * @param readiness What the readiness check finds.
 */
function serve(
	answers: Answers,
	readiness = { ready: true, stops: [], advice: [] } as object
) {
	mockedFetch.mockImplementation( ( options ) => {
		const { path, method, data } = options as {
			path: string;
			method?: string;
			data?: Record< string, unknown >;
		};
		const step = /^\/orillacart\/v1\/setup\/answers\/(\w+)$/.exec( path );
		if ( step && method === 'PUT' ) {
			answers[ step[ 1 ] as string ] = data ?? {};
			return Promise.resolve( { ...answers } );
		}
		switch ( path ) {
			case '/orillacart/v1/setup/answers':
				return Promise.resolve( { ...answers } );
			case '/orillacart/v1/setup/countries/DE':
				return Promise.resolve( germany );
			case '/orillacart/v1/countries':
				return Promise.resolve( [
					{
						code: 'DE',
						name: 'Germany',
						in_eu: true,
						has_states: false,
					},
				] );
			case '/orillacart/v1/setup/plan':
				return Promise.resolve( {
					fingerprint: 'f'.repeat( 64 ),
					changes: [
						{
							key: 'tax.group.standard',
							step: 'tax',
							summary:
								'A tax group "Standard rate" at 19% (MwSt.).',
							details: [],
							existing:
								'The shop has a group "Standard rate" with 1 rates; replacing it gives it these instead.',
							warning: null,
							replace: false,
						},
						{
							key: 'emails.sender',
							step: 'emails',
							summary:
								'Emails come from "Acme" <shop@acme.test>.',
							details: [],
							existing: null,
							warning:
								'Mail from shop@acme.test may land in spam.',
						},
					],
				} );
			case '/orillacart/v1/setup/apply':
				return Promise.resolve( {
					applied: [ 'tax.group.standard', 'emails.sender' ],
					kept: [],
					refused: null,
				} );
			case '/orillacart/v1/setup/readiness':
				return Promise.resolve( readiness );
			case '/orillacart/v1/opening':
				return Promise.resolve( { opens_soon: method !== 'PUT' } );
			case '/orillacart/v1/setup':
				return Promise.resolve( { finished: method === 'PUT' } );
			default:
				return Promise.resolve(
					path.startsWith( '/orillacart/v1/states' ) ? [] : {}
				);
		}
	} );
}

describe( 'SetupScreen', () => {
	beforeAll( () => {
		// jsdom draws no page to scroll.
		window.scrollTo = jest.fn();
	} );
	afterEach( () => mockedFetch.mockReset() );

	it( 'asks about the business, keeps the answers and goes on', async () => {
		const answers: Answers = { business: null, selling: null };
		serve( answers );
		const user = userEvent.setup();
		render( <SetupScreen /> );

		expect(
			await screen.findByRole( 'heading', { name: 'Your business' } )
		).toBeVisible();
		await user.type(
			screen.getByLabelText( 'Legal name of the business' ),
			'Acme GmbH'
		);
		await user.type(
			screen.getByLabelText( 'Street and number' ),
			'Hauptstraße 1'
		);
		await user.type( screen.getByLabelText( 'City' ), 'München' );
		await user.selectOptions(
			await screen.findByLabelText( 'Country the shop sells from' ),
			'DE'
		);
		expect( await screen.findByLabelText( 'Currency' ) ).toHaveValue(
			'EUR'
		);
		await user.type(
			screen.getByLabelText( 'Email for customers' ),
			'shop@acme.test'
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Save and go on' } )
		);

		expect(
			await screen.findByRole( 'heading', { name: 'Customers' } )
		).toBeVisible();
		expect( answers.business ).toMatchObject( {
			legal_name: 'Acme GmbH',
			country: 'DE',
			currency: 'EUR',
			state: null,
		} );
	} );

	it( 'takes the setup up again at the first step not answered, and asks about tax as the country taxes', async () => {
		serve( {
			business,
			selling: { customers: 'both', regions: 'eu' },
			tax: null,
		} );
		const user = userEvent.setup();
		render( <SetupScreen /> );

		expect(
			await screen.findByRole( 'heading', { name: 'Tax' } )
		).toBeVisible();
		expect(
			await screen.findByText(
				'MwSt. here: 19% standard, 7% reduced; as in force on 2026-10-25.'
			)
		).toBeVisible();
		await user.click(
			screen.getByRole( 'checkbox', {
				name: 'The business is registered for MwSt.',
			} )
		);
		expect(
			screen.getByLabelText( 'What invoices say instead' )
		).toHaveValue( 'Gemäß § 19 UStG wird keine Umsatzsteuer berechnet.' );
		expect(
			screen.queryByRole( 'checkbox', {
				name: /One-Stop Shop/,
			} )
		).not.toBeInTheDocument();
	} );

	it( 'shows the plan before anything is saved, and applies it replacing only what was chosen', async () => {
		const answers: Answers = {
			business,
			selling: {},
			tax: {},
			shipping: {},
			payments: {},
			legal: {},
			emails: {},
		};
		serve( answers );
		const user = userEvent.setup();
		render( <SetupScreen /> );

		expect(
			await screen.findByText(
				'A tax group "Standard rate" at 19% (MwSt.).'
			)
		).toBeVisible();
		expect(
			screen.getByText(
				'Mail from shop@acme.test may land in spam.',
				NOTICE
			)
		).toBeVisible();
		expect( answers.opening ).toEqual( { opens_soon: true } );
		await user.click(
			screen.getByRole( 'checkbox', { name: /Replace it/ } )
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Apply the setup' } )
		);

		expect(
			await screen.findByRole( 'heading', { name: 'Open' } )
		).toBeVisible();
		expect( mockedFetch ).toHaveBeenCalledWith( {
			path: '/orillacart/v1/setup/apply',
			method: 'POST',
			data: {
				fingerprint: 'f'.repeat( 64 ),
				replace: [ 'tax.group.standard' ],
			},
		} );
	} );

	it( 'opens the shop only once nothing stops it from selling', async () => {
		serve(
			{
				business,
				selling: {},
				tax: {},
				shipping: {},
				payments: {},
				legal: {},
				emails: {},
			},
			{
				ready: false,
				stops: [
					{
						key: 'legal.terms',
						stops: true,
						message: 'The terms of sale are not published.',
						fix: 'settings',
					},
				],
				advice: [],
			}
		);
		const user = userEvent.setup();
		render( <SetupScreen /> );

		await user.click(
			await screen.findByRole( 'button', { name: 'Open' } )
		);

		const stops = await screen.findByRole( 'heading', {
			name: 'Before the shop can sell',
		} );
		expect( stops ).toBeVisible();
		const list = stops.nextElementSibling as HTMLElement;
		expect(
			within( list ).getByText( 'The terms of sale are not published.' )
		).toBeVisible();
		expect(
			within( list ).getByRole( 'link', { name: 'Put it right' } )
		).toHaveAttribute( 'href', 'admin.php?page=orillacart-settings' );
		expect(
			screen.getByRole( 'button', { name: 'Open the shop' } )
		).toBeDisabled();
	} );
} );
