import apiFetch from '@wordpress/api-fetch';

const BASE = '/orillacart/v1/setup';

export function fetchSetup(): Promise< { finished: boolean } > {
	return apiFetch( { path: BASE } );
}

export function finishSetup(): Promise< { finished: boolean } > {
	return apiFetch( { path: BASE, method: 'PUT' } );
}

export function fetchSampleData(): Promise< { present: boolean } > {
	return apiFetch( { path: '/orillacart/v1/sample-data' } );
}

export function addSampleData(): Promise< {
	present: boolean;
	added: number;
} > {
	return apiFetch( { path: '/orillacart/v1/sample-data', method: 'POST' } );
}

export function removeSampleData(): Promise< {
	present: boolean;
	removed: number;
} > {
	return apiFetch( { path: '/orillacart/v1/sample-data', method: 'DELETE' } );
}

/** Each step's answers as given, null for a step not answered yet. */
export type Answers = Record< string, Record< string, unknown > | null >;

export function fetchAnswers(): Promise< Answers > {
	return apiFetch( { path: `${ BASE }/answers` } );
}

/**
 * Keeps one step's answers; nothing in the shop changes until the plan is applied.
 *
 * @param step    The step's key: "business", "tax".
 * @param answers Its answers, as its questions ask for them.
 */
export function saveAnswers(
	step: string,
	answers: Record< string, unknown >
): Promise< Answers > {
	return apiFetch( {
		path: `${ BASE }/answers/${ step }`,
		method: 'PUT',
		data: answers,
	} );
}

/** A change the setup would make, in words. */
export interface PlannedChange {
	key: string;
	step: string;
	summary: string;
	details: string[];
	/** What there is already, which the change would replace; null when it adds. */
	existing: string | null;
	warning: string | null;
	/** Whether replacing what there is is chosen unless unticked. */
	replace: boolean;
}

export interface Plan {
	changes: PlannedChange[];
	fingerprint: string;
}

export function fetchPlan(): Promise< Plan > {
	return apiFetch( { path: `${ BASE }/plan`, method: 'POST' } );
}

export interface SetupResult {
	applied: string[];
	kept: string[];
	refused: { key: string; reason: string } | null;
}

/**
 * Makes the plan the owner saw.
 *
 * @param fingerprint The plan's, so one the shop moved on from is not applied.
 * @param replace     The changes to make over what there is.
 */
export function applyPlan(
	fingerprint: string,
	replace: string[]
): Promise< SetupResult > {
	return apiFetch( {
		path: `${ BASE }/apply`,
		method: 'POST',
		data: { fingerprint, replace },
	} );
}

export interface Readiness {
	key: string;
	stops: boolean;
	message: string;
	/** The admin screen that puts it right: "payments". */
	fix: string | null;
}

export interface ReadinessReport {
	ready: boolean;
	stops: Readiness[];
	advice: Readiness[];
}

export function fetchReadiness(): Promise< ReadinessReport > {
	return apiFetch( { path: `${ BASE }/readiness` } );
}

/** What the setup knows of a country. */
export interface CountryProfile {
	country: string;
	in_eu: boolean;
	currency: string | null;
	weight_unit: string;
	dimension_unit: string;
	tax_system: 'vat' | 'gst' | 'sales_tax' | 'unknown';
	tax_name: string;
	standard: string | null;
	reduced: string[];
	super_reduced: string | null;
	regional: { state: string; name: string; percent: string }[];
	prices_include_tax: boolean;
	small_business_note: string | null;
	checked_on: string | null;
	withdrawal_days: number | null;
	legal_notice: boolean;
	legal_notice_title: string;
	consumer_checkout: boolean;
	e_invoices: string | null;
	currencies: string[];
	/** Whether its banks number accounts by IBAN. */
	iban: boolean;
}

export function fetchCountryProfile(
	country: string
): Promise< CountryProfile > {
	return apiFetch( { path: `${ BASE }/countries/${ country }` } );
}

export interface ShippingZone {
	id: 'home' | 'eu' | 'europe' | 'world' | 'listed';
	countries: string[];
}

export function fetchShippingZones(): Promise< ShippingZone[] > {
	return apiFetch( { path: `${ BASE }/shipping-zones` } );
}

/** Opens the shop to visitors. */
export function openShop(): Promise< { opens_soon: boolean } > {
	return apiFetch( { path: '/orillacart/v1/opening', method: 'PUT' } );
}

export function fetchOpening(): Promise< { opens_soon: boolean } > {
	return apiFetch( { path: '/orillacart/v1/opening' } );
}

export interface SettingsTest {
	works: boolean;
	problem: string | null;
}

/**
 * Tries a gateway's settings at the provider; nothing is saved.
 *
 * @param gateway  The gateway's id.
 * @param settings The settings as the form has them.
 */
export function testPaymentSettings(
	gateway: string,
	settings: Record< string, string | boolean >
): Promise< SettingsTest > {
	return apiFetch( {
		path: `/orillacart/v1/payment-methods/${ gateway }/test`,
		method: 'POST',
		data: { settings },
	} );
}

/**
 * Tries a shipping method's settings at the courier; nothing is saved.
 *
 * @param method    The method's id.
 * @param settings  The settings as the form has them.
 * @param carrierId The carrier whose saved secrets fill the empty ones.
 */
export function testShippingSettings(
	method: string,
	settings: Record< string, string | boolean >,
	carrierId: number | null
): Promise< SettingsTest > {
	return apiFetch( {
		path: `/orillacart/v1/shipping-methods/${ method }/test`,
		method: 'POST',
		data: { settings, carrier_id: carrierId },
	} );
}

/** Sends the order confirmation, as a test, to the logged-in manager. */
export function sendTestEmail(): Promise< { to: string; sent: boolean } > {
	return apiFetch( {
		path: '/orillacart/v1/email-templates/order-received/test',
		method: 'POST',
		data: {},
	} );
}
