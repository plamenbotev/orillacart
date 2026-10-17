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
