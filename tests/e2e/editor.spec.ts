/**
 * The shop's blocks in WordPress's editors, as the owner builds pages and templates with them: each previews the
 * server's own rendering, and the page shows what was designed.
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';
import type { Editor } from '@wordpress/e2e-test-utils-playwright';
import type { Page } from '@playwright/test';

import { remove, setUpShop, tearDownShop } from './shop';

const pages: number[] = [];

test.beforeAll( async ( { requestUtils } ) => {
	await setUpShop( requestUtils );
} );

test.afterAll( async ( { requestUtils } ) => {
	for ( const id of pages ) {
		await remove( requestUtils, `/wp/v2/pages/${ id }?force=true` );
	}
	await tearDownShop( requestUtils );
} );

/**
 * Publishes the page being edited and answers its address, keeping its id to delete it afterwards.
 *
 * @param editor The editor.
 * @param page   The browser page.
 */
async function publish( editor: Editor, page: Page ): Promise< string > {
	await editor.publishPost();
	const [ id, link ] = await page.evaluate( () => {
		const select = (
			window as unknown as {
				wp: {
					data: {
						select: ( store: string ) => {
							getCurrentPostId: () => number;
							getEditedPostAttribute: ( key: string ) => string;
						};
					};
				};
			}
		 ).wp.data.select( 'core/editor' );
		return [
			select.getCurrentPostId(),
			select.getEditedPostAttribute( 'link' ),
		] as const;
	} );
	pages.push( id );
	return link;
}

test.describe( 'The editor', () => {
	test( 'previews the product blocks in the site editor’s product template with the latest product', async ( {
		admin,
		editor,
		requestUtils,
	} ) => {
		// The plugin's template, under the theme's name, as WordPress lists it.
		const template = await requestUtils.rest< { id: string } >( {
			path: '/wp/v2/templates/lookup',
			params: { slug: 'single-orillacart_product' },
		} );
		await admin.visitSiteEditor( {
			postId: template.id,
			postType: 'wp_template',
			canvas: 'edit',
		} );

		// A template has no product of its own: the blocks show the latest one (the hoodie), as the server renders it.
		await expect(
			editor.canvas
				.locator( '[data-type="orillacart/product-price"]' )
				.locator( '.orillacart-price' )
		).toContainText( 'From' );
		const buying = editor.canvas.locator(
			'[data-type="orillacart/add-to-cart"]'
		);
		await expect( buying.getByLabel( /Size/ ) ).toBeVisible();
		await expect(
			buying.getByRole( 'button', { name: 'Add to cart' } )
		).toBeVisible();
	} );

	test( 'designs the Products block’s card, and the shop page shows each product in it', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( {
			postType: 'page',
			title: 'E2E Designed shop',
		} );
		await editor.insertBlock( { name: 'orillacart/products' } );

		// Only a card goes in the block: its appender adds one, with the shop's picture, name, stars, price and button.
		await editor.canvas
			.locator( '[data-type="orillacart/products"]' )
			.getByRole( 'button', { name: /^Add/ } )
			.click();
		const card = editor.canvas.locator(
			'[data-type="orillacart/product-card"]'
		);
		await expect( card ).toBeVisible();
		await expect(
			card.locator( '[data-type="orillacart/product-image"]' )
		).toBeVisible();
		await expect(
			card.locator( '[data-type="core/post-title"]' )
		).toBeVisible();
		await expect(
			card.locator( '[data-type="orillacart/product-button"]' )
		).toBeVisible();

		// The owner leaves the price out of the card.
		await card.locator( '[data-type="orillacart/product-price"]' ).click();
		await editor.clickBlockOptionsMenuItem( 'Delete' );
		await expect(
			card.locator( '[data-type="orillacart/product-price"]' )
		).toHaveCount( 0 );

		const link = await publish( editor, page );
		await page.goto( link );
		const designed = page.locator( '.orillacart-product-card--designed' );
		await expect( designed.first() ).toBeVisible();
		await expect( designed.filter( { hasText: 'E2E Lamp' } ) ).toHaveCount(
			1
		);
		await expect(
			designed.first().locator( '.orillacart-price' )
		).toHaveCount( 0 );
	} );

	test( 'chooses the product of a Single product block, whose blocks then show it', async ( {
		admin,
		editor,
		page,
	} ) => {
		await admin.createNewPost( {
			postType: 'page',
			title: 'E2E Lamp spotlight',
		} );
		await editor.insertBlock( { name: 'orillacart/product' } );

		// Without a product the block asks for one; the chooser finds it by its name.
		const block = editor.canvas.locator(
			'[data-type="orillacart/product"]'
		);
		const chooser = block.getByRole( 'combobox', { name: 'Product' } );
		await chooser.fill( 'E2E La' );
		await editor.canvas.getByRole( 'option', { name: 'E2E Lamp' } ).click();

		await expect(
			block.locator( '[data-type="orillacart/product-title"]' )
		).toContainText( 'E2E Lamp' );
		await expect( chooser ).toHaveCount( 0 );

		const link = await publish( editor, page );
		await page.goto( link );
		await expect(
			page.getByRole( 'heading', { name: 'E2E Lamp', exact: true } )
		).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Add to cart' } )
		).toBeVisible();
	} );
} );
