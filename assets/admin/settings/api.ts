import apiFetch from '@wordpress/api-fetch';

/** The shop settings as GET /orillacart/v1/settings returns them. */
export interface Settings {
	currency: string;
	price_decimals: number;
	weight_unit: string;
	dimension_unit: string;
	shop_country: string | null;
	shop_state: string | null;
	weight_units: string[];
	dimension_units: string[];
}

/** The fields PUT /orillacart/v1/settings accepts; every one is required. */
export type SettingsInput = Pick<
	Settings,
	| 'currency'
	| 'price_decimals'
	| 'weight_unit'
	| 'dimension_unit'
	| 'shop_country'
	| 'shop_state'
>;

const PATH = '/orillacart/v1/settings';

export function fetchSettings(): Promise< Settings > {
	return apiFetch< Settings >( { path: PATH } );
}

export function saveSettings( input: SettingsInput ): Promise< Settings > {
	return apiFetch< Settings >( { path: PATH, method: 'PUT', data: input } );
}
