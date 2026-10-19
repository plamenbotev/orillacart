/**
 * Sets up a small shop through the REST API, as the admin, for the end-to-end tests, and takes it down again.
 */
/// <reference types="node" />
import { execSync } from 'child_process';
import type { RequestUtils } from '@wordpress/e2e-test-utils-playwright';

export type ShopPages = {
	shop: number | null;
	cart: number | null;
	checkout: number | null;
	account: number | null;
};

export type Shop = {
	lamp: number;
	miniCartUrl: string;
	hoodie: number;
	rate: number;
	urls: { shop: string; cart: string; checkout: string; account: string };
	lampUrl: string;
	hoodieUrl: string;
};

const BILLING = {
	first_name: 'Ada',
	last_name: 'Lovelace',
	street: '1 Vitosha Blvd',
	city: 'Sofia',
	postcode: '1000',
	phone: '+359 2 000 0000',
};
export { BILLING };

/**
 * DELETE through the REST API. The shop's routes answer 204 with no body, which the
 * utilities' rest() cannot read as JSON.
 *
 * @param requestUtils The admin's API access.
 * @param path         The route, below /wp-json.
 */
async function remove(
	requestUtils: RequestUtils,
	path: string
): Promise< void > {
	if ( ! requestUtils.storageState?.nonce ) {
		await requestUtils.setupRest();
	}
	const response = await requestUtils.request.fetch(
		`${ requestUtils.storageState?.rootURL ?? '' }${ path.replace( /^\//, '' ) }`,
		{
			method: 'DELETE',
			headers: { 'X-WP-Nonce': requestUtils.storageState?.nonce ?? '' },
		}
	);
	if ( ! response.ok() ) {
		throw new Error(
			`DELETE ${ path }: ${ response.status() } ${ await response.text() }`
		);
	}
}

/**
 * The shop's pages, made again when an earlier run removed one: each a page holding its block.
 *
 * @param requestUtils The admin's API access.
 */
async function ensurePages(
	requestUtils: RequestUtils
): Promise< Record< keyof ShopPages, number > > {
	const pages = await requestUtils.rest< ShopPages >( {
		path: '/orillacart/v1/shop-pages',
	} );
	const blocks = {
		shop: 'products',
		cart: 'cart',
		checkout: 'checkout',
		account: 'account',
	} as const;
	const ids = {} as Record< keyof ShopPages, number >;
	for ( const part of Object.keys( blocks ) as ( keyof ShopPages )[] ) {
		const id = pages[ part ];
		const exists =
			id !== null &&
			( await requestUtils
				.rest< { status: string } >( { path: `/wp/v2/pages/${ id }` } )
				.then( ( page ) => page.status === 'publish' )
				.catch( () => false ) );
		ids[ part ] =
			exists && id !== null
				? id
				: (
						await requestUtils.rest< { id: number } >( {
							method: 'POST',
							path: '/wp/v2/pages',
							data: {
								title: `Shop ${ part }`,
								status: 'publish',
								content: `<!-- wp:orillacart/${ blocks[ part ] } {"align":"wide"} /-->`,
							},
						} )
					).id;
	}
	await requestUtils.rest( {
		method: 'PUT',
		path: '/orillacart/v1/shop-pages',
		data: ids,
	} );
	return ids;
}

async function product(
	requestUtils: RequestUtils,
	title: string,
	details: Record< string, unknown >
): Promise< { id: number; link: string; saved: Record< string, unknown > } > {
	const post = await requestUtils.rest< { id: number; link: string } >( {
		method: 'POST',
		path: '/wp/v2/orillacart_product',
		data: {
			title,
			status: 'publish',
			content: `<p>About the ${ title }.</p>`,
		},
	} );
	const saved = await requestUtils.rest< Record< string, unknown > >( {
		method: 'PUT',
		path: `/orillacart/v1/products/${ post.id }`,
		data: details,
	} );
	return { id: post.id, link: post.link, saved };
}

export async function setUpShop( requestUtils: RequestUtils ): Promise< Shop > {
	await tearDownShop( requestUtils );

	const lamp = await product( requestUtils, 'E2E Lamp', {
		price: '40',
		sale: { price: '30' },
		stock_mode: 'always_available',
		weight: '1',
	} );
	const hoodie = await product( requestUtils, 'E2E Hoodie', {
		price: '50',
		stock_mode: 'always_available',
		attributes: [
			{ name: 'Size', options: [ { name: 'S' }, { name: 'M' } ] },
		],
	} );
	const attributes = hoodie.saved.attributes as {
		options: { id: number }[];
	}[];
	const [ small, medium ] = attributes[ 0 ]?.options ?? [];
	await requestUtils.rest( {
		method: 'POST',
		path: `/orillacart/v1/products/${ hoodie.id }/variations`,
		data: {
			option_ids: [ small?.id ],
			price: '48',
			stock_mode: 'always_available',
		},
	} );
	await requestUtils.rest( {
		method: 'POST',
		path: `/orillacart/v1/products/${ hoodie.id }/variations`,
		data: {
			option_ids: [ medium?.id ],
			price: '52',
			stock_mode: 'always_available',
		},
	} );

	const carrier = await requestUtils.rest< { rates: { id: number }[] } >( {
		method: 'POST',
		path: '/orillacart/v1/carriers',
		data: {
			name: 'E2E Courier',
			rates: [ { name: 'Bulgaria', price: '5', regions: [ 'BG' ] } ],
		},
	} );
	await requestUtils.rest( {
		method: 'PUT',
		path: '/orillacart/v1/payment-methods/bank_transfer',
		data: {
			name: 'Bank transfer',
			active: true,
			receipt_note: 'Pay to IBAN BG00 E2E',
		},
	} );
	await requestUtils.rest( {
		method: 'PUT',
		path: '/orillacart/v1/customer-settings',
		data: { accounts: 'optional' },
	} );
	await shopIn( requestUtils, 'BG' );

	const pages = await ensurePages( requestUtils );
	const miniCart = await requestUtils.rest< { link: string } >( {
		method: 'POST',
		path: '/wp/v2/pages',
		data: {
			title: 'E2E Mini cart',
			status: 'publish',
			content:
				'<!-- wp:orillacart/mini-cart {"showButtons":true} /--><!-- wp:orillacart/products /-->',
		},
	} );
	// The test site's Apache cannot answer before work after the response, so the queue waits for cron.
	runJobs();
	const link = async ( id: number ) =>
		(
			await requestUtils.rest< { link: string } >( {
				path: `/wp/v2/pages/${ id }`,
			} )
		).link;

	return {
		lamp: lamp.id,
		miniCartUrl: miniCart.link,
		hoodie: hoodie.id,
		rate: carrier.rates[ 0 ]?.id ?? 0,
		lampUrl: lamp.link,
		hoodieUrl: hoodie.link,
		urls: {
			shop: await link( pages.shop ),
			cart: await link( pages.cart ),
			checkout: await link( pages.checkout ),
			account: await link( pages.account ),
		},
	};
}

/**
 * Runs the jobs the shop queued, such as the search index, as WP-Cron would within a minute.
 */
export function runJobs(): void {
	execSync( 'npx wp-env run tests-cli wp orillacart jobs work', {
		stdio: 'ignore',
	} );
}

/**
 * Puts the shop in a country, or in none: the cart estimates tax and shipping there.
 * @param requestUtils
 * @param country      ISO 3166-1 code, or null.
 */
async function shopIn(
	requestUtils: RequestUtils,
	country: string | null
): Promise< void > {
	const {
		weight_units: weightUnits,
		dimension_units: dimensionUnits,
		currency_positions: currencyPositions,
		price_decimals: decimals,
		currency_decimals: currencyDecimals,
		...settings
	} = await requestUtils.rest< Record< string, unknown > >( {
		path: '/orillacart/v1/settings',
	} );
	await requestUtils.rest( {
		method: 'PUT',
		path: '/orillacart/v1/settings',
		data: { ...settings, shop_country: country, shop_state: null },
	} );
}

/**
 * Removes what setUpShop made, and the orders and customers the tests made.
 * @param requestUtils
 */
export async function tearDownShop(
	requestUtils: RequestUtils
): Promise< void > {
	await shopIn( requestUtils, null );
	const products = await requestUtils.rest< { id: number }[] >( {
		path: '/wp/v2/orillacart_product?search=E2E&status=any&per_page=100&_fields=id',
	} );
	for ( const { id } of products ) {
		await requestUtils.rest( {
			method: 'DELETE',
			path: `/wp/v2/orillacart_product/${ id }`,
			params: { force: true },
		} );
	}
	const pages = await requestUtils.rest< { id: number }[] >( {
		path: '/wp/v2/pages?search=E2E&status=any&per_page=100&_fields=id',
	} );
	for ( const { id } of pages ) {
		await requestUtils.rest( {
			method: 'DELETE',
			path: `/wp/v2/pages/${ id }`,
			params: { force: true },
		} );
	}
	const carriers = await requestUtils.rest< { id: number; name: string }[] >(
		{
			path: '/orillacart/v1/carriers',
		}
	);
	for ( const carrier of carriers.filter( ( item ) =>
		item.name.startsWith( 'E2E' )
	) ) {
		await remove( requestUtils, `/orillacart/v1/carriers/${ carrier.id }` );
	}
	const users = await requestUtils.rest< { id: number }[] >( {
		path: '/wp/v2/users?search=e2e-&context=edit&per_page=100&_fields=id',
	} );
	for ( const { id } of users ) {
		await requestUtils.rest( {
			method: 'DELETE',
			path: `/wp/v2/users/${ id }`,
			params: { force: true, reassign: 1 },
		} );
	}
}
