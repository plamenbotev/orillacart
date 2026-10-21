/**
 * The wishlist: products saved for later. A customer's is in their account; a visitor's in their browser,
 * which joins the account's when they log in. Pages are the same for everyone (page caches keep them): the
 * script marks what is saved and fills the lists.
 */
import { __ } from '@wordpress/i18n';

import { api } from './api';
import { showCards } from './cards';
import { storedIds, storeIds } from './storage';
import { showMessage } from './dom';

const KEY = 'orillacart_wishlist';
const MOST = 100;

type Saved = { product_ids: number[] };

function settings() {
	const config = window.orillacartStorefront;
	return {
		policy: config?.wishlist ?? 'off',
		// Only logged-in pages carry a nonce.
		loggedIn: typeof config?.nonce === 'string',
		account: config?.account ?? null,
	};
}

/**
 * Marks the save buttons and fills the wishlists on the page; first, a visitor's list joins the account they
 * logged in to.
 */
export async function startWishlist(): Promise< void > {
	const { policy, loggedIn } = settings();
	if ( policy === 'off' ) {
		return;
	}
	const buttons = Array.from(
		document.querySelectorAll< HTMLButtonElement >(
			'button[data-orillacart-wishlist]'
		)
	);
	const lists = Array.from(
		document.querySelectorAll< HTMLElement >(
			'[data-orillacart-wishlist-list]'
		)
	);
	let saved: number[] = [];
	if ( loggedIn ) {
		const kept = storedIds( KEY );
		let result = null;
		if ( kept.length > 0 && policy === 'guests' ) {
			result = await api< Saved >( 'POST', '/account/wishlist', {
				product_ids: kept,
			} );
		} else if ( buttons.length > 0 || lists.length > 0 ) {
			result = await api< Saved >( 'GET', '/account/wishlist' );
		}
		if ( result?.ok ) {
			saved = result.data.product_ids;
			storeIds( KEY, [] );
		}
	} else if ( policy === 'guests' ) {
		saved = storedIds( KEY );
	}
	buttons.forEach( ( button ) =>
		bindButton(
			button,
			() => saved,
			( ids ) => ( saved = ids )
		)
	);
	for ( const list of lists ) {
		await fillList( list, saved, ( ids ) => ( saved = ids ) );
	}
}

function mark( button: HTMLButtonElement, on: boolean ): void {
	button.setAttribute( 'aria-pressed', on ? 'true' : 'false' );
	const label = button.querySelector( '[data-orillacart-wishlist-label]' );
	if ( label ) {
		label.textContent = on
			? __( 'Saved to your wishlist', 'orillacart' )
			: __( 'Save to wishlist', 'orillacart' );
	}
	const heart = button.querySelector( '[aria-hidden]' );
	if ( heart ) {
		heart.textContent = on ? '♥' : '♡';
	}
}

/**
 * Saves the product, or takes it off: in the account, or the browser for a visitor.
 *
 * @param productId The product.
 * @param on        Save it, or take it off.
 * @param saved     The list as it is.
 * @return The list after, or null when it could not change.
 */
async function change(
	productId: number,
	on: boolean,
	saved: number[]
): Promise< number[] | null > {
	if ( settings().loggedIn ) {
		const result = await api< Saved >(
			on ? 'PUT' : 'DELETE',
			`/account/wishlist/${ productId }`
		);
		return result.ok ? result.data.product_ids : null;
	}
	const ids = on
		? [ productId, ...saved.filter( ( id ) => id !== productId ) ].slice(
				0,
				MOST
			)
		: saved.filter( ( id ) => id !== productId );
	storeIds( KEY, ids );
	return ids;
}

function bindButton(
	button: HTMLButtonElement,
	saved: () => number[],
	keep: ( ids: number[] ) => void
): void {
	const { policy, loggedIn, account } = settings();
	const productId = Number( button.dataset.orillacartWishlist );
	const scope = button.parentElement;
	mark( button, saved().includes( productId ) );
	button.hidden = false;
	button.addEventListener( 'click', async () => {
		if ( ! loggedIn && policy === 'accounts' ) {
			showMessage(
				scope,
				__( 'Log in to keep a wishlist.', 'orillacart' ),
				'error',
				account
					? { url: account, label: __( 'Log in', 'orillacart' ) }
					: undefined
			);
			return;
		}
		const on = button.getAttribute( 'aria-pressed' ) !== 'true';
		button.disabled = true;
		const ids = await change( productId, on, saved() );
		button.disabled = false;
		if ( ids === null ) {
			showMessage(
				scope,
				__(
					'The wishlist could not be saved. Try again.',
					'orillacart'
				),
				'error'
			);
			return;
		}
		keep( ids );
		mark( button, on );
		showMessage(
			scope,
			on
				? __( 'Saved. Find it in your wishlist.', 'orillacart' )
				: __( 'Taken off your wishlist.', 'orillacart' ),
			'success',
			on && account
				? {
						url: `${ account }${
							account.includes( '?' ) ? '&' : '?'
						}section=wishlist#wishlist`,
						label: __( 'See the wishlist', 'orillacart' ),
					}
				: undefined
		);
	} );
}

async function fillList(
	list: HTMLElement,
	saved: number[],
	keep: ( ids: number[] ) => void
): Promise< void > {
	const empty = list.parentElement?.querySelector< HTMLElement >(
		'[data-orillacart-wishlist-empty]'
	);
	const shown = await showCards( list, saved );
	if ( empty ) {
		empty.hidden = shown.length > 0;
	}
	list.querySelectorAll< HTMLElement >( 'li[data-product]' ).forEach(
		( card ) => {
			const productId = Number( card.dataset.product );
			const remove = document.createElement( 'button' );
			remove.type = 'button';
			remove.className = 'orillacart-wishlist__remove';
			remove.textContent = __( 'Remove', 'orillacart' );
			remove.addEventListener( 'click', async () => {
				remove.disabled = true;
				const ids = await change( productId, false, saved );
				if ( ids === null ) {
					remove.disabled = false;
					return;
				}
				saved = ids;
				keep( ids );
				card.remove();
				if ( empty ) {
					empty.hidden = list.children.length > 0;
				}
			} );
			card.append( remove );
		}
	);
}
