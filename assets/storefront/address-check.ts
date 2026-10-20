/**
 * Before the order is placed, the add-ons' address checks look at the addresses on the form: a correction they
 * suggest, or an address they could not find, is shown, and the customer takes the suggestion or keeps theirs.
 * Nothing here keeps the customer from ordering: a check that does not answer is passed over.
 */
import { __ } from '@wordpress/i18n';

import { api } from './api';
import type { Json } from './dom';

type Suggestion = {
	message: string;
	found: boolean;
	street: string | null;
	city: string | null;
	postcode: string | null;
	state: string | null;
};

type Suggestions = {
	billing: Suggestion | null;
	shipping_address: Suggestion | null;
};

const KINDS = [ 'billing', 'shipping_address' ] as const;
type Kind = ( typeof KINDS )[ number ];

/**
 * Whether the order can go now: the checks had nothing to say of these addresses, or the customer answered. When
 * the customer still has to answer, the suggestions are shown and the form is sent again once they did.
 *
 * @param form The checkout form.
 * @param body What the form sends, with its addresses.
 */
export async function addressesConfirmed(
	form: HTMLFormElement,
	body: Record< string, Json >
): Promise< boolean > {
	const panel = form.querySelector< HTMLElement >(
		'[data-orillacart-address-suggestions]'
	);
	if ( ! panel || ! form.hasAttribute( 'data-orillacart-address-checks' ) ) {
		return true;
	}
	const addresses = {
		billing: body.billing ?? null,
		shipping_address: body.shipping_address ?? null,
	};
	const key = JSON.stringify( addresses );
	if ( form.dataset.orillacartAddressesAnswered === key ) {
		return true;
	}
	const result = await api< Suggestions >(
		'POST',
		'/checkout/addresses',
		addresses
	);
	const asked = result.ok
		? KINDS.filter( ( kind ) => result.data[ kind ] !== null )
		: [];
	if ( ! result.ok || asked.length === 0 ) {
		form.dataset.orillacartAddressesAnswered = key;
		return true;
	}

	panel.replaceChildren();
	panel.hidden = false;
	const pending = new Set< Kind >( asked );
	const answered = ( kind: Kind ): void => {
		pending.delete( kind );
		if ( pending.size > 0 ) {
			return;
		}
		panel.hidden = true;
		panel.replaceChildren();
		// What the form now holds, with any suggestion taken, is what the customer confirmed.
		form.dataset.orillacartAddressesConfirming = '1';
		form.requestSubmit();
	};
	for ( const kind of asked ) {
		const suggestion = result.data[ kind ] as Suggestion;
		panel.append( suggestionBox( form, kind, suggestion, answered ) );
	}
	panel.querySelector< HTMLButtonElement >( 'button' )?.focus();

	return false;
}

/**
 * Marks the addresses on the form as the ones the customer confirmed, after taking or keeping the suggestions.
 *
 * @param form The checkout form.
 * @param body What the form sends now.
 */
export function confirmAddresses(
	form: HTMLFormElement,
	body: Record< string, Json >
): void {
	if ( form.dataset.orillacartAddressesConfirming === '1' ) {
		delete form.dataset.orillacartAddressesConfirming;
		form.dataset.orillacartAddressesAnswered = JSON.stringify( {
			billing: body.billing ?? null,
			shipping_address: body.shipping_address ?? null,
		} );
	}
}

function suggestionBox(
	form: HTMLFormElement,
	kind: Kind,
	suggestion: Suggestion,
	answered: ( kind: Kind ) => void
): HTMLElement {
	const box = document.createElement( 'div' );
	box.className = 'orillacart-notice orillacart-address-suggestion';
	box.setAttribute( 'role', 'group' );
	const heading = document.createElement( 'p' );
	heading.append(
		document.createElement( 'strong' ),
		suggestion.message === '' ? '' : ` ${ suggestion.message }`
	);
	( heading.firstChild as HTMLElement ).textContent =
		kind === 'billing'
			? __( 'Your billing address', 'orillacart' )
			: __( 'Your shipping address', 'orillacart' );
	box.append( heading );

	const buttons = document.createElement( 'p' );
	if ( suggestion.found ) {
		const corrected = document.createElement( 'p' );
		corrected.className = 'orillacart-address-suggestion__address';
		corrected.textContent = [
			suggestion.street,
			[ suggestion.postcode, suggestion.city ]
				.filter( Boolean )
				.join( ' ' ),
			suggestion.state,
		]
			.filter( Boolean )
			.join( ', ' );
		box.append( corrected );
		buttons.append(
			button( __( 'Use this address', 'orillacart' ), () => {
				fill( form, kind, suggestion );
				answered( kind );
			} )
		);
	}
	buttons.append(
		button( __( 'Keep my address', 'orillacart' ), () => answered( kind ) )
	);
	box.append( buttons );

	return box;
}

function button( label: string, onClick: () => void ): HTMLButtonElement {
	const element = document.createElement( 'button' );
	element.type = 'button';
	element.className = 'wp-element-button orillacart-button';
	element.textContent = label;
	element.addEventListener( 'click', onClick, { once: true } );
	return element;
}

/**
 * Puts the suggested address in the form, as if the customer had typed it.
 * @param form
 * @param kind
 * @param suggestion
 */
function fill(
	form: HTMLFormElement,
	kind: Kind,
	suggestion: Suggestion
): void {
	for ( const part of [ 'street', 'city', 'postcode', 'state' ] as const ) {
		const value = suggestion[ part ];
		const field = form.querySelector<
			HTMLInputElement | HTMLSelectElement
		>( `[name="${ kind }.${ part }"]` );
		if ( value !== null && field ) {
			field.value = value;
			field.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		}
	}
}
