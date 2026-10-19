/**
 * The orders list's view (filters, search, sort and page) as the API asks for it, and as the screen's address
 * keeps it, so a reload, the back button or a shared link shows the same list.
 */
import type { Filter, View } from '@wordpress/dataviews';

import { fromLocal } from '../shared/dates';
import { type OrderQuery, type Status, STATUSES } from './api';

/** The address's query arguments the list keeps, by the filter or setting they hold. */
const ARGS = {
	status: 'status',
	attention: 'attention',
	payment: 'payment',
	placed_by: 'placed_by',
	placed_at: 'placed',
	total: 'total',
	search: 's',
	page: 'paged',
	sort: 'orderby',
	direction: 'order',
} as const;

const one = ( filter: Filter | undefined ): string | undefined =>
	typeof filter?.value === 'string' && filter.value !== ''
		? filter.value
		: undefined;

const pair = ( filter: Filter | undefined ): [ string, string ] | undefined =>
	Array.isArray( filter?.value ) &&
	typeof filter.value[ 0 ] === 'string' &&
	typeof filter.value[ 1 ] === 'string'
		? [ filter.value[ 0 ], filter.value[ 1 ] ]
		: undefined;

/**
 * The day after a Y-m-d day, for "placed before the next day".
 * @param day The day.
 */
const nextDay = ( day: string ): string => {
	const [ year, month, date ] = day.split( '-' ).map( Number );
	const next = new Date(
		Date.UTC( year ?? 1970, ( month ?? 1 ) - 1, date ?? 1 )
	);
	next.setUTCDate( next.getUTCDate() + 1 );
	return next.toISOString().slice( 0, 10 );
};

const startOf = ( day: string ): string | undefined =>
	fromLocal( `${ day }T00:00` ) ?? undefined;

/**
 * What the API is asked for the view.
 * @param view The list's view.
 */
export function ordersQuery( view: View ): OrderQuery {
	const filter = ( field: string ) =>
		view.filters?.find( ( item ) => item.field === field );
	const status = one( filter( 'status' ) );
	const placedBy = one( filter( 'placed_by' ) );
	const placed = filter( 'placed_at' );
	const total = filter( 'total' );
	const query: OrderQuery = {
		status:
			status !== undefined &&
			( STATUSES as readonly string[] ).includes( status )
				? ( status as Status )
				: undefined,
		attention: one( filter( 'attention' ) ) === 'yes' ? true : undefined,
		payment: one( filter( 'payment' ) ),
		placed_by:
			placedBy === 'account' || placedBy === 'guest'
				? placedBy
				: undefined,
		search: view.search === '' ? undefined : view.search,
		page: view.page ?? 1,
		per_page: view.perPage ?? 20,
	};
	const days = pair( placed );
	if ( placed?.operator === 'between' && days ) {
		query.placed_from = startOf( days[ 0 ] );
		query.placed_before = startOf( nextDay( days[ 1 ] ) );
	} else if ( placed?.operator === 'on' && one( placed ) ) {
		query.placed_from = startOf( one( placed ) as string );
		query.placed_before = startOf( nextDay( one( placed ) as string ) );
	} else if ( placed?.operator === 'after' && one( placed ) ) {
		query.placed_from = startOf( nextDay( one( placed ) as string ) );
	} else if ( placed?.operator === 'before' && one( placed ) ) {
		query.placed_before = startOf( one( placed ) as string );
	}
	const amount =
		typeof total?.value === 'number' ? String( total.value ) : undefined;
	if ( total?.operator === 'greaterThanOrEqual' ) {
		query.min_total = amount;
	} else if ( total?.operator === 'lessThanOrEqual' ) {
		query.max_total = amount;
	}
	if ( view.sort?.field === 'total' || view.sort?.field === 'number' ) {
		query.order_by = view.sort.field;
		query.order = view.sort.direction;
	}
	return query;
}

/**
 * The view as the address keeps it: only what differs from the default list.
 * @param view The list's view.
 */
export function viewArgs( view: View ): Record< string, string > {
	const args: Record< string, string > = {};
	for ( const filter of view.filters ?? [] ) {
		const name = ARGS[ filter.field as keyof typeof ARGS ];
		if ( name === undefined || filter.value === undefined ) {
			continue;
		}
		const value = Array.isArray( filter.value )
			? filter.value.join( '..' )
			: String( filter.value );
		args[ name ] =
			filter.field === 'placed_at' || filter.field === 'total'
				? `${ filter.operator }:${ value }`
				: value;
	}
	if ( view.search ) {
		args[ ARGS.search ] = view.search;
	}
	if ( ( view.page ?? 1 ) > 1 ) {
		args[ ARGS.page ] = String( view.page );
	}
	if ( view.sort ) {
		args[ ARGS.sort ] = view.sort.field;
		args[ ARGS.direction ] = view.sort.direction;
	}
	return args;
}

/**
 * The view an address asks for, on top of the default one.
 * @param search   The address's query string.
 * @param defaults The list's default view.
 */
export function viewFromArgs( search: string, defaults: View ): View {
	const args = new URLSearchParams( search );
	const filters: Filter[] = [];
	for ( const field of [
		'status',
		'attention',
		'payment',
		'placed_by',
	] as const ) {
		const value = args.get( ARGS[ field ] );
		if ( value ) {
			filters.push( { field, operator: 'is', value } );
		}
	}
	for ( const field of [ 'placed_at', 'total' ] as const ) {
		const [ operator, value ] = ( args.get( ARGS[ field ] ) ?? '' ).split(
			':'
		);
		if ( ! operator || value === undefined || value === '' ) {
			continue;
		}
		if ( field === 'total' ) {
			filters.push( {
				field,
				operator,
				value: Number( value ),
			} as Filter );
		} else {
			filters.push( {
				field,
				operator,
				value: value.includes( '..' ) ? value.split( '..' ) : value,
			} as Filter );
		}
	}
	const sort = args.get( ARGS.sort );
	const direction = args.get( ARGS.direction );
	return {
		...defaults,
		filters,
		search: args.get( ARGS.search ) ?? '',
		page: Math.max( 1, Number( args.get( ARGS.page ) ?? '1' ) || 1 ),
		...( ( sort === 'number' || sort === 'total' ) &&
		( direction === 'asc' || direction === 'desc' )
			? { sort: { field: sort, direction } }
			: {} ),
	};
}
