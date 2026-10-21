import apiFetch from '@wordpress/api-fetch';

/** What the storefront tells search engines and social sites, and keeps for shoppers, as GET /orillacart/v1/storefront-settings returns it. */
export interface StorefrontSettings {
	structured_data: boolean;
	sharing_tags: 'auto' | 'always' | 'never';
	wishlist: 'guests' | 'accounts' | 'off';
	recently_viewed: boolean;
	data_layer: boolean;
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
