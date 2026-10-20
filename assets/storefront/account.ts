/**
 * The account page's forms: asking for an account, choosing its password from the emailed link, finding a
 * guest's order, the address book and the emails the customer wants.
 */
import { __ } from '@wordpress/i18n';

import { api } from './api';
import {
	bindStates,
	clearErrors,
	failure,
	formBody,
	showErrors,
	showMessage,
} from './dom';

/**
 * Disables the form's button while a request runs, so a second click sends nothing.
 * @param form The form.
 * @param work The request.
 */
async function busy< T >(
	form: HTMLFormElement,
	work: () => Promise< T >
): Promise< T | null > {
	const button = form.querySelector< HTMLButtonElement >(
		'button[type="submit"]'
	);
	if ( button?.disabled ) {
		return null;
	}
	if ( button ) {
		button.disabled = true;
	}
	try {
		return await work();
	} finally {
		if ( button ) {
			button.disabled = false;
		}
	}
}

export function bindRegister( form: HTMLFormElement ): void {
	form.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();
		clearErrors( form );
		const fields = formBody( form ).register as
			Record< string, string > | undefined;
		const result = await busy( form, () =>
			api( 'POST', '/accounts', fields ?? {} )
		);
		if ( result === null ) {
			return;
		}
		if ( result.ok ) {
			// The same answer whether the email has an account or not: the email tells them.
			form.reset();
			showMessage(
				form,
				__(
					'Look in your email for the link to make your account.',
					'orillacart'
				)
			);
			return;
		}
		// The form's fields are named register.email and so on; the API names them email.
		const violations = result.violations.map( ( violation ) => ( {
			...violation,
			field: `register.${ violation.field }`,
		} ) );
		showMessage(
			form,
			failure( result, showErrors( form, violations ) ),
			'error'
		);
	} );
}

/**
 * A guest looks up an order: the link to it goes to the email it was placed with, and the page says the same
 * whether the two matched or not.
 * @param form The account page's find-an-order form.
 */
export function bindFindOrder( form: HTMLFormElement ): void {
	form.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();
		clearErrors( form );
		const fields =
			( formBody( form ).find as Record< string, string > | undefined ) ??
			{};
		// "#1042" as the emails write it.
		const number = Number( ( fields.number ?? '' ).replace( /\D/g, '' ) );
		const result = await busy( form, () =>
			api( 'POST', '/receipts/lookup', { ...fields, number } )
		);
		if ( result === null ) {
			return;
		}
		if ( result.ok ) {
			form.reset();
			showMessage(
				form,
				__(
					'If the order number and email match, the link to the order is on its way to that email.',
					'orillacart'
				)
			);
			return;
		}
		const violations = result.violations.map( ( violation ) => ( {
			...violation,
			field: `find.${ violation.field }`,
		} ) );
		showMessage(
			form,
			failure( result, showErrors( form, violations ) ),
			'error'
		);
	} );
}

/**
 * Choosing the password of an account asked for: the account is made, and the page shows it, without the link.
 * @param form The form with the emailed link's token.
 */
export function bindConfirm( form: HTMLFormElement ): void {
	form.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();
		clearErrors( form );
		const result = await busy( form, () =>
			api( 'POST', '/accounts/confirm', formBody( form ) )
		);
		if ( result === null ) {
			return;
		}
		if ( result.ok ) {
			const url = new URL( window.location.href );
			url.searchParams.delete( 'orillacart_account' );
			window.location.assign( url.toString() );
			return;
		}
		showMessage(
			form,
			failure( result, showErrors( form, result.violations ) ),
			'error'
		);
	} );
}

/**
 * The account's address book: each form saves an address, new or saved, and each remove button forgets one;
 * the page then shows the book as it is now.
 * @param book The address book.
 */
export function bindAddressBook( book: HTMLElement ): void {
	bindStates( book );
	book.querySelectorAll< HTMLFormElement >(
		'form[data-orillacart-address-book]'
	).forEach( ( form ) => {
		const prefix = form.dataset.orillacartAddressBook ?? '';
		const id = form.dataset.orillacartAddressId;
		form.addEventListener( 'submit', async ( event ) => {
			event.preventDefault();
			clearErrors( form );
			const body = formBody( form );
			const result = await busy( form, () =>
				api(
					id ? 'PUT' : 'POST',
					id ? `/account/addresses/${ id }` : '/account/addresses',
					{
						address: body[ prefix ] ?? {},
						billing: body.billing === true,
						shipping: body.shipping === true,
					}
				)
			);
			if ( result === null ) {
				return;
			}
			if ( result.ok ) {
				window.location.reload();
				return;
			}
			// The form's fields are named for the form (address-new.street); the API names them address.street.
			const violations = result.violations.map( ( violation ) => ( {
				...violation,
				field: violation.field.replace( /^address\./, `${ prefix }.` ),
			} ) );
			showMessage(
				form,
				failure( result, showErrors( form, violations ) ),
				'error'
			);
		} );
	} );
	book.querySelectorAll< HTMLButtonElement >(
		'[data-orillacart-remove-address]'
	).forEach( ( button ) => {
		button.addEventListener( 'click', async () => {
			button.disabled = true;
			const result = await api(
				'DELETE',
				`/account/addresses/${
					button.dataset.orillacartRemoveAddress ?? ''
				}`
			);
			if ( result.ok ) {
				window.location.reload();
				return;
			}
			button.disabled = false;
			showMessage(
				button.closest( '.orillacart-saved-address' ),
				failure( result ),
				'error'
			);
		} );
	} );
}

/**
 * The emails the customer wants: the newsletter, and the reminders of carts they leave.
 * @param form The account's emails form.
 */
export function bindEmails( form: HTMLFormElement ): void {
	form.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();
		const newsletter = form.querySelector< HTMLInputElement >(
			'[name="newsletter"]'
		);
		const reminders = form.querySelector< HTMLInputElement >(
			'[name="cart_reminders"]'
		);
		const result = await busy( form, () =>
			api( 'PUT', '/account/emails', {
				cart_reminders:
					reminders?.type !== 'checkbox' || reminders.checked,
				newsletter: newsletter ? newsletter.checked : null,
			} )
		);
		if ( result === null ) {
			return;
		}
		showMessage(
			form,
			result.ok
				? __( 'Your choices are saved.', 'orillacart' )
				: failure( result ),
			result.ok ? 'success' : 'error'
		);
	} );
}
