/**
 * The checkout form: the summary follows the addresses, and placing the order goes to the API.
 */
import { __ } from '@wordpress/i18n';

import { api } from './api';
import { bindCoupons } from './coupons';
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
	bindContact( form );

	bindCoupons( form, () => {
		summary = summary.then( () => refreshSummary( form ) );
		return summary;
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
			target instanceof HTMLInputElement &&
			( target.name === 'payment' || target.name === 'shipping_option' )
		) {
			// A refresh already on its way draws the summary with the choices it was asked for: the
			// customer's are kept here, for the refreshes after it.
			form.dataset[ CHOSEN[ target.name ] ] = target.value;
		}
		if (
			target.matches(
				'[data-orillacart-country], [data-orillacart-state], [name$=".postcode"], [data-orillacart-ship-elsewhere], [data-orillacart-rate], [data-orillacart-payment], [name="vat_number"]'
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
		const body = formBody( form, { flags: [ 'create_account' ] } );
		delete body.ship_elsewhere;
		delete body.remind_cart;
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
		if ( result.code === 'orillacart_cart_changed' ) {
			// The summary shows what the order costs now; the message goes in after it is drawn.
			summary = refreshSummary( form );
			await summary;
		}
		showMessage(
			form,
			failure( result, showErrors( form, result.violations ) ),
			'error'
		);
	} );
}

/**
 * Tells the shop the customer is at checkout with the cart, with the email so far and whether they ask to be
 * reminded of the cart: when the page opens, and when either changes.
 * @param form The checkout form.
 */
function bindContact( form: HTMLFormElement ): void {
	const email = form.querySelector< HTMLInputElement >( '[name="email"]' );
	const remind = form.querySelector< HTMLInputElement >(
		'[data-orillacart-remind]'
	);
	// One after another, each with the form as it is when it goes: leaving the email field and ticking the box
	// fire together, and the shop keeps whichever arrives last.
	let sending: Promise< unknown > = Promise.resolve();
	const send = (): void => {
		sending = sending
			.catch( () => undefined )
			.then( () => {
				const given = email?.value.trim() ?? '';
				return api( 'POST', '/cart/checkout-contact', {
					email: given !== '' && email?.validity.valid ? given : null,
					remind: remind?.checked === true,
				} );
			} );
	};
	send();
	email?.addEventListener( 'change', send );
	remind?.addEventListener( 'change', send );
}

/** Where the form keeps the customer's last choice of each. */
const CHOSEN = {
	payment: 'orillacartPayment',
	shipping_option: 'orillacartShipping',
} as const;

/**
 * The way to ship or to pay the customer chose last, or the one ticked.
 *
 * @param form The checkout form.
 * @param name The radio buttons' name.
 */
function chosen(
	form: HTMLFormElement,
	name: keyof typeof CHOSEN
): string | undefined {
	return (
		form.dataset[ CHOSEN[ name ] ] ??
		form.querySelector< HTMLInputElement >( `[name="${ name }"]:checked` )
			?.value
	);
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
		shipping: chosen( form, 'shipping_option' ) ?? '',
		pickup_point: value( 'pickup_point' ),
		vat_number: value( 'vat_number' ),
		// A way to pay may cost a fee: the totals are for the one chosen.
		payment: chosen( form, 'payment' ) ?? '',
	};
	for ( const [ name, given ] of Object.entries( query ) ) {
		if ( given ) {
			url.searchParams.set( name, given );
		} else {
			url.searchParams.delete( name );
		}
	}
	const payment = chosen( form, 'payment' );
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
