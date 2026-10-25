/**
 * The setup, as a new shop's owner goes through it: a German shop selling to consumers in the EU, set up by the
 * wizard, opened, and a visitor's order taxed, shipped and paid as the plan said. Everything the setup made is
 * taken away again after.
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';
import type { RequestUtils } from '@wordpress/e2e-test-utils-playwright';

import { remove, setUpShop, type Shop, tearDownShop } from './shop';

let shop: Shop;

/** What the shop had before the setup, to put back after it. */
interface Before {
	archive: { settings: Record< string, unknown > };
	tax: Record< string, unknown >;
	groups: number[];
	carriers: number[];
	pages: number[];
	methods: Record< string, unknown >[];
}
let before: Before;

async function ids( requestUtils: RequestUtils, path: string ) {
	const rows = await requestUtils.rest< { id: number }[] >( { path } );
	return rows.map( ( row ) => row.id );
}

/**
 * What an earlier run of this test that failed may have left: the setup makes these by name.
 * @param requestUtils
 */
async function removeLeftovers( requestUtils: RequestUtils ) {
	const tax = await requestUtils.rest< Record< string, unknown > >( {
		path: '/orillacart/v1/tax/settings',
	} );
	const { bases: _bases, providers: _providers, ...settings } = tax;
	await requestUtils.rest( {
		method: 'PUT',
		path: '/orillacart/v1/tax/settings',
		data: {
			...settings,
			default_group_id: null,
			basis: 'shipping_address',
		},
	} );
	const groups = await requestUtils.rest< { id: number; name: string }[] >( {
		path: '/orillacart/v1/tax/groups',
	} );
	for ( const group of groups.filter( ( { name } ) =>
		[
			'Standard rate',
			'Reduced rate',
			'Second reduced rate',
			'Super-reduced rate',
			'Zero rate',
		].includes( name )
	) ) {
		await remove( requestUtils, `/orillacart/v1/tax/groups/${ group.id }` );
	}
	const carriers = await requestUtils.rest< { id: number; name: string }[] >(
		{ path: '/orillacart/v1/carriers' }
	);
	for ( const carrier of carriers.filter( ( { name } ) =>
		[ 'Standard shipping', 'Our shop' ].includes( name )
	) ) {
		await remove( requestUtils, `/orillacart/v1/carriers/${ carrier.id }` );
	}
	const pages = await requestUtils.rest<
		{ id: number; title: { rendered: string } }[]
	>( { path: '/wp/v2/pages?status=any&per_page=100&_fields=id,title' } );
	for ( const page of pages.filter( ( { title } ) =>
		[
			'Terms of sale',
			'Right of withdrawal',
			'Shipping and payment',
			'Impressum',
			'Legal notice',
		].includes( title.rendered )
	) ) {
		await requestUtils.rest( {
			method: 'DELETE',
			path: `/wp/v2/pages/${ page.id }`,
			params: { force: true },
		} );
	}
	await requestUtils.rest( {
		method: 'PUT',
		path: '/orillacart/v1/opening',
	} );
}

test.beforeAll( async ( { requestUtils } ) => {
	shop = await setUpShop( requestUtils );
	await removeLeftovers( requestUtils );
	before = {
		archive: await requestUtils.rest( {
			path: '/orillacart/v1/settings-archive',
		} ),
		tax: await requestUtils.rest( { path: '/orillacart/v1/tax/settings' } ),
		groups: await ids( requestUtils, '/orillacart/v1/tax/groups' ),
		carriers: await ids( requestUtils, '/orillacart/v1/carriers' ),
		pages: await ids(
			requestUtils,
			'/wp/v2/pages?status=any&per_page=100&_fields=id'
		),
		methods: await requestUtils.rest( {
			path: '/orillacart/v1/payment-methods',
		} ),
	};
} );

test.afterAll( async ( { requestUtils } ) => {
	const restored = await requestUtils.rest< {
		refused: Record< string, string >;
	} >( {
		method: 'PUT',
		path: '/orillacart/v1/settings-archive',
		data: before.archive,
	} );
	expect( Object.keys( restored.refused ) ).toEqual( [] );
	// The groups the setup made are not the default any more, so they can go.
	const { bases: _bases, providers: _providers, ...tax } = before.tax;
	await requestUtils.rest( {
		method: 'PUT',
		path: '/orillacart/v1/tax/settings',
		data: tax,
	} );
	await requestUtils.rest( {
		method: 'PUT',
		path: '/orillacart/v1/legal-settings',
		data: { accept_terms: false, order_button: 'place_order' },
	} );
	await requestUtils.rest( {
		method: 'PUT',
		path: '/orillacart/v1/opening',
	} );
	for ( const method of before.methods ) {
		await requestUtils.rest( {
			method: 'PUT',
			path: `/orillacart/v1/payment-methods/${ method.gateway }`,
			data: {
				name: method.name,
				active: method.active,
				position: method.position,
				countries: method.countries,
				instructions: method.instructions,
				receipt_note: method.receipt_note,
				fee: method.fee,
				fee_percent: method.fee_percent,
				fee_label: method.fee_label,
			},
		} );
	}
	for ( const id of (
		await ids( requestUtils, '/orillacart/v1/carriers' )
	).filter( ( carrier ) => ! before.carriers.includes( carrier ) ) ) {
		await remove( requestUtils, `/orillacart/v1/carriers/${ id }` );
	}
	for ( const id of (
		await ids( requestUtils, '/orillacart/v1/tax/groups' )
	).filter( ( group ) => ! before.groups.includes( group ) ) ) {
		await remove( requestUtils, `/orillacart/v1/tax/groups/${ id }` );
	}
	for ( const id of (
		await ids(
			requestUtils,
			'/wp/v2/pages?status=any&per_page=100&_fields=id'
		)
	).filter( ( page ) => ! before.pages.includes( page ) ) ) {
		await requestUtils.rest( {
			method: 'DELETE',
			path: `/wp/v2/pages/${ id }`,
			params: { force: true },
		} );
	}
	await tearDownShop( requestUtils );
} );

test( 'sets up a German shop for consumers in the EU, opens it, and a visitor buys from it', async ( {
	admin,
	page,
	browser,
	requestUtils,
} ) => {
	test.slow();
	await admin.visitAdminPage( 'admin.php', 'page=orillacart-setup' );

	// Your business
	await page.getByRole( 'button', { name: 'Your business' } ).click();
	await page
		.getByLabel( 'Legal name of the business' )
		.fill( 'E2E Acme GmbH' );
	await page.getByLabel( 'Street and number' ).fill( 'Hauptstraße 1' );
	await page.getByLabel( 'Postcode' ).fill( '80331' );
	await page.getByLabel( 'City' ).fill( 'München' );
	await page.getByLabel( 'Country the shop sells from' ).selectOption( 'DE' );
	await expect( page.getByLabel( 'Currency' ) ).toHaveValue( 'EUR' );
	await page.getByLabel( 'Email for customers' ).fill( 'shop@acme.test' );
	await page.getByRole( 'button', { name: 'Save and go on' } ).click();

	// Customers
	await expect(
		page.getByRole( 'heading', { name: 'Customers' } )
	).toBeVisible();
	await page.getByRole( 'radio', { name: 'Both' } ).check();
	await page.getByRole( 'radio', { name: 'In the EU' } ).check();
	await page.getByRole( 'button', { name: 'Save and go on' } ).click();

	// Tax: registered for VAT, below the EU's threshold.
	await expect(
		page.getByText( 'MwSt. here: 19% standard, 7% reduced' )
	).toBeVisible();
	await page.getByRole( 'button', { name: 'Save and go on' } ).click();

	// Shipping: 4.90 at home, free from 50; 12.90 in the rest of the EU.
	await expect( page.getByText( 'The rest of the EU' ) ).toBeVisible();
	await page.getByRole( 'button', { name: 'Save and go on' } ).click();

	// Payments
	await page.getByLabel( 'IBAN' ).fill( 'DE89 3704 0044 0532 0130 00' );
	await page.getByRole( 'button', { name: 'Save and go on' } ).click();

	// Legal, emails
	await expect(
		page.getByText(
			/Consumers may withdraw from a purchase within 14 days/
		)
	).toBeVisible();
	await page.getByRole( 'button', { name: 'Save and go on' } ).click();
	await expect( page.getByLabel( 'Their address' ) ).toHaveValue(
		'shop@acme.test'
	);
	await page.getByRole( 'button', { name: 'Save and go on' } ).click();

	// Products: the shop's test products are there.
	await page.getByRole( 'button', { name: 'Go on' } ).click();

	// Review: nothing saved until applied. The test site was set up before, so "Opening soon" starts unticked.
	const soon = page.getByRole( 'checkbox', { name: /Opening soon/ } );
	await soon.check();
	await expect( soon ).toBeChecked();
	await expect(
		page.getByText( 'A tax group "Standard rate" at 19% (MwSt.).' )
	).toBeVisible();
	await expect(
		page.getByText(
			'Checkout asks customers to accept the terms of sale, and its button says "Buy now".'
		)
	).toBeVisible();
	await page.getByRole( 'button', { name: 'Apply the setup' } ).click();

	// Open: the drafts stop it until they are published.
	await expect(
		page.getByRole( 'heading', { name: 'Before the shop can sell' } )
	).toBeVisible();
	await expect(
		page.getByText( 'The terms of sale are not published.' )
	).toBeVisible();
	await expect(
		page.getByRole( 'button', { name: 'Open the shop' } )
	).toBeDisabled();

	// A visitor sees "Opening soon" meanwhile.
	const visitor = await browser.newContext( {
		storageState: { cookies: [], origins: [] },
	} );
	const visit = await visitor.newPage();
	const closed = await visit.goto( shop.urls.shop );
	expect( closed?.status() ).toBe( 503 );
	await expect(
		visit.getByRole( 'heading', { name: 'Opening soon' } )
	).toBeVisible();

	const legal = await requestUtils.rest< Record< string, number | null > >( {
		path: '/orillacart/v1/legal-settings',
	} );
	for ( const part of [ 'terms', 'withdrawal' ] ) {
		await requestUtils.rest( {
			method: 'POST',
			path: `/wp/v2/pages/${ legal[ part ] }`,
			data: { status: 'publish' },
		} );
	}
	await page.getByRole( 'button', { name: 'Check again' } ).click();
	await expect(
		page.locator( '.components-notice__content', {
			hasText: 'Nothing stops the shop from selling.',
		} )
	).toBeVisible();
	await page.getByRole( 'button', { name: 'Open the shop' } ).click();
	await expect(
		page.locator( '.components-notice__content', {
			hasText: 'The shop is open.',
		} )
	).toBeVisible();

	// The visitor buys: prices with MwSt., shipping at home, the terms accepted, "Buy now".
	await visit.goto( shop.urls.shop );
	const card = visit.locator( '.orillacart-product-card', {
		hasText: 'E2E Lamp',
	} );
	await card.getByRole( 'button', { name: /Add to cart/ } ).click();
	await expect( card.getByRole( 'status' ) ).toContainText( 'Added' );
	await visit.goto( shop.urls.checkout );
	await visit.getByLabel( 'Email' ).fill( 'e2e-visitor@example.com' );
	await visit.locator( '#billing-first_name' ).fill( 'Ada' );
	await visit.locator( '#billing-last_name' ).fill( 'Lovelace' );
	await visit.locator( '#billing-street' ).fill( 'Marienplatz 2' );
	await visit.locator( '#billing-city' ).fill( 'München' );
	await visit.locator( '#billing-postcode' ).fill( '80331' );
	await visit.locator( '#billing-phone' ).fill( '+49 89 000000' );
	await visit.locator( '#billing-country' ).selectOption( 'DE' );
	await expect( visit.locator( '.orillacart-total' ) ).toContainText(
		'34.90'
	);
	await expect(
		visit.locator( '.orillacart-checkout__summary' )
	).toContainText( 'MwSt.' );
	await visit.getByRole( 'radio', { name: 'Bank transfer' } ).check();
	const buy = visit.getByRole( 'button', { name: 'Buy now' } );
	await expect( buy ).toBeVisible();
	await expect(
		visit
			.locator( '.orillacart-checkout__withdrawal' )
			.getByRole( 'link', { name: 'right of withdrawal' } )
	).toBeVisible();
	await visit.getByLabel( /I accept the/ ).check();
	await buy.click();

	await expect(
		visit.getByRole( 'heading', { name: 'Thank you for your order' } )
	).toBeVisible();
	await expect(
		visit.getByText( /Pay to E2E Acme GmbH, IBAN DE89/ )
	).toBeVisible();
	await visitor.close();
} );
