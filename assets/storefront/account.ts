/**
 * The account page's forms: making an account, and saving addresses.
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
			window.location.reload();
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

export function bindAddresses( form: HTMLFormElement ): void {
	bindStates( form );
	form.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();
		clearErrors( form );
		const result = await busy( form, () =>
			api( 'PUT', '/account/addresses', formBody( form ) )
		);
		if ( result === null ) {
			return;
		}
		if ( result.ok ) {
			showMessage(
				form,
				__( 'Your addresses are saved.', 'orillacart' )
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
