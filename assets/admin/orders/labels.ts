import { __ } from '@wordpress/i18n';

import type { Status } from './api';
import { shopDateTime } from '../shared/dates';

export const STATUS_LABELS: Record< Status, string > = {
	pending_payment: __( 'Pending payment', 'orillacart' ),
	on_hold: __( 'On hold', 'orillacart' ),
	processing: __( 'Processing', 'orillacart' ),
	shipped: __( 'Shipped', 'orillacart' ),
	completed: __( 'Completed', 'orillacart' ),
	cancelled: __( 'Cancelled', 'orillacart' ),
	failed: __( 'Failed', 'orillacart' ),
	refunded: __( 'Refunded', 'orillacart' ),
};

export const EMAIL_LABELS: Record< string, string > = {
	order_received: __( 'Order received (awaiting payment)', 'orillacart' ),
	order_processing: __( 'Order confirmed', 'orillacart' ),
	order_backordered: __( 'Items on backorder', 'orillacart' ),
	tracking_added: __( 'Shipment booked, with its tracking', 'orillacart' ),
	order_shipped: __( 'Order shipped', 'orillacart' ),
	ready_for_pickup: __( 'Ready to collect', 'orillacart' ),
	order_completed: __( 'Order completed', 'orillacart' ),
	payment_reminder: __( 'Reminder to pay', 'orillacart' ),
	order_cancelled: __( 'Order cancelled', 'orillacart' ),
	order_failed: __( 'Payment failed', 'orillacart' ),
	order_refunded: __( 'Order refunded', 'orillacart' ),
	customer_note: __( 'Note to the customer', 'orillacart' ),
	receipt_link: __(
		'New link to the order (when the customer’s expired)',
		'orillacart'
	),
	account_welcome: __( 'Welcome to a new account', 'orillacart' ),
	back_in_stock: __( 'Back in stock, to those who asked', 'orillacart' ),
	gift_card: __( 'A gift card, to whom it is for', 'orillacart' ),
	cart_reminder: __( 'Cart reminder, to those who asked', 'orillacart' ),
	new_order: __( 'New order (to the shop)', 'orillacart' ),
	order_attention: __(
		'An order needs attention (to the shop)',
		'orillacart'
	),
	payment_failed: __( 'Payment failed (to the shop)', 'orillacart' ),
	refund_made: __( 'Refund made (to the shop)', 'orillacart' ),
	low_stock: __( 'Stock runs low (to the shop, daily)', 'orillacart' ),
	exemption_received: __(
		'Tax exemption certificate to check (to the shop)',
		'orillacart'
	),
	exemption_reviewed: __(
		'Tax exemption certificate approved or refused',
		'orillacart'
	),
	exemption_expiring: __(
		'Tax exemption certificate runs out in a month',
		'orillacart'
	),
};

declare global {
	interface Window {
		/** How the shop shows amounts; printed by AdminScripts. */
		orillacartMoney?: {
			currency: string;
			sign: string;
			position: string;
			decimals: number;
			/** The site language's separators, as the storefront uses them. */
			thousands?: string;
			point?: string;
		};
	}
}

/**
 * The decimals of a currency's amounts, as ISO 4217 has them (yen none, Kuwaiti dinars three).
 *
 * @param currency The currency code.
 */
export function currencyDecimals( currency: string ): number {
	try {
		return (
			new Intl.NumberFormat( 'en', {
				style: 'currency',
				currency,
			} ).resolvedOptions().maximumFractionDigits ?? 2
		);
	} catch {
		return 2;
	}
}

/**
 * An amount as the shop shows it, as the storefront does: the shop's decimals for its own currency and the
 * currency's for another (an old order's), the site language's separators, and the currency sign where the shop
 * puts it (another currency shows its code).
 *
 * @param amount   A decimal string from the API.
 * @param currency The amount's currency code.
 * @return The formatted amount.
 */
export function money( amount: string, currency: string ): string {
	const shop = window.orillacartMoney ?? {
		currency,
		sign: '',
		position: 'after_space',
		decimals: 2,
	};
	const decimals =
		currency === shop.currency
			? shop.decimals
			: currencyDecimals( currency );
	const number = Number( amount );
	let digits = amount;
	if ( Number.isFinite( number ) ) {
		const [ whole = '0', fraction = '' ] = Math.abs( number )
			.toFixed( decimals )
			.split( '.' );
		const grouped = whole.replace(
			/\B(?=(\d{3})+(?!\d))/g,
			shop.thousands ?? ','
		);
		digits =
			( number < 0 ? '-' : '' ) +
			grouped +
			( fraction === '' ? '' : ( shop.point ?? '.' ) + fraction );
	}
	const sign =
		currency === shop.currency && shop.sign !== '' ? shop.sign : currency;
	switch ( shop.position ) {
		case 'before':
			return `${ sign }${ digits }`;
		case 'before_space':
			return `${ sign } ${ digits }`;
		case 'after':
			return `${ digits }${ sign }`;
		default:
			return `${ digits } ${ sign }`;
	}
}

/**
 * @param date An ISO 8601 date from the API.
 * @return The date and time on the shop's clock, in the browser's language.
 */
export function when( date: string ): string {
	return shopDateTime( date );
}
