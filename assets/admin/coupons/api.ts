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
	/** No code to enter: it applies wherever it holds. */
	automatic?: boolean;
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
	automatic?: boolean;
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

export { type Named, fetchProducts, fetchTerms } from '../shared/CatalogTokens';
