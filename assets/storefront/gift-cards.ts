/**
 * Gift cards and store credit at checkout: entering and removing a code, and paying with store credit, go to the
 * API, then the page shows its totals anew. A code that cannot pay says why.
 */
import { __ } from '@wordpress/i18n';

import { api } from './api';
import { failure, showMessage } from './dom';

/**
 * @param scope   The checkout form; the gift card box is inside it.
 * @param refresh Shows the totals anew after a change.
 */
export function bindGiftCards(
	scope: HTMLElement,
	refresh: () => Promise< void >
): void {
	const refreshed = async ( box: Element | null ): Promise< void > => {
		try {
			await refresh();
		} catch {
			showMessage(
				box,
				__(
					'The totals could not be shown anew. Reload the page.',
					'orillacart'
				),
				'error'
			);
		}
	};

	const apply = async ( button: HTMLButtonElement ): Promise< void > => {
		const box = button.closest( '[data-orillacart-gift-cards]' );
		const field = box?.querySelector< HTMLInputElement >(
			'[data-orillacart-gift-card-code]'
		);
		const code = field?.value.trim() ?? '';
		if ( code === '' ) {
			showMessage(
				box ?? null,
				__( 'Enter the code of the gift card.', 'orillacart' ),
				'error'
			);
			field?.focus();
			return;
		}
		button.disabled = true;
		const result = await api( 'POST', '/cart/gift-cards', { code } );
		if ( ! result.ok ) {
			button.disabled = false;
			showMessage(
				box ?? null,
				failure( result, result.violations ),
				'error'
			);
			return;
		}
		await refreshed( box );
	};

	scope.addEventListener( 'click', async ( event ) => {
		const target = event.target instanceof Element ? event.target : null;
		const applying = target?.closest< HTMLButtonElement >(
			'[data-orillacart-apply-gift-card]'
		);
		if ( applying ) {
			await apply( applying );
			return;
		}
		const removing = target?.closest< HTMLButtonElement >(
			'[data-orillacart-remove-gift-card]'
		);
		if ( removing ) {
			const box = removing.closest( '[data-orillacart-gift-cards]' );
			removing.disabled = true;
			const result = await api(
				'DELETE',
				`/cart/gift-cards/${ encodeURIComponent(
					removing.dataset.orillacartRemoveGiftCard ?? ''
				) }`
			);
			if ( ! result.ok ) {
				removing.disabled = false;
				showMessage( box, failure( result ), 'error' );
				return;
			}
			await refreshed( box );
		}
	} );

	scope.addEventListener( 'change', async ( event ) => {
		const target = event.target;
		if (
			! ( target instanceof HTMLInputElement ) ||
			! target.matches( '[data-orillacart-store-credit]' )
		) {
			return;
		}
		const box = target.closest( '[data-orillacart-gift-cards]' );
		target.disabled = true;
		const result = await api( 'PUT', '/cart/store-credit', {
			use: target.checked,
		} );
		target.disabled = false;
		if ( ! result.ok ) {
			target.checked = ! target.checked;
			showMessage( box, failure( result ), 'error' );
			return;
		}
		await refreshed( box );
	} );

	// Enter in the field applies the code; on the checkout it would place the order.
	scope.addEventListener( 'keydown', async ( event ) => {
		const target = event.target;
		if (
			event instanceof KeyboardEvent &&
			event.key === 'Enter' &&
			target instanceof HTMLInputElement &&
			target.matches( '[data-orillacart-gift-card-code]' )
		) {
			event.preventDefault();
			const button = target
				.closest( '[data-orillacart-gift-cards]' )
				?.querySelector< HTMLButtonElement >(
					'[data-orillacart-apply-gift-card]'
				);
			if ( button ) {
				await apply( button );
			}
		}
	} );
}
