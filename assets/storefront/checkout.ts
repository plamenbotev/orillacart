/**
 * The checkout form: the summary follows the addresses, and placing the order goes to the API.
 */
import { __, sprintf } from '@wordpress/i18n';

import { addressesConfirmed, confirmAddresses } from './address-check';
import { api } from './api';
import { bindCoupons } from './coupons';
import { bindGiftCards } from './gift-cards';
import {
	bindStates,
	clearErrors,
	failure,
	formBody,
	reloadRegions,
	showErrors,
	showMessage,
} from './dom';
import { bindConditions } from './forms';

type PlacedOrder = { receipt_url: string; redirect_url: string | null };

export function bindCheckout( form: HTMLFormElement ): void {
	bindStates( form );
	bindConditions( form );
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
	bindSavedAddresses( form );

	bindCoupons( form, () => {
		summary = summary.then( () => refreshSummary( form ) );
		return summary;
	} );
	bindGiftCards( form, () => {
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
			( target instanceof HTMLInputElement ||
				target instanceof HTMLSelectElement ) &&
			target.name in CHOSEN
		) {
			// A refresh already on its way draws the summary with the choices it was asked for: the
			// customer's are kept here, for the refreshes after it.
			form.dataset[ CHOSEN[ target.name as keyof typeof CHOSEN ] ] =
				target.value;
		}
		if (
			target.matches(
				'[data-orillacart-country], [data-orillacart-state], [name$=".postcode"], [name$=".city"], [data-orillacart-ship-elsewhere], [data-orillacart-rate], [data-orillacart-payment], [data-orillacart-delivery], [name="vat_number"]'
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
		confirmAddresses( form, body );
		if ( ! ( await addressesConfirmed( form, body ) ) ) {
			if ( button ) {
				button.disabled = false;
			}
			return;
		}
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

/** A saved address as the page has it, keyed as the address fields are named. */
type SavedAddress = Record< string, string | null >;
const SAVED_FIELDS = [
	'first_name',
	'last_name',
	'street',
	'city',
	'postcode',
	'phone',
] as const;

/**
 * Choosing one of the customer's saved addresses fills in the address's fields; a new country refills the
 * states first, and the summary follows.
 * @param form The checkout form.
 */
function bindSavedAddresses( form: HTMLFormElement ): void {
	const saved = JSON.parse(
		form.querySelector( '[data-orillacart-saved-addresses]' )
			?.textContent ?? '{}'
	) as Record< string, SavedAddress >;
	form.querySelectorAll< HTMLSelectElement >(
		'[data-orillacart-saved-address]'
	).forEach( ( select ) => {
		select.addEventListener( 'change', () => {
			const address = saved[ select.value ];
			const prefix = select.dataset.orillacartSavedAddress ?? '';
			const field = ( name: string ) =>
				form.querySelector< HTMLInputElement | HTMLSelectElement >(
					`[name="${ prefix }.${ name }"]`
				);
			if ( ! address ) {
				return;
			}
			for ( const name of SAVED_FIELDS ) {
				const input = field( name );
				if ( input ) {
					input.value = address[ name ] ?? '';
				}
			}
			const country = field( 'country' );
			if ( country ) {
				country.value = address.country ?? '';
				country.dispatchEvent(
					new Event( 'change', { bubbles: true } )
				);
			}
			const state = field( 'state' );
			if ( state ) {
				state.value = address.state ?? '';
			}
		} );
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
	delivery_date: 'orillacartDeliveryDate',
	delivery_slot: 'orillacartDeliverySlot',
} as const;

/**
 * The way to ship or to pay, or the delivery day or time, the customer chose last; or the one the form shows.
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
			?.value ??
		form.querySelector< HTMLSelectElement >( `select[name="${ name }"]` )
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
		billing_city: value( 'billing.city' ),
		shipping_country: value( 'shipping_address.country' ),
		shipping_state: value( 'shipping_address.state' ),
		shipping_postcode: value( 'shipping_address.postcode' ),
		shipping_city: value( 'shipping_address.city' ),
		shipping: chosen( form, 'shipping_option' ) ?? '',
		pickup_point: value( 'pickup_point' ),
		vat_number: value( 'vat_number' ),
		// A way to pay may cost a fee: the totals are for the one chosen.
		payment: chosen( form, 'payment' ) ?? '',
		// The times of day open follow the day.
		delivery_date: chosen( form, 'delivery_date' ) ?? '',
		delivery_slot: chosen( form, 'delivery_slot' ) ?? '',
	};
	for ( const [ name, given ] of Object.entries( query ) ) {
		if ( given ) {
			url.searchParams.set( name, given );
		} else {
			url.searchParams.delete( name );
		}
	}
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
	const fresh = form.querySelector(
		'[data-orillacart-region="checkout-summary"]'
	);
	fresh?.removeAttribute( 'aria-busy' );
	const said = form.querySelector( '[data-orillacart-total-said]' );
	const total = (
		fresh?.querySelector( '.orillacart-amount-due td' ) ??
		fresh?.querySelector( '.orillacart-total td' )
	)?.textContent?.trim();
	const saying = total
		? sprintf(
				/* translators: %s: the order's total, with its currency */
				__( 'Total: %s', 'orillacart' ),
				total
			)
		: '';
	// Only a change is said: a refresh that leaves the total says nothing.
	if ( said && saying !== '' && said.textContent !== saying ) {
		said.textContent = saying;
	}
	// A choice made while this refresh was on its way stands, where the new summary still offers it.
	for ( const name of Object.keys( CHOSEN ) as ( keyof typeof CHOSEN )[] ) {
		const kept = form.dataset[ CHOSEN[ name ] ];
		if ( kept === undefined ) {
			continue;
		}
		const radio = form.querySelector< HTMLInputElement >(
			`input[name="${ name }"][value="${ CSS.escape( kept ) }"]`
		);
		if ( radio ) {
			radio.checked = true;
		}
		const select = form.querySelector< HTMLSelectElement >(
			`select[name="${ name }"]`
		);
		const option = select?.querySelector< HTMLOptionElement >(
			`option[value="${ CSS.escape( kept ) }"]`
		);
		if ( select && option && ! option.disabled ) {
			select.value = kept;
		}
	}
}
