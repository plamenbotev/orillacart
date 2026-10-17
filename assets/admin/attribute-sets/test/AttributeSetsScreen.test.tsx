import '@testing-library/jest-dom';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { AttributeSetsScreen } from '../AttributeSetsScreen';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const NOTICE = { selector: '.components-notice__content' };

const clothing = {
	id: 3,
	name: 'Clothing',
	active: true,
	attributes: [
		{
			id: 7,
			name: 'Size',
			required: true,
			price_hidden: false,
			set_id: 3,
			options: [
				{ id: 20, name: 'S', adjustment: '0' },
				{ id: 21, name: 'M', adjustment: '2' },
			],
		},
	],
};

/**
 * @param elements Elements in document order.
 * @return The last one; the test fails when there is none.
 */
function last( elements: HTMLElement[] ): HTMLElement {
	const element = elements[ elements.length - 1 ];
	if ( element === undefined ) {
		throw new Error( 'No such element.' );
	}

	return element;
}

type Request = { path: string; method?: string; data?: unknown };

/**
 * @param sets  What GET /attribute-sets answers.
 * @param write Answers POST, PUT and DELETE requests.
 */
function serve(
	sets: unknown[],
	write: ( request: Request ) => Promise< unknown > = () =>
		Promise.resolve( {} )
) {
	mockedFetch.mockImplementation( ( options ) => {
		const request = options as Request;
		return request.method === undefined
			? Promise.resolve( sets )
			: write( request );
	} );
}

describe( 'AttributeSetsScreen', () => {
	afterEach( () => mockedFetch.mockReset() );

	it( 'shows the first set with its attributes and options', async () => {
		serve( [ clothing ] );

		render( <AttributeSetsScreen /> );

		expect( await screen.findByLabelText( 'Set name' ) ).toHaveValue(
			'Clothing'
		);
		expect( screen.getByLabelText( 'Attribute' ) ).toHaveValue( 'Size' );
		expect(
			screen
				.getAllByLabelText( 'Option' )
				.map( ( input ) => ( input as HTMLInputElement ).value )
		).toEqual( [ 'S', 'M' ] );
		expect(
			screen.getByLabelText( 'Customers must choose one' )
		).toBeChecked();
	} );

	it( 'sends the whole set, keeping the ids of what exists', async () => {
		serve( [ clothing ], ( request ) =>
			Promise.resolve( { ...clothing, ...( request.data as object ) } )
		);
		const user = userEvent.setup();
		render( <AttributeSetsScreen /> );

		await screen.findByLabelText( 'Set name' );
		await user.click(
			screen.getByRole( 'button', { name: 'Add option' } )
		);
		await user.type( last( screen.getAllByLabelText( 'Option' ) ), 'L' );
		await user.click(
			screen.getByRole( 'button', { name: 'Save attribute set' } )
		);

		expect(
			await screen.findByText( 'Attribute set saved.', NOTICE )
		).toBeVisible();
		expect( mockedFetch ).toHaveBeenLastCalledWith( {
			path: '/orillacart/v1/attribute-sets/3',
			method: 'PUT',
			data: {
				name: 'Clothing',
				active: true,
				attributes: [
					{
						id: 7,
						name: 'Size',
						required: true,
						price_hidden: false,
						options: [
							{ id: 20, name: 'S', adjustment: '0' },
							{ id: 21, name: 'M', adjustment: '2' },
							{ name: 'L', adjustment: '0' },
						],
					},
				],
			},
		} );
	} );

	it( 'creates a set when there is none', async () => {
		serve( [], () =>
			Promise.resolve( { ...clothing, id: 4, name: 'Mugs' } )
		);
		const user = userEvent.setup();
		render( <AttributeSetsScreen /> );

		await user.type( await screen.findByLabelText( 'Set name' ), 'Mugs' );
		await user.click(
			screen.getByRole( 'button', { name: 'Save attribute set' } )
		);

		expect( mockedFetch ).toHaveBeenLastCalledWith(
			expect.objectContaining( {
				path: '/orillacart/v1/attribute-sets',
				method: 'POST',
				data: { name: 'Mugs', active: true, attributes: [] },
			} )
		);
		expect(
			await screen.findByRole( 'button', { name: 'Mugs' } )
		).toBeVisible();
	} );

	it( 'says why a set cannot be deleted', async () => {
		serve( [ clothing ], () =>
			Promise.reject( {
				code: 'orillacart_attribute_set_in_use',
				message:
					'Product variations use options of "Clothing"; change or remove them first.',
				data: { status: 422 },
			} )
		);
		const user = userEvent.setup();
		render( <AttributeSetsScreen /> );

		await user.click(
			await screen.findByRole( 'button', { name: 'Delete set' } )
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Delete the set' } )
		);

		expect(
			await screen.findByText(
				'Product variations use options of "Clothing"; change or remove them first.',
				NOTICE
			)
		).toBeVisible();
	} );
} );
