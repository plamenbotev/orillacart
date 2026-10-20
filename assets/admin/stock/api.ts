import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

import type { StockItemKind } from '../inventory/api';

export interface StockRow {
	kind: StockItemKind;
	id: number;
	/** The product to edit for it; null for an option of an attribute set. */
	product_id: number | null;
	name: string;
	/** For an option: the set or product it belongs to. */
	owner: string;
	sku: string | null;
	stock_mode: 'tracked' | 'always_available' | 'unavailable';
	/** By stockroom id; a stockroom without a level of the item is left out. */
	levels: Record< string, number >;
}

export interface StockSheet {
	stockrooms: { id: number; name: string }[];
	rows: StockRow[];
	total: number;
	page: number;
	pages: number;
}

export interface StockSheetQuery {
	kind: 'items' | 'options';
	stockroom: number | null;
	category: number | null;
	search: string;
	parent: number | null;
	page: number;
}

export interface LevelChange {
	kind: StockItemKind;
	id: number;
	stockroom_id: number;
	/** Null removes the stockroom's level of the item. */
	quantity: number | null;
}

const BASE = '/orillacart/v1';

export function fetchStockSheet(
	query: StockSheetQuery
): Promise< StockSheet > {
	const args: Record< string, string | number > = {
		kind: query.kind,
		page: query.page,
		per_page: 25,
	};
	if ( query.stockroom !== null ) {
		args.stockroom = query.stockroom;
	}
	if ( query.category !== null ) {
		args.category = query.category;
	}
	if ( query.search.trim() !== '' ) {
		args.search = query.search.trim();
	}
	if ( query.parent !== null ) {
		args.parent = query.parent;
	}
	return apiFetch< StockSheet >( {
		path: addQueryArgs( `${ BASE }/stock-sheet`, args ),
	} );
}

export function changeLevels( changes: LevelChange[] ): Promise< void > {
	return apiFetch( {
		path: `${ BASE }/stock-levels`,
		method: 'PATCH',
		data: { changes },
	} );
}

export interface Category {
	id: number;
	name: string;
	parent: number;
}

/** Product categories, each under its parent, with their depth for indenting. */
export async function fetchCategories(): Promise<
	( Category & { depth: number } )[]
> {
	// Every category, past the API's 100 a page: apiFetch reads each page for per_page=-1.
	const all = await apiFetch< Category[] >( {
		path: '/wp/v2/orillacart_category?per_page=-1&_fields=id,name,parent&orderby=name',
	} );
	const decode = document.createElement( 'textarea' );
	const ordered: ( Category & { depth: number } )[] = [];
	const add = ( parent: number, depth: number ) => {
		for ( const category of all.filter(
			( item ) => item.parent === parent
		) ) {
			decode.innerHTML = category.name;
			ordered.push( { ...category, name: decode.value, depth } );
			add( category.id, depth + 1 );
		}
	};
	add( 0, 0 );
	return ordered;
}

export type StockReason =
	'sale' | 'release' | 'return' | 'count' | 'import' | 'bulk_edit';

/** One move of a stock level. */
export interface Movement {
	id: number;
	kind: StockItemKind;
	item_id: number;
	/** The product to open for it; null for an option of an attribute set. */
	product_id: number | null;
	name: string;
	sku: string | null;
	stockroom_id: number;
	stockroom: string;
	/** Up when positive. */
	delta: number;
	/** The stockroom's level after it; below zero by what is owed. */
	after: number;
	reason: StockReason;
	order_id: number | null;
	/** Who made it; null for the shop itself, as with an order. */
	user: string | null;
	at: string;
}

export interface StockHistory {
	movements: Movement[];
	/** What moved in and out in all, by stockroom and reason. */
	totals: {
		reason: StockReason;
		stockroomId: number;
		stockroom: string;
		in: number;
		out: number;
	}[];
	total: number;
	page: number;
	pages: number;
}

export interface StockHistoryQuery {
	product: number | null;
	stockroom: number | null;
	reason: StockReason | null;
	/** ISO 8601 moments; null for no bound. */
	from: string | null;
	before: string | null;
	page: number;
}

/**
 * @param query Which movements, newest first.
 */
export function fetchStockHistory(
	query: StockHistoryQuery
): Promise< StockHistory > {
	return apiFetch< StockHistory >( {
		path: addQueryArgs(
			`${ BASE }/stock-history`,
			Object.fromEntries(
				Object.entries( { ...query, per_page: 50 } ).filter(
					( [ , value ] ) => value !== null
				)
			)
		),
	} );
}
