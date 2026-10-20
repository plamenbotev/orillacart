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
