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
import { navigate, reloadPage } from './navigation';

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
		clearErrors( form );
		// The total the customer saw when they chose to order: a refresh still on its way may change it.
		const seen = expectedTotal( form );
		placing( form, true );
		await summary;
		const body = formBody( form, { flags: [ 'create_account' ] } );
		delete body.ship_elsewhere;
		delete body.remind_cart;
		body.remember_addresses = body.remember_addresses === true;
		confirmAddresses( form, body );
		const now = expectedTotal( form );
		if ( seen !== null && now !== null && seen !== now ) {
			// The order costs something else than the page showed when they clicked (a new address, or a
			// suggested one taken): they see the new total, and place the order again for it.
			placing( form, false );
			showMessage(
				form,
				sprintf(
					/* translators: %s: the order's new total, with its currency */
					__(
						'The total of your order is now %s. Check it and place the order again.',
						'orillacart'
					),
					shownTotal( form ) ?? now
				),
				'error'
			);
			return;
		}
		if ( ! ( await addressesConfirmed( form, body ) ) ) {
			placing( form, false );
			return;
		}
		const result = await api< PlacedOrder >( 'POST', '/checkout', body );
		if ( result.ok ) {
			navigate( result.data.redirect_url ?? result.data.receipt_url );
			return;
		}
		if ( result.code === 'orillacart_cart_empty' ) {
			// Nothing to order any more: the cart says why (an item no longer sold, a cart emptied in another tab).
			navigate( form.dataset.orillacartCartUrl || window.location.href );
			return;
		}
		if ( result.code === 'orillacart_cart_changed' ) {
			// The summary shows what the order costs now; the message goes in after it is drawn. A page that
			// cannot show it any more (an item no longer sold) is loaded anew by the refresh, and says why.
			summary = refreshSummary( form );
			await summary;
		}
		placing( form, false );
		if (
			result.code === 'orillacart_unreadable' ||
			result.code === 'orillacart_network'
		) {
			// The order may have been placed: where to find it is said, rather than inviting a second one.
			const orders = form.dataset.orillacartAccountUrl;
			showMessage(
				form,
				result.message +
					' ' +
					__( 'Your order may have been placed.', 'orillacart' ),
				'error',
				orders
					? {
							url: orders,
							label: __( 'See your orders', 'orillacart' ),
						}
					: undefined
			);
			return;
		}
		const violations =
			result.code === 'orillacart_terms_not_accepted' &&
			result.violations.length === 0
				? [ { field: 'accepts_terms', message: result.message } ]
				: result.violations;
		showMessage(
			form,
			failure( result, showErrors( form, violations ) ),
			'error'
		);
	} );

	// A page the browser kept and shows again (Back after paying elsewhere) comes as it was left: the button
	// placing the order off, and the totals of then.
	window.addEventListener( 'pageshow', ( event ) => {
		if ( ! event.persisted || ! form.isConnected ) {
			return;
		}
		placing( form, false );
		summary = summary.then( () => refreshSummary( form ) );
	} );
}

/**
 * Turns the order button off while the order is being placed, or back on; a summary drawn anew meanwhile
 * brings a button of its own, which follows.
 * @param form The checkout form.
 * @param on   Whether the order is being placed.
 */
function placing( form: HTMLFormElement, on: boolean ): void {
	if ( on ) {
		form.dataset.orillacartPlacing = '1';
	} else {
		delete form.dataset.orillacartPlacing;
	}
	form.querySelectorAll< HTMLButtonElement >(
		'button[type="submit"]'
	).forEach( ( button ) => {
		button.disabled = on;
	} );
}

/**
 * The total the summary on the page priced, as it sends it.
 * @param form The checkout form.
 */
function expectedTotal( form: HTMLFormElement ): string | null {
	return (
		form.querySelector< HTMLInputElement >( '[name="expected_total"]' )
			?.value ?? null
	);
}

/**
 * The total as the summary shows it, with its currency.
 * @param form The checkout form.
 */
function shownTotal( form: HTMLFormElement ): string | null {
	const fresh = form.querySelector(
		'[data-orillacart-region="checkout-summary"]'
	);
	return (
		(
			fresh?.querySelector( '.orillacart-amount-due td' ) ??
			fresh?.querySelector( '.orillacart-total td' )
		)?.textContent?.trim() || null
	);
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
 * What the summary says it refuses: each code or gift card that does not hold, with why.
 * @param form The checkout form.
 */
function refusals( form: HTMLFormElement ): string[] {
	return Array.from(
		form.querySelectorAll(
			'[data-orillacart-region="checkout-summary"] .orillacart-coupon--refused, [data-orillacart-region="checkout-summary"] .orillacart-gift-card--refused'
		),
		( refusal ) => {
			const copy = refusal.cloneNode( true ) as HTMLElement;
			copy.querySelectorAll( 'button' ).forEach( ( button ) =>
				button.remove()
			);
			return ( copy.textContent ?? '' ).replace( /\s+/g, ' ' ).trim();
		}
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
	// Read before the summary is drawn anew, to say only what the new one refuses.
	// eslint-disable-next-line @wordpress/no-unused-vars-before-return -- a page reloaded needs it not
	const refusedBefore = refusals( form );
	summary?.setAttribute( 'aria-busy', 'true' );
	try {
		const missing = await reloadRegions(
			[ 'checkout-summary' ],
			url.toString()
		);
		if ( missing.length > 0 ) {
			// The page renders the checkout no more (the cart emptied, an item no longer sold): it is loaded
			// anew, and says why, rather than leave totals that no longer hold.
			reloadPage();
			return;
		}
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
	// The summary's new button stays off while the order is being placed.
	if ( form.dataset.orillacartPlacing === '1' ) {
		fresh
			?.querySelectorAll< HTMLButtonElement >( 'button[type="submit"]' )
			.forEach( ( button ) => {
				button.disabled = true;
			} );
	}
	const said = form.querySelector( '[data-orillacart-total-said]' );
	const total = shownTotal( form );
	const saying = total
		? sprintf(
				/* translators: %s: the order's total, with its currency */
				__( 'Total: %s', 'orillacart' ),
				total
			)
		: '';
	// A code or gift card the new totals refuse (the country changed, the minimum is no longer met) is said too:
	// it shows inside the summary, which is not read out as it is replaced.
	const refused = refusals( form ).filter(
		( text ) => ! refusedBefore.includes( text )
	);
	// Only a change is said: a refresh that leaves the total, and refuses nothing new, says nothing.
	const totalChanged =
		saying !== '' && ( said?.textContent ?? '' ).indexOf( saying ) !== 0;
	if ( said && ( totalChanged || refused.length > 0 ) ) {
		said.textContent = [ saying, ...refused ].filter( Boolean ).join( ' ' );
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
