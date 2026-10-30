/**
 * The cart page: quantities and removals go to the API one after another, then the cart is shown anew from the
 * server once, after the last of them, with the focus back where it was.
 */
import { __ } from '@wordpress/i18n';

import { api } from './api';
import { bindCoupons } from './coupons';
import { failure, reloadRegions, showMessage } from './dom';

// Changes run one after another, in the order made; the cart is shown anew after the last.
let changes: Promise< void > = Promise.resolve();
let waiting = 0;
let failed: string | null = null;

export function bindCart( root: Document ): void {
	bindCoupons( root, async () => {
		await reloadRegions( [ 'cart', 'mini-cart' ] );
	} );

	root.addEventListener( 'change', ( event ) => {
		const input = event.target;
		if (
			! ( input instanceof HTMLInputElement ) ||
			! input.dataset.orillacartQuantity
		) {
			return;
		}
		const quantity = Number( input.value );
		if ( ! Number.isInteger( quantity ) || quantity < 1 ) {
			input.value = input.defaultValue;
			showMessage(
				cartRegion(),
				__(
					'Enter a quantity of 1 or more, or remove the item.',
					'orillacart'
				),
				'error'
			);
			return;
		}
		queue( 'PUT', `/cart/items/${ input.dataset.orillacartQuantity }`, {
			quantity,
		} );
	} );

	root.addEventListener( 'click', ( event ) => {
		const button =
			event.target instanceof Element
				? event.target.closest< HTMLButtonElement >(
						'[data-orillacart-remove]'
					)
				: null;
		if ( ! button || button.disabled ) {
			return;
		}
		button.disabled = true;
		queue(
			'DELETE',
			`/cart/items/${ button.dataset.orillacartRemove ?? '' }`
		);
	} );
}

function cartRegion(): Element | null {
	return document.querySelector( '[data-orillacart-region="cart"]' );
}

function queue( method: 'PUT' | 'DELETE', path: string, body?: unknown ): void {
	++waiting;
	cartRegion()?.setAttribute( 'aria-busy', 'true' );
	changes = changes.then( () => change( method, path, body ) );
}

async function change(
	method: 'PUT' | 'DELETE',
	path: string,
	body?: unknown
): Promise< void > {
	const result = await api( method, path, body );
	if ( ! result.ok ) {
		failed = failure( result );
	}
	if ( --waiting > 0 ) {
		return;
	}
	// The field being typed in is found again in the new cart by its line.
	const active = cartRegion()?.ownerDocument.activeElement;
	const focused =
		active instanceof HTMLInputElement
			? active.dataset.orillacartQuantity
			: undefined;
	try {
		await reloadRegions( [ 'cart', 'mini-cart' ] );
	} catch {
		failed ??= __(
			'The cart could not be shown anew. Reload the page.',
			'orillacart'
		);
	}
	cartRegion()?.removeAttribute( 'aria-busy' );
	if ( focused !== undefined ) {
		document
			.querySelector< HTMLInputElement >(
				`[data-orillacart-quantity="${ CSS.escape( focused ) }"]`
			)
			?.focus();
	}
	if ( failed !== null ) {
		showMessage( cartRegion(), failed, 'error' );
		failed = null;
	}
}
