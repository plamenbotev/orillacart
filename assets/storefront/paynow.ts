/**
 * The receipt's Pay now form: an unpaid order is paid with the way chosen, then the customer goes to the
 * provider, or sees the receipt anew for an offline payment.
 */
import { api } from './api';
import { failure, showMessage } from './dom';

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
