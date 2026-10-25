/**
 * The withdrawal function: the consumer gives their name, the order's number and email and where the
 * confirmation goes, chooses the items once the order is found (all of them unless they choose fewer), and
 * confirms. The shop answers with what was declared and when, which the confirmation email repeats.
 */
import { __, _n, sprintf } from '@wordpress/i18n';

import { api } from './api';
import { clearErrors, failure, showErrors, showMessage } from './dom';

interface Withdrawable {
	number: number;
	name: string;
	lines: { line: number; name: string; quantity: number }[];
}

interface Withdrawn {
	number: number;
	name: string;
	confirmation_email: string;
	items: { name: string; quantity: number }[];
	declared_at: string;
}

/**
 * Binds the withdrawal form.
 *
 * @param form The form, with data-orillacart-withdrawal.
 */
export function bindWithdrawal( form: HTMLFormElement ): void {
	const field = ( name: string ) =>
		form.querySelector< HTMLInputElement >( `[name="${ name }"]` );
	const items = form.querySelector< HTMLElement >(
		'[data-orillacart-withdrawal-items]'
	);
	const lines = form.querySelector< HTMLElement >(
		'[data-orillacart-withdrawal-lines]'
	);
	let found: Withdrawable | null = null;

	const find = async () => {
		const number = Number( field( 'number' )?.value );
		const email = field( 'email' )?.value ?? '';
		if ( ! number || ! email.includes( '@' ) ) {
			return;
		}
		const result = await api< Withdrawable >(
			'POST',
			'/withdrawals/orders',
			{ number, email }
		);
		if ( ! result.ok ) {
			found = null;
			if ( items ) {
				items.hidden = true;
			}
			showMessage( form, failure( result, [] ), 'error' );
			return;
		}
		found = result.data;
		showMessage( form, '' );
		const name = field( 'name' );
		if ( name && name.value === '' ) {
			name.value = result.data.name;
		}
		if ( lines && items ) {
			lines.replaceChildren(
				...result.data.lines.map( ( line ) => {
					const row = document.createElement( 'p' );
					row.className = 'orillacart-field';
					const label = document.createElement( 'label' );
					label.htmlFor = `withdrawal-line-${ line.line }`;
					label.textContent = line.name;
					const quantity = document.createElement( 'select' );
					quantity.id = `withdrawal-line-${ line.line }`;
					quantity.dataset.line = String( line.line );
					for ( let count = line.quantity; count >= 0; count-- ) {
						const option = document.createElement( 'option' );
						option.value = String( count );
						option.textContent = String( count );
						quantity.append( option );
					}
					row.append( label, quantity );
					return row;
				} )
			);
			items.hidden = false;
		}
	};
	form.querySelectorAll< HTMLInputElement >(
		'[data-orillacart-withdrawal-order]'
	).forEach( ( input ) => input.addEventListener( 'change', find ) );

	form.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();
		clearErrors( form );
		const button = form.querySelector< HTMLButtonElement >(
			'button[type="submit"]'
		);
		if ( button ) {
			button.disabled = true;
		}
		const chosen = Array.from(
			lines?.querySelectorAll< HTMLSelectElement >( 'select' ) ?? []
		).map( ( select ) => ( {
			line: Number( select.dataset.line ),
			quantity: Number( select.value ),
		} ) );
		const result = await api< Withdrawn >( 'POST', '/withdrawals', {
			name: field( 'name' )?.value ?? '',
			number: Number( field( 'number' )?.value ) || 0,
			email: field( 'email' )?.value ?? '',
			confirmation_email: field( 'confirmation_email' )?.value ?? '',
			// Every item unless the consumer chose fewer.
			items:
				found === null
					? []
					: chosen.filter( ( item ) => item.quantity > 0 ),
		} );
		if ( button ) {
			button.disabled = false;
		}
		if ( ! result.ok ) {
			const unplaced = showErrors( form, result.violations );
			showMessage( form, failure( result, unplaced ), 'error' );
			return;
		}
		done( form, result.data );
	} );
}

/**
 * Shows what was declared and when, in place of the form.
 *
 * @param form      The form.
 * @param withdrawn What the shop recorded.
 */
function done( form: HTMLFormElement, withdrawn: Withdrawn ): void {
	const box = form.parentElement?.querySelector< HTMLElement >(
		'[data-orillacart-withdrawal-done]'
	);
	if ( ! box ) {
		return;
	}
	const heading = document.createElement( 'h2' );
	heading.textContent = __( 'We received your withdrawal', 'orillacart' );
	const when = document.createElement( 'p' );
	when.textContent = sprintf(
		/* translators: 1: order number, 2: date and time, 3: email address */
		__(
			'Your withdrawal from order #%1$d was sent on %2$s. The confirmation goes to %3$s.',
			'orillacart'
		),
		withdrawn.number,
		new Date( withdrawn.declared_at ).toLocaleString(),
		withdrawn.confirmation_email
	);
	const list = document.createElement( 'ul' );
	list.append(
		...withdrawn.items.map( ( item ) => {
			const row = document.createElement( 'li' );
			row.textContent = sprintf(
				/* translators: 1: how many, 2: the item's name */
				_n( '%1$d × %2$s', '%1$d × %2$s', item.quantity, 'orillacart' ),
				item.quantity,
				item.name
			);
			return row;
		} )
	);
	box.replaceChildren( heading, when, list );
	form.hidden = true;
	box.hidden = false;
	box.focus();
}
