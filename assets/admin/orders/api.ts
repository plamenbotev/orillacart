import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

import type { Address } from '../shared/address';

export const STATUSES = [
	'pending_payment',
	'on_hold',
	'processing',
	'shipped',
	'completed',
	'cancelled',
	'failed',
	'refunded',
] as const;
export type Status = ( typeof STATUSES )[ number ];

export interface OrderSummary {
	number: number;
	status: Status;
	customer: string;
	email: string;
	customer_id: number | null;
	items: number;
	total: string;
	currency: string;
	payment_name: string;
	placed_at: string;
}

export interface OrderPage {
	orders: OrderSummary[];
	total: number;
	page: number;
	pages: number;
}

export interface OrderLine {
	product_id: number;
	variation_id: number | null;
	name: string;
	sku: string | null;
	options: { attribute: string; option: string }[];
	quantity: number;
	unit_price: string;
	total: string;
	tax: string;
	option_ids: number[];
	/** How many the shop owes: they were not in stock when the order was placed. */
	backordered: number;
	/** Whether the customer was told of the backorder. */
	backorder_shown: boolean;
}

export interface OrderNote {
	text: string;
	author: 'system' | 'shop' | 'customer';
	for_customer: boolean;
	created_at: string;
}

export interface Order {
	number: number;
	key: string;
	status: Status;
	email: string;
	billing: Address;
	shipping_address: Address | null;
	lines: OrderLine[];
	shipping: string | null;
	shipping_total: string;
	shipping_tax: string;
	taxes: { name: string; percent: string; amount: string }[];
	subtotal: string;
	items_tax: string;
	total: string;
	currency: string;
	payment_gateway: string | null;
	payment_name: string;
	payment_note: string;
	note: string;
	placed_at: string;
	paid_at: string | null;
	customer_id: number | null;
	transaction_id: string | null;
	notes: OrderNote[];
	next_statuses: Status[];
	downloads: {
		id: number;
		name: string;
		product: string;
		remaining: number | null;
		expires_at: string | null;
		downloads: number;
	}[];
	shipping_rate_id: number | null;
}

export interface OrderQuery {
	status?: Status | undefined;
	search?: string | undefined;
	page: number;
	per_page: number;
}

export interface EditedItem {
	product_id: number;
	option_ids: number[];
	quantity: number;
	unit_price: string | null;
}

export const EMAILS = [
	'order_received',
	'order_processing',
	'order_shipped',
	'order_completed',
	'order_cancelled',
	'order_failed',
	'order_refunded',
	'new_order',
] as const;

const BASE = '/orillacart/v1';

export function fetchOrders( query: OrderQuery ): Promise< OrderPage > {
	return apiFetch< OrderPage >( {
		path: addQueryArgs( `${ BASE }/orders`, { ...query } ),
	} );
}

export function fetchOrder( id: number ): Promise< Order > {
	return apiFetch< Order >( { path: `${ BASE }/orders/${ id }` } );
}

export function changeStatus(
	id: number,
	status: Status,
	restock: boolean,
	note: string
): Promise< Order > {
	return apiFetch< Order >( {
		path: `${ BASE }/orders/${ id }/status`,
		method: 'PUT',
		data: { status, restock, note },
	} );
}

export function addNote(
	id: number,
	text: string,
	forCustomer: boolean
): Promise< Order > {
	return apiFetch< Order >( {
		path: `${ BASE }/orders/${ id }/notes`,
		method: 'POST',
		data: { text, for_customer: forCustomer },
	} );
}

export function changeContact(
	id: number,
	email: string,
	billing: Address,
	shippingAddress: Address | null
): Promise< Order > {
	return apiFetch< Order >( {
		path: `${ BASE }/orders/${ id }/contact`,
		method: 'PUT',
		data: { email, billing, shipping_address: shippingAddress },
	} );
}

export function editItems(
	id: number,
	items: EditedItem[],
	rateId: number | null
): Promise< Order > {
	return apiFetch< Order >( {
		path: `${ BASE }/orders/${ id }/items`,
		method: 'PUT',
		data: { items, rate_id: rateId },
	} );
}

export function sendEmail(
	id: number,
	kind: string
): Promise< { sent: boolean } > {
	return apiFetch< { sent: boolean } >( {
		path: `${ BASE }/orders/${ id }/emails`,
		method: 'POST',
		data: { kind },
	} );
}

export function deleteOrder( id: number ): Promise< unknown > {
	return apiFetch( { path: `${ BASE }/orders/${ id }`, method: 'DELETE' } );
}

/**
 * Moves the order to a customer's account; null makes it a guest's order.
 * @param id
 * @param customerId
 */
export function assignOrder(
	id: number,
	customerId: number | null
): Promise< Order > {
	return apiFetch< Order >( {
		path: `${ BASE }/orders/${ id }/customer`,
		method: 'PUT',
		data: { customer_id: customerId },
	} );
}

export interface ShippingOption {
	rate_id: number;
	carrier: string;
	name: string;
	total: string;
}

/**
 * The ways the order can ship to its address, with its items as they are.
 * @param id
 */
export function fetchShippingOptions(
	id: number
): Promise< ShippingOption[] > {
	return apiFetch< ShippingOption[] >( {
		path: `${ BASE }/orders/${ id }/shipping-options`,
	} );
}

/**
 * @param id        The order.
 * @param grantId   The bought file.
 * @param remaining Downloads left; null for no limit.
 * @param expiresAt The last day (YYYY-MM-DD); null for no end.
 */
export function changeDownloadAllowance(
	id: number,
	grantId: number,
	remaining: number | null,
	expiresAt: string | null
): Promise< Order > {
	return apiFetch< Order >( {
		path: `${ BASE }/orders/${ id }/downloads/${ grantId }`,
		method: 'PUT',
		data: { remaining, expires_at: expiresAt },
	} );
}
