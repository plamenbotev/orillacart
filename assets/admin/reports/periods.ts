import { __ } from '@wordpress/i18n';

/**
 * A day as the API takes it.
 * @param date
 */
export const day = ( date: Date ): string =>
	`${ date.getFullYear() }-${ String( date.getMonth() + 1 ).padStart(
		2,
		'0'
	) }-${ String( date.getDate() ).padStart( 2, '0' ) }`;

const addDays = ( date: Date, days: number ): Date =>
	new Date( date.getFullYear(), date.getMonth(), date.getDate() + days );

export interface Preset {
	key: string;
	label: string;
	/** The first and last day, from today. */
	range: ( today: Date ) => [ Date, Date ];
}

/** The periods the screen offers at a click; any other is chosen by its dates. */
export const PRESETS: Preset[] = [
	{
		key: 'today',
		label: __( 'Today', 'orillacart' ),
		range: ( today ) => [ today, today ],
	},
	{
		key: 'yesterday',
		label: __( 'Yesterday', 'orillacart' ),
		range: ( today ) => [ addDays( today, -1 ), addDays( today, -1 ) ],
	},
	{
		key: '7d',
		label: __( 'Last 7 days', 'orillacart' ),
		range: ( today ) => [ addDays( today, -6 ), today ],
	},
	{
		key: '30d',
		label: __( 'Last 30 days', 'orillacart' ),
		range: ( today ) => [ addDays( today, -29 ), today ],
	},
	{
		key: 'month',
		label: __( 'This month', 'orillacart' ),
		range: ( today ) => [
			new Date( today.getFullYear(), today.getMonth(), 1 ),
			today,
		],
	},
	{
		key: 'last-month',
		label: __( 'Last month', 'orillacart' ),
		range: ( today ) => [
			new Date( today.getFullYear(), today.getMonth() - 1, 1 ),
			new Date( today.getFullYear(), today.getMonth(), 0 ),
		],
	},
	{
		key: 'quarter',
		label: __( 'This quarter', 'orillacart' ),
		range: ( today ) => [
			new Date(
				today.getFullYear(),
				Math.floor( today.getMonth() / 3 ) * 3,
				1
			),
			today,
		],
	},
	{
		key: 'year',
		label: __( 'This year', 'orillacart' ),
		range: ( today ) => [ new Date( today.getFullYear(), 0, 1 ), today ],
	},
	{
		key: '12m',
		label: __( 'Last 12 months', 'orillacart' ),
		range: ( today ) => [
			new Date(
				today.getFullYear() - 1,
				today.getMonth(),
				today.getDate() + 1
			),
			today,
		],
	},
];

/**
 * @param from  The first day, Y-m-d.
 * @param to    The last day, Y-m-d.
 * @param today
 * @return The preset that covers these days today, or "custom".
 */
export function presetOf( from: string, to: string, today: Date ): string {
	return (
		PRESETS.find( ( preset ) => {
			const [ start, end ] = preset.range( today );
			return day( start ) === from && day( end ) === to;
		} )?.key ?? 'custom'
	);
}

/**
 * The change from one figure to another, as a percentage; null when there is nothing to compare with.
 *
 * @param current  This period's figure.
 * @param previous The compared period's.
 */
export function change(
	current: number,
	previous: number | null | undefined
): number | null {
	if ( previous === null || previous === undefined ) {
		return null;
	}
	if ( previous === 0 ) {
		return current === 0 ? 0 : null;
	}
	return ( ( current - previous ) / Math.abs( previous ) ) * 100;
}
