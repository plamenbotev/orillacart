/**
 * The storefront as a visitor uses it: no login, a cart in a cookie, the REST API behind the forms.
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';
import type { Page } from '@playwright/test';

import { BILLING, setUpShop, tearDownShop, type Shop } from './shop';

let shop: Shop;

test.beforeAll( async ( { requestUtils } ) => {
	shop = await setUpShop( requestUtils );
} );

test.afterAll( async ( { requestUtils } ) => {
	await tearDownShop( requestUtils );
} );

// Visitors: no admin login.
test.use( { storageState: { cookies: [], origins: [] } } );

async function fillBilling( page: Page, email: string ) {
	await page.getByLabel( 'Email' ).fill( email );
	await page.locator( '#billing-first_name' ).fill( BILLING.first_name );
	await page.locator( '#billing-last_name' ).fill( BILLING.last_name );
	await page.locator( '#billing-street' ).fill( BILLING.street );
	await page.locator( '#billing-city' ).fill( BILLING.city );
	await page.locator( '#billing-postcode' ).fill( BILLING.postcode );
	await page.locator( '#billing-phone' ).fill( BILLING.phone );
	await page.locator( '#billing-country' ).selectOption( 'BG' );
}

test.describe( 'A visitor', () => {
	test( 'buys from the shop page and pays by bank transfer', async ( {
		page,
	} ) => {
		await page.goto( shop.urls.shop );
		const card = page.locator( '.orillacart-product-card', {
			hasText: 'E2E Lamp',
		} );
		await expect( card.locator( 'del' ) ).toHaveText( /40\.00/ );
		await expect( card.locator( 'ins' ) ).toHaveText( /30\.00/ );
		await card.getByRole( 'button', { name: /Add to cart/ } ).click();
		await expect( card.getByRole( 'status' ) ).toHaveText(
			'Added to your cart. View cart'
		);

		await page.goto( shop.urls.cart );
		const quantity = page.getByLabel( 'Quantity of E2E Lamp' );
		await expect( quantity ).toHaveValue( '1' );
		await quantity.fill( '2' );
		await quantity.dispatchEvent( 'change' );
		await expect(
			page.locator( '.orillacart-cart__table tfoot' )
		).toContainText( '60.00' );

		await page.getByRole( 'link', { name: 'Check out' } ).click();
		await expect( page ).toHaveURL( shop.urls.checkout );
		await fillBilling( page, 'visitor@example.com' );
		const rate = page.getByRole( 'radio', { name: /Bulgaria/ } );
		await expect( rate ).toBeChecked();
		await expect( page.locator( '.orillacart-total' ) ).toContainText(
			'65.00'
		);
		await page.getByRole( 'radio', { name: 'Bank transfer' } ).check();
		await page.getByRole( 'button', { name: 'Place order' } ).click();

		await expect(
			page.getByRole( 'heading', { name: 'Thank you for your order' } )
		).toBeVisible();
		await expect( page ).toHaveURL( /orillacart_order=[a-f0-9]{32}/ );
		await expect( page.getByText( 'Pay to IBAN BG00 E2E' ) ).toBeVisible();
		await expect(
			page.locator( '.orillacart-receipt__facts' )
		).toContainText( 'On hold' );

		// The cart is empty after ordering.
		await page.goto( shop.urls.cart );
		await expect( page.getByText( 'Your cart is empty.' ) ).toBeVisible();
	} );

	test( 'sees the mini cart follow the cart', async ( { page } ) => {
		await page.goto( shop.miniCartUrl );
		const miniCart = page.locator( '.orillacart-mini-cart--panel' );
		await expect(
			miniCart.getByText( 'Your cart is empty.' )
		).toBeVisible();
		await expect(
			miniCart.getByRole( 'link', { name: 'Check out' } )
		).toBeHidden();

		await page
			.locator( '.orillacart-product-card', { hasText: 'E2E Lamp' } )
			.getByRole( 'button', { name: /Add to cart/ } )
			.click();
		await expect( miniCart.getByText( '1 item in cart.' ) ).toBeVisible();
		await expect(
			miniCart.getByText( 'Your cart is empty.' )
		).toBeHidden();
		await miniCart.getByRole( 'link', { name: 'Check out' } ).click();
		await expect( page ).toHaveURL( shop.urls.checkout );

		// Emptied elsewhere, the mini cart says so on the next page.
		await page.goto( shop.urls.cart );
		await page.getByRole( 'button', { name: 'Remove E2E Lamp' } ).click();
		await expect( page.getByText( 'Your cart is empty.' ) ).toBeVisible();
		await page.goto( shop.miniCartUrl );
		await expect(
			miniCart.getByText( 'Your cart is empty.' )
		).toBeVisible();
	} );

	test( 'chooses a variation on the product page', async ( { page } ) => {
		await page.goto( shop.hoodieUrl );
		await expect( page.getByText( 'About the E2E Hoodie.' ) ).toBeVisible();
		await expect( page.locator( '[data-orillacart-price]' ) ).toHaveText(
			/From 48\.00/
		);

		await page.getByLabel( 'Size' ).selectOption( { label: 'M' } );
		await expect( page.locator( '[data-orillacart-price]' ) ).toHaveText(
			/52\.00/
		);
		await page.getByRole( 'button', { name: 'Add to cart' } ).click();
		await expect( page.getByRole( 'status' ) ).toHaveText(
			'Added to your cart. View cart'
		);

		await page.getByRole( 'link', { name: 'View cart' } ).click();
		await expect(
			page.getByRole( 'link', { name: 'E2E Hoodie – M' } )
		).toBeVisible();
		await page
			.getByRole( 'button', { name: 'Remove E2E Hoodie – M' } )
			.click();
		await expect( page.getByText( 'Your cart is empty.' ) ).toBeVisible();
	} );

	test( 'sees what is missing next to each field', async ( { page } ) => {
		await page.goto( shop.lampUrl );
		await page.getByRole( 'button', { name: 'Add to cart' } ).click();
		await expect( page.getByRole( 'status' ) ).toHaveText( /Added/ );

		await page.goto( shop.urls.checkout );
		await page.getByLabel( 'Email' ).fill( 'not an email' );
		await page.getByRole( 'button', { name: 'Place order' } ).click();

		await expect( page.locator( '#billing-city' ) ).toHaveAttribute(
			'aria-invalid',
			'true'
		);
		await expect( page.getByLabel( 'Email' ) ).toHaveAttribute(
			'aria-invalid',
			'true'
		);
		await expect(
			page
				.getByRole( 'alert' )
				.filter( { hasText: 'Check the marked fields.' } )
		).toBeVisible();
	} );

	test( 'makes an account while ordering, then finds the order in it', async ( {
		page,
	} ) => {
		const email = `e2e-${ Date.now() }@example.com`;
		await page.goto( shop.lampUrl );
		await page.getByRole( 'button', { name: 'Add to cart' } ).click();
		await expect( page.getByRole( 'status' ) ).toHaveText( /Added/ );

		await page.goto( shop.urls.checkout );
		await fillBilling( page, email );
		await page.getByLabel( 'Make an account with this order' ).check();
		await page.getByLabel( 'Password' ).fill( 'correct horse battery' );
		await page.getByRole( 'button', { name: 'Place order' } ).click();
		await expect(
			page.getByRole( 'heading', { name: 'Thank you for your order' } )
		).toBeVisible();
		const number =
			( await page
				.locator( '.orillacart-receipt__facts strong' )
				.first()
				.textContent() ) ?? '';

		await page.goto( shop.urls.account );
		await expect(
			page.getByRole( 'link', { name: 'Log out' } )
		).toBeVisible();
		await page.getByRole( 'link', { name: number } ).click();
		await expect(
			page.getByRole( 'heading', { name: `Order ${ number }` } )
		).toBeVisible();

		await page.getByRole( 'link', { name: 'Addresses' } ).click();
		await expect( page.locator( '#billing-city' ) ).toHaveValue( 'Sofia' );
	} );

	test( 'makes an account on the account page and logs out', async ( {
		page,
	} ) => {
		await page.goto( shop.urls.account );
		await page.locator( '#register-first_name' ).fill( 'Grace' );
		await page.locator( '#register-last_name' ).fill( 'Hopper' );
		await page
			.locator( '#register-email' )
			.fill( `e2e-grace-${ Date.now() }@example.com` );
		await page.locator( '#register-password' ).fill( 'short' );
		await page.getByRole( 'button', { name: 'Make an account' } ).click();
		await expect(
			page.getByText( 'Choose a password of at least 8 characters.' )
		).toBeVisible();

		await page
			.locator( '#register-password' )
			.fill( 'correct horse battery' );
		await page.getByRole( 'button', { name: 'Make an account' } ).click();
		await expect(
			page.getByText( 'You have not ordered anything yet.' )
		).toBeVisible();

		await page.getByRole( 'link', { name: 'Log out' } ).click();
		await expect( page.locator( '#orillacart-login' ) ).toBeVisible();
	} );
} );
