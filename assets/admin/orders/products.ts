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
	} >( { path: `/orillacart/v1/products/${ id }` } );
	return {
		id: product.id,
		name: product.name,
		attributes: product.variations.length > 0 ? [] : product.offered,
		variations: product.variations,
	};
}
