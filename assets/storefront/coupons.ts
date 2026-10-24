/**
 * Coupon codes on the cart and checkout: applying and removing a code goes to the API, then the page shows its
 * prices anew. A code that does not hold says why.
 */
import { __ } from '@wordpress/i18n';

import { api } from './api';
import { failure, showMessage } from './dom';

/**
 * @param scope   The cart region's document or the checkout form; the coupon field is inside it.
 * @param refresh Shows the prices anew after a change.
 */
/**
 * Shows the prices anew, or says they could not be.
 * @param box     The coupon box, for the message.
 * @param refresh Shows the prices anew.
 */
async function refreshed(
	box: Element | null,
	refresh: () => Promise< void >
): Promise< void > {
	try {
		await refresh();
	} catch {
		showMessage(
			box,
			__(
				'The prices could not be shown anew. Reload the page.',
				'orillacart'
			),
			'error'
		);
	}
}

export function bindCoupons(
	scope: Document | HTMLElement,
	refresh: () => Promise< void >
): void {
	const apply = async ( button: HTMLButtonElement ): Promise< void > => {
		const box = button.closest( '[data-orillacart-coupons]' );
		const field = box?.querySelector< HTMLInputElement >(
			'[data-orillacart-coupon-code]'
		);
		const code = field?.value.trim() ?? '';
		if ( code === '' ) {
			showMessage(
				box ?? null,
				__( 'Enter a code.', 'orillacart' ),
				'error'
			);
			field?.focus();
			return;
		}
		button.disabled = true;
		const result = await api( 'POST', '/cart/coupons', { code } );
		if ( ! result.ok ) {
			button.disabled = false;
			showMessage(
				box ?? null,
				failure( result, result.violations ),
				'error'
			);
			return;
		}
		// Sent: the refresh keeps only what is still being typed.
		if ( field ) {
			field.value = '';
		}
		await refreshed( box, refresh );
	};

	scope.addEventListener( 'click', async ( event ) => {
		const target = event.target instanceof Element ? event.target : null;
		const applying = target?.closest< HTMLButtonElement >(
			'[data-orillacart-apply-coupon]'
		);
		if ( applying ) {
			await apply( applying );
			return;
		}
		const removing = target?.closest< HTMLButtonElement >(
			'[data-orillacart-remove-coupon]'
		);
		if ( removing ) {
			const box = removing.closest( '[data-orillacart-coupons]' );
			removing.disabled = true;
			const result = await api(
				'DELETE',
				`/cart/coupons/${ encodeURIComponent(
					removing.dataset.orillacartRemoveCoupon ?? ''
				) }`
			);
			if ( ! result.ok ) {
				removing.disabled = false;
				showMessage( box, failure( result ), 'error' );
				return;
			}
			await refreshed( box, refresh );
		}
	} );

	// Enter in the field applies the code; on the checkout it would place the order.
	scope.addEventListener( 'keydown', async ( event ) => {
		const target = event.target;
		if (
			event instanceof KeyboardEvent &&
			event.key === 'Enter' &&
			target instanceof HTMLInputElement &&
			target.matches( '[data-orillacart-coupon-code]' )
		) {
			event.preventDefault();
			const button = target
				.closest( '[data-orillacart-coupons]' )
				?.querySelector< HTMLButtonElement >(
					'[data-orillacart-apply-coupon]'
				);
			if ( button ) {
				await apply( button );
			}
		}
	} );
}
