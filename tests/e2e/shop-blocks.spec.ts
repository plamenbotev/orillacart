/**
 * The shop-wide blocks as a visitor meets them: the store notice they may close, how far their cart is from free
 * shipping, and what it still needs for a promotion or a lower price by quantity, on a page a cache could keep.
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';

import { remove, setUpShop, tearDownShop, type Shop } from './shop';

let shop: Shop;

test.beforeAll( async ( { requestUtils } ) => {
	shop = await setUpShop( requestUtils );
} );

test.afterAll( async ( { requestUtils } ) => {
	await tearDownShop( requestUtils );
} );

// Visitors: no admin login.
test.use( { storageState: { cookies: [], origins: [] } } );

test.describe( 'A visitor', () => {
	test( 'reads the store notice until they close it, and sees how much more ships their cart free', async ( {
		page,
		requestUtils,
	} ) => {
		const settings = await requestUtils.rest< Record< string, unknown > >( {
			path: '/orillacart/v1/storefront-settings',
		} );
		const carrier = await requestUtils.rest< { id: number } >( {
			method: 'POST',
			path: '/orillacart/v1/carriers',
			data: {
				name: 'E2E Free',
				rates: [
					{
						name: 'Free over 1000',
						price: '0',
						regions: [ 'BG' ],
						subtotal: { min: '1000' },
					},
				],
			},
		} );
		const blocks = await requestUtils.rest< { id: number; link: string } >(
			{
				method: 'POST',
				path: '/wp/v2/pages',
				data: {
					title: 'E2E Shop blocks',
					status: 'publish',
					content:
						'<!-- wp:orillacart/store-notice /--><!-- wp:orillacart/free-shipping-progress /-->',
				},
			}
		);
		try {
			await requestUtils.rest( {
				method: 'PUT',
				path: '/orillacart/v1/storefront-settings',
				data: {
					...settings,
					notice: true,
					notice_text: 'E2E free delivery this week',
					notice_link: '',
					notice_link_label: '',
					notice_dismissible: true,
				},
			} );

			await page.goto( blocks.link );
			const notice = page.getByRole( 'region', { name: 'Store notice' } );
			await expect( notice ).toContainText(
				'E2E free delivery this week'
			);
			// An empty cart has nothing to say.
			await expect( page.getByRole( 'progressbar' ) ).toHaveCount( 0 );

			await notice
				.getByRole( 'button', { name: 'Dismiss this notice' } )
				.click();
			await expect( notice ).toBeHidden();
			await page.reload();
			await expect(
				page.getByText( 'E2E free delivery this week' )
			).toBeHidden();

			await page.goto( shop.lampUrl );
			await page.getByRole( 'button', { name: 'Add to cart' } ).click();
			await expect( page.getByRole( 'status' ).first() ).toHaveText(
				/Added/
			);
			await page.goto( blocks.link );
			await expect(
				page.getByText( /more for free shipping\./ )
			).toBeVisible();
			await expect(
				page.getByRole( 'progressbar', {
					name: 'Towards free shipping',
				} )
			).toHaveAttribute( 'aria-valuemax', /^1000/ );
		} finally {
			await requestUtils.rest( {
				method: 'PUT',
				path: '/orillacart/v1/storefront-settings',
				data: settings,
			} );
			await remove(
				requestUtils,
				`/orillacart/v1/carriers/${ carrier.id }`
			);
			await remove(
				requestUtils,
				`/wp/v2/pages/${ blocks.id }?force=true`
			);
		}
	} );

	test( 'sees what their cart still needs for buy 2, get 1 free, and then that it applies', async ( {
		page,
		requestUtils,
	} ) => {
		const coupon = await requestUtils.rest< { id: number } >( {
			method: 'POST',
			path: '/orillacart/v1/coupons',
			data: {
				code: '',
				kind: 'buy_get',
				amount: '100',
				description: 'E2E lamps: buy 2, get 1 free',
				automatic: true,
				combines: true,
				buy_quantity: 2,
				get_quantity: 1,
				product_ids: [ shop.lamp ],
			},
		} );
		const blocks = await requestUtils.rest< { id: number; link: string } >(
			{
				method: 'POST',
				path: '/wp/v2/pages',
				data: {
					title: 'E2E Promotions',
					status: 'publish',
					content: `<!-- wp:orillacart/promotion-progress /--><!-- wp:orillacart/add-to-cart {"productId":${ shop.lamp }} /-->`,
				},
			}
		);
		try {
			await page.goto( shop.lampUrl );
			// The product page tells of it, in words.
			await expect(
				page.getByText( 'Buy 2, get 1 free', { exact: true } )
			).toBeVisible();

			await page.goto( blocks.link );
			// An empty cart has nothing to say.
			await expect(
				page.getByRole( 'progressbar', { name: /Towards/ } )
			).toHaveCount( 0 );
			const addToCart = page.getByRole( 'button', {
				name: 'Add to cart',
			} );
			await page.getByLabel( 'Quantity' ).fill( '2' );
			await addToCart.click();
			await expect( page.getByRole( 'status' ).first() ).toHaveText(
				/Added/
			);
			// Drawn again as the cart changed, on the same page.
			await expect(
				page.getByText( 'Add 1 more E2E Lamp to get 1 free.' )
			).toBeVisible();
			await expect(
				page.getByRole( 'progressbar', {
					name: 'Towards E2E lamps: buy 2, get 1 free',
				} )
			).toHaveAttribute( 'aria-valuetext', '2 of 3 items' );

			await page.getByLabel( 'Quantity' ).fill( '1' );
			await addToCart.click();
			await expect(
				page.getByText(
					/E2E lamps: buy 2, get 1 free — applied, you save/
				)
			).toBeVisible();
			await expect(
				page.getByRole( 'progressbar', { name: /Towards/ } )
			).toHaveCount( 0 );

			// A page a cache keeps is filled in by the script, from the shopper's own cart.
			await page.reload();
			await expect( page.getByText( /applied, you save/ ) ).toBeVisible();
		} finally {
			await remove(
				requestUtils,
				`/orillacart/v1/coupons/${ coupon.id }`
			);
			await remove(
				requestUtils,
				`/wp/v2/pages/${ blocks.id }?force=true`
			);
		}
	} );

	test( 'sees how many more lamps pay a lower price each, until they have them', async ( {
		page,
		requestUtils,
	} ) => {
		const rule = await requestUtils.rest< { id: number } >( {
			method: 'POST',
			path: '/orillacart/v1/price-rules',
			data: {
				name: 'E2E lamps by two',
				tiers: [
					{ min_quantity: 2, kind: 'fixed_price', value: '25' },
				],
				product_ids: [ shop.lamp ],
				with_sales: true,
			},
		} );
		const blocks = await requestUtils.rest< { id: number; link: string } >(
			{
				method: 'POST',
				path: '/wp/v2/pages',
				data: {
					title: 'E2E Quantity tiers',
					status: 'publish',
					content: `<!-- wp:orillacart/promotion-progress /--><!-- wp:orillacart/add-to-cart {"productId":${ shop.lamp }} /-->`,
				},
			}
		);
		try {
			await page.goto( blocks.link );
			const addToCart = page.getByRole( 'button', {
				name: 'Add to cart',
			} );
			await addToCart.click();
			await expect(
				page.getByText( /Add 1 more E2E Lamp to pay .+ each\./ )
			).toBeVisible();
			await expect(
				page.getByRole( 'progressbar', {
					name: /Towards Buy 2 or more: .+ each/,
				} )
			).toHaveAttribute( 'aria-valuetext', '1 of 2 items' );

			await addToCart.click();
			await expect( page.getByText( /to pay .+ each\./ ) ).toHaveCount(
				0
			);
		} finally {
			await remove(
				requestUtils,
				`/orillacart/v1/price-rules/${ rule.id }`
			);
			await remove(
				requestUtils,
				`/wp/v2/pages/${ blocks.id }?force=true`
			);
		}
	} );
} );
