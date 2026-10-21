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
