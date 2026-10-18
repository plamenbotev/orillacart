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
	/** The shipping classes it carries, 0 for items without one; empty for every item. */
	shipping_classes: number[];
	/** Postcodes ("1000"), starts ("SW1*") or numeric ranges ("1000-1999"); empty for any. */
	postcodes: string[];
}

/** A kind of goods that ships its own way, such as "Bulky". */
export interface ShippingClass {
	id: number;
	name: string;
}

/** Stands, in a rate's classes, for the items that have no class. */
export const WITHOUT_CLASS = 0;

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

export function fetchShippingClasses(): Promise< ShippingClass[] > {
	return apiFetch< ShippingClass[] >( {
		path: `${ BASE }/shipping-classes`,
	} );
}

export function saveShippingClass(
	id: number | null,
	name: string
): Promise< ShippingClass > {
	return apiFetch< ShippingClass >(
		id === null
			? {
					path: `${ BASE }/shipping-classes`,
					method: 'POST',
					data: { name },
				}
			: {
					path: `${ BASE }/shipping-classes/${ id }`,
					method: 'PUT',
					data: { name },
				}
	);
}

export function deleteShippingClass( id: number ): Promise< unknown > {
	return apiFetch( {
		path: `${ BASE }/shipping-classes/${ id }`,
		method: 'DELETE',
	} );
}

export function deleteCarrier( id: number ): Promise< unknown > {
	return apiFetch( { path: `${ BASE }/carriers/${ id }`, method: 'DELETE' } );
}
