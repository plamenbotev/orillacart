/**
 * The storefront as a visitor uses it: no login, a cart in a cookie, the REST API behind the forms.
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';
import type { Page } from '@playwright/test';

import {
	accountLink,
	BILLING,
	cartLink,
	mailedLink,
	product,
	remove,
	runJobs,
	setUpShop,
	takeReturns,
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
	test( 'narrows the shop by a filter without leaving the page, and goes back', async ( {
		page,
		requestUtils,
	} ) => {
		const filter = await requestUtils.rest< { id: number } >( {
			method: 'POST',
			path: '/orillacart/v1/search-filters',
			data: {
				label: 'E2E Size',
				key: 'e2e-size',
				attributes: [ 'Size' ],
			},
		} );
		try {
			await page.goto( shop.urls.shop );
			const cards = page.locator( '.orillacart-product-card__name' );
			await expect( cards.filter( { hasText: 'E2E Lamp' } ) ).toHaveCount(
				1
			);

			await page
				.getByRole( 'group', { name: 'E2E Size' } )
				.getByRole( 'checkbox', { name: /^M/ } )
				.check();
			await expect( page ).toHaveURL( /f%5Be2e-size%5D%5B%5D=m/ );
			await expect( cards.filter( { hasText: 'E2E Lamp' } ) ).toHaveCount(
				0
			);
			await expect(
				cards.filter( { hasText: 'E2E Hoodie' } )
			).toHaveCount( 1 );

			await page.goBack();
			await expect( cards.filter( { hasText: 'E2E Lamp' } ) ).toHaveCount(
				1
			);
		} finally {
			await requestUtils
				.rest( {
					method: 'DELETE',
					path: `/orillacart/v1/search-filters/${ filter.id }`,
				} )
				.catch( () => undefined );
		}
	} );

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
		// Before an address, shipping and tax are estimated at the shop's.
		await expect(
			page.locator( '.orillacart-cart__table .orillacart-total' )
		).toContainText( /Estimated total.*65\.00/ );

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
		await expect( page ).toHaveURL(
			/orillacart_order=\d+-\d+-[a-f0-9]{32}/
		);
		await expect( page.getByText( 'Pay to IBAN BG00 E2E' ) ).toBeVisible();
		await expect(
			page.locator( '.orillacart-receipt__facts' )
		).toContainText( 'On hold' );

		// The receipt offers to pay it now; paying the same way leaves it waiting for the transfer.
		const pay = page.locator( 'form[data-orillacart-pay-now]' );
		await expect(
			pay.getByRole( 'heading', { name: 'Pay now' } )
		).toBeVisible();
		// The receipt loads again once the payment is set up; wait for it, or the next page races the reload.
		const reloaded = page.waitForEvent( 'framenavigated' );
		await pay.getByRole( 'button', { name: /Pay 65\.00/ } ).click();
		await reloaded;
		await page.waitForLoadState();
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

	test( 'gets the whole product page in the first response, as search engines do', async ( {
		request,
	} ) => {
		// The product blocks render on the server: no script runs here.
		const html = await ( await request.get( shop.lampUrl ) ).text();
		expect( html ).toContain( 'E2E Lamp' );
		expect( html ).toMatch(
			/data-orillacart-price[^>]*>[\s\S]*?\d+\.\d\d/
		);
		expect( html ).toContain( 'data-orillacart-add-to-cart' );
		expect( html ).toContain( 'https://schema.org/BreadcrumbList' );
		expect( html ).toContain( '<script type="application/ld+json">' );
		expect( html.match( /data-orillacart-add-to-cart/g ) ).toHaveLength(
			1
		);
	} );

	test( 'adds to the cart without JavaScript, and is told checking out needs it', async ( {
		browser,
	} ) => {
		const context = await browser.newContext( {
			javaScriptEnabled: false,
		} );
		const page = await context.newPage();
		try {
			await page.goto( shop.lampUrl );
			await page.getByLabel( 'Quantity' ).fill( '2' );
			await page.getByRole( 'button', { name: 'Add to cart' } ).click();

			await expect( page ).toHaveURL( shop.urls.cart );
			await expect(
				page.getByRole( 'link', { name: 'E2E Lamp' } )
			).toBeVisible();
			// Playwright turns scripts off without the parser showing <noscript>: the page source holds it.
			expect( await page.content() ).toContain(
				'Turn on JavaScript in your browser to change the cart and check out.'
			);
		} finally {
			await context.close();
		}
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
		await page.getByRole( 'button', { name: 'Place order' } ).click();
		await expect(
			page.getByRole( 'heading', { name: 'Thank you for your order' } )
		).toBeVisible();
		const number =
			( await page
				.locator( '.orillacart-receipt__facts strong' )
				.first()
				.textContent() ) ?? '';

		// The account is made with the emailed link.
		await page.goto( accountLink( email ) );
		await page.getByLabel( 'Password' ).fill( 'correct horse battery' );
		await page.getByRole( 'button', { name: 'Make my account' } ).click();
		await expect(
			page.getByRole( 'link', { name: 'Log out' } )
		).toBeVisible();
		await page.getByRole( 'link', { name: number } ).click();
		await expect(
			page.getByRole( 'heading', { name: `Order ${ number }` } )
		).toBeVisible();

		// The order's address is saved; another is added, for shipping to.
		await page.getByRole( 'link', { name: 'Addresses' } ).click();
		await expect(
			page.locator( '.orillacart-saved-address' ).first()
		).toContainText( '1 Vitosha Blvd' );
		await page.getByText( 'Add an address' ).click();
		await page.locator( '#address-new-first_name' ).fill( 'Ada' );
		await page.locator( '#address-new-last_name' ).fill( 'Lovelace' );
		await page.locator( '#address-new-street' ).fill( '5 Shipka St' );
		await page.locator( '#address-new-city' ).fill( 'Sofia' );
		await page.locator( '#address-new-postcode' ).fill( '1504' );
		await page.locator( '#address-new-phone' ).fill( BILLING.phone );
		await page.locator( '#address-new-country' ).selectOption( 'BG' );
		await page.getByLabel( 'Use for shipping' ).check();
		await page.getByRole( 'button', { name: 'Save address' } ).click();
		await expect(
			page.locator( '.orillacart-saved-address', {
				hasText: '5 Shipka St',
			} )
		).toContainText( 'Shipping' );

		// Checkout starts from it, and offers the other.
		await page.goto( shop.lampUrl );
		await page.getByRole( 'button', { name: 'Add to cart' } ).click();
		await expect( page.getByRole( 'status' ) ).toHaveText( /Added/ );
		await page.goto( shop.urls.checkout );
		await expect( page.locator( '#billing-street' ) ).toHaveValue(
			'1 Vitosha Blvd'
		);
		await expect( page.locator( '#shipping_address-street' ) ).toHaveValue(
			'5 Shipka St'
		);
		await page
			.locator( '#shipping_address-saved' )
			.selectOption( { index: 0 } );
		await expect( page.locator( '#shipping_address-street' ) ).toHaveValue(
			'1 Vitosha Blvd'
		);
		await expect(
			page.locator( '#shipping_address-postcode' )
		).toHaveValue( '1000' );
		await expect( page.locator( '#orillacart-remind' ) ).toBeChecked();

		// Turned off in the account, the reminder's box starts unticked.
		await page.goto( shop.urls.account );
		await page.getByRole( 'link', { name: 'Emails' } ).click();
		await page
			.getByLabel( 'At checkout, offer to remind me of a cart I leave' )
			.uncheck();
		await page.getByRole( 'button', { name: 'Save' } ).click();
		await expect(
			page.getByText( 'Your choices are saved.' )
		).toBeVisible();
		await page.goto( shop.urls.checkout );
		await expect( page.locator( '#orillacart-remind' ) ).not.toBeChecked();
	} );

	test( 'signs up for the newsletter at checkout', async ( {
		page,
		requestUtils,
	} ) => {
		const email = `e2e-reader-${ Date.now() }@example.com`;
		await requestUtils.rest( {
			method: 'PUT',
			path: '/orillacart/v1/newsletter-settings',
			data: {
				offered: true,
				consent: 'Send me the E2E letter.',
				lists: [],
			},
		} );
		try {
			await page.goto( shop.lampUrl );
			await page.getByRole( 'button', { name: 'Add to cart' } ).click();
			await expect( page.getByRole( 'status' ) ).toHaveText( /Added/ );
			await page.goto( shop.urls.checkout );
			await fillBilling( page, email );
			await page.getByLabel( 'Send me the E2E letter.' ).check();
			await page.getByRole( 'button', { name: 'Place order' } ).click();
			await expect(
				page.getByRole( 'heading', {
					name: 'Thank you for your order',
				} )
			).toBeVisible();

			const settings = await requestUtils.rest< { subscribers: number } >(
				{
					path: '/orillacart/v1/newsletter-settings',
				}
			);
			expect( settings.subscribers ).toBeGreaterThan( 0 );
		} finally {
			await requestUtils.rest( {
				method: 'PUT',
				path: '/orillacart/v1/newsletter-settings',
				data: {
					offered: false,
					consent: 'Send me the E2E letter.',
					lists: [],
				},
			} );
		}
	} );

	test( 'orders as a guest, then finds the order by its number and email', async ( {
		page,
	} ) => {
		const email = `e2e-guest-${ Date.now() }@example.com`;
		await page.goto( shop.lampUrl );
		await page.getByRole( 'button', { name: 'Add to cart' } ).click();
		await expect( page.getByRole( 'status' ) ).toHaveText( /Added/ );
		await page.goto( shop.urls.checkout );
		await fillBilling( page, email );
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
		await page.getByLabel( 'Order number' ).fill( number );
		await page
			.getByLabel( 'Email the order was placed with' )
			.fill( email );
		await page.getByRole( 'button', { name: 'Email me the link' } ).click();
		await expect(
			page.getByText( 'If the order number and email match' )
		).toBeVisible();

		runJobs();
		await page.goto(
			mailedLink(
				email,
				/href="([^"]*orillacart_order=[^"]+)"/,
				'The link to your order'
			)
		);
		await expect(
			page.getByText( number, { exact: true } ).first()
		).toBeVisible();

		// The receipt orders the items again: they are in the cart.
		await page.getByRole( 'button', { name: 'Order these again' } ).click();
		await expect( page ).toHaveURL( shop.urls.cart );
		await expect(
			page.locator( '.orillacart-cart__table' ).getByText( 'E2E Lamp' )
		).toBeVisible();
	} );

	test( 'tells Google Tag Manager what it views, adds and buys, when the shop says so', async ( {
		page,
		requestUtils,
	} ) => {
		const settings = await requestUtils.rest< Record< string, unknown > >( {
			path: '/orillacart/v1/storefront-settings',
		} );
		await requestUtils.rest( {
			method: 'PUT',
			path: '/orillacart/v1/storefront-settings',
			data: { ...settings, data_layer: true },
		} );
		const events = () =>
			page.evaluate( () =>
				(
					(
						window as unknown as {
							dataLayer?: { event?: string }[];
						}
					 ).dataLayer ?? []
				)
					.map( ( entry ) => entry.event )
					.filter( Boolean )
			);
		try {
			await page.goto( shop.lampUrl );
			await page.getByRole( 'button', { name: 'Add to cart' } ).click();
			await expect( page.getByRole( 'status' ).first() ).toHaveText(
				/Added/
			);
			expect( await events() ).toEqual( [ 'view_item', 'add_to_cart' ] );

			await page.goto( shop.urls.checkout );
			expect( await events() ).toEqual( [ 'begin_checkout' ] );
			await fillBilling( page, `e2e-tag-${ Date.now() }@example.com` );
			await page.getByRole( 'button', { name: 'Place order' } ).click();
			await expect(
				page.getByRole( 'heading', {
					name: 'Thank you for your order',
				} )
			).toBeVisible();
			expect( await events() ).toEqual( [ 'purchase' ] );
			await page.reload();
			expect( await events() ).toEqual( [] );
		} finally {
			await requestUtils.rest( {
				method: 'PUT',
				path: '/orillacart/v1/storefront-settings',
				data: settings,
			} );
		}
	} );

	test( 'saves a product to the wishlist as a visitor, and sees it in the account page', async ( {
		page,
	} ) => {
		await page.goto( shop.lampUrl );
		const save = page.getByRole( 'button', { name: 'Save to wishlist' } );
		await save.click();
		await expect(
			page.getByRole( 'button', { name: 'Saved to your wishlist' } )
		).toHaveAttribute( 'aria-pressed', 'true' );

		await page.goto( shop.urls.account );
		const wishlist = page.locator( '[data-orillacart-wishlist-list]' );
		await expect(
			wishlist.locator( '.orillacart-product-card__name' )
		).toHaveText( [ 'E2E Lamp' ] );
		await wishlist.getByRole( 'button', { name: 'Remove' } ).click();
		await expect(
			page.getByText( 'Nothing saved yet.', { exact: false } )
		).toBeVisible();
	} );

	test( 'sees the products it viewed lately under another product', async ( {
		page,
	} ) => {
		await page.goto( shop.lampUrl );
		await page.goto( shop.hoodieUrl );
		const recent = page.locator( '.orillacart-recently-viewed' );
		// Not the page's own product.
		await expect(
			recent.locator( '.orillacart-product-card__name' )
		).toHaveText( [ 'E2E Lamp' ] );
	} );

	test( 'reviews a product, and the review waits for the shop', async ( {
		page,
	} ) => {
		await page.goto( shop.lampUrl );
		const form = page.locator( 'form[data-orillacart-review-form]' );
		await form.getByRole( 'button', { name: 'Send the review' } ).click();
		await expect( form.getByRole( 'status' ) ).toHaveClass( /--error/ );

		await form.getByText( '4 stars' ).click( { force: true } );
		await form
			.getByLabel( 'Your review' )
			.fill( 'Warm light for reading.' );
		await form.getByLabel( 'Name' ).fill( 'E2E Reviewer' );
		await form
			.getByLabel( 'Email' )
			.fill( `e2e-review-${ Date.now() }@example.com` );
		await form.getByRole( 'button', { name: 'Send the review' } ).click();
		// WordPress holds a first comment from someone until the shop approves one.
		await expect( form.getByRole( 'status' ) ).toHaveText(
			/shows once the shop has approved it/
		);
		await expect( form.getByLabel( 'Your review' ) ).toBeHidden();
	} );

	test( 'makes an account on the account page and logs out', async ( {
		page,
	} ) => {
		const email = `e2e-grace-${ Date.now() }@example.com`;
		await page.goto( shop.urls.account );
		await page.locator( '#register-first_name' ).fill( 'Grace' );
		await page.locator( '#register-last_name' ).fill( 'Hopper' );
		await page.locator( '#register-email' ).fill( email );
		await page.getByRole( 'button', { name: 'Make an account' } ).click();
		await expect(
			page.getByText(
				'Look in your email for the link to make your account.'
			)
		).toBeVisible();

		await page.goto( accountLink( email ) );
		await page.getByLabel( 'Password' ).fill( 'short' );
		await page.getByRole( 'button', { name: 'Make my account' } ).click();
		await expect(
			page.getByText( 'Choose a password of at least 8 characters.' )
		).toBeVisible();
		await page.getByLabel( 'Password' ).fill( 'correct horse battery' );
		await page.getByRole( 'button', { name: 'Make my account' } ).click();
		await expect(
			page.getByText( 'You have not ordered anything yet.' )
		).toBeVisible();

		await page.getByRole( 'link', { name: 'Log out' } ).click();
		await expect( page.locator( '#orillacart-login' ) ).toBeVisible();
	} );

	test( 'asks at checkout to be reminded of the cart, and its link brings it back elsewhere', async ( {
		page,
		browser,
	} ) => {
		await page.goto( shop.miniCartUrl );
		await page
			.locator( '.orillacart-product-card', { hasText: 'E2E Lamp' } )
			.getByRole( 'button', { name: /Add to cart/ } )
			.click();
		await expect(
			page
				.locator( '.orillacart-mini-cart--panel' )
				.getByText( '1 item in cart.' )
		).toBeVisible();
		await page.goto( shop.urls.checkout );
		const remind = page.getByLabel(
			'Remind me once about my cart if I leave without ordering'
		);
		await expect( remind ).not.toBeChecked();
		await page.getByLabel( 'Email' ).fill( 'e2e-left@example.com' );
		const told = page.waitForResponse(
			( response ) =>
				response.url().includes( 'checkout-contact' ) &&
				( response.request().postDataJSON() as { remind: boolean } )
					.remind
		);
		await remind.check();
		expect( ( await told ).status() ).toBe( 204 );

		const elsewhere = await browser.newContext();
		try {
			const other = await elsewhere.newPage();
			await other.goto(
				`${ shop.urls.cart }${
					shop.urls.cart.includes( '?' ) ? '&' : '?'
				}${ cartLink( 'e2e-left@example.com', 'ab'.repeat( 32 ) ) }`
			);
			await expect( other ).toHaveURL( shop.urls.cart );
			await expect(
				other.getByRole( 'link', { name: 'E2E Lamp' } )
			).toBeVisible();
		} finally {
			await elsewhere.close();
		}
	} );

	test( 'sees the fee of a way to pay in the totals, and pays it', async ( {
		page,
		requestUtils,
	} ) => {
		await requestUtils.rest( {
			method: 'PUT',
			path: '/orillacart/v1/payment-methods/cash_on_delivery',
			data: {
				name: 'Cash on delivery',
				active: true,
				fee: '2',
				fee_label: 'Cash on delivery fee',
			},
		} );
		try {
			await page.goto( shop.miniCartUrl );
			await page
				.locator( '.orillacart-product-card', { hasText: 'E2E Lamp' } )
				.getByRole( 'button', { name: /Add to cart/ } )
				.click();
			await expect(
				page
					.locator( '.orillacart-mini-cart--panel' )
					.getByText( '1 item in cart.' )
			).toBeVisible();
			await page.goto( shop.urls.checkout );
			await fillBilling( page, 'e2e-fee@example.com' );
			const cash = page.getByRole( 'radio', {
				name: /Cash on delivery \(\+ .*2\.00.* fee\)/,
			} );
			await cash.check();
			await expect(
				page.locator( '.orillacart-checkout__lines .orillacart-fee' )
			).toContainText( /Cash on delivery fee.*2\.00/ );
			await expect( page.locator( '.orillacart-total' ) ).toContainText(
				'37.00'
			);

			await page.getByRole( 'radio', { name: 'Bank transfer' } ).check();
			await expect( page.locator( '.orillacart-total' ) ).toContainText(
				'35.00'
			);
			await expect( page.locator( '.orillacart-fee' ) ).toHaveCount( 0 );

			await page
				.getByRole( 'radio', { name: /Cash on delivery/ } )
				.check();
			await expect( page.locator( '.orillacart-total' ) ).toContainText(
				'37.00'
			);
			await page.getByRole( 'button', { name: 'Place order' } ).click();
			await expect(
				page.getByRole( 'heading', {
					name: 'Thank you for your order',
				} )
			).toBeVisible();
			await expect( page.locator( '.orillacart-fee' ) ).toContainText(
				/Cash on delivery fee.*2\.00/
			);
		} finally {
			await requestUtils.rest( {
				method: 'PUT',
				path: '/orillacart/v1/payment-methods/cash_on_delivery',
				data: { name: 'Cash on delivery', active: false },
			} );
		}
	} );

	test( 'pays part of the order with a gift card', async ( {
		page,
		requestUtils,
	} ) => {
		const issued = await requestUtils.rest< { code: string } >( {
			method: 'POST',
			path: '/orillacart/v1/gift-cards',
			data: { amount: '20' },
		} );
		await page.goto( shop.miniCartUrl );
		await page
			.locator( '.orillacart-product-card', { hasText: 'E2E Lamp' } )
			.getByRole( 'button', { name: /Add to cart/ } )
			.click();
		await expect(
			page
				.locator( '.orillacart-mini-cart--panel' )
				.getByText( '1 item in cart.' )
		).toBeVisible();
		await page.goto( shop.urls.checkout );
		await fillBilling( page, 'e2e-gift-card@example.com' );
		await page
			.getByLabel( 'Gift card', { exact: true } )
			.fill( issued.code );
		await page.locator( '[data-orillacart-apply-gift-card]' ).click();
		await expect( page.locator( '.orillacart-amount-due' ) ).toContainText(
			'15.00'
		);
		await page.getByRole( 'radio', { name: 'Bank transfer' } ).check();
		await page.getByRole( 'button', { name: 'Place order' } ).click();
		await expect(
			page.getByRole( 'heading', { name: 'Thank you for your order' } )
		).toBeVisible();
		await expect(
			page.locator( '.orillacart-gift-card-paid' ).first()
		).toContainText( '20.00' );
	} );

	test( 'chooses another currency and sees the prices in it', async ( {
		page,
		requestUtils,
	} ) => {
		await requestUtils.rest( {
			method: 'PUT',
			path: '/orillacart/v1/currencies',
			data: {
				offered: [ { code: 'USD', rate: '2', rounding: 'decimals' } ],
			},
		} );
		try {
			await page.goto(
				`${ shop.miniCartUrl }${
					shop.miniCartUrl.includes( '?' ) ? '&' : '?'
				}orillacart_currency=USD`
			);
			await expect( page ).not.toHaveURL( /orillacart_currency/ );
			const lamp = page.locator( '.orillacart-product-card', {
				hasText: 'E2E Lamp',
			} );
			await expect( lamp.locator( '.orillacart-price' ) ).toContainText(
				'60.00 USD'
			);
			await lamp.getByRole( 'button', { name: /Add to cart/ } ).click();
			await expect(
				page
					.locator( '.orillacart-mini-cart--panel' )
					.getByText( '1 item in cart.' )
			).toBeVisible();
			await page.goto( shop.urls.checkout );
			await fillBilling( page, 'e2e-currency@example.com' );
			await expect( page.locator( '.orillacart-total' ) ).toContainText(
				'70.00 USD'
			);
		} finally {
			await requestUtils.rest( {
				method: 'PUT',
				path: '/orillacart/v1/currencies',
				data: { offered: [] },
			} );
		}
	} );

	test( 'collects the order at one of the shop’s places', async ( {
		page,
		requestUtils,
	} ) => {
		const pickup = await requestUtils.rest< { id: number } >( {
			method: 'POST',
			path: '/orillacart/v1/carriers',
			data: {
				name: 'E2E Pickup',
				pickup_locations: [
					{
						id: 'sofia',
						name: 'Sofia shop',
						address: '1 Vitosha Blvd, Sofia',
						hours: 'Mon–Fri 9–18',
						fee: '0',
					},
				],
			},
		} );
		try {
			await page.goto( shop.miniCartUrl );
			await page
				.locator( '.orillacart-product-card', { hasText: 'E2E Lamp' } )
				.getByRole( 'button', { name: /Add to cart/ } )
				.click();
			await expect(
				page
					.locator( '.orillacart-mini-cart--panel' )
					.getByText( '1 item in cart.' )
			).toBeVisible();
			await page.goto( shop.urls.checkout );
			await fillBilling( page, 'e2e-pickup@example.com' );
			const place = page.getByRole( 'radio', { name: /Sofia shop/ } );
			await expect( place ).toBeVisible();
			await expect(
				page.getByText( '1 Vitosha Blvd, Sofia · Mon–Fri 9–18' )
			).toBeVisible();
			await place.check();
			await expect( page.locator( '.orillacart-total' ) ).toContainText(
				'30.00'
			);
			await page.getByRole( 'radio', { name: 'Bank transfer' } ).check();
			await page.getByRole( 'button', { name: 'Place order' } ).click();
			await expect(
				page.getByRole( 'heading', {
					name: 'Thank you for your order',
				} )
			).toBeVisible();
			await expect(
				page.getByText(
					'Sofia shop, 1 Vitosha Blvd, Sofia (Mon–Fri 9–18)'
				)
			).toBeVisible();
		} finally {
			await remove(
				requestUtils,
				`/orillacart/v1/carriers/${ pickup.id }`
			);
		}
	} );

	test( 'chooses the day and the time of delivery', async ( {
		page,
		requestUtils,
	} ) => {
		const scheduled = await requestUtils.rest< { id: number } >( {
			method: 'POST',
			path: '/orillacart/v1/carriers',
			data: {
				name: 'E2E Scheduled',
				rates: [ { name: 'By appointment', price: '7' } ],
				delivery_schedule: {
					weekdays: [ 1, 2, 3, 4, 5, 6, 7 ],
					lead_days: 1,
					days_ahead: 7,
					slots: [
						{ id: 'morning', from: '09:00', to: '12:00' },
						{ id: 'evening', from: '17:00', to: '20:00' },
					],
					required: true,
				},
			},
		} );
		try {
			await page.goto( shop.miniCartUrl );
			await page
				.locator( '.orillacart-product-card', { hasText: 'E2E Lamp' } )
				.getByRole( 'button', { name: /Add to cart/ } )
				.click();
			await expect(
				page
					.locator( '.orillacart-mini-cart--panel' )
					.getByText( '1 item in cart.' )
			).toBeVisible();
			await page.goto( shop.urls.checkout );
			await fillBilling( page, 'e2e-day@example.com' );
			await page.getByRole( 'radio', { name: /By appointment/ } ).check();
			const day = page.getByLabel( 'Day of delivery' );
			await expect( day.locator( 'option' ) ).toHaveCount( 7 );
			const second = await day
				.locator( 'option' )
				.nth( 1 )
				.getAttribute( 'value' );
			await day.selectOption( second ?? '' );
			const time = page.getByLabel( 'Time of delivery' );
			await expect( time ).toHaveValue( 'morning' );
			await time.selectOption( 'evening' );
			await page.getByRole( 'radio', { name: 'Bank transfer' } ).check();
			await page.getByRole( 'button', { name: 'Place order' } ).click();
			await expect(
				page.getByRole( 'heading', {
					name: 'Thank you for your order',
				} )
			).toBeVisible();
			await expect(
				page
					.locator( '.orillacart-receipt' )
					.getByText( /, 17:00–20:00/ )
			).toBeVisible();
		} finally {
			await remove(
				requestUtils,
				`/orillacart/v1/carriers/${ scheduled.id }`
			);
		}
	} );

	test( 'takes the address an address check suggests', async ( { page } ) => {
		await page.goto( shop.miniCartUrl );
		await page
			.locator( '.orillacart-product-card', { hasText: 'E2E Lamp' } )
			.getByRole( 'button', { name: /Add to cart/ } )
			.click();
		await expect(
			page
				.locator( '.orillacart-mini-cart--panel' )
				.getByText( '1 item in cart.' )
		).toBeVisible();
		await page.goto( shop.urls.checkout );
		await fillBilling( page, 'e2e-address@example.com' );
		await page.locator( '#billing-street' ).fill( '1 Vitoshaa' );
		await page.getByRole( 'radio', { name: 'Bank transfer' } ).check();
		await page.getByRole( 'button', { name: 'Place order' } ).click();

		const suggestion = page.locator( '.orillacart-address-suggestion' );
		await expect( suggestion ).toContainText( 'Did you mean this street?' );
		await expect( suggestion ).toContainText( '1 Vitosha Blvd' );
		await suggestion
			.getByRole( 'button', { name: 'Use this address' } )
			.click();

		await expect(
			page.getByRole( 'heading', { name: 'Thank you for your order' } )
		).toBeVisible();
		await expect(
			page.getByText( '1 Vitosha Blvd' ).first()
		).toBeVisible();
	} );

	test( 'asks from the receipt to send an item back', async ( {
		page,
		requestUtils,
	} ) => {
		await takeReturns( requestUtils, 30 );
		try {
			await requestUtils.rest( {
				method: 'POST',
				path: '/orillacart/v1/cart/items',
				data: { product_id: shop.lamp, quantity: 2 },
			} );
			const placed = await requestUtils.rest< {
				number: number;
				receipt_url: string;
			} >( {
				method: 'POST',
				path: '/orillacart/v1/checkout',
				data: {
					email: 'e2e-return@example.com',
					billing: { ...BILLING, country: 'BG' },
					shipping_option: `rate:${ shop.rate }`,
					payment: 'bank_transfer',
					remember_addresses: false,
				},
			} );
			// Paid: an order never paid has no money to give back.
			await requestUtils.rest( {
				method: 'POST',
				path: `/orillacart/v1/orders/${ placed.number }/paid`,
				data: { note: 'The transfer came.' },
			} );
			await requestUtils.rest( {
				method: 'PUT',
				path: `/orillacart/v1/orders/${ placed.number }/status`,
				data: { status: 'shipped' },
			} );

			await page.goto( placed.receipt_url );
			const form = page.locator( 'form[data-orillacart-return]' );
			await form.getByLabel( 'E2E Lamp' ).selectOption( '1' );
			await form.getByLabel( 'Why' ).selectOption( 'damaged' );
			await form.getByLabel( 'Anything to add' ).fill( 'Cracked.' );
			await form
				.getByRole( 'button', { name: 'Ask to return these items' } )
				.click();

			await expect(
				page.locator( '.orillacart-return' ).first()
			).toContainText( '1 × E2E Lamp — Asked; the shop will answer' );
			await expect(
				page
					.locator( 'form[data-orillacart-return]' )
					.getByLabel( 'E2E Lamp' )
			).toHaveValue( '0' );
		} finally {
			await takeReturns( requestUtils, null );
		}
	} );

	test( 'personalises an item on its page, sends a logo, and answers a question at checkout', async ( {
		page,
		requestUtils,
	} ) => {
		const mug = await product( requestUtils, 'E2E Mug', {
			price: '10',
			stock_mode: 'always_available',
		} );
		await requestUtils.rest( {
			method: 'POST',
			path: '/orillacart/v1/forms',
			data: {
				name: 'E2E Mug print',
				scope: 'products',
				product_ids: [ mug.id ],
				fields: [
					{
						label: 'Engrave it',
						key: 'engrave',
						kind: 'choice',
						display: 'buttons',
						required: true,
						choices: [
							{ label: 'Yes', value: 'yes', price: '5' },
							{ label: 'No', value: 'no' },
						],
					},
					{
						label: 'Engraving',
						key: 'text',
						required: true,
						longest: 20,
						condition: { field: 'engrave', values: [ 'yes' ] },
					},
					{
						label: 'Logo',
						key: 'logo',
						kind: 'file',
						types: [ 'image/png' ],
						largest: 1,
						price: '2',
					},
				],
			},
		} );
		await requestUtils.rest( {
			method: 'POST',
			path: '/orillacart/v1/forms',
			data: {
				name: 'E2E Mug order',
				placement: 'checkout',
				scope: 'products',
				product_ids: [ mug.id ],
				fields: [
					{
						label: 'PO number',
						key: 'po',
						section: 'billing',
					},
				],
			},
		} );

		await page.goto( mug.link );
		const engraving = page.getByLabel( 'Engraving' );
		await expect( engraving ).toBeHidden();
		await page.getByRole( 'radio', { name: /Yes/ } ).check();
		await expect( engraving ).toBeVisible();
		await engraving.fill( 'For Ada' );
		await page.getByLabel( /Logo/ ).setInputFiles( {
			name: 'logo.png',
			mimeType: 'image/png',
			buffer: Buffer.from(
				'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=',
				'base64'
			),
		} );
		await expect( page.getByText( 'Sent: logo.png' ) ).toBeVisible();
		await page.getByRole( 'button', { name: 'Add to cart' } ).click();
		await expect( page.getByText( 'Added to your cart.' ) ).toBeVisible();

		await page.goto( shop.urls.cart );
		const line = page.locator( '[data-orillacart-line]', {
			hasText: 'E2E Mug',
		} );
		await expect( line ).toContainText( 'Engrave it: Yes' );
		await expect( line ).toContainText( 'Engraving: For Ada' );
		await expect( line ).toContainText( 'Logo: logo.png' );
		await expect( line ).toContainText( '17.00' );

		await page.goto( shop.urls.checkout );
		await fillBilling( page, 'e2e-mug@example.com' );
		await page.getByLabel( 'PO number' ).fill( 'PO-42' );
		await page.getByRole( 'radio', { name: 'Bank transfer' } ).check();
		await page.getByRole( 'button', { name: 'Place order' } ).click();
		await expect(
			page.getByRole( 'heading', { name: 'Thank you for your order' } )
		).toBeVisible();
		const receipt = page.locator( '.orillacart-receipt' );
		await expect( receipt ).toContainText( 'Engraving: For Ada' );
		await expect( receipt ).toContainText( 'PO-42' );
		const number = Number(
			/orillacart_order=(\d+)-/.exec( page.url() )?.[ 1 ] ?? 0
		);

		// The shop manager downloads the logo, as an attachment the browser runs nothing of; a visitor cannot.
		const order = await requestUtils.rest< {
			lines: { personalisation: { upload_id: number | null }[] }[];
		} >( { path: `/orillacart/v1/orders/${ number }` } );
		const upload = order.lines[ 0 ]?.personalisation.find(
			( answer ) => answer.upload_id !== null
		)?.upload_id;
		expect( upload ).toBeGreaterThan( 0 );
		const address = `${
			new URL( '/wp-admin/admin-post.php', shop.urls.shop ).href
		}?action=orillacart_order_upload&order=${ number }&upload=${ upload }`;
		const file = await requestUtils.request.get(
			`${ address }&_wpnonce=${ requestUtils.storageState?.nonce ?? '' }`
		);
		expect( file.status() ).toBe( 200 );
		expect( file.headers()[ 'content-disposition' ] ).toContain(
			'attachment; filename="logo.png"'
		);
		expect( file.headers()[ 'x-content-type-options' ] ).toBe( 'nosniff' );
		expect( file.headers()[ 'content-security-policy' ] ).toContain(
			'sandbox'
		);
		expect( ( await file.body() ).subarray( 1, 4 ).toString() ).toBe(
			'PNG'
		);
		const visitor = await page.request.get( `${ address }&_wpnonce=x` );
		expect( visitor.status() ).not.toBe( 200 );
	} );

	test( 'asks to hear when a sold-out product is back', async ( {
		page,
		requestUtils,
	} ) => {
		const stockroom = await requestUtils.rest< { id: number } >( {
			method: 'POST',
			path: '/orillacart/v1/stockrooms',
			data: { name: 'E2E Back room' },
		} );
		const lamp = await product( requestUtils, 'E2E Sold Out Lamp', {
			price: '15',
		} );
		try {
			await requestUtils.rest( {
				method: 'PUT',
				path: `/orillacart/v1/stock/product/${ lamp.id }`,
				data: {
					levels: [ { stockroom_id: stockroom.id, quantity: 0 } ],
				},
			} );
			await page.goto( lamp.link );
			const form = page.locator( 'form[data-orillacart-back-in-stock]' );
			await form
				.getByLabel( 'Email me when it is back' )
				.fill( 'waiting@example.com' );
			await form.getByRole( 'button', { name: 'Tell me' } ).click();

			await expect(
				form.getByText( 'We will email you once when it is back.' )
			).toBeVisible();
		} finally {
			await remove(
				requestUtils,
				`/wp/v2/orillacart_product/${ lamp.id }?force=true`
			);
			await remove(
				requestUtils,
				`/orillacart/v1/stockrooms/${ stockroom.id }`
			);
		}
	} );
} );

test.describe( 'Templates', () => {
	test( 'show a product in the template it chose, and a category or a product in one made for it alone', async ( {
		page,
		requestUtils,
	} ) => {
		const made: string[] = [];
		const terms: number[] = [];
		const template = async ( slug: string, text: string ) => {
			const saved = await requestUtils.rest< { id: string } >( {
				method: 'POST',
				path: '/wp/v2/templates',
				data: {
					slug,
					title: slug,
					content: `<!-- wp:paragraph --><p>${ text }</p><!-- /wp:paragraph -->`,
				},
			} );
			made.push( saved.id );
		};
		const category = async ( name: string ) => {
			const term = await requestUtils.rest< {
				id: number;
				slug: string;
				link: string;
			} >( {
				method: 'POST',
				path: '/wp/v2/orillacart_category',
				data: { name },
			} );
			terms.push( term.id );
			return term;
		};
		try {
			// A template the product chose in the editor's Template panel: the wide image, the buying form kept.
			await requestUtils.rest( {
				method: 'POST',
				path: `/wp/v2/orillacart_product/${ shop.lamp }`,
				data: { template: 'orillacart-product-wide' },
			} );
			await page.goto( shop.lampUrl );
			await expect(
				page.locator( 'main.orillacart-product-page--wide' )
			).toBeVisible();
			await expect(
				page.getByRole( 'button', { name: 'Add to cart' } )
			).toBeVisible();

			// A template the site editor made for one category wins over the plugin's for all of them.
			const lamps = await category( 'E2E Lamps' );
			const other = await category( 'E2E Other' );
			await requestUtils.rest( {
				method: 'POST',
				path: `/wp/v2/orillacart_product/${ shop.lamp }`,
				data: { orillacart_category: [ lamps.id, other.id ] },
			} );
			await template(
				`taxonomy-orillacart_category-${ lamps.slug }`,
				'Only the lamps here.'
			);
			await page.goto( lamps.link );
			await expect(
				page.getByText( 'Only the lamps here.' )
			).toBeVisible();
			await page.goto( other.link );
			await expect(
				page.getByText( 'Only the lamps here.' )
			).toHaveCount( 0 );
			await expect(
				page.getByRole( 'link', { name: 'E2E Lamp' } ).first()
			).toBeVisible();

			// One made for one product wins over the plugin's for all of them (a template the product chose wins
			// over both, as WordPress has it).
			await requestUtils.rest( {
				method: 'POST',
				path: `/wp/v2/orillacart_product/${ shop.lamp }`,
				data: { template: '' },
			} );
			const lamp = await requestUtils.rest< { slug: string } >( {
				path: `/wp/v2/orillacart_product/${ shop.lamp }`,
			} );
			await template(
				`single-orillacart_product-${ lamp.slug }`,
				'This lamp alone.'
			);
			await page.goto( shop.lampUrl );
			await expect( page.getByText( 'This lamp alone.' ) ).toBeVisible();
		} finally {
			for ( const id of made ) {
				await remove(
					requestUtils,
					`/wp/v2/templates/${ id }?force=true`
				);
			}
			for ( const id of terms ) {
				await remove(
					requestUtils,
					`/wp/v2/orillacart_category/${ id }?force=true`
				);
			}
			await requestUtils.rest( {
				method: 'POST',
				path: `/wp/v2/orillacart_product/${ shop.lamp }`,
				data: { template: '' },
			} );
		}
	} );
} );
