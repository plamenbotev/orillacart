/**
 * Product cards for lists the browser keeps (a visitor's wishlist, the products viewed), from GET /product-cards.
 */
import { api } from './api';
import { bindAddToCart } from './product';

/**
 * Fills the list with the cards of the products still for show, in order, and binds their buttons.
 *
 * @param list       The list (ul) the cards go in.
 * @param productIds The products, in order.
 * @return The ids of the products shown.
 */
export async function showCards(
	list: HTMLElement,
	productIds: number[]
): Promise< number[] > {
	if ( productIds.length === 0 ) {
		list.replaceChildren();
		return [];
	}
	const result = await api< { html: string; ids: number[] } >(
		'GET',
		`/product-cards?ids=${ productIds.join( ',' ) }`
	);
	if ( ! result.ok ) {
		return [];
	}
	// The shop's own template, rendered by the server for these ids.
	list.innerHTML = result.data.html;
	list.querySelectorAll< HTMLFormElement >(
		'form[data-orillacart-add-to-cart]'
	).forEach( bindAddToCart );
	return result.data.ids;
}

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
