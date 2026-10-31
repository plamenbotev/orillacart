/**
 * Shop-wide blocks: the free shipping progress, filled in from the cart and again as it changes, and the store
 * notice, which a shopper may close.
 */
import { api, CART_CHANGED } from './api';

const NOTICE_KEY = 'orillacart_store_notices_closed';
/** The notices a browser remembers closing; an older one is long replaced. */
const NOTICES_KEPT = 20;

/** The latest progress asked for: an older answer arriving late is ignored. */
let progressRequest = 0;
let listening = false;

const progressBlocks = () =>
	document.querySelectorAll< HTMLElement >(
		'[data-orillacart-free-shipping]'
	);

/**
 * Fills every Free shipping progress block on the page from the shopper's cart: a visitor's page may come from a
 * page cache, so it carries no one's cart; a logged-in customer's, which caches do not keep, comes filled in. All
 * are drawn again whenever the cart changes.
 */
export function startFreeShipping(): void {
	if ( progressBlocks().length === 0 ) {
		return;
	}
	if ( ! listening ) {
		listening = true;
		document.addEventListener(
			CART_CHANGED,
			() => void showFreeShipping()
		);
	}
	if ( typeof window.orillacartStorefront?.nonce !== 'string' ) {
		void showFreeShipping();
	}
}

async function showFreeShipping(): Promise< void > {
	if ( progressBlocks().length === 0 ) {
		return;
	}
	const request = ++progressRequest;
	const result = await api< { html: string } >(
		'GET',
		'/cart/free-shipping'
	);
	if ( ! result.ok || request !== progressRequest ) {
		return;
	}
	progressBlocks().forEach( ( block ) => {
		// The shop's own template, escaped there.
		block.innerHTML = result.data.html;
		block.hidden = result.data.html === '';
	} );
}

/**
 * Shows the store notices the shopper has not closed, and lets them close one: their browser remembers it by the
 * notice's key, which changes with its words, so a new notice shows again. Without storage (a private window), a
 * notice closed stays closed for the page.
 */
export function bindStoreNotices(): void {
	document
		.querySelectorAll< HTMLElement >( '[data-orillacart-store-notice]' )
		.forEach( ( notice ) => {
			const key = notice.dataset.orillacartStoreNotice ?? '';
			if ( key === '' ) {
				return;
			}
			if ( closedNotices().includes( key ) ) {
				return;
			}
			notice.hidden = false;
			notice
				.querySelector( '[data-orillacart-store-notice-dismiss]' )
				?.addEventListener( 'click', () => {
					rememberClosed( key );
					// Every copy of it on the page, in the header and elsewhere.
					document
						.querySelectorAll< HTMLElement >(
							'[data-orillacart-store-notice]'
						)
						.forEach( ( copy ) => {
							if ( copy.dataset.orillacartStoreNotice === key ) {
								copy.hidden = true;
							}
						} );
				} );
		} );
}

function closedNotices(): string[] {
	try {
		const value: unknown = JSON.parse(
			window.localStorage.getItem( NOTICE_KEY ) ?? '[]'
		);
		return Array.isArray( value )
			? value.filter( ( key ): key is string => typeof key === 'string' )
			: [];
	} catch {
		return [];
	}
}

function rememberClosed( key: string ): void {
	try {
		window.localStorage.setItem(
			NOTICE_KEY,
			JSON.stringify(
				[
					key,
					...closedNotices().filter( ( kept ) => kept !== key ),
				].slice( 0, NOTICES_KEPT )
			)
		);
	} catch {
		// Storage off: closed for this page only.
	}
}
