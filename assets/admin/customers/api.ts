import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

import type { Address } from '../shared/address';

export interface Customer {
	id: number;
	email: string;
	first_name: string;
	last_name: string;
	registered_at: string;
	/** The saved address checkout starts from for billing. */
	billing: Address | null;
	/** The saved address checkout starts from for shipping. */
	shipping_address: Address | null;
	orders: number;
	/** The customer groups they are in. */
	group_ids: number[];
	addresses: SavedAddress[];
	lifetime_value: LifetimeValue | null;
	cart_reminders: boolean;
	/** Their sign-up for the newsletter, if they ever signed up. */
	newsletter: Subscription | null;
}

export interface Subscription {
	email: string;
	subscribed: boolean;
	subscribed_at: string;
	unsubscribed_at: string | null;
	source: 'checkout' | 'account';
	/** The words of the box they ticked. */
	consent: string;
}

export interface NewsletterSettings {
	offered: boolean;
	consent: string;
	/** The add-ons' mailing lists, and whether the sign-ups go to each. */
	lists: { id: string; name: string; on: boolean }[];
	subscribers: number;
}

/** What the customer paid in the shop's currency, less what went back, over their paid orders. */
export interface LifetimeValue {
	currency: string;
	spent: string;
	paid_orders: number;
	average: string;
	first_order_at: string | null;
	last_order_at: string | null;
}

/** What the shop noted about a customer, for itself. */
export interface CustomerNote {
	id: number;
	text: string;
	/** Who of the shop wrote it; null when their account is gone. */
	author: string | null;
	created_at: string;
}

/** An address of the customer's address book, and whether checkout starts from it for billing or shipping. */
export interface SavedAddress {
	id: string;
	address: Address;
	billing: boolean;
	shipping: boolean;
}

export interface CustomerGroup {
	id: number;
	name: string;
	members: number;
}

export interface CustomerPage {
	customers: Customer[];
	total: number;
	page: number;
	pages: number;
}

export interface CustomerSettings {
	accounts: 'optional' | 'required' | 'disabled';
	modes: string[];
}

const BASE = '/orillacart/v1';

export function fetchCustomers( query: {
	search?: string | undefined;
	page: number;
	per_page: number;
} ): Promise< CustomerPage > {
	return apiFetch< CustomerPage >( {
		path: addQueryArgs( `${ BASE }/customers`, { ...query } ),
	} );
}

/**
 * Saves an address in the customer's address book: a new one (addressId null), or in place of a saved one.
 * @param id            The customer.
 * @param addressId     The saved address, or null for a new one.
 * @param address       The address.
 * @param uses          Whether checkout starts from it for billing or shipping from now on.
 * @param uses.billing
 * @param uses.shipping
 */
export function saveAddress(
	id: number,
	addressId: string | null,
	address: Address,
	uses: { billing: boolean; shipping: boolean }
): Promise< Customer > {
	return apiFetch< Customer >( {
		path:
			addressId === null
				? `${ BASE }/customers/${ id }/addresses`
				: `${ BASE }/customers/${ id }/addresses/${ addressId }`,
		method: addressId === null ? 'POST' : 'PUT',
		data: { address, ...uses },
	} );
}

export function removeAddress(
	id: number,
	addressId: string
): Promise< Customer > {
	return apiFetch< Customer >( {
		path: `${ BASE }/customers/${ id }/addresses/${ addressId }`,
		method: 'DELETE',
	} );
}

export function fetchNotes( id: number ): Promise< CustomerNote[] > {
	return apiFetch< CustomerNote[] >( {
		path: `${ BASE }/customers/${ id }/notes`,
	} );
}

export function addNote( id: number, text: string ): Promise< CustomerNote > {
	return apiFetch< CustomerNote >( {
		path: `${ BASE }/customers/${ id }/notes`,
		method: 'POST',
		data: { text },
	} );
}

export function removeNote( id: number, noteId: number ): Promise< unknown > {
	return apiFetch( {
		path: `${ BASE }/customers/${ id }/notes/${ noteId }`,
		method: 'DELETE',
	} );
}

export const EXEMPTION_REASONS = [
	'resale',
	'nonprofit',
	'government',
	'other',
] as const;

/** A customer's exemption from tax, from the certificate they gave the shop. */
export interface TaxExemption {
	id: number;
	customer_id: number;
	reason: ( typeof EXEMPTION_REASONS )[ number ];
	certificate: string;
	/** Countries ("US") and states ("US-TX") it holds in; empty for everywhere. */
	regions: string[];
	/** Its last day, Y-m-d; null when it does not expire. */
	expires_on: string | null;
	note: string;
	created_at: string;
	/** "pending" while the shop checks one the customer sent; only an approved one exempts. */
	status: 'pending' | 'approved' | 'refused';
	/** Why the shop refused it, as the customer was told. */
	refusal: string | null;
	reviewed_at: string | null;
	/** The name of the copy the customer sent; null for none. */
	file: string | null;
}

export type TaxExemptionInput = Pick<
	TaxExemption,
	'reason' | 'certificate' | 'regions' | 'expires_on' | 'note'
>;

export function fetchTaxExemptions( id: number ): Promise< TaxExemption[] > {
	return apiFetch< TaxExemption[] >( {
		path: `${ BASE }/customers/${ id }/tax-exemptions`,
	} );
}

export function addTaxExemption(
	id: number,
	exemption: TaxExemptionInput
): Promise< TaxExemption > {
	return apiFetch< TaxExemption >( {
		path: `${ BASE }/customers/${ id }/tax-exemptions`,
		method: 'POST',
		data: exemption,
	} );
}

export function removeTaxExemption(
	id: number,
	exemptionId: number
): Promise< unknown > {
	return apiFetch( {
		path: `${ BASE }/customers/${ id }/tax-exemptions/${ exemptionId }`,
		method: 'DELETE',
	} );
}

export function approveTaxExemption(
	id: number,
	exemptionId: number,
	held: { regions: string[]; expires_on: string | null }
): Promise< TaxExemption > {
	return apiFetch< TaxExemption >( {
		path: `${ BASE }/customers/${ id }/tax-exemptions/${ exemptionId }/approve`,
		method: 'POST',
		data: held,
	} );
}

export function refuseTaxExemption(
	id: number,
	exemptionId: number,
	reason: string
): Promise< TaxExemption > {
	return apiFetch< TaxExemption >( {
		path: `${ BASE }/customers/${ id }/tax-exemptions/${ exemptionId }/refuse`,
		method: 'POST',
		data: { reason },
	} );
}

export function fetchNewsletterSettings(): Promise< NewsletterSettings > {
	return apiFetch< NewsletterSettings >( {
		path: `${ BASE }/newsletter-settings`,
	} );
}

export function saveNewsletterSettings(
	settings: Pick< NewsletterSettings, 'offered' | 'consent' > & {
		lists: string[];
	}
): Promise< NewsletterSettings > {
	return apiFetch< NewsletterSettings >( {
		path: `${ BASE }/newsletter-settings`,
		method: 'PUT',
		data: settings,
	} );
}

export function fetchGroups(): Promise< CustomerGroup[] > {
	return apiFetch< CustomerGroup[] >( { path: `${ BASE }/customer-groups` } );
}

export function saveGroup(
	id: number | null,
	name: string
): Promise< CustomerGroup > {
	return apiFetch< CustomerGroup >( {
		path:
			id === null
				? `${ BASE }/customer-groups`
				: `${ BASE }/customer-groups/${ id }`,
		method: id === null ? 'POST' : 'PUT',
		data: { name },
	} );
}

export function deleteGroup( id: number ): Promise< unknown > {
	return apiFetch( {
		path: `${ BASE }/customer-groups/${ id }`,
		method: 'DELETE',
	} );
}

export function setCustomerGroups(
	id: number,
	groupIds: number[]
): Promise< { group_ids: number[] } > {
	return apiFetch< { group_ids: number[] } >( {
		path: `${ BASE }/customers/${ id }/groups`,
		method: 'PUT',
		data: { group_ids: groupIds },
	} );
}

export function fetchCustomerSettings(): Promise< CustomerSettings > {
	return apiFetch< CustomerSettings >( {
		path: `${ BASE }/customer-settings`,
	} );
}

export function saveCustomerSettings(
	accounts: CustomerSettings[ 'accounts' ]
): Promise< CustomerSettings > {
	return apiFetch< CustomerSettings >( {
		path: `${ BASE }/customer-settings`,
		method: 'PUT',
		data: { accounts },
	} );
}
