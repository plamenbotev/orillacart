import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { FormsScreen } from '../FormsScreen';
import { slugOf } from '../api';
import { forgetContentLanguages } from '../../shared/Translations';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

type Request = { path: string; method?: string; data?: unknown };

const engraving = {
	id: 4,
	name: 'Engraving',
	placement: 'product',
	active: true,
	position: 0,
	scope: 'all',
	category_ids: [],
	product_ids: [],
	fields: [
		{
			key: 'engrave',
			label: 'Engrave it',
			kind: 'checkbox',
			required: false,
			help: '',
			placeholder: '',
			longest: 200,
			choices: [],
			display: 'list',
			price: '5',
			min: null,
			max: null,
			whole: false,
			section: 'order',
			condition: null,
			types: [],
			largest: 10,
		},
	],
};

function serve(
	forms: unknown[],
	write: ( request: Request ) => Promise< unknown > = ( request ) =>
		Promise.resolve( { ...( request.data as object ), id: 9 } ),
	languages: unknown[] = []
) {
	forgetContentLanguages();
	mockedFetch.mockImplementation( ( options ) => {
		const request = options as Request;
		if ( request.path.endsWith( '/content-languages' ) ) {
			return Promise.resolve( languages );
		}
		if ( request.method !== undefined ) {
			return write( request );
		}
		return Promise.resolve(
			request.path.startsWith( '/wp/v2/' ) ? [] : forms
		);
	} );
}

describe( 'FormsScreen', () => {
	afterEach( () => mockedFetch.mockReset() );

	it( 'lists the forms with where they are asked', async () => {
		serve( [ engraving ] );
		render( <FormsScreen /> );

		expect( await screen.findByText( 'Engraving' ) ).toBeInTheDocument();
		expect( screen.getByText( 'Product page' ) ).toBeInTheDocument();
		expect( screen.getByText( 'All products' ) ).toBeInTheDocument();
	} );

	it( 'saves a new form whose condition names a question by the key its label made', async () => {
		const write = jest.fn( ( request: Request ) =>
			Promise.resolve( { ...( request.data as object ), id: 9 } )
		);
		serve( [], write );
		const user = userEvent.setup();
		render( <FormsScreen /> );

		await user.click(
			await screen.findByRole( 'button', { name: 'Add form' } )
		);
		await user.type( screen.getByLabelText( 'Name' ), 'Rings' );
		await user.click(
			screen.getByRole( 'button', { name: 'Add a question' } )
		);
		await user.type( screen.getByLabelText( 'Question' ), 'Engrave it?' );
		await user.selectOptions( screen.getByLabelText( 'Kind' ), 'checkbox' );
		await user.type(
			screen.getByLabelText( 'Price added to each item when answered' ),
			'5'
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Add a question' } )
		);
		await user.type( screen.getByLabelText( 'Question' ), 'Text' );
		await user.selectOptions(
			screen.getByLabelText( 'Ask it' ),
			'engrave_it'
		);
		await user.click( screen.getByRole( 'button', { name: 'Save form' } ) );

		expect( write ).toHaveBeenCalledWith(
			expect.objectContaining( {
				path: '/orillacart/v1/forms',
				method: 'POST',
				data: expect.objectContaining( {
					name: 'Rings',
					fields: [
						expect.objectContaining( {
							key: 'engrave_it',
							kind: 'checkbox',
							price: '5',
						} ),
						expect.objectContaining( {
							key: 'text',
							condition: { field: 'engrave_it', values: [] },
						} ),
					],
				} ),
			} )
		);
		expect(
			await screen.findByText( 'Form saved.', {
				selector: '.components-notice__content',
			} )
		).toBeInTheDocument();
	} );

	it( 'saves a question’s words in the site’s other languages', async () => {
		const write = jest.fn( ( request: Request ) =>
			Promise.resolve( { ...( request.data as object ), id: 4 } )
		);
		serve( [ engraving ], write, [
			{ code: 'en', locale: 'en_US', name: 'English', default: true },
			{ code: 'de', locale: 'de_DE', name: 'Deutsch', default: false },
		] );
		const user = userEvent.setup();
		render( <FormsScreen /> );

		await user.click(
			await screen.findByRole( 'button', { name: 'Edit' } )
		);
		// The question opens with its own Edit button.
		await user.click(
			await screen.findByRole( 'button', {
				name: 'Edit',
				expanded: false,
			} )
		);
		await user.type(
			await screen.findByLabelText( 'Question in Deutsch' ),
			'Gravieren?'
		);
		await user.type(
			screen.getByLabelText( 'Help shown under it in Deutsch' ),
			'Bis zu 20 Zeichen.'
		);
		await user.click( screen.getByRole( 'button', { name: 'Save form' } ) );

		expect( write ).toHaveBeenCalledWith(
			expect.objectContaining( {
				data: expect.objectContaining( {
					fields: [
						expect.objectContaining( {
							key: 'engrave',
							translations: {
								label: { de_DE: 'Gravieren?' },
								help: { de_DE: 'Bis zu 20 Zeichen.' },
							},
						} ),
					],
				} ),
			} )
		);
	} );

	it( 'makes keys the shop would make', () => {
		expect( slugOf( 'Гравиране', '_', 30, [] ) ).toBe( 'gravirane' );
		expect( slugOf( 'Café crème', '_', 30, [ 'cafe_creme' ] ) ).toBe(
			'cafe_creme_2'
		);
		expect( slugOf( '12 ct', '_', 30, [] ) ).toBe( 'field_12_ct' );
		expect( slugOf( '12 ct', '-', 40, [] ) ).toBe( '12-ct' );
		expect( slugOf( '!!!', '_', 30, [] ) ).toBe( 'field' );
	} );
} );
