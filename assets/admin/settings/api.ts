import apiFetch from '@wordpress/api-fetch';

/** The shop settings as GET /orillacart/v1/settings returns them. */
export interface Settings {
	currency: string;
	price_decimals: number;
	weight_unit: string;
	dimension_unit: string;
	shop_country: string | null;
	shop_state: string | null;
	shop_postcode: string | null;
	/** Shown with amounts; empty for the currency code. */
	currency_sign: string;
	currency_position: string;
	/** Products are shown but not sold. */
	catalog_only: boolean;
	/** Prices are not shown and products not sold online, unless a product says otherwise. */
	hide_prices: boolean;
	/** Countries the shop sells to; empty for everywhere. */
	sell_to: string[];
	weight_units: string[];
	dimension_units: string[];
	currency_positions: string[];
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
	| 'shop_postcode'
	| 'currency_sign'
	| 'currency_position'
	| 'catalog_only'
	| 'hide_prices'
	| 'sell_to'
>;

const PATH = '/orillacart/v1/settings';

export function fetchSettings(): Promise< Settings > {
	return apiFetch< Settings >( { path: PATH } );
}

export function saveSettings( input: SettingsInput ): Promise< Settings > {
	return apiFetch< Settings >( { path: PATH, method: 'PUT', data: input } );
}
