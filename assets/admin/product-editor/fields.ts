import { __ } from '@wordpress/i18n';

import type { Backorders, Sale, StockMode } from '../catalog/api';

export const STOCK_MODES: { label: string; value: StockMode }[] = [
	{ label: __( 'Count stock', 'orillacart' ), value: 'tracked' },
	{
		label: __( 'Always available', 'orillacart' ),
		value: 'always_available',
	},
	{ label: __( 'Not available', 'orillacart' ), value: 'unavailable' },
];

export const BACKORDERS: { label: string; value: Backorders }[] = [
	{ label: __( 'Stop selling', 'orillacart' ), value: 'no' },
	{ label: __( 'Take backorders', 'orillacart' ), value: 'yes' },
	{
		label: __( 'Take backorders and tell the customer', 'orillacart' ),
		value: 'notify',
	},
];

/**
 * @param value An ISO 8601 date-time from the API, or null.
 * @return The date part, for a date field.
 */
export function toDateField( value: string | null ): string {
	return value === null ? '' : value.slice( 0, 10 );
}

/**
 * @param value A date field's value, such as "2026-10-17".
 * @return Midnight UTC that day, or null for an empty field.
 */
export function fromDateField( value: string ): string | null {
	return value === '' ? null : `${ value }T00:00:00+00:00`;
}

/**
 * @param sale  The sale being edited, or null.
 * @param price The sale price field; empty removes the sale.
 * @return The sale with the new price.
 */
export function withSalePrice( sale: Sale | null, price: string ): Sale | null {
	return price.trim() === ''
		? null
		: { from: null, until: null, ...sale, price };
}

/**
 * @param value A text field's value.
 * @return The value, or null when empty.
 */
export function orNull( value: string ): string | null {
	return value.trim() === '' ? null : value;
}
