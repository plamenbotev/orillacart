/**
 * The shop's admin screens, as the admin uses them after a customer ordered.
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

import { BILLING, setUpShop, tearDownShop, type Shop } from './shop';

let shop: Shop;
let order: number;

test.beforeAll( async ( { requestUtils } ) => {
	shop = await setUpShop( requestUtils );
	// A customer's order, placed through the API as the storefront does.
	await requestUtils.rest( {
		method: 'POST',
		path: '/orillacart/v1/cart/items',
		data: { product_id: shop.lamp, quantity: 1 },
	} );
	const placed = await requestUtils.rest< { number: number } >( {
		method: 'POST',
		path: '/orillacart/v1/checkout',
		data: {
			email: 'e2e-admin@example.com',
			billing: { ...BILLING, country: 'BG' },
			rate_id: shop.rate,
			payment: 'bank_transfer',
			remember_addresses: false,
		},
	} );
	order = placed.number;
} );

test.afterAll( async ( { requestUtils } ) => {
	await tearDownShop( requestUtils );
} );

test.describe( 'The admin', () => {
	test( 'finds the order and marks it paid', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'admin.php', 'page=orillacart' );
		await page.getByRole( 'searchbox' ).fill( 'e2e-admin@example.com' );
		await page.getByRole( 'button', { name: `#${ order }` } ).click();

		await expect(
			page.getByRole( 'heading', { name: `Order #${ order } — On hold` } )
		).toBeVisible();
		await page
			.getByLabel( 'Move the order to' )
			.selectOption( 'processing' );
		await page.getByRole( 'button', { name: 'Change status' } ).click();
		await expect(
			page.getByRole( 'heading', {
				name: `Order #${ order } — Processing`,
			} )
		).toBeVisible();
		await expect(
			page.getByText( 'Status changed from on hold to processing.' )
		).toBeVisible();
	} );

	test( 'adds a product to an order on hold with the product picker', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await requestUtils.rest( {
			method: 'POST',
			path: '/orillacart/v1/cart/items',
			data: { product_id: shop.lamp, quantity: 1 },
		} );
		const second = await requestUtils.rest< { number: number } >( {
			method: 'POST',
			path: '/orillacart/v1/checkout',
			data: {
				email: 'e2e-admin@example.com',
				billing: { ...BILLING, country: 'BG' },
				rate_id: shop.rate,
				payment: 'bank_transfer',
				remember_addresses: false,
			},
		} );

		await admin.visitAdminPage( 'admin.php', 'page=orillacart' );
		await page.getByRole( 'searchbox' ).fill( 'e2e-admin@example.com' );
		await page
			.getByRole( 'button', { name: `#${ second.number }` } )
			.click();
		await page
			.getByRole( 'combobox', { name: 'Add a product' } )
			.pressSequentially( 'E2E Hood' );
		await page.getByRole( 'option', { name: 'E2E Hoodie' } ).click();
		await page.getByLabel( 'Variation' ).selectOption( { label: 'S' } );
		await page.getByRole( 'button', { name: 'Add', exact: true } ).click();
		await page.getByRole( 'button', { name: 'Save the items' } ).click();

		await expect(
			page
				.locator( '.components-notice__content' )
				.getByText( 'The items were changed.' )
		).toBeVisible();
		await expect(
			page.getByRole( 'cell', { name: /E2E Hoodie – S/ } )
		).toBeVisible();
	} );

	test( 'chooses the page the cart is on', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'admin.php', 'page=orillacart-settings' );
		const cart = page.getByRole( 'combobox', {
			name: 'Cart',
			exact: true,
		} );
		const current = await cart.inputValue();
		await expect( cart ).not.toHaveValue( '' );

		await cart.selectOption( '' );
		await page.getByRole( 'button', { name: 'Save shop pages' } ).click();
		await expect(
			page
				.locator( '.components-notice__content' )
				.getByText( 'Shop pages saved.' )
		).toBeVisible();

		await cart.selectOption( current );
		await page.getByRole( 'button', { name: 'Save shop pages' } ).click();
		await expect(
			page
				.locator( '.components-notice__content' )
				.getByText( 'Shop pages saved.' )
		).toBeVisible();
	} );

	test( 'orders product categories for the shop page', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await admin.visitAdminPage(
			'edit-tags.php',
			'taxonomy=orillacart_category&post_type=orillacart_product'
		);
		await page.locator( '#tag-name' ).fill( 'E2E Category' );
		await page.locator( '#orillacart_position' ).fill( '7' );
		await page.getByRole( 'button', { name: 'Add new category' } ).click();
		const row = page.getByRole( 'row', { name: /E2E Category/ } );
		await expect( row ).toContainText( '7' );

		const terms = await requestUtils.rest<
			{ id: number; meta: { orillacart_position: number } }[]
		>( {
			path: '/wp/v2/orillacart_category?search=E2E Category',
		} );
		expect( terms[ 0 ]?.meta.orillacart_position ).toBe( 7 );
		for ( const term of terms ) {
			await requestUtils.rest( {
				method: 'DELETE',
				path: `/wp/v2/orillacart_category/${ term.id }`,
				params: { force: true },
			} );
		}
	} );
} );
