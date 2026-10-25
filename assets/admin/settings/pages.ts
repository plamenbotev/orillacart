import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

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

/** The shop's legal pages and what checkout asks, as GET /orillacart/v1/legal-settings returns them. */
export interface LegalSettings {
	terms: number | null;
	withdrawal: number | null;
	delivery: number | null;
	legal_notice: number | null;
	accept_terms: boolean;
	order_button: string;
	order_buttons?: string[];
}

const LEGAL = '/orillacart/v1/legal-settings';

export function fetchLegalSettings(): Promise< LegalSettings > {
	return apiFetch< LegalSettings >( { path: LEGAL } );
}

export function saveLegalSettings(
	settings: LegalSettings
): Promise< LegalSettings > {
	const data: Partial< LegalSettings > = { ...settings };
	delete data.order_buttons;
	return apiFetch< LegalSettings >( { path: LEGAL, method: 'PUT', data } );
}

/**
 * Pages, by title: published ones, or drafts too.
 *
 * @param drafts Whether drafts are choices too.
 */
export async function fetchPageChoices(
	drafts = false
): Promise< PageChoice[] > {
	// Every page, past the API's 100 a page: apiFetch reads each page for per_page=-1.
	const pages = await apiFetch<
		{ id: number; title: { rendered: string }; status?: string }[]
	>( {
		path: `/wp/v2/pages?status=${
			drafts ? 'publish,draft' : 'publish'
		}&per_page=-1&orderby=title&order=asc&_fields=id,title,status`,
	} );
	const decode = document.createElement( 'textarea' );
	return pages.map( ( page ) => {
		// Titles come rendered as HTML (&amp;); select options show text.
		decode.innerHTML = page.title.rendered;
		return {
			id: page.id,
			title:
				page.status === 'draft'
					? sprintf(
							/* translators: %s: a page's title */
							__( '%s (draft)', 'orillacart' ),
							decode.value
						)
					: decode.value,
		};
	} );
}
