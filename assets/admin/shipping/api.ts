import apiFetch from '@wordpress/api-fetch';

/** From min to max, both included; no max for no end. */
export interface Range {
	min: string;
	max: string | null;
}

export const MEASURES = [
	'weight',
	'volume',
	'length',
	'width',
	'height',
	'subtotal',
] as const;
export type Measure = ( typeof MEASURES )[ number ];

export interface ShippingRate extends Record< Measure, Range | null > {
	id: number;
	name: string;
	price: string;
	per_item: boolean;
	/** Countries ("BG") or states ("US-CA"); empty for everywhere. */
	regions: string[];
	taxable: boolean;
	tax_group_id: number | null;
}

export type ShippingRateInput = Omit< ShippingRate, 'id' > & {
	id?: number;
};

export interface Carrier {
	id: number;
	name: string;
	active: boolean;
	position: number;
	rates: ShippingRate[];
}

export interface CarrierInput {
	name: string;
	active: boolean;
	position: number;
	rates: ShippingRateInput[];
}

const BASE = '/orillacart/v1';

export function fetchCarriers(): Promise< Carrier[] > {
	return apiFetch< Carrier[] >( { path: `${ BASE }/carriers` } );
}

export function saveCarrier(
	id: number | null,
	input: CarrierInput
): Promise< Carrier > {
	return apiFetch< Carrier >(
		id === null
			? { path: `${ BASE }/carriers`, method: 'POST', data: input }
			: { path: `${ BASE }/carriers/${ id }`, method: 'PUT', data: input }
	);
}

export function deleteCarrier( id: number ): Promise< unknown > {
	return apiFetch( { path: `${ BASE }/carriers/${ id }`, method: 'DELETE' } );
}
