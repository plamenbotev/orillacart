/**
 * The shop's questions on a page (form_field in _macros.html.twig): a question asked only when another is
 * answered shows then, and its inputs are off while it is hidden, so they are not sent; a file is sent as soon as
 * it is chosen, and the answer is the token the shop gives back. Prices show in the labels, as options' do.
 */

import { __, sprintf } from '@wordpress/i18n';

import { api } from './api';

type Input = HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement;

/**
 * What the field named so answers now: a ticked box as "1", a button's value, the text typed; '' for none.
 * @param scope The form.
 * @param name  The field's name, as fields.f12_size.
 */
function answerOf( scope: HTMLElement, name: string ): string {
	const inputs = Array.from(
		scope.querySelectorAll< Input >( `[name="${ CSS.escape( name ) }"]` )
	).filter( ( input ) => ! input.disabled );
	for ( const input of inputs ) {
		if (
			input instanceof HTMLInputElement &&
			( input.type === 'checkbox' || input.type === 'radio' )
		) {
			if ( input.checked ) {
				return input.type === 'checkbox' ? '1' : input.value;
			}
			continue;
		}
		return input.value.trim();
	}
	return '';
}

/**
 * Shows each conditional question while its condition holds. Questions come in order, and each looks at one above
 * it, so one pass in page order settles chains.
 * @param scope The form.
 */
export function bindConditions( scope: HTMLElement ): void {
	// Looked up each time: parts of the page may be drawn again.
	const update = () => {
		for ( const field of scope.querySelectorAll< HTMLElement >(
			'[data-orillacart-when]'
		) ) {
			const values = ( field.dataset.orillacartWhenValues ?? '' )
				.split( ' ' )
				.filter( ( value ) => value !== '' );
			const answer = answerOf(
				scope,
				field.dataset.orillacartWhen ?? ''
			);
			const shown =
				answer !== '' &&
				( values.length === 0 || values.includes( answer ) );
			field.hidden = ! shown;
			field
				.querySelectorAll< Input >( 'input, select, textarea' )
				.forEach( ( input ) => {
					input.disabled = ! shown;
				} );
		}
		scope.dispatchEvent( new Event( 'orillacart:answers' ) );
	};
	scope.addEventListener( 'input', update );
	scope.addEventListener( 'change', update );
	update();
}

type SentUpload = { token: string; name: string };

/**
 * Sends each chosen file for its field and keeps the token for the answer.
 * @param scope     The form.
 * @param productId The product the field is for.
 */
export function bindUploads( scope: HTMLElement, productId: number ): void {
	scope
		.querySelectorAll< HTMLInputElement >( '[data-orillacart-upload]' )
		.forEach( ( input ) => {
			const field = input.parentElement;
			const answer = field?.querySelector< HTMLInputElement >(
				'input[type="hidden"]'
			);
			const status = field?.querySelector< HTMLElement >(
				'[data-orillacart-upload-status]'
			);
			if ( ! answer || ! status ) {
				return;
			}
			input.addEventListener( 'change', async () => {
				answer.value = '';
				const file = input.files?.[ 0 ];
				if ( file === undefined ) {
					status.textContent = '';
					return;
				}
				const largest = Number( input.dataset.orillacartLargest ?? 20 );
				if ( file.size > largest * 1024 * 1024 ) {
					status.textContent = sprintf(
						/* translators: %d: megabytes */
						__( 'The file is larger than %d MB.', 'orillacart' ),
						largest
					);
					input.value = '';
					return;
				}
				const body = new FormData();
				body.append( 'product_id', String( productId ) );
				body.append( 'field', input.dataset.orillacartUpload ?? '' );
				body.append( 'file', file );
				status.textContent = __( 'Sending the file…', 'orillacart' );
				const buttons = scope.querySelectorAll< HTMLButtonElement >(
					'button[type="submit"]'
				);
				buttons.forEach( ( button ) => ( button.disabled = true ) );
				const result = await api< SentUpload >(
					'POST',
					'/form-uploads',
					body
				);
				buttons.forEach( ( button ) => ( button.disabled = false ) );
				if ( result.ok ) {
					answer.value = result.data.token;
					status.textContent = sprintf(
						/* translators: %s: file name */
						__( 'Sent: %s', 'orillacart' ),
						result.data.name
					);
				} else {
					input.value = '';
					status.textContent =
						result.violations[ 0 ]?.message ??
						( result.message ||
							__( 'The file could not be sent.', 'orillacart' ) );
				}
				scope.dispatchEvent( new Event( 'orillacart:answers' ) );
			} );
		} );
}
