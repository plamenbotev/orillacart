import apiFetch from '@wordpress/api-fetch';

/** The page each part of the storefront is on, as GET /orillacart/v1/shop-pages returns it. */
export interface ShopPages {
	shop: number | null;
	cart: number | null;
	checkout: number | null;
	account: number | null;
}

export interface PageChoice {
	id: number;
	title: string;
}

const PATH = '/orillacart/v1/shop-pages';

export function fetchShopPages(): Promise< ShopPages > {
	return apiFetch< ShopPages >( { path: PATH } );
}

export function saveShopPages( pages: ShopPages ): Promise< ShopPages > {
	return apiFetch< ShopPages >( { path: PATH, method: 'PUT', data: pages } );
}

/** Published pages, by title. */
export async function fetchPageChoices(): Promise< PageChoice[] > {
	// Every page, past the API's 100 a page: apiFetch reads each page for per_page=-1.
	const pages = await apiFetch<
		{ id: number; title: { rendered: string } }[]
	>( {
		path: '/wp/v2/pages?status=publish&per_page=-1&orderby=title&order=asc&_fields=id,title',
	} );
	const decode = document.createElement( 'textarea' );
	return pages.map( ( page ) => {
		// Titles come rendered as HTML (&amp;); select options show text.
		decode.innerHTML = page.title.rendered;
		return { id: page.id, title: decode.value };
	} );
}
