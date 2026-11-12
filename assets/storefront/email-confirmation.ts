/**
 * The page the link that confirms a guest's email for ordering opens, on whatever device their mail is read: a
 * button confirms it, and the browser that asked places its order.
 */
import { api } from './api';
import { clearErrors, failure, showMessage } from './dom';

/**
 * Binds the form.
 *
 * @param form The form, with data-orillacart-email-confirmation holding the link's token.
 */
export function bindEmailConfirmation( form: HTMLFormElement ): void {
	form.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();
		clearErrors( form );
		const button = form.querySelector< HTMLButtonElement >(
			'button[type="submit"]'
		);
		if ( button ) {
			button.disabled = true;
		}
		const result = await api< { message: string } >(
			'POST',
			'/checkout/email-confirmation',
			{ token: form.dataset.orillacartEmailConfirmation ?? '' }
		);
		if ( ! result.ok ) {
			if ( button ) {
				button.disabled = false;
			}
			showMessage( form, failure( result, result.violations ), 'error' );
			return;
		}
		const done = form.parentElement?.querySelector< HTMLElement >(
			'[data-orillacart-email-confirmed]'
		);
		form.hidden = true;
		if ( done ) {
			done.hidden = false;
			done.focus();
		}
	} );
}
