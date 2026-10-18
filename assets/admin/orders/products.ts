import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

/** A product with the choices an order line can make, from GET /orillacart/v1/products/{id}. */
export interface ProductChoice {
	id: number;
	name: string;
	attributes: {
		id: number;
		name: string;
		options: { id: number; name: string }[];
	}[];
	/** Products with variations are sold only as one of them. */
	variations: { id: number; option_ids: number[]; label: string }[];
	/** A bundle's items the shop decides about: which variation, or whether to add an optional one. */
	bundleChoices: BundleDecision[];
}

export interface BundleDecision {
	product_id: number;
	name: string;
	optional: boolean;
	/** The ids that pick it: its variations, or the product itself. */
	choices: { id: number; name: string }[];
}

interface BundleItemData {
	product_id: number;
	variation_ids: number[];
	optional: boolean;
	name: string;
	variations: { id: number; name: string }[];
}

/**
 * Products whose name matches, from the WordPress posts API; at most 20.
 * @param search
 */
export async function searchProducts(
	search: string
): Promise< { id: number; title: string }[] > {
	const posts = await apiFetch<
		{ id: number; title: { rendered: string } }[]
	>( {
		path: addQueryArgs( '/wp/v2/orillacart_product', {
			search,
			per_page: 20,
			status: 'publish,private,draft',
			_fields: 'id,title',
		} ),
	} );
	const decode = document.createElement( 'textarea' );
	return posts.map( ( post ) => {
		decode.innerHTML = post.title.rendered;
		return { id: post.id, title: decode.value || `#${ post.id }` };
	} );
}

export async function fetchProductChoices(
	id: number
): Promise< ProductChoice > {
	const product = await apiFetch< {
		id: number;
		name: string;
		offered: ProductChoice[ 'attributes' ];
		variations: ProductChoice[ 'variations' ];
		bundle: { items: BundleItemData[] } | null;
	} >( { path: `/orillacart/v1/products/${ id }` } );
	return {
		id: product.id,
		name: product.name,
		attributes: product.variations.length > 0 ? [] : product.offered,
		variations: product.variations,
		bundleChoices: ( product.bundle?.items ?? [] )
			.filter(
				( item ) => item.optional || item.variation_ids.length > 1
			)
			.map( ( item ) => ( {
				product_id: item.product_id,
				name: item.name,
				optional: item.optional,
				choices:
					item.variations.length > 0
						? item.variations
						: [ { id: item.product_id, name: item.name } ],
			} ) ),
	};
}
