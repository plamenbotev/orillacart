#!/usr/bin/env node
/**
 * Takes readme.txt's screenshots from the wp-env development site, into .wordpress-org/ (the plugin directory's
 * assets): the shop, a product, the cart, the orders screen and the reports. Fill the site first:
 *
 *   npx wp-env run cli --env-cwd=wp-content/plugins/orillacart wp eval-file bin/demo-shop.php
 *   npx wp-env run cli wp orillacart jobs work
 *   node bin/screenshots.mjs
 */
import { mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from '@playwright/test';

const SITE = process.env.WP_BASE_URL ?? 'http://localhost:8888';
const OUT = join( dirname( fileURLToPath( import.meta.url ) ), '..', '.wordpress-org' );
mkdirSync( OUT, { recursive: true } );

const browser = await chromium.launch();
const shot = async ( page, number ) =>
	page.screenshot( { path: join( OUT, `screenshot-${ number }.png` ) } );

try {
	const visitor = await browser.newPage( { viewport: { width: 1280, height: 900 } } );
	await visitor.goto( `${ SITE }/shop/` );
	await visitor.locator( '.orillacart-product-card' ).first().waitFor();
	await shot( visitor, 1 );

	await visitor.locator( '.orillacart-product-card a' ).first().click();
	await visitor.locator( '.orillacart-add-to-cart' ).waitFor();
	await shot( visitor, 2 );

	await visitor.goto( `${ SITE }/shop/` );
	await visitor.locator( '.orillacart-add-to-cart--card button' ).first().click();
	await visitor.getByRole( 'status' ).first().waitFor();
	await visitor.goto( `${ SITE }/cart/` );
	await visitor.locator( '.orillacart-cart__table' ).waitFor();
	await shot( visitor, 3 );

	const admin = await browser.newPage( { viewport: { width: 1440, height: 900 } } );
	await admin.goto( `${ SITE }/wp-login.php` );
	await admin.fill( '#user_login', process.env.WP_USERNAME ?? 'admin' );
	await admin.fill( '#user_pass', process.env.WP_PASSWORD ?? 'password' );
	await admin.click( '#wp-submit' );
	await admin.waitForURL( /wp-admin/ );

	await admin.goto( `${ SITE }/wp-admin/admin.php?page=orillacart` );
	await admin.locator( '.dataviews-view-table, .dataviews-no-results' ).first().waitFor();
	await shot( admin, 4 );

	await admin.goto( `${ SITE }/wp-admin/admin.php?page=orillacart-reports` );
	await admin.locator( '.orillacart-reports__kpis' ).waitFor();
	await admin.waitForTimeout( 1000 );
	await shot( admin, 5 );
} finally {
	await browser.close();
}
