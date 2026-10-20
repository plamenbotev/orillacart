import apiFetch from '@wordpress/api-fetch';

export interface Stockroom {
	id: number;
	name: string;
	description: string;
	min_delivery_days: number;
	max_delivery_days: number;
	active: boolean;
	/** How the times are shown and entered; they are kept in days. */
	delivery_unit: 'days' | 'weeks';
	/** The shop hears when an item here is down to this many or fewer; null for the shop's. */
	low_stock_threshold: number | null;
}

export type StockroomInput = Omit< Stockroom, 'id' >;

export type StockItemKind = 'product' | 'variation' | 'option';

export interface Stock {
	/** Such as "product:12". */
	item: string;
	levels: { stockroom_id: number; quantity: number }[];
	/** How many can be sold; null when stock does not limit the item. */
	available: number | null;
}

const BASE = '/orillacart/v1';

export function fetchStockrooms(): Promise< Stockroom[] > {
	return apiFetch< Stockroom[] >( { path: `${ BASE }/stockrooms` } );
}

export function saveStockroom(
	id: number | null,
	input: StockroomInput
): Promise< Stockroom > {
	return apiFetch< Stockroom >(
		id === null
			? { path: `${ BASE }/stockrooms`, method: 'POST', data: input }
			: {
					path: `${ BASE }/stockrooms/${ id }`,
					method: 'PUT',
					data: input,
				}
	);
}

export function deleteStockroom( id: number ): Promise< unknown > {
	return apiFetch( {
		path: `${ BASE }/stockrooms/${ id }`,
		method: 'DELETE',
	} );
}

export function fetchStock(
	kind: StockItemKind,
	id: number
): Promise< Stock > {
	return apiFetch< Stock >( { path: `${ BASE }/stock/${ kind }/${ id }` } );
}

export function saveStock(
	kind: StockItemKind,
	id: number,
	levels: Stock[ 'levels' ]
): Promise< Stock > {
	return apiFetch< Stock >( {
		path: `${ BASE }/stock/${ kind }/${ id }`,
		method: 'PUT',
		data: { levels },
	} );
}
