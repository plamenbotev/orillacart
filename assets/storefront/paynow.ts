/**
 * The receipt's forms: Pay now, where an unpaid order is paid with the way chosen, then the customer goes to
 * the provider, or sees the receipt anew for an offline payment; asking to return items; and, for an expired
 * link, asking for a new one.
 */
import { __ } from '@wordpress/i18n';

import { api } from './api';
import { failure, formBody, showMessage } from './dom';

type Placed = { receipt_url: string; redirect_url: string | null };

export function bindPayNow( form: HTMLFormElement ): void {
	form.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();
		const button = form.querySelector< HTMLButtonElement >(
			'button[type="submit"]'
		);
		if ( button?.disabled ) {
			return;
		}
		if ( button ) {
			button.disabled = true;
		}
		const payment =
			form.querySelector< HTMLInputElement >( '[name="payment"]:checked' )
				?.value ?? '';
		const result = await api< Placed >(
			'POST',
			`/receipts/${ form.dataset.orillacartPayNow ?? '' }/payment`,
			{ payment }
		);
		if ( result.ok ) {
			if ( result.data.redirect_url !== null ) {
				window.location.assign( result.data.redirect_url );
			} else {
				window.location.reload();
			}
			return;
		}
		if ( button ) {
			button.disabled = false;
		}
		showMessage( form, failure( result ), 'error' );
	} );
}

/**
 * Asks for a new link to the order: it goes to the order's email, so the answer is the same whatever was sent.
 * @param form The expired receipt's form.
 */
export function bindReceiptLink( form: HTMLFormElement ): void {
	form.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();
		const button = form.querySelector< HTMLButtonElement >(
			'button[type="submit"]'
		);
		if ( button?.disabled ) {
			return;
		}
		if ( button ) {
			button.disabled = true;
		}
		const result = await api( 'POST', '/receipts/link', formBody( form ) );
		if ( button ) {
			button.disabled = false;
		}
		showMessage(
			form,
			result.ok
				? __(
						'If the link was ours, a new one is on its way to the order’s email.',
						'orillacart'
					)
				: failure( result ),
			result.ok ? 'success' : 'error'
		);
	} );
}

/**
 * Asks to send some of the order's items back: how many of each, and why. The receipt shows the request after.
 * @param form The receipt's return form.
 */
export function bindReturn( form: HTMLFormElement ): void {
	form.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();
		const button = form.querySelector< HTMLButtonElement >(
			'button[type="submit"]'
		);
		if ( button?.disabled ) {
			return;
		}
		const items = Array.from(
			form.querySelectorAll< HTMLSelectElement >(
				'[data-orillacart-return-line]'
			)
		)
			.map( ( select ) => ( {
				line: Number( select.dataset.orillacartReturnLine ),
				quantity: Number( select.value ),
			} ) )
			.filter( ( item ) => item.quantity > 0 );
		if ( items.length === 0 ) {
			showMessage(
				form,
				__( 'Choose how many of an item to send back.', 'orillacart' ),
				'error'
			);
			return;
		}
		if ( button ) {
			button.disabled = true;
		}
		const body = formBody( form );
		const result = await api(
			'POST',
			`/receipts/${ form.dataset.orillacartReturn ?? '' }/returns`,
			{ items, reason: body.reason, comment: body.comment ?? '' }
		);
		if ( result.ok ) {
			window.location.hash = 'returns';
			window.location.reload();
			return;
		}
		if ( button ) {
			button.disabled = false;
		}
		showMessage( form, failure( result ), 'error' );
	} );
}
