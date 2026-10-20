/**
 * The shop's other admin screens, each through its main task, as the shop manager works them: what is made here
 * is removed again through the API.
 */
import {
	expect,
	test,
	type RequestUtils,
} from '@wordpress/e2e-test-utils-playwright';

import { BILLING, remove, setUpShop, tearDownShop } from './shop';

test.beforeAll( async ( { requestUtils } ) => {
	// The shop's pages, a product and a carrier, as the screens expect a shop to have.
	await setUpShop( requestUtils );
} );

test.afterAll( async ( { requestUtils } ) => {
	await tearDownShop( requestUtils );
} );

type Listed = { id: number; code?: string; name?: string };

/**
 * Removes the coupons a run of this test made, an earlier one too.
 * @param requestUtils The admin's API access.
 */
async function removeCoupons( requestUtils: RequestUtils ) {
	const { coupons } = await requestUtils.rest< { coupons: Listed[] } >( {
		path: '/orillacart/v1/coupons',
	} );
	for ( const coupon of coupons.filter(
		( item ) => item.code === 'E2E-SPRING'
	) ) {
		await remove( requestUtils, `/orillacart/v1/coupons/${ coupon.id }` );
	}
}

test.describe( 'The admin', () => {
	test( 'adds a coupon on the Coupons screen', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await removeCoupons( requestUtils );
		await admin.visitAdminPage( 'admin.php', 'page=orillacart-coupons' );
		await page.getByRole( 'button', { name: 'Add a coupon' } ).click();
		await page
			.getByRole( 'textbox', { name: 'Code' } )
			.fill( 'e2e-spring' );
		await page.getByLabel( 'Discount' ).selectOption( 'percent' );
		await page.getByLabel( 'Percentage' ).fill( '10' );
		await page.getByRole( 'button', { name: 'Save the coupon' } ).click();

		await expect(
			page.getByText( 'The coupon E2E-SPRING was saved.' ).first()
		).toBeVisible();
		await expect( page.getByText( 'E2E-SPRING' ).first() ).toBeVisible();
		await removeCoupons( requestUtils );
	} );

	test( 'adds a price rule for more items on the Price rules screen', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await admin.visitAdminPage(
			'admin.php',
			'page=orillacart-price-rules'
		);
		await page.getByRole( 'button', { name: 'Add a rule' } ).click();
		await page.getByLabel( 'Name', { exact: true } ).fill( 'E2E bulk' );
		await page.getByLabel( 'From items' ).first().fill( '10' );
		await page.getByLabel( 'Percentage' ).first().fill( '10' );
		await page.getByRole( 'button', { name: 'Save the rule' } ).click();

		await expect( page.getByText( 'E2E bulk' ).first() ).toBeVisible();
		const rules = await requestUtils.rest< Listed[] >( {
			path: '/orillacart/v1/price-rules',
		} );
		for ( const rule of rules.filter(
			( item ) => item.name === 'E2E bulk'
		) ) {
			await remove(
				requestUtils,
				`/orillacart/v1/price-rules/${ rule.id }`
			);
		}
	} );

	test( 'adds a carrier with a rate on the Shipping screen', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await admin.visitAdminPage( 'admin.php', 'page=orillacart-shipping' );
		const card = page.locator( '.orillacart-card', {
			has: page.getByRole( 'heading', { name: 'Add a carrier' } ),
		} );
		await card.getByLabel( 'Name', { exact: true } ).fill( 'E2E Post' );
		await card.getByRole( 'button', { name: 'Add a rate' } ).click();
		await card.getByLabel( 'Rate name' ).fill( 'Anywhere' );
		await card.getByLabel( 'Price', { exact: true } ).fill( '4' );
		await card.getByRole( 'button', { name: 'Add carrier' } ).click();

		await expect(
			page.getByText( 'Carrier added.' ).first()
		).toBeVisible();
		const carriers = await requestUtils.rest< Listed[] >( {
			path: '/orillacart/v1/carriers',
		} );
		for ( const carrier of carriers.filter(
			( item ) => item.name === 'E2E Post'
		) ) {
			await remove(
				requestUtils,
				`/orillacart/v1/carriers/${ carrier.id }`
			);
		}
	} );

	test( 'limits a payment method to some countries on the Payments screen', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'admin.php', 'page=orillacart-payments' );
		const card = page.locator( '.orillacart-card', {
			has: page.getByRole( 'heading', { name: 'Cash on delivery' } ),
		} );
		await card.getByLabel( 'Billing countries' ).fill( 'bg, ro' );
		await card
			.getByRole( 'button', { name: 'Save Cash on delivery' } )
			.click();
		await expect(
			page.getByText( 'Cash on delivery saved.' ).first()
		).toBeVisible();

		await page.reload();
		await expect(
			page
				.locator( '.orillacart-card', {
					has: page.getByRole( 'heading', {
						name: 'Cash on delivery',
					} ),
				} )
				.getByLabel( 'Billing countries' )
		).toHaveValue( /BG, RO/i );
		await card.getByLabel( 'Billing countries' ).fill( '' );
		await card
			.getByRole( 'button', { name: 'Save Cash on delivery' } )
			.click();
		await expect(
			page.getByText( 'Cash on delivery saved.' ).first()
		).toBeVisible();
	} );

	test( 'adds a tax group and a rate in it on the Tax screen', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await admin.visitAdminPage( 'admin.php', 'page=orillacart-tax' );
		await page.getByLabel( 'New group' ).fill( 'E2E Standard' );
		await page.getByRole( 'button', { name: 'Add group' } ).click();
		try {
			await page
				.getByRole( 'button', { name: 'Add rate' } )
				.first()
				.click();
			const dialog = page.getByRole( 'dialog' );
			await dialog.getByLabel( 'Country' ).selectOption( 'RO' );
			await dialog.getByLabel( 'Rate (%)' ).fill( '19' );
			await dialog.getByLabel( 'Name' ).fill( 'E2E VAT' );
			await dialog.getByRole( 'button', { name: 'Save rate' } ).click();

			const row = page.getByRole( 'row', { name: /E2E VAT/ } );
			await expect( row ).toBeVisible();
			await expect( row ).toContainText( '19' );
		} finally {
			const groups = await requestUtils.rest< Listed[] >( {
				path: '/orillacart/v1/tax/groups',
			} );
			for ( const group of groups.filter(
				( item ) => item.name === 'E2E Standard'
			) ) {
				await remove(
					requestUtils,
					`/orillacart/v1/tax/groups/${ group.id }`
				);
			}
		}
	} );

	test( 'finds a customer on the Customers screen and puts them in a group', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const email = `e2e-customer-${ Date.now() }@example.com`;
		const user = await requestUtils.createUser( {
			username: email.replace( /@.*/, '' ),
			email,
			firstName: BILLING.first_name,
			lastName: BILLING.last_name,
			password: 'e2e-password-123',
			roles: [ 'orillacart_customer' ],
		} );
		try {
			await admin.visitAdminPage(
				'admin.php',
				'page=orillacart-customers'
			);
			await page.getByLabel( 'New group' ).fill( 'E2E Trade' );
			await page.getByRole( 'button', { name: 'Add the group' } ).click();
			await expect( page.getByText( 'E2E Trade' ).first() ).toBeVisible();
			await page.getByRole( 'searchbox' ).fill( email );
			await expect( page.getByText( email ) ).toBeVisible();
		} finally {
			await requestUtils.rest( {
				method: 'DELETE',
				path: `/wp/v2/users/${ user.id }`,
				params: { force: true, reassign: 1 },
			} );
			const groups = await requestUtils.rest< Listed[] >( {
				path: '/orillacart/v1/customer-groups',
			} );
			for ( const group of groups.filter(
				( item ) => item.name === 'E2E Trade'
			) ) {
				await remove(
					requestUtils,
					`/orillacart/v1/customer-groups/${ group.id }`
				);
			}
		}
	} );

	test( 'reads the sales and the products on the Reports screen', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'admin.php', 'page=orillacart-reports' );
		await expect( page.getByText( 'Net sales' ).first() ).toBeVisible();
		await page.getByRole( 'tab', { name: 'Products' } ).click();
		await expect(
			page.getByRole( 'tab', { name: 'Products' } )
		).toHaveAttribute( 'aria-selected', 'true' );
	} );

	test( 'writes, previews and resets an email template on the Emails screen', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		await remove(
			requestUtils,
			'/orillacart/v1/email-templates/account-welcome'
		);
		await admin.visitAdminPage( 'admin.php', 'page=orillacart-emails' );
		await page
			.getByRole( 'row', { name: /Welcome to a new account/ } )
			.getByRole( 'button', { name: 'Edit' } )
			.click();
		const source = page.getByLabel( 'Template (Twig)' );
		await expect( source ).toHaveValue( /Your account is ready/ );
		await source.fill(
			"{% extends 'emails/layout.html.twig' %}{% block subject %}Hello {{ first_name }}{% endblock %}{% block content %}<p>Shop with us</p>{% endblock %}"
		);
		await page.getByRole( 'button', { name: 'Preview' } ).click();
		await expect(
			page.getByRole( 'heading', { name: /Hello Ada/ } )
		).toBeVisible();
		await expect(
			page
				.frameLocator( 'iframe[title="Preview"]' )
				.getByText( 'Shop with us' )
		).toBeVisible();

		await page.getByRole( 'button', { name: 'Save', exact: true } ).click();
		await expect(
			page.getByText( 'Template saved.' ).first()
		).toBeVisible();
		await page.getByRole( 'button', { name: 'Reset to the file' } ).click();
		await page
			.getByRole( 'dialog' )
			.getByRole( 'button', { name: 'Reset' } )
			.click();
		await expect(
			page.getByText( 'Back to the file’s template.' ).first()
		).toBeVisible();
		await expect( source ).toHaveValue( /Your account is ready/ );
	} );
} );
