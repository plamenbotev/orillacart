import apiFetch from '@wordpress/api-fetch';

/** The shop settings as GET /orillacart/v1/settings returns them. */
export interface Settings {
	currency: string;
	/** The decimals amounts are priced, charged and shown in. */
	price_decimals: number;
	/** Fewer decimals than the currency has, as the shop chose; null for the currency's own. */
	fewer_decimals: number | null;
	/** The currency's own decimals (ISO 4217), the most amounts can have. */
	currency_decimals: number;
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
	/** Sales show the lowest price of the 30 days before them (EU Omnibus). */
	show_lowest_price: boolean;
	/** Deleting the plugin removes the shop's data too. */
	remove_data_on_uninstall: boolean;
	/** The shop hears when a stockroom holds this many of an item or fewer, unless the stockroom or item says. */
	low_stock_threshold: number;
	/** Orders on hold for a bank transfer or cheque are cancelled after this many days unpaid; null never. */
	unpaid_hold_days: number | null;
	/** Customers ask to return items this many days after their order shipped; null takes no returns. */
	return_days: number | null;
	/** The least the items may come to, after discounts, to order; null for no least. */
	minimum_order: string | null;
	/** The day the reports count an order on: paid or placed; a change counts every order again. */
	report_day: 'paid' | 'placed';
	report_days: ( 'paid' | 'placed' )[];
	weight_units: string[];
	dimension_units: string[];
	currency_positions: string[];
}

/** The fields PUT /orillacart/v1/settings accepts; every one is required. */
export type SettingsInput = Pick<
	Settings,
	| 'currency'
	| 'fewer_decimals'
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
	| 'show_lowest_price'
	| 'remove_data_on_uninstall'
	| 'low_stock_threshold'
	| 'unpaid_hold_days'
	| 'return_days'
	| 'minimum_order'
	| 'report_day'
>;

/**
 * What of the settings a save takes back: the read-only fields (units, decimals) left out.
 *
 * @param settings The settings as read.
 */
export function settingsInput( settings: Settings ): SettingsInput {
	return {
		currency: settings.currency,
		fewer_decimals: settings.fewer_decimals,
		weight_unit: settings.weight_unit,
		dimension_unit: settings.dimension_unit,
		shop_country: settings.shop_country,
		shop_state: settings.shop_state,
		shop_postcode: settings.shop_postcode,
		currency_sign: settings.currency_sign,
		currency_position: settings.currency_position,
		catalog_only: settings.catalog_only,
		hide_prices: settings.hide_prices,
		show_lowest_price: settings.show_lowest_price,
		remove_data_on_uninstall: settings.remove_data_on_uninstall,
		low_stock_threshold: settings.low_stock_threshold,
		unpaid_hold_days: settings.unpaid_hold_days,
		return_days: settings.return_days,
		minimum_order: settings.minimum_order,
		report_day: settings.report_day,
		sell_to: settings.sell_to,
	};
}

const PATH = '/orillacart/v1/settings';

export function fetchSettings(): Promise< Settings > {
	return apiFetch< Settings >( { path: PATH } );
}

export function saveSettings( input: SettingsInput ): Promise< Settings > {
	return apiFetch< Settings >( { path: PATH, method: 'PUT', data: input } );
}
