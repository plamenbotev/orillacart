/**
 * The storefront's events for analytics, as Google Analytics 4 names them: view_item, add_to_cart,
 * begin_checkout and purchase. Each fires orillacart:<event> on the document, with the event's data (currency,
 * value, items) as its detail, and goes to the dataLayer too when the shop says so (Settings → Storefront).
 */
import { storedIds, storeIds } from './storage';

export type EcommerceItem = {
	item_id?: string;
	item_name: string;
	item_variant?: string;
	price: number;
	quantity: number;
};

export type Ecommerce = {
	currency: string;
	value: number;
	items: EcommerceItem[];
	transaction_id?: string;
};

type CartLine = {
	id: number;
	product_id: number;
	name: string;
	sku: string | null;
	options: { attribute: string; option: string }[];
	unit_price: string;
};

const PURCHASES = 'orillacart_purchases';

/**
 * Fires the event, and pushes it to the dataLayer when the shop sends events there.
 *
 * @param event The event's GA4 name.
 * @param data  Its data.
 */
export function track( event: string, data: Ecommerce ): void {
	document.dispatchEvent(
		new CustomEvent( `orillacart:${ event }`, { detail: data } )
	);
	if ( window.orillacartStorefront?.dataLayer ) {
		const page = window as unknown as { dataLayer?: unknown[] };
		page.dataLayer = page.dataLayer ?? [];
		// GA4 asks to clear the previous event's ecommerce first.
		page.dataLayer.push( { ecommerce: null } );
		page.dataLayer.push( { event, ecommerce: data } );
	}
}

/**
 * The events the page carries: the product viewed, the cart checked out, the order placed (once per browser,
 * however often its receipt is opened).
 */
export function startAnalytics(): void {
	document
		.querySelectorAll< HTMLScriptElement >(
			'script[data-orillacart-event]'
		)
		.forEach( ( script ) => {
			const event = script.dataset.orillacartEvent ?? '';
			let data: Ecommerce;
			try {
				data = JSON.parse( script.textContent ?? '' ) as Ecommerce;
			} catch {
				return;
			}
			if ( event === 'purchase' ) {
				const order = Number( data.transaction_id );
				const sent = storedIds( PURCHASES );
				if ( sent.includes( order ) ) {
					return;
				}
				storeIds( PURCHASES, [ order, ...sent ].slice( 0, 50 ) );
			}
			track( event, data );
		} );
}

/**
 * The add_to_cart event, from the cart the shop answered with: the line of the product added.
 *
 * @param lines     The cart's lines, as the shop answered.
 * @param productId The product added.
 * @param quantity  How many were added.
 */
export function trackAdded(
	lines: CartLine[] | undefined,
	productId: number,
	quantity: number
): void {
	// Analytics never stand in the cart's way: without lines, no event.
	const line = ( lines ?? [] )
		.filter( ( candidate ) => candidate.product_id === productId )
		.sort( ( a, b ) => b.id - a.id )[ 0 ];
	const currency = window.orillacartStorefront?.currency;
	if ( ! line || ! currency ) {
		return;
	}
	const price = Number( line.unit_price );
	const variant = line.options
		.map( ( option ) => `${ option.attribute }: ${ option.option }` )
		.join( ', ' );
	track( 'add_to_cart', {
		currency,
		value: price * quantity,
		items: [
			{
				item_id: line.sku ?? String( line.product_id ),
				item_name: line.name,
				...( variant ? { item_variant: variant } : {} ),
				price,
				quantity,
			},
		],
	} );
}
