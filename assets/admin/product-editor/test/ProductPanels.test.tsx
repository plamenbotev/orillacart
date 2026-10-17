import '@testing-library/jest-dom';
import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';
import { dispatch, select } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { store as noticesStore } from '@wordpress/notices';
import type { ReactNode } from 'react';

import { ProductPanels } from '../ProductPanels';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

type MockState = { saving: boolean; autosaving: boolean };

// A stand-in for the block editor's store: the post id and whether a save is running.
jest.mock( '@wordpress/editor', () => {
	const { createReduxStore, register } =
		jest.requireActual( '@wordpress/data' );
	const store = createReduxStore( 'test/editor', {
		reducer: (
			state: MockState = { saving: false, autosaving: false },
			action: { type: string; state?: Partial< MockState > }
		) => ( action.type === 'SET' ? { ...state, ...action.state } : state ),
		actions: {
			set: ( state: Partial< MockState > ) => ( { type: 'SET', state } ),
		},
		selectors: {
			getCurrentPostId: () => 10,
			isSavingPost: ( state: MockState ) => state.saving,
			isAutosavingPost: ( state: MockState ) => state.autosaving,
			didPostSaveRequestFail: () => false,
		},
	} );
	register( store );

	return {
		store,
		PluginDocumentSettingPanel: ( {
			title,
			children,
		}: {
			title: string;
			children: ReactNode;
		} ) => <section aria-label={ title }>{ children }</section>,
	};
} );

/**
 * @param state        Whether the post is being saved.
 * @param state.saving
 */
function editorState( state: { saving: boolean } ) {
	(
		dispatch( editorStore ) as unknown as { set: ( s: object ) => void }
	 ).set( state );
}

/**
 * @return The messages of the editor's notices.
 */
function notices(): string[] {
	return select( noticesStore )
		.getNotices()
		.map( ( notice ) => notice.content as string );
}

const product = {
	id: 10,
	name: 'Mug',
	saved: true,
	type: 'physical',
	price: '10',
	sale: null,
	sku: 'MUG',
	taxable: true,
	tax_group_id: null,
	stock_mode: 'tracked',
	purchasable: true,
	featured: false,
	weight: null,
	length: null,
	width: null,
	height: null,
	set_ids: [],
	attributes: [],
	offered: [],
	variations: [],
	download_limit: null,
	download_expiry_days: null,
	downloads: [],
};

type Request = { path: string; method?: string; data?: unknown };

/**
 * @param write Answers PUT and POST requests.
 */
function serve( write: ( request: Request ) => Promise< unknown > ) {
	mockedFetch.mockImplementation( ( options ) => {
		const request = options as Request;
		if ( request.method !== undefined ) {
			return write( request );
		}
		switch ( request.path ) {
			case '/orillacart/v1/products/10':
				return Promise.resolve( product );
			case '/orillacart/v1/stock/product/10':
				return Promise.resolve( {
					item: 'product:10',
					levels: [ { stockroom_id: 1, quantity: 4 } ],
					available: 4,
				} );
			case '/orillacart/v1/stockrooms':
				return Promise.resolve( [
					{
						id: 1,
						name: 'Sofia',
						description: '',
						min_delivery_days: 1,
						max_delivery_days: 2,
						active: true,
					},
				] );
			default:
				return Promise.resolve( [] );
		}
	} );
}

describe( 'ProductPanels', () => {
	afterEach( () => {
		mockedFetch.mockReset();
		for ( const notice of select( noticesStore ).getNotices() ) {
			void dispatch( noticesStore ).removeNotice( notice.id );
		}
	} );

	it( 'shows the price, the sale and the stock', async () => {
		serve( () => Promise.resolve( product ) );

		render( <ProductPanels /> );

		expect( await screen.findByLabelText( 'Price' ) ).toHaveValue( '10' );
		expect( screen.getByLabelText( 'SKU' ) ).toHaveValue( 'MUG' );
		expect( await screen.findByLabelText( 'Sofia' ) ).toHaveValue( 4 );
		expect( screen.getByText( '4 available' ) ).toBeVisible();
		expect(
			screen.queryByLabelText( 'Sale starts' )
		).not.toBeInTheDocument();
	} );

	it( 'saves the details when the post is saved', async () => {
		serve( ( request ) =>
			Promise.resolve( { ...product, ...( request.data as object ) } )
		);
		const user = userEvent.setup();
		render( <ProductPanels /> );

		const price = await screen.findByLabelText( 'Price' );
		await user.clear( price );
		await user.type( price, '12.5' );
		await user.type( screen.getByLabelText( 'Sale price' ), '9' );

		await act( async () => editorState( { saving: true } ) );
		await act( async () => editorState( { saving: false } ) );

		expect( mockedFetch ).toHaveBeenLastCalledWith(
			expect.objectContaining( {
				path: '/orillacart/v1/products/10',
				method: 'PUT',
				data: expect.objectContaining( {
					price: '12.5',
					sale: { price: '9', from: null, until: null },
				} ),
			} )
		);
		expect( notices() ).toEqual( [] );
	} );

	it( 'reports what the server refused', async () => {
		serve( () =>
			Promise.reject( {
				code: 'orillacart_invalid_input',
				message: 'Some fields are not valid.',
				data: {
					status: 422,
					violations: [
						{
							field: 'price',
							message: 'Use an amount of zero or more.',
						},
					],
				},
			} )
		);
		const user = userEvent.setup();
		render( <ProductPanels /> );

		await user.click(
			await screen.findByRole( 'button', {
				name: 'Save product details',
			} )
		);

		await screen.findByRole( 'button', { name: 'Save product details' } );
		expect( notices() ).toEqual( [
			'Some fields are not valid. price: Use an amount of zero or more.',
		] );
	} );
} );
