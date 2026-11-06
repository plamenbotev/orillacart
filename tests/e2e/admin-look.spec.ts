/**
 * Every Shop screen opens and stands on the design system (design 52): the page frame with its title, the menu's
 * group entry highlighted, and no error. A screenshot of each goes with the run's artifacts, to look at a change
 * of the system on every screen at once.
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

const SCREENS: { slug: string; group: string }[] = [
	{ slug: 'orillacart', group: 'Home' },
	{ slug: 'orillacart-orders', group: 'Orders' },
	{ slug: 'orillacart-customers', group: 'Customers' },
	{ slug: 'orillacart-customer-settings', group: 'Customers' },
	{ slug: 'orillacart-stock', group: 'Catalog' },
	{ slug: 'orillacart-stockrooms', group: 'Catalog' },
	{ slug: 'orillacart-attribute-sets', group: 'Catalog' },
	{ slug: 'orillacart-price-rules', group: 'Catalog' },
	{ slug: 'orillacart-exchange', group: 'Catalog' },
	{ slug: 'orillacart-filters', group: 'Catalog' },
	{ slug: 'orillacart-coupons', group: 'Marketing' },
	{ slug: 'orillacart-automatic-discounts', group: 'Marketing' },
	{ slug: 'orillacart-gift-cards', group: 'Marketing' },
	{ slug: 'orillacart-reports', group: 'Reports' },
	{ slug: 'orillacart-settings', group: 'Settings' },
	{ slug: 'orillacart-pages', group: 'Settings' },
	{ slug: 'orillacart-invoices', group: 'Settings' },
	{ slug: 'orillacart-storefront', group: 'Settings' },
	{ slug: 'orillacart-tax', group: 'Settings' },
	{ slug: 'orillacart-shipping', group: 'Settings' },
	{ slug: 'orillacart-payments', group: 'Settings' },
	{ slug: 'orillacart-emails', group: 'Settings' },
	{ slug: 'orillacart-forms', group: 'Settings' },
	{ slug: 'orillacart-integrations', group: 'Settings' },
	{ slug: 'orillacart-advanced', group: 'Settings' },
	{ slug: 'orillacart-status', group: 'System' },
	{ slug: 'orillacart-jobs', group: 'System' },
	{ slug: 'orillacart-setup', group: '' },
];

test.describe( 'The admin screens', () => {
	for ( const { slug, group } of SCREENS ) {
		test( `${ slug } opens in its frame`, async ( {
			admin,
			page,
		}, testInfo ) => {
			await admin.visitAdminPage( 'admin.php', `page=${ slug }` );

			const title = page.locator( '.oc-page__title h1' );
			await expect( title ).toBeVisible();
			// Its content came: a skeleton gives way to the screen.
			await expect( page.locator( '.oc-skeleton' ) ).toHaveCount( 0, {
				timeout: 15000,
			} );
			await expect(
				page.locator( '.components-notice.is-error' )
			).toHaveCount( 0 );
			if ( group !== '' ) {
				await expect(
					page.locator( '#toplevel_page_orillacart li.current' )
				).toHaveText( group );
			}
			await testInfo.attach( slug, {
				body: await page.screenshot( { fullPage: true } ),
				contentType: 'image/png',
			} );
		} );
	}
} );
