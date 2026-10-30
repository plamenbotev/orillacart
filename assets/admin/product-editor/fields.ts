import { __ } from '@wordpress/i18n';

import type { Backorders, Sale, StockMode } from '../catalog/api';
import { fromLocal, toLocal } from '../shared/dates';

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
 * The day a moment falls on in the shop's calendar (Settings → General's time zone), for a date field.
 *
 * @param value An ISO 8601 date-time from the API, or null.
 * @return The day, "2026-10-17"; empty for null.
 */
export function toDateField( value: string | null ): string {
	return value === null ? '' : toLocal( value ).slice( 0, 10 );
}

/**
 * The start of a day in the shop's calendar.
 *
 * @param value A date field's value, such as "2026-10-17".
 * @return The shop's midnight that day, in UTC, or null for an empty field.
 */
export function fromDateField( value: string ): string | null {
	return value === '' ? null : fromLocal( `${ value }T00:00` );
}

/**
 * The last day of a period that ends before a moment (a sale's until, which is not in the sale): the day before
 * when the moment is the shop's midnight, as the box saves it.
 *
 * @param value The moment the period ends at, ISO 8601, or null.
 * @return The last day, "2026-10-17"; empty for null.
 */
export function toLastDayField( value: string | null ): string {
	if ( value === null ) {
		return '';
	}
	const local = toLocal( value );
	return local.slice( 11, 16 ) === '00:00'
		? nextDay( local.slice( 0, 10 ), -1 )
		: local.slice( 0, 10 );
}

/**
 * The end of a period whose last day is chosen: the shop's midnight after it, so the whole day is in.
 *
 * @param value A date field's value, the last day, such as "2026-10-17".
 * @return The shop's midnight the next day, in UTC, or null for an empty field.
 */
export function fromLastDayField( value: string ): string | null {
	return value === '' ? null : fromLocal( `${ nextDay( value, 1 ) }T00:00` );
}

/**
 * @param day  A day, "2026-10-17".
 * @param days How many days on, below zero for back.
 * @return That day, "2026-10-18".
 */
function nextDay( day: string, days: number ): string {
	const at = new Date( `${ day }T12:00:00Z` );
	at.setUTCDate( at.getUTCDate() + days );
	return at.toISOString().slice( 0, 10 );
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
