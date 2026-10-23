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

/** Cancelled, failed and refunded orders are over. */
export const CLOSED_STATUSES: readonly Status[] = [
	'cancelled',
	'failed',
	'refunded',
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
	/** The shop has to look at it, such as a payment disputed after it was paid. */
	needs_attention: boolean;
	/** The money came, given back since or not: the order stays, with its invoice, for the books. */
	paid: boolean;
}

export interface OrderPage {
	orders: OrderSummary[];
	total: number;
	page: number;
	pages: number;
}

/** One rate charged on a line, by jurisdiction, as returns are filed. */
export interface LineTax {
	name: string;
	percent: string;
	country: string;
	region: string | null;
	level: 'country' | 'region' | 'local';
	/** The code it is filed under. */
	code: string;
	jurisdiction: string;
	/** What it was charged on, before tax. */
	taxable: string;
	amount: string;
	compound: boolean;
	/** What refunds gave back of it. */
	refunded: string;
}

/** A change to a line's price after its unit price; below zero when it lowered it. */
export interface Adjustment {
	/** Null on a quote, before the order is saved. */
	id: number | null;
	kind: 'coupon' | 'automatic' | 'manual' | 'add_on' | 'adjuster';
	/** The change it is a share of, the same on every line it was spread over; null for coupons. */
	change: string | null;
	/** The coupon's code, the add-on's id, or the shop manager's user id. */
	source: string;
	label: string;
	amount: string;
	/** A share of one amount for the whole order, or this line's own. */
	scope: 'order' | 'line';
	free_shipping: boolean;
	reason: string | null;
}

export interface OrderLine {
	/** An item, the shipping or a fee. */
	kind?: 'item' | 'shipping' | 'fee';
	/** Its place in the order, as refunds and edits name it. */
	position?: number;
	taxes?: LineTax[];
	adjustments?: Adjustment[];
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
	/** Items of a refunded order that can still go back in stock. */
	restockable: number;
	/** What coupons took off the total; the tax is on what is left. */
	discount: string;
	/** What one bundle holds, for a bundle. */
	contents?: { name: string; quantity: number }[];
	/** The answers to the shop's product forms, as sold: a file by its name, with the upload to download. */
	personalisation?: {
		id: string;
		label: string;
		answer: string;
		price: string | null;
		upload_id: number | null;
	}[];
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
	/** What gift cards and store credit paid of the total, each of them, and the rest paid the chosen way. */
	gift_card_total?: string;
	gift_cards?: { label: string; amount: string }[];
	amount_due?: string;
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
	/** What it pays besides its items and shipping, as its prices are. */
	fees: { label: string; amount: string }[];
	fees_total: string;
	fees_tax: string;
	/** The day the customer chose for delivery, "2026-10-21", and the time of day. */
	delivery_date: string | null;
	delivery_slot: string | null;
	/** Why the shop has to look at it, one reason a line; null when nothing. */
	attention: string | null;
	/** The business customer's VAT number, and whether it bought without VAT. */
	vat_number: string | null;
	reverse_charge: boolean;
	/** The customer's details were erased, as a privacy request asked. */
	anonymised: boolean;
	/** The amounts include tax: the taxes are in the total, not added to it. */
	prices_include_tax?: boolean;
	/** What is left to give back. */
	refundable?: string;
	/** The shipping and the fees, as lines with their taxes and adjustments. */
	charges?: OrderLine[];
	/** The shop's and add-ons' changes to its price, each once. */
	price_changes?: PriceChangeMade[];
	/** All the tax charged: on the items, the shipping and the fees. */
	tax?: string;
	/** The customer's exemption from tax that covered the order. */
	tax_exemption?: { reason: string; certificate: string } | null;
}

/** A button an add-on puts on the order screen. */
export interface OrderAction {
	id: string;
	label: string;
}

export interface Refund {
	id: number;
	/** Through the gateway, recorded as made outside the shop, or made at the provider's own site. */
	method: 'provider' | 'manual' | 'external' | 'store_credit';
	/**
	 * Pending: the provider did not answer; trying again never refunds twice. Accepted: the provider sends the
	 * money later; the order learns of it once it is done.
	 */
	status: 'pending' | 'accepted' | 'done' | 'failed';
	amount: string;
	currency: string;
	lines: { line: number; quantity: number }[];
	shipping: boolean;
	restock: boolean;
	reason: string;
	provider_reference: string | null;
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
	/** The money goes back as store credit, or for a guest as a gift card. */
	store_credit: boolean;
}

/** Whether it was paid, and given back since. */
export const PAID = [
	'unpaid',
	'paid',
	'partly_refunded',
	'refunded',
] as const;
export type Paid = ( typeof PAID )[ number ];

/** The choices the list's filters offer, from what the shop's orders have used. */
export interface OrderFacets {
	shipping: string[];
	countries: string[];
	currencies: string[];
	/** The order's own facts, then what other areas and add-ons mark. */
	has: { value: string; label: string }[];
}

export interface OrderQuery {
	/** One status or several, comma-separated. */
	status?: string | undefined;
	search?: string | undefined;
	/** Only orders the shop has to look at. */
	attention?: boolean | undefined;
	/** Moments, as the shop's days begin: placed from the first, before the second. */
	placed_from?: string | undefined;
	placed_before?: string | undefined;
	/** A payment gateway's id. */
	payment?: string | undefined;
	min_total?: string | undefined;
	max_total?: string | undefined;
	placed_by?: 'account' | 'guest' | undefined;
	order_by?: 'number' | 'total' | undefined;
	order?: 'asc' | 'desc' | undefined;
	paid?: Paid | undefined;
	/** Whether it has left for the customer; "no" only for orders that ship. */
	shipped?: 'yes' | 'no' | undefined;
	/** The way it ships, as orders name it. */
	shipping?: string | undefined;
	/** The billing or shipping country. */
	country?: string | undefined;
	/** The delivery day chosen at checkout, Y-m-d, both ends included. */
	delivery_from?: string | undefined;
	delivery_until?: string | undefined;
	/** What it has, comma-separated: each narrows the list. */
	has?: string | undefined;
	currency?: string | undefined;
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
	/** The order's line it continues, by its place from 0: it keeps that line's answers to the product's forms. */
	line?: number;
}

export const EMAILS = [
	'order_received',
	'payment_reminder',
	'order_processing',
	'order_shipped',
	'order_completed',
	'order_cancelled',
	'order_failed',
	'new_order',
] as const;

const BASE = '/orillacart/v1';

export function fetchOrders( query: OrderQuery ): Promise< OrderPage > {
	return apiFetch< OrderPage >( {
		path: addQueryArgs( `${ BASE }/orders`, { ...query } ),
	} );
}

export function fetchOrderFacets(): Promise< OrderFacets > {
	return apiFetch< OrderFacets >( { path: `${ BASE }/orders/facets` } );
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

/** An invoice or credit note of an order, with its printable page. */
export interface Invoice {
	number: string;
	kind: 'invoice' | 'credit_note';
	amount: string;
	currency: string;
	issued_at: string;
	url: string;
}

export function fetchInvoices( id: number ): Promise< Invoice[] > {
	return apiFetch< Invoice[] >( {
		path: `${ BASE }/orders/${ id }/invoices`,
	} );
}

/**
 * The shop says the money came: an order waiting for it is fulfilled, one being fulfilled keeps its status.
 * @param id
 * @param note
 */
export function markPaid( id: number, note: string ): Promise< Order > {
	return apiFetch< Order >( {
		path: `${ BASE }/orders/${ id }/paid`,
		method: 'POST',
		data: { note },
	} );
}

/**
 * Items of a refunded order came back: they go back in stock.
 * @param id
 * @param lines
 */
export function restockOrder(
	id: number,
	lines: { line: number; quantity: number }[]
): Promise< Order > {
	return apiFetch< Order >( {
		path: `${ BASE }/orders/${ id }/restock`,
		method: 'POST',
		data: { lines },
	} );
}

/**
 * The shop looked at what the order needed attention for.
 * @param id
 */
export function markHandled( id: number ): Promise< Order > {
	return apiFetch< Order >( {
		path: `${ BASE }/orders/${ id }/attention`,
		method: 'DELETE',
	} );
}

export function anonymiseOrder( id: number ): Promise< Order > {
	return apiFetch< Order >( {
		path: `${ BASE }/orders/${ id }/anonymise`,
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

/** One change the shop or an add-on made to an order's price, once however many lines it was spread over. */
export interface PriceChangeMade {
	change: string;
	kind: 'manual' | 'add_on' | 'adjuster';
	/** The shop manager's user id, or the add-on's id. */
	source: string;
	label: string;
	/** What all its shares add up to. */
	amount: string;
	/** The item's place among the order's items; null for the whole order. */
	line: number | null;
	reason: string | null;
	/** An add-on's order adjuster works its own out again, so it cannot be taken off. */
	removable: boolean;
}

/** A change to the price of an order on hold: below zero to lower it; `line` is an item's place, null for the whole order. */
export interface PriceChange {
	amount: string;
	label: string;
	line: number | null;
	reason: string;
}

export function adjustPrice(
	id: number,
	change: PriceChange
): Promise< Order > {
	return apiFetch< Order >( {
		path: `${ BASE }/orders/${ id }/adjustments`,
		method: 'POST',
		data: change,
	} );
}

export function removeAdjustment(
	id: number,
	change: string
): Promise< Order > {
	return apiFetch< Order >( {
		path: `${ BASE }/orders/${ id }/adjustments/${ change }`,
		method: 'DELETE',
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
	/** What it pays besides its items and shipping, such as for the way to pay. */
	fees: { label: string; amount: string }[];
	fees_tax: string;
}

export interface QuoteQuery {
	items: EditedItem[];
	billing_country: string | null;
	billing_state: string | null;
	billing_postcode: string | null;
	shipping_country: string | null;
	shipping_state: string | null;
	shipping_postcode: string | null;
	shipping_option: string | null;
	/** Whose order: automatic discounts for groups or first orders depend on it. */
	customer_id: number | null;
	email: string | null;
	/** The way it is paid, for its fee. */
	payment: string | null;
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

/** How many of one of the order's lines a shipment carries, or are left to send; lines count from 0. */
export interface ShippedItem {
	line: number;
	name: string;
	quantity: number;
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
	items: ShippedItem[];
	/** Each parcel's number, when there is one each. */
	parcels: { number: string; tracking_url: string | null }[];
	/** The shop sent it itself, and says when it arrived. */
	sent_by_shop: boolean;
}

export interface OrderShipments {
	/** Whether the order's carrier books shipments with its courier. */
	bookable: boolean;
	carrier: string | null;
	shipments: Shipment[];
	/** The items not in a shipment yet. */
	left: ShippedItem[];
	/** Whether what is left can be sent. */
	sendable: boolean;
	/** The shop's place the customer collects the order from, for a pickup. */
	pickup: string | null;
	/** Whether the shop can say it is ready there, and that it was collected. */
	collectable: boolean;
}

export function fetchShipments( orderId: number ): Promise< OrderShipments > {
	return apiFetch< OrderShipments >( {
		path: `${ BASE }/orders/${ orderId }/shipments`,
	} );
}

/** What a new shipment carries: quantities of lines; empty for everything left. */
export type ShipItems = { line: number; quantity: number }[];

export function bookShipment(
	orderId: number,
	parcels: number,
	items: ShipItems = []
): Promise< Shipment > {
	return apiFetch< Shipment >( {
		path: `${ BASE }/orders/${ orderId }/shipments`,
		method: 'POST',
		data: { parcels, items },
	} );
}

/**
 * Records a shipment the shop sent itself, with each parcel's tracking number.
 * @param orderId
 * @param input
 * @param input.carrier
 * @param input.parcels
 * @param input.items
 */
export function sendShipment(
	orderId: number,
	input: {
		carrier: string;
		parcels: { number: string; tracking_url: string | null }[];
		items: ShipItems;
	}
): Promise< Shipment > {
	return apiFetch< Shipment >( {
		path: `${ BASE }/orders/${ orderId }/shipments/sent`,
		method: 'POST',
		data: input,
	} );
}

/**
 * Tells the customer the order is ready at the place they collect it from.
 * @param orderId
 */
export function readyForPickup( orderId: number ): Promise< void > {
	return apiFetch< void >( {
		path: `${ BASE }/orders/${ orderId }/ready-for-pickup`,
		method: 'POST',
	} );
}

/**
 * The customer collected the order: it is completed.
 * @param orderId
 */
export function pickedUp( orderId: number ): Promise< void > {
	return apiFetch< void >( {
		path: `${ BASE }/orders/${ orderId }/picked-up`,
		method: 'POST',
	} );
}

/**
 * A shipment the shop sent itself arrived, or came back.
 * @param id
 * @param status
 */
export function shipmentArrived(
	id: number,
	status: 'delivered' | 'returned'
): Promise< Shipment > {
	return apiFetch< Shipment >( {
		path: `${ BASE }/shipments/${ id }/arrived`,
		method: 'POST',
		data: { status },
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

/** A customer's request to send items back, and where it stands. */
export interface ItemReturn {
	id: number;
	status: 'requested' | 'approved' | 'refused' | 'received' | 'refunded';
	items: { line: number; name: string; quantity: number }[];
	reason: string;
	reason_label: string;
	comment: string;
	/** What the shop told the customer when it answered. */
	answer: string;
	/** What refunding the items gives back, with their tax. */
	amount: string;
	requested_at: string;
	answered_at: string | null;
	received_at: string | null;
	refunded_at: string | null;
}

export interface OrderReturns {
	/** Whether the customer may ask for a return now. */
	accepting: boolean;
	/** The last day they may, "2026-11-19". */
	until: string | null;
	returnable: { line: number; name: string; quantity: number }[];
	returns: ItemReturn[];
}

export function fetchReturns( orderId: number ): Promise< OrderReturns > {
	return apiFetch< OrderReturns >( {
		path: `${ BASE }/orders/${ orderId }/returns`,
	} );
}

/**
 * Moves a return on: approve or refuse it with an answer for the customer, say the items came back, or refund them.
 * @param id           The return.
 * @param step         What happens to it.
 * @param data         What the step takes.
 * @param data.answer  For approving or refusing: what the customer reads.
 * @param data.outside For refunding: the shop gave the money back itself.
 */
export function moveReturn(
	id: number,
	step: 'approve' | 'refuse' | 'receive' | 'refund',
	data: { answer?: string; outside?: boolean } = {}
): Promise< ItemReturn > {
	return apiFetch< ItemReturn >( {
		path: `${ BASE }/returns/${ id }/${ step }`,
		method: 'POST',
		data,
	} );
}
