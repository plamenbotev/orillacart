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
			shipping_option: `rate:${ shop.rate }`,
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
	test( 'finds the order, marks it paid and records its shipment', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'admin.php', 'page=orillacart' );
		await page.getByRole( 'searchbox' ).fill( 'e2e-admin@example.com' );
		await page.getByRole( 'button', { name: `#${ order }` } ).click();

		await expect(
			page.getByRole( 'heading', { name: `Order #${ order } — On hold` } )
		).toBeVisible();
		await page.getByRole( 'button', { name: 'Mark as paid' } ).click();
		await expect(
			page.getByRole( 'heading', {
				name: `Order #${ order } — Processing`,
			} )
		).toBeVisible();
		await expect(
			page.getByText( 'Status changed from on hold to processing.' )
		).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Mark as paid' } )
		).toHaveCount( 0 );

		// Sent with a courier the shop has no add-on for: the tracking number, and the order ships.
		const shipments = page.locator( '.orillacart-card', {
			has: page.getByRole( 'heading', { name: 'Shipments' } ),
		} );
		await shipments.getByLabel( 'Sent with' ).fill( 'E2E Post' );
		await shipments
			.getByLabel( 'Tracking number of parcel 1' )
			.fill( 'E2E-PS1' );
		await shipments
			.getByLabel( 'Tracking link of parcel 1' )
			.fill( 'https://post.example/E2E-PS1' );
		await shipments
			.getByRole( 'button', { name: 'Record the shipment' } )
			.click();
		await expect( shipments.getByText( 'E2E Post E2E-PS1' ) ).toBeVisible();
		await expect(
			page.getByRole( 'heading', { name: `Order #${ order } — Shipped` } )
		).toBeVisible();
		await page.getByRole( 'button', { name: 'It arrived' } ).click();
		await expect(
			page.getByRole( 'heading', {
				name: `Order #${ order } — Completed`,
			} )
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
				shipping_option: `rate:${ shop.rate }`,
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

	test( 'takes an order by hand, cancels it, then deletes it from the list', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'admin.php', 'page=orillacart' );
		await page.getByRole( 'button', { name: 'Add order' } ).click();
		await page.getByLabel( 'Email' ).fill( 'e2e-phone@example.com' );
		await page.getByLabel( 'First name' ).fill( BILLING.first_name );
		await page.getByLabel( 'Last name' ).fill( BILLING.last_name );
		await page.getByLabel( 'Street' ).fill( BILLING.street );
		await page.getByLabel( 'Postcode' ).fill( BILLING.postcode );
		await page.getByLabel( 'City' ).fill( BILLING.city );
		await page
			.getByLabel( 'Country', { exact: true } )
			.selectOption( 'BG' );
		await page.getByLabel( 'Phone' ).fill( BILLING.phone );
		await page
			.getByRole( 'combobox', { name: 'Add a product' } )
			.pressSequentially( 'E2E Lam' );
		await page.getByRole( 'option', { name: 'E2E Lamp' } ).click();
		await page.getByRole( 'button', { name: 'Add', exact: true } ).click();
		await page
			.getByLabel( 'Payment method' )
			.selectOption( { label: 'Bank transfer' } );
		await expect( page.getByLabel( 'Ships by' ) ).toBeVisible();
		await page.getByRole( 'button', { name: 'Create the order' } ).click();

		const heading = page.getByRole( 'heading', { name: /— On hold$/ } );
		await expect( heading ).toBeVisible();
		await expect( page.getByText( 'Taken by the shop.' ) ).toBeVisible();
		const number = /#(\d+)/.exec(
			( await heading.textContent() ) ?? ''
		)?.[ 1 ];
		await page
			.getByLabel( 'Move the order to' )
			.selectOption( { label: 'Cancelled' } );
		await page.getByRole( 'button', { name: 'Change status' } ).click();
		await expect(
			page.getByRole( 'heading', { name: /— Cancelled$/ } )
		).toBeVisible();

		await page.getByRole( 'button', { name: '← All orders' } ).click();
		const row = page.getByRole( 'row' ).filter( {
			has: page.getByRole( 'button', {
				name: `#${ number }`,
				exact: true,
			} ),
		} );
		await row.getByRole( 'button', { name: 'Actions' } ).click();
		await page.getByRole( 'menuitem', { name: 'Delete' } ).click();
		await page
			.getByRole( 'dialog' )
			.getByRole( 'button', { name: 'Delete' } )
			.click();
		await expect( row ).toHaveCount( 0 );
	} );

	test( 'saves a product to its own wishlist and finds it in the account page', async ( {
		page,
	} ) => {
		await page.goto( shop.lampUrl );
		const save = page.getByRole( 'button', { name: /wishlist/ } );
		if ( ( await save.getAttribute( 'aria-pressed' ) ) !== 'true' ) {
			await save.click();
		}
		await expect(
			page.getByRole( 'button', { name: 'Saved to your wishlist' } )
		).toHaveAttribute( 'aria-pressed', 'true' );

		// Read with the logged-in user's nonce: without it WordPress answers as to a visitor.
		await page.goto( `${ shop.urls.account }?section=wishlist` );
		const wishlist = page.locator( '[data-orillacart-wishlist-list]' );
		await expect(
			wishlist.locator( '.orillacart-product-card__name' )
		).toHaveText( [ 'E2E Lamp' ] );
		await wishlist.getByRole( 'button', { name: 'Remove' } ).click();
		await expect(
			page.getByText( 'Nothing saved yet.', { exact: false } )
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

test.describe( 'Attribute sets', () => {
	test( 'keep the order the admin drags options into', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const set = await requestUtils.rest< { id: number } >( {
			method: 'POST',
			path: '/orillacart/v1/attribute-sets',
			data: {
				name: 'E2E Sizes',
				active: true,
				attributes: [
					{
						name: 'E2E Size',
						required: true,
						price_hidden: false,
						options: [
							{ name: 'Small', adjustment: '0' },
							{ name: 'Large', adjustment: '0' },
						],
					},
				],
			},
		} );
		try {
			await admin.visitAdminPage(
				'admin.php',
				'page=orillacart-attribute-sets'
			);
			await page.getByRole( 'button', { name: 'E2E Sizes' } ).click();
			const size = page.getByRole( 'button', {
				name: /E2E Size$/,
				expanded: false,
			} );
			await size.click();
			const large = page.locator(
				'.orillacart-sortable .orillacart-sortable',
				{
					has: page.getByRole( 'button', {
						name: 'Large',
						exact: true,
					} ),
				}
			);
			await large
				.locator( '.orillacart-sortable__handle' )
				.dragTo(
					page.getByRole( 'button', { name: 'Small', exact: true } )
				);
			await page
				.getByRole( 'button', { name: 'Save attribute set' } )
				.click();
			await expect(
				page
					.locator( '.components-notice__content' )
					.getByText( 'Attribute set saved.' )
			).toBeVisible();

			const saved = await requestUtils.rest< {
				attributes: { options: { name: string }[] }[];
			} >( {
				path: `/orillacart/v1/attribute-sets/${ set.id }`,
			} );
			expect(
				saved.attributes[ 0 ]?.options.map( ( option ) => option.name )
			).toEqual( [ 'Large', 'Small' ] );
		} finally {
			await requestUtils.request.fetch(
				`${ requestUtils.storageState?.rootURL ?? '' }orillacart/v1/attribute-sets/${ set.id }`,
				{
					method: 'DELETE',
					headers: {
						'X-WP-Nonce': requestUtils.storageState?.nonce ?? '',
					},
				}
			);
		}
	} );
} );
