/**
 * The checkout form: the summary follows the addresses, and placing the order goes to the API.
 */
import { __ } from '@wordpress/i18n';

import { api } from './api';
import {
	bindStates,
	clearErrors,
	failure,
	formBody,
	reloadRegions,
	showErrors,
	showMessage,
} from './dom';

type PlacedOrder = { receipt_url: string; redirect_url: string | null };

export function bindCheckout( form: HTMLFormElement ): void {
	bindStates( form );
	const shipping = form.querySelector< HTMLElement >(
		'[data-orillacart-shipping-address]'
	);
	const elsewhere = form.querySelector< HTMLInputElement >(
		'[data-orillacart-ship-elsewhere]'
	);
	const showShipping = (): void => {
		if ( shipping && elsewhere ) {
			shipping.hidden = ! elsewhere.checked;
			shipping.querySelectorAll( 'input, select' ).forEach( ( field ) => {
				( field as HTMLInputElement ).disabled =
					! elsewhere.checked ||
					field.closest( '[data-orillacart-state-field][hidden]' ) !==
						null;
			} );
		}
	};
	showShipping();

	const account = form.querySelector< HTMLInputElement >(
		'[data-orillacart-create-account]'
	);
	const password = form.querySelector< HTMLElement >(
		'[data-orillacart-password]'
	);
	account?.addEventListener( 'change', () => {
		if ( password ) {
			password.hidden = ! account.checked;
			password
				.querySelector( 'input' )
				?.toggleAttribute( 'required', account.checked );
		}
	} );

	// Refreshes run one after another, so the last change wins; placing the order waits for them, since
	// the way to ship it comes with the refreshed summary.
	let summary: Promise< void > = Promise.resolve();
	form.addEventListener( 'change', ( event ) => {
		const target = event.target as HTMLElement;
		if ( target === elsewhere ) {
			showShipping();
		}
		if (
			target.matches(
				'[data-orillacart-country], [data-orillacart-state], [name$=".postcode"], [data-orillacart-ship-elsewhere], [data-orillacart-rate]'
			)
		) {
			// After this change's other listeners: the state list follows a new country first.
			summary = summary
				.then(
					() => new Promise( ( resolve ) => setTimeout( resolve, 0 ) )
				)
				.then( () => refreshSummary( form ) );
		}
	} );

	form.addEventListener( 'submit', async ( event ) => {
		event.preventDefault();
		const button = form.querySelector< HTMLButtonElement >(
			'button[type="submit"]'
		);
		clearErrors( form );
		if ( button ) {
			button.disabled = true;
		}
		await summary;
		const body = formBody( form, {
			numbers: [ 'rate_id' ],
			flags: [ 'create_account' ],
		} );
		delete body.ship_elsewhere;
		body.remember_addresses = body.remember_addresses === true;
		const result = await api< PlacedOrder >( 'POST', '/checkout', body );
		if ( result.ok ) {
			window.location.assign(
				result.data.redirect_url ?? result.data.receipt_url
			);
			return;
		}
		if ( button ) {
			button.disabled = false;
		}
		showMessage(
			form,
			failure( result, showErrors( form, result.violations ) ),
			'error'
		);
	} );
}

/**
 * Prices the order again for the addresses on the form, keeping the chosen way to pay.
 * @param form The checkout form.
 */
async function refreshSummary( form: HTMLFormElement ): Promise< void > {
	const value = ( name: string ): string => {
		const field = form.querySelector<
			HTMLInputElement | HTMLSelectElement
		>( `[name="${ name }"]` );
		return field && ! field.disabled ? field.value : '';
	};
	const url = new URL( window.location.href );
	const query: Record< string, string > = {
		billing_country: value( 'billing.country' ),
		billing_state: value( 'billing.state' ),
		billing_postcode: value( 'billing.postcode' ),
		shipping_country: value( 'shipping_address.country' ),
		shipping_state: value( 'shipping_address.state' ),
		shipping_postcode: value( 'shipping_address.postcode' ),
		rate:
			form.querySelector< HTMLInputElement >( '[name="rate_id"]:checked' )
				?.value ?? '',
	};
	for ( const [ name, given ] of Object.entries( query ) ) {
		if ( given ) {
			url.searchParams.set( name, given );
		} else {
			url.searchParams.delete( name );
		}
	}
	const payment = form.querySelector< HTMLInputElement >(
		'[name="payment"]:checked'
	)?.value;
	const summary = form.querySelector(
		'[data-orillacart-region="checkout-summary"]'
	);
	summary?.setAttribute( 'aria-busy', 'true' );
	try {
		await reloadRegions( [ 'checkout-summary' ], url.toString() );
	} catch {
		showMessage(
			form,
			__(
				'The totals could not be updated. Check your connection.',
				'orillacart'
			),
			'error'
		);
	}
	form.querySelector(
		'[data-orillacart-region="checkout-summary"]'
	)?.removeAttribute( 'aria-busy' );
	if ( payment ) {
		const again = form.querySelector< HTMLInputElement >(
			`[name="payment"][value="${ CSS.escape( payment ) }"]`
		);
		if ( again ) {
			again.checked = true;
		}
	}
}
