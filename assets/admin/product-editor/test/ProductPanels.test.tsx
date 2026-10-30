import '@testing-library/jest-dom';
import { act, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';
import { dispatch, select } from '@wordpress/data';
import { store as editorStore } from '@wordpress/editor';
import { store as noticesStore } from '@wordpress/notices';
import type { ReactNode } from 'react';

import {
	classicEditorHost,
	EditorHostProvider,
	forgetClassicPost,
} from '../host';
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
	tax_code: null,
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
	updated_at: '2026-10-30T08:00:00+00:00',
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
		await userEvent
			.setup()
			.click( screen.getByRole( 'tab', { name: 'Stock' } ) );
		expect( screen.getByText( '4 available' ) ).toBeVisible();
		expect(
			screen.queryByLabelText( 'Sale starts' )
		).not.toBeInTheDocument();
	} );

	it( 'on a translation edits its original, and says so', async () => {
		// Polylang or WPML: post 10 translates product 7, whose details the shop answers with.
		const paths: string[] = [];
		mockedFetch.mockImplementation( ( options ) => {
			const request = options as Request;
			paths.push( request.path );
			switch ( request.path ) {
				case '/orillacart/v1/products/10':
					return Promise.resolve( {
						...product,
						id: 7,
						name: 'Becher',
					} );
				case '/orillacart/v1/stock/product/7':
					return Promise.resolve( {
						item: 'product:7',
						levels: [ { stockroom_id: 1, quantity: 2 } ],
						available: 2,
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

		render( <ProductPanels /> );

		expect( await screen.findByLabelText( 'Sofia' ) ).toHaveValue( 2 );
		// In the panel: the notice is also spoken, outside it.
		const panel = within(
			screen.getByRole( 'tabpanel', { name: 'Price and tax' } )
		);
		expect(
			panel.getByText( /This is a translation of Becher/ )
		).toBeVisible();
		expect(
			panel.getByRole( 'link', { name: 'Open the original' } )
		).toHaveAttribute( 'href', 'post.php?post=7&action=edit' );
		expect( paths ).not.toContain( '/orillacart/v1/stock/product/10' );
	} );

	it( 'says nothing of translations on the product itself', async () => {
		serve( () => Promise.resolve( product ) );

		render( <ProductPanels /> );

		expect( await screen.findByLabelText( 'Price' ) ).toHaveValue( '10' );
		expect(
			within(
				screen.getByRole( 'tabpanel', { name: 'Price and tax' } )
			).queryByText( /This is a translation/ )
		).not.toBeInTheDocument();
	} );

	it( 'lists its sections as tabs beside the one open, keeping what is typed in the others', async () => {
		serve( () => Promise.resolve( product ) );
		const user = userEvent.setup();
		render( <ProductPanels /> );

		const price = await screen.findByLabelText( 'Price' );
		expect(
			screen.getAllByRole( 'tab' ).map( ( tab ) => tab.textContent )
		).toEqual( [
			'Price and tax',
			'Weight and size',
			'Stock',
			'Attributes',
			'Downloads',
			'Bundle',
			'Variations',
		] );
		await user.clear( price );
		await user.type( price, '11' );
		await user.click( screen.getByRole( 'tab', { name: 'Stock' } ) );
		expect( price ).not.toBeVisible();
		expect( screen.getByRole( 'tab', { name: 'Stock' } ) ).toHaveAttribute(
			'aria-selected',
			'true'
		);
		await user.click(
			screen.getByRole( 'tab', { name: 'Price and tax' } )
		);
		expect( screen.getByLabelText( 'Price' ) ).toHaveValue( '11' );
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
					updated_at: '2026-10-30T08:00:00+00:00',
				} ),
			} )
		);
		expect( notices() ).toEqual( [] );
	} );

	it( 'sends nothing when the post is saved and the details did not change', async () => {
		serve( ( request ) =>
			Promise.resolve( { ...product, ...( request.data as object ) } )
		);
		const user = userEvent.setup();
		render( <ProductPanels /> );

		// Typed and put back: the same details.
		const price = await screen.findByLabelText( 'Price' );
		await user.clear( price );
		await user.type( price, '10.00' );

		await act( async () => editorState( { saving: true } ) );
		await act( async () => editorState( { saving: false } ) );

		expect( mockedFetch ).not.toHaveBeenCalledWith(
			expect.objectContaining( { method: 'PUT' } )
		);
	} );

	it( 'says the product changed elsewhere, and saves over it or loads it as asked', async () => {
		const now = {
			...product,
			price: '15',
			sku: 'MUG-2',
			updated_at: '2026-10-30T09:00:00+00:00',
		};
		const puts: Request[] = [];
		serve( ( request ) => {
			puts.push( request );
			return puts.length === 1
				? Promise.reject( {
						code: 'orillacart_product_changed',
						message: 'The product was changed since it was loaded.',
						data: { status: 409, product: now },
					} )
				: Promise.resolve( { ...now, ...( request.data as object ) } );
		} );
		const user = userEvent.setup();
		render( <ProductPanels /> );

		const price = await screen.findByLabelText( 'Price' );
		await user.clear( price );
		await user.type( price, '12' );
		await user.click(
			screen.getByRole( 'button', { name: 'Save product details' } )
		);

		expect(
			await screen.findByText( /changed elsewhere since you opened it/, {
				selector: 'p',
			} )
		).toBeVisible();
		expect( screen.getByLabelText( 'Price' ) ).toHaveValue( '12' );

		// Loading it asks first, as what was typed goes.
		await user.click(
			screen.getByRole( 'button', { name: 'Load it as it is now' } )
		);
		await user.click(
			await screen.findByRole( 'button', { name: 'Cancel' } )
		);
		expect( screen.getByLabelText( 'Price' ) ).toHaveValue( '12' );

		await user.click(
			screen.getByRole( 'button', { name: 'Save mine over it' } )
		);
		expect( puts[ 1 ]?.data ).toEqual(
			expect.objectContaining( {
				price: '12',
				updated_at: '2026-10-30T09:00:00+00:00',
			} )
		);
		await screen.findByRole( 'button', { name: 'Save product details' } );
		expect(
			screen.queryByText( /changed elsewhere since you opened it/, {
				selector: 'p',
			} )
		).not.toBeInTheDocument();
	} );

	it( 'loads the product as it is now once the owner agrees to lose their changes', async () => {
		const now = {
			...product,
			price: '15',
			updated_at: '2026-10-30T09:00:00+00:00',
		};
		serve( () =>
			Promise.reject( {
				code: 'orillacart_product_changed',
				message: 'The product was changed since it was loaded.',
				data: { status: 409, product: now },
			} )
		);
		const user = userEvent.setup();
		render( <ProductPanels /> );

		const price = await screen.findByLabelText( 'Price' );
		await user.clear( price );
		await user.type( price, '12' );
		await user.click(
			screen.getByRole( 'button', { name: 'Save product details' } )
		);
		await user.click(
			await screen.findByRole( 'button', {
				name: 'Load it as it is now',
			} )
		);
		await user.click(
			await screen.findByRole( 'button', {
				name: 'Load it, losing my changes',
			} )
		);

		expect( screen.getByLabelText( 'Price' ) ).toHaveValue( '15' );
	} );

	it( 'keeps the details typed and not saved when a download is removed', async () => {
		let removed = false;
		const withFile = {
			...product,
			stock_mode: 'always_available',
			downloads: [
				{
					id: 3,
					name: 'manual.pdf',
					mime_type: 'application/pdf',
					size: 2048,
				},
			],
		};
		mockedFetch.mockImplementation( ( options ) => {
			const request = options as Request;
			if ( request.method === 'DELETE' ) {
				removed = true;
				return Promise.resolve( undefined );
			}
			if ( request.path === '/orillacart/v1/products/10' ) {
				return Promise.resolve(
					removed
						? {
								...product,
								stock_mode: 'always_available',
								sku: 'MUG-NEW',
								updated_at: '2026-10-30T09:00:00+00:00',
							}
						: withFile
				);
			}
			return Promise.resolve( [] );
		} );
		const user = userEvent.setup();
		render( <ProductPanels /> );

		const price = await screen.findByLabelText( 'Price' );
		await user.clear( price );
		await user.type( price, '12' );
		await user.click( screen.getByRole( 'tab', { name: 'Downloads' } ) );
		await user.click( screen.getByRole( 'button', { name: 'Remove' } ) );
		await user.click(
			within( await screen.findByRole( 'dialog' ) ).getByRole( 'button', {
				name: 'Remove',
			} )
		);

		expect( await screen.findByLabelText( 'SKU' ) ).toHaveValue(
			'MUG-NEW'
		);
		expect( screen.getByLabelText( 'Price' ) ).toHaveValue( '12' );
		expect( screen.queryByText( 'manual.pdf' ) ).not.toBeInTheDocument();
	} );

	it( 'names a refused field as the box does', async () => {
		serve( () =>
			Promise.reject( {
				code: 'orillacart_invalid_input',
				message: 'Some fields are not valid.',
				data: {
					status: 422,
					violations: [
						{
							field: 'sale.until',
							message: 'A sale has to end after it starts.',
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
			'Some fields are not valid. Sale ends: A sale has to end after it starts.',
		] );
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
			'Some fields are not valid. Price: Use an amount of zero or more.',
		] );
	} );

	describe( 'on the classic edit screen', () => {
		let form: HTMLFormElement;
		let sent: number;

		beforeEach( () => {
			// WordPress's post form, its id and its Update button; the page "goes" when the form's submit is not stopped.
			form = document.createElement( 'form' );
			form.id = 'post';
			form.innerHTML =
				'<input type="hidden" id="post_ID" value="10"><input type="submit" id="publish" value="Update">';
			document.body.appendChild( form );
			sent = 0;
			form.addEventListener( 'submit', ( event ) => {
				sent += 1;
				event.preventDefault();
			} );
			forgetClassicPost();
		} );

		afterEach( () => form.remove() );

		const classic = () =>
			render(
				<EditorHostProvider value={ classicEditorHost }>
					<ProductPanels />
				</EditorHostProvider>
			);

		it( 'saves the details before the page is sent, then sends it', async () => {
			serve( ( request ) =>
				Promise.resolve( { ...product, ...( request.data as object ) } )
			);
			const user = userEvent.setup();
			classic();

			const price = await screen.findByLabelText( 'Price' );
			await user.clear( price );
			await user.type( price, '14' );
			await user.click( screen.getByDisplayValue( 'Update' ) );

			await act( async () => {} );
			expect( mockedFetch ).toHaveBeenCalledWith(
				expect.objectContaining( {
					path: '/orillacart/v1/products/10',
					method: 'PUT',
					data: expect.objectContaining( { price: '14' } ),
				} )
			);
			expect( sent ).toBe( 1 );
		} );

		it( 'keeps the page when the shop refuses, with its reasons in the box', async () => {
			serve( ( request ) =>
				request.method === 'PUT'
					? Promise.reject( {
							code: 'orillacart_invalid_input',
							message: 'Some fields are not valid.',
							data: {
								status: 422,
								violations: [
									{
										field: 'price',
										message:
											'Use an amount of zero or more.',
									},
								],
							},
						} )
					: Promise.resolve( product )
			);
			const user = userEvent.setup();
			classic();

			const price = await screen.findByLabelText( 'Price' );
			await user.clear( price );
			await user.type( price, '-1' );
			await user.click( screen.getByDisplayValue( 'Update' ) );

			// In the box: the reason is also spoken, outside it.
			expect(
				await screen.findByText(
					'Some fields are not valid. Price: Use an amount of zero or more.',
					{ selector: '.components-notice__content' }
				)
			).toBeVisible();
			expect( sent ).toBe( 0 );
		} );
	} );
} );
