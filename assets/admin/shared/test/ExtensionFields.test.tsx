import '@testing-library/jest-dom';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { ExtensionFieldsSection } from '../ExtensionFields';
import { forgetToasts } from '../ui';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

type Request = { path: string; method?: string; data?: unknown };

const SECTIONS = {
	sections: [
		{
			extension: 'lead_times',
			name: 'Lead times',
			fields: [
				{
					name: 'lead_time',
					kind: 'number',
					label: 'Lead time',
					help: 'Days to make it',
					required: false,
					default: null,
					of: [],
					readonly: false,
					filter: false,
					column: true,
				},
				{
					name: 'material',
					kind: 'choice',
					label: 'Material',
					help: null,
					required: false,
					default: null,
					of: [ 'wool', 'cotton' ],
					readonly: false,
					filter: true,
					column: true,
				},
				{
					name: 'rush',
					kind: 'boolean',
					label: 'Rush orders',
					help: null,
					required: false,
					default: false,
					of: [],
					readonly: false,
					filter: false,
					column: true,
				},
				{
					name: 'tags',
					kind: 'list',
					label: 'Tags',
					help: null,
					required: false,
					default: null,
					of: [],
					readonly: false,
					filter: false,
					column: false,
				},
				{
					name: 'score',
					kind: 'number',
					label: 'Score',
					help: null,
					required: false,
					default: null,
					of: [],
					readonly: true,
					filter: false,
					column: true,
				},
			],
		},
	],
};

describe( 'ExtensionFieldsSection', () => {
	const requests: Request[] = [];

	beforeEach( () => {
		requests.length = 0;
		forgetToasts();
		mockedFetch.mockImplementation( ( options ) => {
			const request = options as Request;
			requests.push( request );
			if ( request.path === '/orillacart/v1/record-fields/products' ) {
				return Promise.resolve( SECTIONS );
			}
			if ( request.method === 'PUT' ) {
				const data = request.data as {
					fields: Record< string, unknown >;
				};
				return Promise.resolve( {
					fields: { ...data.fields, score: 7 },
				} );
			}
			return Promise.resolve( { sections: [] } );
		} );
	} );

	it( 'builds one block per extension from the declaration and shows the values', async () => {
		render(
			<ExtensionFieldsSection
				kind="products"
				id={ 12 }
				values={ {
					lead_times: {
						lead_time: 3,
						material: 'wool',
						rush: true,
						tags: [ 'a', 'b' ],
						score: 5,
					},
				} }
				onError={ () => {} }
			/>
		);

		expect( await screen.findByText( 'Lead times' ) ).toBeInTheDocument();
		expect( screen.getByLabelText( 'Lead time' ) ).toHaveValue( 3 );
		expect( screen.getByText( 'Days to make it' ) ).toBeInTheDocument();
		expect( screen.getByLabelText( 'Material' ) ).toHaveValue( 'wool' );
		expect( screen.getByLabelText( 'Rush orders' ) ).toBeChecked();
		expect( screen.getByLabelText( 'Tags' ) ).toHaveValue( 'a\nb' );
		expect( screen.getByLabelText( 'Score' ) ).toBeDisabled();
	} );

	it( 'saves the editable fields as the declaration takes them', async () => {
		const user = userEvent.setup();
		render(
			<ExtensionFieldsSection
				kind="products"
				id={ 12 }
				values={ {
					lead_times: {
						lead_time: null,
						material: null,
						rush: false,
						tags: null,
						score: 5,
					},
				} }
				onError={ () => {} }
			/>
		);
		await screen.findByText( 'Lead times' );

		await user.type( screen.getByLabelText( 'Lead time' ), '4' );
		await user.selectOptions(
			screen.getByLabelText( 'Material' ),
			'cotton'
		);
		await user.click( screen.getByLabelText( 'Rush orders' ) );
		await user.type( screen.getByLabelText( 'Tags' ), 'x\ny' );
		await user.click(
			screen.getByRole( 'button', { name: 'Save Lead times' } )
		);

		await waitFor( () =>
			expect( requests.some( ( r ) => r.method === 'PUT' ) ).toBe( true )
		);
		const put = requests.find( ( r ) => r.method === 'PUT' );
		expect( put?.path ).toBe(
			'/orillacart/v1/products/12/extensions/lead_times'
		);
		expect( put?.data ).toEqual( {
			fields: {
				lead_time: 4,
				material: 'cotton',
				rush: true,
				tags: [ 'x', 'y' ],
			},
		} );
		await waitFor( () =>
			expect( screen.getByLabelText( 'Score' ) ).toHaveValue( 7 )
		);
	} );

	it( 'is nothing when no extension declares fields on the kind', async () => {
		mockedFetch.mockImplementation( () =>
			Promise.resolve( { sections: [] } )
		);
		const { container } = render(
			<ExtensionFieldsSection
				kind="orders"
				id={ 1 }
				values={ null }
				onError={ () => {} }
			/>
		);
		await waitFor( () => expect( mockedFetch ).toHaveBeenCalled() );
		expect( container ).toBeEmptyDOMElement();
	} );
} );
