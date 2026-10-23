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
	/** The code the shop files this tax under, such as a county's or a city's; null to build one from the place. */
	code: string | null;
}

export interface TaxGroup {
	id: number;
	name: string;
	rates: TaxRate[];
	/** What tax services call the group's goods, such as PC040100. */
	tax_code: string | null;
}

export type TaxRateInput = Omit< TaxRate, 'id' >;

/** Regions, "US-TX", whose sales within them are taxed at the seller's address. */
export const REGION = /^[A-Z]{2}-[A-Z0-9]{1,3}$/;

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
	/** Regions ("US-TX") taxing sales within them at the seller's address. */
	origin_regions: string[];
	/** The tax service working out the taxes, by its id; null for the shop's own rates. */
	provider: string | null;
	/** When the service cannot be reached at checkout: tax at the shop's rates, or refuse the order. */
	when_provider_fails: 'shop_rates' | 'refuse';
	/** The tax services add-ons offer. */
	providers: { id: string; label: string }[];
	/** Customers send their exemption certificates for the shop to check. */
	accepts_certificates: boolean;
	/** How the catalog shows prices. */
	shown_in_shop: PriceShown;
	/** How the cart and checkout show prices. */
	shown_in_cart: PriceShown;
	/** Text after the catalog's prices: "incl. VAT", with {price_including_tax} and {price_excluding_tax}. */
	price_suffix: string;
	/** Prices including tax: every customer pays them as entered (true), or the price before the shop's tax stays (false). */
	same_price_everywhere: boolean;
}

export type PriceShown = 'as_entered' | 'with_tax' | 'without_tax';

export type TaxSettingsInput = Omit< TaxSettings, 'bases' | 'providers' >;

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

export function renameGroup(
	id: number,
	name: string,
	taxCode: string | null
): Promise< TaxGroup > {
	return apiFetch< TaxGroup >( {
		path: `${ BASE }/groups/${ id }`,
		method: 'PUT',
		data: { name, tax_code: taxCode },
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

/** What a US state counts before a seller from elsewhere owes it sales tax: either one crossed is enough. */
export interface NexusThreshold {
	/** Sales in a year, in the shop's currency; null when not counted. */
	sales: string | null;
	/** Orders in a year; null when not counted. */
	orders: number | null;
}

export interface NexusThresholds {
	everywhere: NexusThreshold;
	/** States whose own threshold differs, by code such as "TX". */
	states: ( NexusThreshold & { state: string } )[];
}

export interface NexusWindow {
	sales: string;
	orders: number;
}

export interface NexusState {
	state: string;
	name: string;
	last_twelve_months: NexusWindow;
	this_year: NexusWindow;
	last_year: NexusWindow;
	threshold: NexusThreshold;
	/** Its sales crossed the threshold in one of the windows. */
	crossed: boolean;
	/** The shop has a tax rate for the state. */
	collecting: boolean;
}

export interface NexusReport {
	currency: string;
	last_twelve_months: { from: string; to: string };
	this_year: { from: string; to: string };
	last_year: { from: string; to: string };
	/** What may be owed and is not collected first. */
	states: NexusState[];
	/** A tax service works out the taxes. */
	tax_service: boolean;
	/** Orders not counted yet. */
	pending: number;
}

export function fetchNexusThresholds(): Promise< NexusThresholds > {
	return apiFetch< NexusThresholds >( {
		path: `${ BASE }/nexus-thresholds`,
	} );
}

export function saveNexusThresholds(
	thresholds: NexusThresholds
): Promise< NexusThresholds > {
	return apiFetch< NexusThresholds >( {
		path: `${ BASE }/nexus-thresholds`,
		method: 'PUT',
		data: thresholds,
	} );
}

export function fetchNexusReport(): Promise< NexusReport > {
	return apiFetch< NexusReport >( { path: '/orillacart/v1/reports/nexus' } );
}
