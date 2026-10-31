import apiFetch from '@wordpress/api-fetch';

export const TIER_KINDS = [ 'percent', 'amount_off', 'fixed_price' ] as const;
export type TierKind = ( typeof TIER_KINDS )[ number ];

export interface Tier {
	min_quantity: number;
	kind: TierKind;
	value: string;
}

export interface PriceRuleFields {
	name: string;
	tiers: Tier[];
	group_ids: number[];
	product_ids: number[];
	category_ids: number[];
	brand_ids: number[];
	starts_at: string | null;
	ends_at: string | null;
	/** It applies on top of a sale price too. */
	with_sales: boolean;
	active: boolean;
	/** Codes and automatic discounts still come off the price it sets. */
	with_coupons: boolean;
	/** It applies on top of the price the other rules give, instead of competing with them. */
	combines: boolean;
}

export interface PriceRule extends PriceRuleFields {
	id: number;
	/** The products it names that were deleted since: it prices nothing for them. */
	missing_product_ids?: number[];
}

export interface CustomerGroup {
	id: number;
	name: string;
	members: number;
}

export const NEW_RULE: PriceRuleFields = {
	name: '',
	tiers: [ { min_quantity: 1, kind: 'percent', value: '10' } ],
	group_ids: [],
	product_ids: [],
	category_ids: [],
	brand_ids: [],
	starts_at: null,
	ends_at: null,
	with_sales: false,
	active: true,
	with_coupons: true,
	combines: false,
};

const BASE = '/orillacart/v1';

export function fetchRules(): Promise< PriceRule[] > {
	return apiFetch< PriceRule[] >( { path: `${ BASE }/price-rules` } );
}

export function saveRule(
	id: number | null,
	rule: PriceRuleFields | PriceRule
): Promise< PriceRule > {
	// A loaded rule also has its id and the products it names that are gone, which the API does not take back.
	const {
		id: _id,
		missing_product_ids: _missing,
		...fields
	} = rule as Partial< PriceRule > & PriceRuleFields;
	return apiFetch< PriceRule >( {
		path:
			id === null
				? `${ BASE }/price-rules`
				: `${ BASE }/price-rules/${ id }`,
		method: id === null ? 'POST' : 'PUT',
		data: fields,
	} );
}

export function deleteRule( id: number ): Promise< unknown > {
	return apiFetch( {
		path: `${ BASE }/price-rules/${ id }`,
		method: 'DELETE',
	} );
}

export function fetchGroups(): Promise< CustomerGroup[] > {
	return apiFetch< CustomerGroup[] >( { path: `${ BASE }/customer-groups` } );
}
