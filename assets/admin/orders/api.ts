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

/** Paid orders are refunded in full before they can be deleted. */
export const PAID_STATUSES: readonly Status[] = [
	'processing',
	'shipped',
	'completed',
];

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
	/** For a bundle: the variations chosen and the optional products added. */
	bundle_choices?: number[];
	/** How many the shop owes: they were not in stock when the order was placed. */
	backordered: number;
	/** Whether the customer was told of the backorder. */
	backorder_shown: boolean;
	refunded_quantity: number;
	/** What coupons took off the total; the tax is on what is left. */
	discount: string;
	/** What one bundle holds, for a bundle. */
	contents?: { name: string; quantity: number }[];
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
	/** How it ships: "rate:12", or "carrier:5:express" for a carrier's own service. */
	shipping_option: string | null;
	pickup_point_id: string | null;
	/** The office or locker it goes to: its name and address. */
	pickup_point: string | null;
	/** What was given back, in all. */
	refunded_total: string;
	shipping_refunded: boolean;
	/** What coupons took off the items; the subtotal is before it. */
	discount_total: string;
	discounts: {
		code: string;
		description: string;
		amount: string;
		free_shipping: boolean;
		/** An automatic discount: shown by its description. */
		automatic?: boolean;
	}[];
	/** Answers to the questions add-ons ask at checkout, with the labels they had. */
	fields: { id: string; label: string; answer: string }[];
}

/** A button an add-on puts on the order screen. */
export interface OrderAction {
	id: string;
	label: string;
}

export interface Refund {
	id: number;
	/** Through the gateway, recorded as made outside the shop, or made at the provider's own site. */
	method: 'provider' | 'manual' | 'external';
	/** Pending: the provider did not answer; trying again never refunds twice. */
	status: 'pending' | 'done' | 'failed';
	amount: string;
	currency: string;
	lines: { line: number; quantity: number }[];
	shipping: boolean;
	restock: boolean;
	reason: string;
	provider_reference: string | null;
	provider_pending: boolean;
	error: string | null;
	created_at: string;
	settled_at: string | null;
}

export interface OrderRefunds {
	refundable: string;
	currency: string;
	paid: boolean;
	/** The payment method's name when it gives money back through its provider. */
	provider: string | null;
	refunds: Refund[];
}

export interface NewRefund {
	amount: string;
	lines: { line: number; quantity: number }[];
	shipping: boolean;
	restock: boolean;
	reason: string;
	outside: boolean;
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
	/** For a bundle: the variations chosen and the optional products added. */
	bundle_choices?: number[];
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
	note: string
): Promise< Order > {
	return apiFetch< Order >( {
		path: `${ BASE }/orders/${ id }/status`,
		method: 'PUT',
		data: { status, note },
	} );
}

export function fetchRefunds( id: number ): Promise< OrderRefunds > {
	return apiFetch< OrderRefunds >( {
		path: `${ BASE }/orders/${ id }/refunds`,
	} );
}

export function refundOrder(
	id: number,
	refund: NewRefund
): Promise< OrderRefunds > {
	return apiFetch< OrderRefunds >( {
		path: `${ BASE }/orders/${ id }/refunds`,
		method: 'POST',
		data: refund,
	} );
}

export function retryRefund( id: number ): Promise< OrderRefunds > {
	return apiFetch< OrderRefunds >( {
		path: `${ BASE }/refunds/${ id }/retry`,
		method: 'POST',
	} );
}

export function giveUpRefund( id: number ): Promise< OrderRefunds > {
	return apiFetch< OrderRefunds >( {
		path: `${ BASE }/refunds/${ id }/give-up`,
		method: 'POST',
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
	shippingAddress: Address | null,
	fields: Record< string, string > = {}
): Promise< Order > {
	return apiFetch< Order >( {
		path: `${ BASE }/orders/${ id }/contact`,
		method: 'PUT',
		data: { email, billing, shipping_address: shippingAddress, fields },
	} );
}

export function fetchOrderActions( id: number ): Promise< OrderAction[] > {
	return apiFetch< OrderAction[] >( {
		path: `${ BASE }/orders/${ id }/actions`,
	} );
}

/**
 * Runs an add-on's action; its answer is recorded in the order's history.
 *
 * @param id     The order number.
 * @param action The action's id.
 */
export function runOrderAction(
	id: number,
	action: string
): Promise< { message: string; order: Order } > {
	return apiFetch< { message: string; order: Order } >( {
		path: `${ BASE }/orders/${ id }/actions/${ action }`,
		method: 'POST',
	} );
}

export function editItems(
	id: number,
	items: EditedItem[],
	shippingOption: string | null,
	pickupPoint: string | null = null
): Promise< Order > {
	return apiFetch< Order >( {
		path: `${ BASE }/orders/${ id }/items`,
		method: 'PUT',
		data: {
			items,
			shipping_option: shippingOption,
			pickup_point: pickupPoint,
		},
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
	id: string;
	/** The service delivers to an office or locker the customer chooses. */
	to_pickup_point?: boolean;
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

export interface OrderQuote {
	lines: {
		name: string;
		options: { attribute: string; option: string }[];
		quantity: number;
		unit_price: string;
		total: string;
		tax: string;
		backordered: number;
	}[];
	needs_shipping: boolean;
	shipping_options: ( ShippingOption & { price: string; tax: string } )[];
	shipping_option: string | null;
	payment_options: { gateway: string; name: string; instructions: string }[];
	/** Where the chosen service can deliver, when it delivers to offices or lockers. */
	pickup_points: PickupPoint[];
	taxes: { name: string; percent: string; total: string }[];
	subtotal: string;
	items_tax: string;
	shipping: string;
	shipping_tax: string;
	total: string;
	currency: string;
	/** The shop's automatic discounts that hold for the order. */
	discount: string;
	discounts: { code: string; description: string; amount: string }[];
}

export interface QuoteQuery {
	items: EditedItem[];
	billing_country: string | null;
	billing_state: string | null;
	shipping_country: string | null;
	shipping_state: string | null;
	shipping_postcode: string | null;
	shipping_option: string | null;
	/** Whose order: automatic discounts for groups or first orders depend on it. */
	customer_id: number | null;
	email: string | null;
}

/**
 * Prices an order the shop is taking, before it is created.
 * @param query
 */
export function quoteOrder( query: QuoteQuery ): Promise< OrderQuote > {
	return apiFetch< OrderQuote >( {
		path: `${ BASE }/orders/quote`,
		method: 'POST',
		data: query,
	} );
}

export interface NewOrder {
	email: string;
	billing: Address;
	shipping_address: Address | null;
	items: EditedItem[];
	customer_id: number | null;
	shipping_option: string | null;
	payment: string | null;
	paid: boolean;
	note: string;
	/** Whether the customer gets the order received or confirmed email; the shop never does. */
	email_customer: boolean;
	pickup_point: string | null;
}

/**
 * Takes an order for the shop: paid, it starts processing; otherwise it waits on hold for the payment.
 * @param order
 */
export function createOrder( order: NewOrder ): Promise< Order > {
	return apiFetch< Order >( {
		path: `${ BASE }/orders`,
		method: 'POST',
		data: order,
	} );
}

export interface PickupPoint {
	id: string;
	name: string;
	address: string;
	hours: string;
}

export interface Shipment {
	id: number;
	number: string;
	carrier: string;
	tracking_url: string | null;
	status: 'booked' | 'on_the_way' | 'delivered' | 'returned' | 'cancelled';
	/** The courier's last words about it. */
	tracking: string;
	booked_at: string;
}

export interface OrderShipments {
	/** Whether the order's carrier books shipments with its courier. */
	bookable: boolean;
	carrier: string | null;
	shipments: Shipment[];
}

export function fetchShipments( orderId: number ): Promise< OrderShipments > {
	return apiFetch< OrderShipments >( {
		path: `${ BASE }/orders/${ orderId }/shipments`,
	} );
}

export function bookShipment(
	orderId: number,
	parcels: number
): Promise< Shipment > {
	return apiFetch< Shipment >( {
		path: `${ BASE }/orders/${ orderId }/shipments`,
		method: 'POST',
		data: { parcels },
	} );
}

export function cancelShipment( id: number ): Promise< Shipment > {
	return apiFetch< Shipment >( {
		path: `${ BASE }/shipments/${ id }/cancel`,
		method: 'POST',
	} );
}

/**
 * The shipment's label as a file: the API sends it base64 in JSON.
 * @param id
 */
export async function fetchLabel( id: number ): Promise< File > {
	const label = await apiFetch< {
		filename: string;
		mime_type: string;
		content: string;
	} >( { path: `${ BASE }/shipments/${ id }/label` } );
	const bytes = Uint8Array.from( window.atob( label.content ), ( char ) =>
		char.charCodeAt( 0 )
	);
	return new File( [ bytes ], label.filename, { type: label.mime_type } );
}

/**
 * The offices or lockers a service delivers the order to near its address.
 * @param orderId
 * @param shippingOption
 */
export function fetchPickupPoints(
	orderId: number,
	shippingOption: string
): Promise< PickupPoint[] > {
	return apiFetch< PickupPoint[] >( {
		path: addQueryArgs( `${ BASE }/orders/${ orderId }/pickup-points`, {
			shipping_option: shippingOption,
		} ),
	} );
}
