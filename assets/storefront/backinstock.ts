/**
 * "Email me when it is back" on a sold-out product or variation.
 */
import { __ } from '@wordpress/i18n';

import { api } from './api';
import { clearErrors, failure, formBody, showErrors, showMessage } from './dom';

/**
 * Sends the visitor's email for the product, or the variation chosen, and says it was taken.
 *
 * @param form The form, with data-product and, for a variation, data-variation.
 */
export function bindBackInStock( form: HTMLFormElement ): void {
	form.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();
		clearErrors( form );
		const button = form.querySelector< HTMLButtonElement >(
			'button[type="submit"]'
		);
		if ( button ) {
			button.disabled = true;
		}
		const variation = form.dataset.variation;
		const result = await api< null >( 'POST', '/back-in-stock', {
			...formBody( form ),
			product_id: Number( form.dataset.product ),
			variation_id: variation ? Number( variation ) : null,
		} );
		if ( button ) {
			button.disabled = false;
		}
		if ( result.ok ) {
			showMessage(
				form,
				__( 'We will email you once when it is back.', 'orillacart' )
			);
			return;
		}
		const unplaced = showErrors( form, result.violations );
		showMessage( form, failure( result, unplaced ), 'error' );
	} );
}

/**
 * Shows the form for a sold-out variation, and hides it for one that can be bought.
 *
 * @param product     The product's section.
 * @param variationId The variation chosen; null for none.
 * @param soldOut     Whether it is sold out.
 */
export function showBackInStock(
	product: Element | null,
	variationId: number | null,
	soldOut: boolean
): void {
	const form = product?.querySelector< HTMLFormElement >(
		'form[data-orillacart-back-in-stock]'
	);
	if ( ! form ) {
		return;
	}
	form.hidden = ! soldOut;
	if ( variationId === null ) {
		delete form.dataset.variation;
	} else {
		form.dataset.variation = String( variationId );
	}
}
