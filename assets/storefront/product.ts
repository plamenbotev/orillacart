/**
 * Add-to-cart forms, on product pages and in the shop's grid, and the mini cart's count.
 */
import { __, _n, sprintf } from '@wordpress/i18n';

import { api } from './api';
import { showBackInStock } from './backinstock';
import { clearErrors, failure, formBody, showErrors, showMessage } from './dom';
import { bindGallery, type Gallery, type Picture } from './gallery';

type CartSummary = { items: number };

type BundleQuote = {
	price: string | null;
	/** Rendered by the shop's own template (escaped there). */
	price_html: string;
	for_sale: boolean;
	available: number | null;
};

type Variation = {
	id: number;
	option_ids: number[];
	sku: string | null;
	for_sale: boolean;
	available: number | null;
	/** Out of stock, sold on backorder, and the customer is told. */
	on_backorder: boolean;
	/** For sale but none in stock: a visitor may ask to hear when it is back. */
	sold_out: boolean;
	price: string;
	image: Picture | null;
	gallery: Picture[];
	description: string;
};

/**
 * Shows the number of items in every mini cart on the page: the count, the "N items in cart" line, and the
 * empty or the full part of mini carts with buttons.
 *
 * @param items How many items the cart holds.
 */
export function setCartCount( items: number ): void {
	document
		.querySelectorAll( '[data-orillacart-cart-count]' )
		.forEach( ( count ) => {
			count.textContent = String( items );
		} );
	document
		.querySelectorAll( '[data-orillacart-cart-items]' )
		.forEach( ( line ) => {
			line.textContent = sprintf(
				/* translators: %d: how many items the cart holds */
				_n(
					'%d item in cart.',
					'%d items in cart.',
					items,
					'orillacart'
				),
				items
			);
		} );
	document
		.querySelectorAll< HTMLElement >( '[data-orillacart-cart-empty]' )
		.forEach( ( part ) => {
			part.hidden = items > 0;
		} );
	document
		.querySelectorAll< HTMLElement >( '[data-orillacart-cart-full]' )
		.forEach( ( part ) => {
			part.hidden = items === 0;
		} );
}

/** Pages with a mini cart may come from a page cache: it is corrected from the cart itself. */
export async function refreshCartCount(): Promise< void > {
	if ( ! document.querySelector( '[data-orillacart-region="mini-cart"]' ) ) {
		return;
	}
	const result = await api< CartSummary >( 'GET', '/cart' );
	if ( result.ok ) {
		setCartCount( result.data.items );
	}
}

export function bindAddToCart( form: HTMLFormElement ): void {
	const variations = JSON.parse(
		form.dataset.variations ?? '[]'
	) as Variation[];
	const product = form.closest( '.orillacart-product' );
	const gallery = product ? bindGallery( product ) : null;
	if ( variations.length > 0 ) {
		form.addEventListener( 'change', () =>
			showVariation( form, variations, gallery )
		);
		showVariation( form, variations, gallery );
	}
	const bundle = Number( form.dataset.bundle ?? 0 );
	if ( bundle > 0 ) {
		form.addEventListener( 'change', ( event ) => {
			if (
				( event.target as HTMLElement ).matches(
					'[data-orillacart-bundle-choice]'
				)
			) {
				void showBundle( form, bundle );
			}
		} );
		void showBundle( form, bundle );
	}

	form.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();
		const button = form.querySelector< HTMLButtonElement >(
			'button[type="submit"]'
		);
		clearErrors( form );
		if ( button ) {
			button.disabled = true;
		}
		// The field that tells the server a browser without the script posted the form is not the API's.
		const { orillacart_add_to_cart: _withoutScript, ...body } = formBody(
			form,
			{
				lists: [ 'option_ids', 'bundle_choices' ],
				numbers: [ 'product_id', 'quantity' ],
			}
		);
		const result = await api< CartSummary >( 'POST', '/cart/items', {
			option_ids: [],
			bundle_choices: [],
			...body,
		} );
		if ( button ) {
			button.disabled = false;
		}
		if ( result.ok ) {
			setCartCount( result.data.items );
			const cart = form.dataset.cartUrl;
			showMessage(
				form,
				__( 'Added to your cart.', 'orillacart' ),
				'success',
				cart
					? { url: cart, label: __( 'View cart', 'orillacart' ) }
					: undefined
			);
			return;
		}
		showMessage(
			form,
			failure( result, showErrors( form, result.violations ) ),
			'error'
		);
	} );
}

/** The latest bundle quote asked for on each form: an older answer arriving late is ignored. */
const bundleRequests = new WeakMap< HTMLFormElement, number >();

/**
 * Shows a bundle's price and stock with the items the customer chose, as the shop prices them.
 * @param form   The add-to-cart form.
 * @param bundle The bundle's product id.
 */
async function showBundle(
	form: HTMLFormElement,
	bundle: number
): Promise< void > {
	const request = ( bundleRequests.get( form ) ?? 0 ) + 1;
	bundleRequests.set( form, request );
	const { bundle_choices: choices = [] } = formBody( form, {
		lists: [ 'bundle_choices' ],
	} );
	const result = await api< BundleQuote >(
		'POST',
		`/products/${ bundle }/bundle-quote`,
		{ bundle_choices: choices }
	);
	if ( bundleRequests.get( form ) !== request ) {
		return;
	}
	const button = form.querySelector< HTMLButtonElement >(
		'button[type="submit"]'
	);
	const product = form.closest( '.orillacart-product' );
	const stock = product?.querySelector( '[data-orillacart-stock]' );
	if ( ! result.ok ) {
		if ( button ) {
			button.disabled = true;
		}
		return;
	}
	const price = product?.querySelector( '[data-orillacart-price]' );
	if ( price ) {
		price.innerHTML = result.data.price_html;
	}
	if ( stock ) {
		if ( ! result.data.for_sale ) {
			stock.textContent = __( 'Not available', 'orillacart' );
		} else {
			stock.textContent =
				result.data.available === null
					? ''
					: sprintf(
							/* translators: %d: how many are in stock */ __(
								'%d in stock',
								'orillacart'
							),
							result.data.available
						);
		}
	}
	const quantity = form.querySelector< HTMLInputElement >(
		'input[name="quantity"]'
	);
	if ( quantity ) {
		quantity.max = String( result.data.available ?? 999 );
	}
	if ( button ) {
		button.disabled = ! result.data.for_sale;
	}
}

/**
 * Shows the price, SKU and stock of the variation the chosen options make, once every choice is made.
 * @param form       The add-to-cart form.
 * @param variations The product's variations.
 * @param gallery
 */
function showVariation(
	form: HTMLFormElement,
	variations: Variation[],
	gallery: Gallery | null
): void {
	const chosen = Array.from(
		form.querySelectorAll< HTMLSelectElement >( '[data-orillacart-option]' )
	)
		.map( ( select ) => Number( select.value ) )
		.filter( ( id ) => id > 0 )
		.sort( ( a, b ) => a - b );
	const variation = variations.find(
		( candidate ) =>
			candidate.option_ids.length === chosen.length &&
			candidate.option_ids.every(
				( id, index ) => id === chosen[ index ]
			)
	);
	const product = form.closest( '.orillacart-product' );
	const button = form.querySelector< HTMLButtonElement >(
		'button[type="submit"]'
	);
	const stock = product?.querySelector( '[data-orillacart-stock]' );
	const choices = form.querySelectorAll( '[data-orillacart-option]' ).length;

	const description = product?.querySelector< HTMLElement >(
		'[data-orillacart-variation-description]'
	);
	if ( description ) {
		description.textContent = variation?.description ?? '';
		description.hidden = ! variation?.description;
	}
	gallery?.use(
		variation
			? [
					...( variation.image ? [ variation.image ] : [] ),
					...variation.gallery.filter(
						( picture ) => picture.id !== variation.image?.id
					),
				]
			: null
	);

	showBackInStock(
		product,
		variation?.id ?? null,
		variation?.sold_out ?? false
	);
	if ( ! variation ) {
		if ( stock && chosen.length > 0 && chosen.length === choices ) {
			stock.textContent = __(
				'This combination is not sold.',
				'orillacart'
			);
		}
		if ( button ) {
			button.disabled = chosen.length > 0 && chosen.length === choices;
		}
		return;
	}
	const price = product?.querySelector( '[data-orillacart-price]' );
	const sku = product?.querySelector( '[data-orillacart-sku]' );
	const quantity = form.querySelector< HTMLInputElement >(
		'input[name="quantity"]'
	);
	if ( price ) {
		// Rendered by the shop's own template (escaped there): the variation's price as the page shows prices.
		price.innerHTML = variation.price;
	}
	if ( sku && variation.sku ) {
		sku.textContent = variation.sku;
	}
	if ( stock ) {
		if ( ! variation.for_sale ) {
			stock.textContent = __( 'Not available', 'orillacart' );
		} else if ( variation.on_backorder ) {
			stock.textContent = __(
				'On backorder: ships when it is back in stock',
				'orillacart'
			);
		} else {
			stock.textContent =
				variation.available === null
					? ''
					: sprintf(
							/* translators: %d: how many are in stock */ __(
								'%d in stock',
								'orillacart'
							),
							variation.available
						);
		}
	}
	if ( quantity ) {
		quantity.max = String( variation.available ?? 999 );
	}
	if ( button ) {
		button.disabled = ! variation.for_sale;
	}
}
