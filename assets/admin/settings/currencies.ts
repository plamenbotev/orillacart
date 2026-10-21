import apiFetch from '@wordpress/api-fetch';

export interface OfferedCurrency {
	code: string;
	/** Units of it for one of the shop's currency, such as "1.08". */
	rate: string;
	rounding: 'decimals' | 'whole' | 'ninety_nine';
}

/** The currencies shoppers may choose besides the shop's own, as GET /orillacart/v1/currencies returns them. */
export interface CurrencySettings {
	offered: OfferedCurrency[];
}

const PATH = '/orillacart/v1/currencies';

export function fetchCurrencies(): Promise< CurrencySettings > {
	return apiFetch< CurrencySettings >( { path: PATH } );
}

export function saveCurrencies(
	settings: CurrencySettings
): Promise< CurrencySettings > {
	return apiFetch< CurrencySettings >( {
		path: PATH,
		method: 'PUT',
		data: settings,
	} );
}
