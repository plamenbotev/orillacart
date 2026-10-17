import { __ } from '@wordpress/i18n';

import type { Status } from './api';

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
	order_shipped: __( 'Order shipped', 'orillacart' ),
	order_completed: __( 'Order completed', 'orillacart' ),
	order_cancelled: __( 'Order cancelled', 'orillacart' ),
	order_failed: __( 'Payment failed', 'orillacart' ),
	order_refunded: __( 'Order refunded', 'orillacart' ),
	customer_note: __( 'Note to the customer', 'orillacart' ),
	new_order: __( 'New order (to the shop)', 'orillacart' ),
};

/**
 * @param amount   A decimal string from the API.
 * @param currency ISO 4217 code.
 * @return The amount for display, such as "12.50 EUR".
 */
export function money( amount: string, currency: string ): string {
	const number = Number( amount );

	return `${ Number.isFinite( number ) ? number.toFixed( 2 ) : amount } ${ currency }`;
}

/**
 * @param date An ISO 8601 date from the API.
 * @return The date and time in the browser's locale.
 */
export function when( date: string ): string {
	return new Date( date ).toLocaleString();
}
