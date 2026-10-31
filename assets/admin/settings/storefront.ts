import apiFetch from '@wordpress/api-fetch';

/** What the storefront tells search engines and social sites, and keeps for shoppers, as GET /orillacart/v1/storefront-settings returns it. */
export interface StorefrontSettings {
	structured_data: boolean;
	sharing_tags: 'auto' | 'always' | 'never';
	wishlist: 'guests' | 'accounts' | 'off';
	recently_viewed: boolean;
	data_layer: boolean;
	hide_single_value_filters: boolean;
	list_untranslated: boolean;
	/** The notice over the shop's pages, wherever the Store notice block is: shown while on and with text. */
	notice: boolean;
	notice_text: string;
	/** https://… or /a-page-of-the-site; empty for none. */
	notice_link: string;
	/** Empty makes the text itself the link. */
	notice_link_label: string;
	notice_dismissible: boolean;
	/** The text's and the link's words in the site's other languages, by field (text, link_label) and locale. */
	notice_translations: Record< string, Record< string, string > >;
}

const PATH = '/orillacart/v1/storefront-settings';

export function fetchStorefrontSettings(): Promise< StorefrontSettings > {
	return apiFetch< StorefrontSettings >( { path: PATH } );
}

export function saveStorefrontSettings(
	settings: StorefrontSettings
): Promise< StorefrontSettings > {
	return apiFetch< StorefrontSettings >( {
		path: PATH,
		method: 'PUT',
		data: settings,
	} );
}
