import apiFetch from '@wordpress/api-fetch';

import type { Setting, SettingValues } from '../shared/SettingFields';

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
	'items',
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
	/** Countries or states it does not ship to, even within its regions. */
	excluded_regions: string[];
	/** Postcode patterns it does not ship to. */
	excluded_postcodes: string[];
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
	/** The add-on's shipping method that prices it; null for its own rates. */
	method: string | null;
	/** Secrets come back empty; secrets_set names the saved ones. */
	method_settings: SettingValues;
	secrets_set: string[];
	method_taxable: boolean;
	method_tax_group_id: number | null;
	/** The shop's places to collect orders, for its own pickup; none for a carrier that ships. */
	pickup_locations: PickupLocation[];
	/** The days, and times of day, the customer chooses for delivery; null to leave them out. */
	delivery_schedule: DeliverySchedule | null;
	/** Goods of a class one of its rates lists go only by the rates that list it. */
	classes_kept_to_their_rates: boolean;
}

/** The days, and times of day, a carrier lets the customer choose for delivery. */
export interface DeliverySchedule {
	/** 1 (Monday) to 7 (Sunday). */
	weekdays: number[];
	/** The days it needs: 1 delivers tomorrow at the earliest. */
	lead_days: number;
	/** "14:00": an order after it counts from the next day. */
	cutoff: string | null;
	/** How many days from the earliest the customer may choose among. */
	days_ahead: number;
	/** Dates it does not deliver on, "2026-12-25". */
	closed: string[];
	slots: { id: string; from: string; to: string; limit: number | null }[];
	/** The customer must choose. */
	required: boolean;
}

/** One of the shop's places where customers collect their orders. */
export interface PickupLocation {
	/** Stays the same while the place is edited: orders name it by it. */
	id: string;
	name: string;
	address: string;
	/** When it is open: "Mon–Fri 9–18, Sat 10–14". */
	hours: string;
	/** What collecting there costs, as the shop's prices are. */
	fee: string;
}

export interface CarrierInput {
	name: string;
	active: boolean;
	position: number;
	rates: ShippingRateInput[];
	method: string | null;
	method_settings: SettingValues;
	method_taxable: boolean;
	method_tax_group_id: number | null;
	pickup_locations: PickupLocation[];
	delivery_schedule: DeliverySchedule | null;
	classes_kept_to_their_rates: boolean;
}

/** A shipping method an add-on brings. */
export interface ShippingMethod {
	id: string;
	name: string;
	fields: Setting[];
	/** Whether its settings can be tried at the courier. */
	testable?: boolean;
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

export function fetchShippingMethods(): Promise< ShippingMethod[] > {
	return apiFetch< ShippingMethod[] >( {
		path: `${ BASE }/shipping-methods`,
	} );
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
