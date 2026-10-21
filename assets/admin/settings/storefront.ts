import apiFetch from '@wordpress/api-fetch';

/** What the storefront tells search engines and social sites, as GET /orillacart/v1/storefront-settings returns it. */
export interface StorefrontSettings {
	structured_data: boolean;
	sharing_tags: 'auto' | 'always' | 'never';
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
