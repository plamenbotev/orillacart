import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

import type { Address } from '../shared/address';

export interface Customer {
	id: number;
	email: string;
	first_name: string;
	last_name: string;
	registered_at: string;
	billing: Address | null;
	shipping_address: Address | null;
	orders: number;
	/** The customer groups they are in. */
	group_ids: number[];
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

export function saveAddresses(
	id: number,
	billing: Address | null,
	shippingAddress: Address | null
): Promise< Customer > {
	return apiFetch< Customer >( {
		path: `${ BASE }/customers/${ id }/addresses`,
		method: 'PUT',
		data: { billing, shipping_address: shippingAddress },
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
