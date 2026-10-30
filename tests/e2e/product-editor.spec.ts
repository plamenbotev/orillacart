/**
 * A product's edit screen in both of WordPress's editors: the product's data in a wide box below the post, saved
 * with it; the gallery, linked products and identifiers in the block editor's sidebar or the classic screen's boxes.
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

import {
	product as makeProduct,
	remove,
	setUpShop,
	tearDownShop,
	type Shop,
} from './shop';

let shop: Shop;

test.beforeAll( async ( { requestUtils } ) => {
	shop = await setUpShop( requestUtils );
} );

test.afterAll( async ( { requestUtils } ) => {
	await tearDownShop( requestUtils );
} );

test.describe( 'The product’s edit screen', () => {
	test( 'in the block editor has the product’s data below the post, as wide as the editor, saved with it', async ( {
		admin,
		editor,
		page,
		requestUtils,
	} ) => {
		await admin.editPost( shop.lamp );

		const box = page.locator( '#orillacart-product-data' );
		await expect( box ).toBeVisible();
		const width = ( await box.boundingBox() )?.width ?? 0;
		expect( width ).toBeGreaterThan( 600 );
		await expect( box.getByRole( 'tab' ) ).toHaveText( [
			'Price and tax',
			'Weight and size',
			'Stock',
			'Attributes',
			'Downloads',
			'Bundle',
			'Variations',
		] );

		const price = box.getByLabel( 'Price', { exact: true } );
		await expect( price ).toHaveValue( '40' );
		await price.fill( '41' );
		await box.getByRole( 'tab', { name: 'Stock' } ).click();
		await expect( price ).toBeHidden();

		// The post's own save takes the details with it.
		await page
			.getByRole( 'region', { name: 'Editor top bar' } )
			.getByRole( 'button', { name: 'Save', exact: true } )
			.click();
		await expect
			.poll( async () => {
				const product = await requestUtils.rest< { price: string } >( {
					path: `/orillacart/v1/products/${ shop.lamp }`,
				} );
				return product.price;
			} )
			.toBe( '41' );

		// The small panels stay in the sidebar.
		await editor.openDocumentSettingsSidebar();
		await expect(
			page
				.getByRole( 'region', { name: 'Editor settings' } )
				.getByRole( 'button', { name: 'Linked products' } )
		).toBeVisible();
	} );

	test( 'on the classic screen has the same box and the small ones beside it, saved before the page is sent', async ( {
		page,
		requestUtils,
		baseURL,
	} ) => {
		await page.context().addCookies( [
			{
				name: 'orillacart_e2e_classic',
				value: '1',
				url: baseURL ?? '',
			},
		] );
		try {
			await page.goto(
				`/wp-admin/post.php?post=${ shop.lamp }&action=edit`
			);
			await expect( page.locator( '#post' ) ).toBeVisible();

			const box = page.locator( '#orillacart-product-data' );
			const price = box.getByLabel( 'Price', { exact: true } );
			await expect( price ).toBeVisible();
			await price.fill( '-5' );
			const identifiers = page.locator( '#orillacart-identifiers' );
			await identifiers
				.getByLabel( 'Barcode (GTIN, EAN, UPC, ISBN)' )
				.fill( '4006381333931' );

			// A refusal keeps the page, with the reasons in the box.
			await page.locator( '#publish' ).click();
			await expect(
				box.locator( '.components-notice__content' )
			).toContainText( 'Price:' );
			await expect( page ).toHaveURL( /action=edit/ );

			await price.fill( '42' );
			await page.locator( '#publish' ).click();
			await expect( page.locator( '#message' ) ).toContainText(
				'updated'
			);

			const product = await requestUtils.rest< { price: string } >( {
				path: `/orillacart/v1/products/${ shop.lamp }`,
			} );
			expect( product.price ).toBe( '42' );
			const post = await requestUtils.rest< {
				meta: { orillacart_gtin: string };
			} >( {
				path: `/wp/v2/orillacart_product/${ shop.lamp }?context=edit`,
			} );
			expect( post.meta.orillacart_gtin ).toBe( '4006381333931' );
			// The box keeps the shop's meta: the Custom Fields box does not list it to send back.
			await expect(
				page.locator( '#postcustom' ).getByText( 'orillacart_gtin' )
			).toHaveCount( 0 );
		} finally {
			await page.context().clearCookies( {
				name: 'orillacart_e2e_classic',
			} );
		}
	} );

	test( 'says the product changed elsewhere since it was opened, and saves over it once asked', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const kettle = await makeProduct( requestUtils, 'E2E Kettle', {
			price: '20',
			stock_mode: 'always_available',
		} );
		try {
			await admin.editPost( kettle.id );
			const box = page.locator( '#orillacart-product-data' );
			const price = box.getByLabel( 'Price', { exact: true } );
			await expect( price ).toHaveValue( '20' );
			await price.fill( '22' );

			// Meanwhile another save changes it.
			const loaded = await requestUtils.rest< {
				updated_at: string | null;
			} >( { path: `/orillacart/v1/products/${ kettle.id }` } );
			await requestUtils.rest( {
				method: 'PUT',
				path: `/orillacart/v1/products/${ kettle.id }`,
				data: {
					price: '25',
					stock_mode: 'always_available',
					updated_at: loaded.updated_at,
				},
			} );

			await box
				.getByRole( 'button', { name: 'Save product details' } )
				.click();
			await expect(
				page
					.getByText( /changed elsewhere since you opened it/, {
						exact: false,
					} )
					.first()
			).toBeVisible();
			await expect( price ).toHaveValue( '22' );
			const between = await requestUtils.rest< { price: string } >( {
				path: `/orillacart/v1/products/${ kettle.id }`,
			} );
			expect( between.price ).toBe( '25' );

			await page
				.getByRole( 'button', { name: 'Save mine over it' } )
				.click();
			await expect
				.poll( async () => {
					const saved = await requestUtils.rest< {
						price: string;
					} >( { path: `/orillacart/v1/products/${ kettle.id }` } );
					return saved.price;
				} )
				.toBe( '22' );
		} finally {
			await remove(
				requestUtils,
				`/wp/v2/orillacart_product/${ kettle.id }?force=true`
			);
		}
	} );
} );
