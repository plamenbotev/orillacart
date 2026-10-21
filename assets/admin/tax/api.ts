import apiFetch from '@wordpress/api-fetch';

export interface TaxRate {
	id: number;
	country: string;
	state: string | null;
	/** Decimal string, such as "7.25". */
	percent: string;
	name: string;
	priority: number;
	/** Postcodes such as 1000, SW1* or 1000-1999 it applies to; empty for every postcode. */
	postcodes: string[];
	/** Charged on the price and the taxes listed before it. */
	compound: boolean;
}

export interface TaxGroup {
	id: number;
	name: string;
	rates: TaxRate[];
}

export type TaxRateInput = Omit< TaxRate, 'id' >;

export interface TaxSettings {
	basis: string;
	default_group_id: number | null;
	bases: string[];
	/** Prices are entered including tax; every customer pays them as entered. */
	prices_include_tax: boolean;
	/** Checkout asks business customers for their EU VAT number (reverse charge). */
	reverse_charge: boolean;
	/** Each line's tax rounded, instead of each rate once over the order. */
	rounds_each_line: boolean;
}

export type TaxSettingsInput = Omit< TaxSettings, 'bases' >;

const BASE = '/orillacart/v1/tax';

export function fetchGroups(): Promise< TaxGroup[] > {
	return apiFetch< TaxGroup[] >( { path: `${ BASE }/groups` } );
}

export function createGroup( name: string ): Promise< TaxGroup > {
	return apiFetch< TaxGroup >( {
		path: `${ BASE }/groups`,
		method: 'POST',
		data: { name },
	} );
}

export function renameGroup( id: number, name: string ): Promise< TaxGroup > {
	return apiFetch< TaxGroup >( {
		path: `${ BASE }/groups/${ id }`,
		method: 'PUT',
		data: { name },
	} );
}

export function deleteGroup( id: number ): Promise< unknown > {
	return apiFetch( { path: `${ BASE }/groups/${ id }`, method: 'DELETE' } );
}

export function saveRate(
	groupId: number,
	rateId: number | null,
	input: TaxRateInput
): Promise< TaxRate > {
	return apiFetch< TaxRate >(
		rateId === null
			? {
					path: `${ BASE }/groups/${ groupId }/rates`,
					method: 'POST',
					data: input,
				}
			: {
					path: `${ BASE }/groups/${ groupId }/rates/${ rateId }`,
					method: 'PUT',
					data: input,
				}
	);
}

export function deleteRate(
	groupId: number,
	rateId: number
): Promise< unknown > {
	return apiFetch( {
		path: `${ BASE }/groups/${ groupId }/rates/${ rateId }`,
		method: 'DELETE',
	} );
}

/**
 * Adds the standard VAT rate of every EU country the group has none for, to charge EU customers their own rate.
 * @param id
 */
export function addEuStandardRates( id: number ): Promise< TaxGroup > {
	return apiFetch< TaxGroup >( {
		path: `${ BASE }/groups/${ id }/eu-standard-rates`,
		method: 'POST',
	} );
}

export function fetchTaxSettings(): Promise< TaxSettings > {
	return apiFetch< TaxSettings >( { path: `${ BASE }/settings` } );
}

export function saveTaxSettings(
	input: TaxSettingsInput
): Promise< TaxSettings > {
	return apiFetch< TaxSettings >( {
		path: `${ BASE }/settings`,
		method: 'PUT',
		data: input,
	} );
}
