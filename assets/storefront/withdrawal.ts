/**
 * The withdrawal function, in two steps. Asked by the order's number and email, the shop answers the same
 * whatever they are and emails the order's own address a link. The link opens the page again: the consumer gives
 * their name and where the confirmation goes, chooses the items (all of them unless they choose fewer), and
 * confirms. The shop answers with what was declared and when, which the confirmation email repeats.
 */
import { __, _n, sprintf } from '@wordpress/i18n';

import { api } from './api';
import { clearErrors, failure, showErrors, showMessage } from './dom';

interface Withdrawable {
	number: number;
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
 * Binds the first step: the order's number and email, for the link.
 *
 * @param form The form, with data-orillacart-withdrawal-ask.
 */
export function bindWithdrawalAsk( form: HTMLFormElement ): void {
	const field = ( name: string ) =>
		form.querySelector< HTMLInputElement >( `[name="${ name }"]` );
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
			'/withdrawals/links',
			{
				number: Number( field( 'number' )?.value ) || 0,
				email: field( 'email' )?.value ?? '',
			}
		);
		if ( button ) {
			button.disabled = false;
		}
		if ( ! result.ok ) {
			const unplaced = showErrors( form, result.violations );
			showMessage( form, failure( result, unplaced ), 'error' );
			return;
		}
		showMessage(
			form,
			__(
				'If an order has this number and email, the link to withdraw from it is on its way to that email. Follow it to choose what you withdraw from and confirm.',
				'orillacart'
			)
		);
	} );
}

/**
 * Binds the second step, opened from the emailed link: the items, the name, and the confirmation.
 *
 * @param form The form, with data-orillacart-withdrawal holding the order's handle: the link's token stays in a
 *             cookie the browser sends with the calls, where no script reads it.
 */
export function bindWithdrawal( form: HTMLFormElement ): void {
	const token = form.dataset.orillacartWithdrawal ?? '';
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
		const result = await api< Withdrawable >(
			'POST',
			'/withdrawals/orders',
			{ token }
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
	void find();

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
			token,
			name: field( 'name' )?.value ?? '',
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
