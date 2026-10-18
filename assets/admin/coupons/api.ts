import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

export const KINDS = [
	'percent',
	'order_amount',
	'item_amount',
	'free_shipping',
] as const;
export type Kind = ( typeof KINDS )[ number ];

export interface CouponFields {
	code: string;
	kind: Kind;
	amount: string;
	description: string;
	cap: string | null;
	active: boolean;
	starts_at: string | null;
	ends_at: string | null;
	min_subtotal: string | null;
	max_subtotal: string | null;
	min_quantity: number;
	countries: string[];
	product_ids: number[];
	category_ids: number[];
	brand_ids: number[];
	excluded_product_ids: number[];
	excluded_category_ids: number[];
	exclude_sale_items: boolean;
	combines: boolean;
	usage_limit: number | null;
	per_customer_limit: number | null;
	first_order_only: boolean;
}

export interface Coupon extends CouponFields {
	id: number;
	/** Orders that use it, not cancelled or failed. */
	uses: number;
}

export interface CouponPage {
	coupons: Coupon[];
	total: number;
	page: number;
	pages: number;
}

export const NEW_COUPON: CouponFields = {
	code: '',
	kind: 'percent',
	amount: '10',
	description: '',
	cap: null,
	active: true,
	starts_at: null,
	ends_at: null,
	min_subtotal: null,
	max_subtotal: null,
	min_quantity: 0,
	countries: [],
	product_ids: [],
	category_ids: [],
	brand_ids: [],
	excluded_product_ids: [],
	excluded_category_ids: [],
	exclude_sale_items: false,
	combines: false,
	usage_limit: null,
	per_customer_limit: null,
	first_order_only: false,
};

const BASE = '/orillacart/v1';

export function fetchCoupons( query: {
	search?: string | undefined;
	page: number;
	per_page: number;
} ): Promise< CouponPage > {
	return apiFetch< CouponPage >( {
		path: addQueryArgs( `${ BASE }/coupons`, { ...query } ),
	} );
}

export function saveCoupon(
	id: number | null,
	coupon: CouponFields
): Promise< Coupon > {
	return apiFetch< Coupon >( {
		path: id === null ? `${ BASE }/coupons` : `${ BASE }/coupons/${ id }`,
		method: id === null ? 'POST' : 'PUT',
		data: coupon,
	} );
}

export function deleteCoupon( id: number ): Promise< unknown > {
	return apiFetch( { path: `${ BASE }/coupons/${ id }`, method: 'DELETE' } );
}

export interface Named {
	id: number;
	name: string;
}

const decoded = ( html: string ): string => {
	const decode = document.createElement( 'textarea' );
	decode.innerHTML = html;
	return decode.value;
};

/**
 * The terms of a product taxonomy, by name.
 *
 * @param taxonomy "orillacart_category" or "orillacart_brand".
 */
export async function fetchTerms( taxonomy: string ): Promise< Named[] > {
	const terms = await apiFetch< { id: number; name: string }[] >( {
		path: `/wp/v2/${ taxonomy }?per_page=100&_fields=id,name&orderby=name`,
	} );
	return terms.map( ( term ) => ( {
		id: term.id,
		name: decoded( term.name ),
	} ) );
}

/**
 * Products by name or by id, from the WordPress posts API; at most 20.
 *
 * @param query A name to search, or the ids to name.
 */
export async function fetchProducts(
	query: { search: string } | { include: number[] }
): Promise< Named[] > {
	if ( 'include' in query && query.include.length === 0 ) {
		return [];
	}
	const posts = await apiFetch<
		{ id: number; title: { rendered: string } }[]
	>( {
		path: addQueryArgs( '/wp/v2/orillacart_product', {
			...( 'search' in query
				? { search: query.search }
				: { include: query.include.join( ',' ) } ),
			per_page: 'include' in query ? 100 : 20,
			status: 'publish,private,draft',
			_fields: 'id,title',
		} ),
	} );
	return posts.map( ( post ) => ( {
		id: post.id,
		name: decoded( post.title.rendered ) || `#${ post.id }`,
	} ) );
}
