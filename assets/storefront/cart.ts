/**
 * The cart page: quantities and removals go to the API, then the cart is shown anew from the server.
 */
import { api } from './api';
import { failure, reloadRegions, showMessage } from './dom';

export function bindCart( root: Document ): void {
	root.addEventListener( 'change', async ( event ) => {
		const input = event.target;
		if (
			! ( input instanceof HTMLInputElement ) ||
			! input.dataset.orillacartQuantity
		) {
			return;
		}
		const quantity = Number( input.value );
		if ( ! Number.isInteger( quantity ) || quantity < 1 ) {
			return;
		}
		await change(
			'PUT',
			`/cart/items/${ input.dataset.orillacartQuantity }`,
			{ quantity }
		);
	} );

	root.addEventListener( 'click', async ( event ) => {
		const button =
			event.target instanceof Element
				? event.target.closest< HTMLButtonElement >(
						'[data-orillacart-remove]'
					)
				: null;
		if ( ! button ) {
			return;
		}
		button.disabled = true;
		await change(
			'DELETE',
			`/cart/items/${ button.dataset.orillacartRemove ?? '' }`
		);
	} );
}

async function change(
	method: 'PUT' | 'DELETE',
	path: string,
	body?: unknown
): Promise< void > {
	const region = document.querySelector( '[data-orillacart-region="cart"]' );
	region?.setAttribute( 'aria-busy', 'true' );
	const result = await api( method, path, body );
	await reloadRegions( [ 'cart', 'mini-cart' ] );
	if ( ! result.ok ) {
		showMessage(
			document.querySelector( '[data-orillacart-region="cart"]' ),
			failure( result ),
			'error'
		);
	}
}
