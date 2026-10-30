/**
 * The shop's other admin screens, each through its main task, as the shop manager works them: what is made here
 * is removed again through the API.
 */
import {
	expect,
	test,
	type RequestUtils,
} from '@wordpress/e2e-test-utils-playwright';

import {
	BILLING,
	product,
	remove,
	runJobs,
	setUpShop,
	tearDownShop,
} from './shop';

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
		await page
			.getByLabel( 'Discount', { exact: true } )
			.selectOption( 'percent' );
		await page.getByLabel( 'Percentage' ).fill( '10' );
		await page.getByRole( 'button', { name: 'Save the coupon' } ).click();

		await expect(
			page.getByText( 'The coupon E2E-SPRING was saved.' ).first()
		).toBeVisible();
		await expect( page.getByText( 'E2E-SPRING' ).first() ).toBeVisible();

		// And changed afterwards: the form sends back only what the API takes.
		await page
			.getByRole( 'button', { name: 'E2E-SPRING' } )
			.first()
			.click();
		await page.getByLabel( 'Percentage' ).fill( '15' );
		await page.getByRole( 'button', { name: 'Save the coupon' } ).click();
		await expect(
			page.getByText( 'The coupon E2E-SPRING was saved.' ).first()
		).toBeVisible();
		await expect(
			page.getByRole( 'cell', { name: '15 %' } )
		).toBeVisible();
		await removeCoupons( requestUtils );
	} );

	test( 'makes a gift card and changes what it has left', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage( 'admin.php', 'page=orillacart-gift-cards' );
		await page.getByLabel( 'Worth' ).fill( '25' );
		await page.getByLabel( 'For (name)' ).fill( 'E2E Ada' );
		await page
			.getByRole( 'button', { name: 'Make the gift card' } )
			.click();

		await expect(
			page.getByText( /Copy the code now/ ).first()
		).toBeVisible();
		await expect( page.locator( '.orillacart-gift-card-code' ) ).toHaveText(
			/^[A-Z2-9]{4}(-[A-Z2-9]{4}){3}$/
		);
		await page.getByLabel( 'Add, or − to take off' ).fill( '-5' );
		await page.getByLabel( 'Why', { exact: true } ).fill( 'E2E spent' );
		await page
			.getByRole( 'button', { name: 'Change the balance' } )
			.click();
		await expect( page.getByText( 'E2E spent' ) ).toBeVisible();
		await expect( page.getByText( /left of/ ) ).toContainText( '20' );
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
		await page.getByRole( 'tab', { name: 'Carts' } ).click();
		await expect(
			page.getByText( 'Carts left at checkout' )
		).toBeVisible();
		await expect( page ).toHaveURL( /report=carts/ );
	} );

	test( 'makes an API key an integration reads the shop with, and revokes it', async ( {
		admin,
		page,
		playwright,
	} ) => {
		await admin.visitAdminPage(
			'admin.php',
			'page=orillacart-integrations'
		);
		await page.getByLabel( 'New key for' ).fill( 'E2E reader' );
		await page.getByRole( 'button', { name: 'Make the key' } ).click();
		const key =
			( await page.locator( '.orillacart-api-key' ).textContent() ) ?? '';
		expect( key ).toMatch( /^oc_[a-f0-9]{40}$/ );

		// Another program: no cookies, no nonce, only the key.
		const api = await playwright.request.newContext( {
			baseURL: new URL( page.url() ).origin,
			extraHTTPHeaders: { Authorization: `Bearer ${ key }` },
		} );
		try {
			const read = await api.get( '/wp-json/orillacart/v1/orders' );
			expect( read.status() ).toBe( 200 );
			const change = await api.put(
				'/wp-json/orillacart/v1/review-settings',
				{
					data: { policy: 'off' },
				}
			);
			expect( ( await change.json() ).code ).toBe(
				'orillacart_read_only_key'
			);

			// This key's row, by its first characters: keys of other runs may be there too.
			const row = page.getByRole( 'row', {
				name: new RegExp( key.slice( 0, 11 ) ),
			} );
			await row.getByRole( 'button', { name: 'Revoke' } ).click();
			await page
				.getByRole( 'dialog' )
				.getByRole( 'button', { name: 'Revoke' } )
				.click();
			await expect( row ).toBeHidden();
			expect(
				( await api.get( '/wp-json/orillacart/v1/orders' ) ).status()
			).toBe( 401 );
		} finally {
			await api.dispose();
		}
	} );

	test( 'adds a webhook, sends it a test and sees the try', async ( {
		admin,
		page,
	} ) => {
		await admin.visitAdminPage(
			'admin.php',
			'page=orillacart-integrations'
		);
		await page.getByRole( 'button', { name: 'Add a webhook' } ).click();
		const name = `E2E hook ${ Date.now() }`;
		await page.getByLabel( 'Name', { exact: true } ).fill( name );
		await page
			.getByLabel( 'Address' )
			.fill( 'http://e2e.example.invalid/hook' );
		await page.getByLabel( /Order placed/ ).check();
		await page.getByRole( 'button', { name: 'Save the webhook' } ).click();
		await expect(
			page.getByText( 'A webhook goes to an https:// address' ).first()
		).toBeVisible();
		await page
			.getByLabel( 'Address' )
			.fill( 'https://e2e.example.invalid/hook' );
		await page.getByRole( 'button', { name: 'Save the webhook' } ).click();
		const row = page.getByRole( 'row', { name: new RegExp( name ) } );
		await expect( row ).toContainText( 'Nothing sent yet' );

		await row.getByRole( 'button', { name: 'Send a test' } ).click();
		await expect(
			page.getByText( 'A test is on its way' ).first()
		).toBeVisible();
		runJobs();
		await row.getByRole( 'button', { name: 'Deliveries' } ).click();
		await expect(
			page.locator( '.orillacart-webhook__deliveries' )
		).toContainText( 'webhook.ping' );
		await expect(
			page.locator( '.orillacart-webhook__deliveries' )
		).toContainText( 'Failed' );

		await row.getByRole( 'button', { name: 'Delete' } ).click();
		await page
			.getByRole( 'dialog' )
			.getByRole( 'button', { name: 'Delete' } )
			.click();
		await expect( row ).toBeHidden();
	} );

	test( 'checks the shop on the Status screen', async ( { admin, page } ) => {
		await admin.visitAdminPage( 'admin.php', 'page=orillacart-status' );
		await expect(
			page.getByText( 'The shop’s tables are up to date' )
		).toBeVisible();
		await expect(
			page.getByRole( 'heading', { name: 'Server' } )
		).toBeVisible();
		await expect(
			page.getByRole( 'button', { name: 'Copy the report for support' } )
		).toBeVisible();
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

	test( 'raises the prices of the products chosen with bulk edit', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const bulk = await product( requestUtils, 'E2E Bulk Mug', {
			price: '20',
		} );
		try {
			await admin.visitAdminPage(
				'edit.php',
				'post_type=orillacart_product&s=E2E+Bulk+Mug'
			);
			await page.locator( `#cb-select-${ bulk.id }` ).check();
			await page
				.locator( '#bulk-action-selector-top' )
				.selectOption( 'edit' );
			await page.locator( '#doaction' ).click();
			const editor = page.locator( '#bulk-edit' );
			await editor
				.locator( 'select[name="orillacart_bulk[price]"]' )
				.selectOption( 'raise_percent' );
			await editor
				.locator( 'input[name="orillacart_bulk[price_by]"]' )
				.fill( '10' );
			await editor.getByRole( 'button', { name: 'Update' } ).click();

			await expect(
				page.locator( `#post-${ bulk.id } .column-orillacart_price` )
			).toContainText( '22' );
		} finally {
			await remove(
				requestUtils,
				`/wp/v2/orillacart_product/${ bulk.id }?force=true`
			);
		}
	} );

	test( 'duplicates a product as a draft from the products list', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const original = await product( requestUtils, 'E2E Copied Mug', {
			price: '12',
			sku: 'E2E-COPIED',
		} );
		let copy = 0;
		try {
			await admin.visitAdminPage(
				'edit.php',
				'post_type=orillacart_product&s=E2E+Copied+Mug'
			);
			await page.locator( `#post-${ original.id }` ).hover();
			await page
				.locator( `#post-${ original.id }` )
				.getByRole( 'link', { name: 'Duplicate' } )
				.click();
			await page.waitForURL( /post\.php\?post=\d+&action=edit/ );
			copy = Number( new URL( page.url() ).searchParams.get( 'post' ) );

			expect( copy ).not.toBe( original.id );
			const saved = await requestUtils.rest< {
				price: string;
				sku: string | null;
			} >( { path: `/orillacart/v1/products/${ copy }` } );
			expect( [ saved.price, saved.sku ] ).toEqual( [ '12', null ] );
			const post = await requestUtils.rest< {
				status: string;
				title: { raw: string };
			} >( {
				path: `/wp/v2/orillacart_product/${ copy }?context=edit`,
			} );
			expect( [ post.status, post.title.raw ] ).toEqual( [
				'draft',
				'E2E Copied Mug (copy)',
			] );
		} finally {
			for ( const id of [ original.id, copy ].filter( Boolean ) ) {
				await remove(
					requestUtils,
					`/wp/v2/orillacart_product/${ id }?force=true`
				);
			}
		}
	} );

	test( 'exports the products file and imports a change from it', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const mug = await product( requestUtils, 'E2E Imported Mug', {
			price: '9',
			sku: 'E2E-IMPORTED',
		} );
		// An import a run before left waiting would stand in for the upload.
		const left = await requestUtils.rest< {
			import: { id: number; status: string } | null;
		} >( { path: '/orillacart/v1/imports/products/latest' } );
		if ( left.import?.status === 'ready' ) {
			await remove(
				requestUtils,
				`/orillacart/v1/imports/products/${ left.import.id }`
			);
		}
		try {
			await admin.visitAdminPage(
				'admin.php',
				'page=orillacart-exchange'
			);
			await page
				.getByRole( 'button', { name: 'Export products' } )
				.click();
			const downloading = page.waitForEvent( 'download' );
			await page
				.getByRole( 'dialog' )
				.getByRole( 'button', { name: 'Export' } )
				.click();
			const download = await downloading;
			expect( download.suggestedFilename() ).toMatch(
				/^products-.*\.csv$/
			);

			await page.locator( 'input[type="file"]' ).setInputFiles( {
				name: 'products.csv',
				mimeType: 'text/csv',
				buffer: Buffer.from( 'sku,price\nE2E-IMPORTED,11.50\n' ),
			} );
			await expect(
				page.getByText( 'products.csv has 1 row. It changes:' )
			).toBeVisible();
			await page
				.getByRole( 'button', { name: 'Start the import' } )
				.click();
			runJobs();
			await expect(
				page
					.getByText(
						'products.csv is imported: 0 added, 1 updated, 0 refused.'
					)
					.first()
			).toBeVisible( { timeout: 30000 } );

			const saved = await requestUtils.rest< { price: string } >( {
				path: `/orillacart/v1/products/${ mug.id }`,
			} );
			expect( saved.price ).toBe( '11.5' );
		} finally {
			await remove(
				requestUtils,
				`/wp/v2/orillacart_product/${ mug.id }?force=true`
			);
		}
	} );

	test( 'keeps the amounts typed on the Stock screen when a sale moved a level, and saves them once asked', async ( {
		admin,
		page,
		requestUtils,
	} ) => {
		const stockroom = await requestUtils.rest< { id: number } >( {
			method: 'POST',
			path: '/orillacart/v1/stockrooms',
			data: { name: 'E2E Counting room' },
		} );
		const jar = await product( requestUtils, 'E2E Jar', { price: '4' } );
		const level = async () =>
			(
				await requestUtils.rest< {
					levels: { stockroom_id: number; quantity: number }[];
				} >( { path: `/orillacart/v1/stock/product/${ jar.id }` } )
			).levels.find( ( item ) => item.stockroom_id === stockroom.id )
				?.quantity;
		try {
			await requestUtils.rest( {
				method: 'PUT',
				path: `/orillacart/v1/stock/product/${ jar.id }`,
				data: {
					levels: [
						{
							stockroom_id: stockroom.id,
							quantity: 5,
							was: ( await level() ) ?? null,
						},
					],
				},
			} );
			await admin.visitAdminPage( 'admin.php', 'page=orillacart-stock' );
			await page
				.getByRole( 'searchbox', { name: 'Search by name, SKU or ID' } )
				.fill( 'E2E Jar' );
			const cell = page.getByRole( 'spinbutton', {
				name: 'E2E Jar in E2E Counting room',
			} );
			await expect( cell ).toHaveValue( '5' );
			await cell.fill( '9' );

			// A sale takes one meanwhile.
			await requestUtils.rest( {
				method: 'PUT',
				path: `/orillacart/v1/stock/product/${ jar.id }`,
				data: {
					levels: [ { stockroom_id: stockroom.id, change: -1 } ],
				},
			} );
			await page.getByRole( 'button', { name: 'Save 1 change' } ).click();
			await expect(
				page
					.getByText(
						'E2E Jar in E2E Counting room now holds 4; you typed 9.'
					)
					.first()
			).toBeVisible();
			await expect( cell ).toHaveValue( '9' );
			expect( await level() ).toBe( 4 );

			await page
				.getByRole( 'button', { name: 'Save what I typed anyway' } )
				.click();
			await expect(
				page.getByText( 'Stock saved.' ).first()
			).toBeVisible();
			expect( await level() ).toBe( 9 );
		} finally {
			await remove(
				requestUtils,
				`/wp/v2/orillacart_product/${ jar.id }?force=true`
			);
			await remove(
				requestUtils,
				`/orillacart/v1/stockrooms/${ stockroom.id }`
			);
		}
	} );
} );
