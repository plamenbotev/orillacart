/**
 * Lists of product ids the browser keeps for the shopper (wishlist, products viewed, purchases tracked).
 */

/**
 * A list of product ids the browser keeps, newest first; empty where storage is off.
 *
 * @param key The storage key.
 */
export function storedIds( key: string ): number[] {
	try {
		const value: unknown = JSON.parse(
			window.localStorage.getItem( key ) ?? '[]'
		);
		return Array.isArray( value )
			? value.filter(
					( id ): id is number => Number.isInteger( id ) && id > 0
				)
			: [];
	} catch {
		return [];
	}
}

/**
 * Keeps the list, or forgets it when empty.
 *
 * @param key The storage key.
 * @param ids The product ids, newest first.
 */
export function storeIds( key: string, ids: number[] ): void {
	try {
		if ( ids.length === 0 ) {
			window.localStorage.removeItem( key );
		} else {
			window.localStorage.setItem( key, JSON.stringify( ids ) );
		}
	} catch {
		// Storage off (a private window): the list lasts the page.
	}
}
