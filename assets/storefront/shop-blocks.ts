/**
 * Shop-wide blocks: the free shipping and promotion progress, filled in from the cart and again as it changes,
 * and the store notice, which a shopper may close.
 */
import { api, CART_CHANGED } from './api';
import { reloadRegions } from './dom';

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

/** The latest promotions asked for: an older answer arriving late is ignored. */
let promotionsRequest = 0;
let listeningForPromotions = false;

const promotionBlocks = () =>
	document.querySelectorAll< HTMLElement >( '[data-orillacart-promotions]' );

/**
 * Fills every Promotion progress block on the page from the shopper's cart, as the free shipping progress is
 * filled: a visitor's page carries no one's cart, a logged-in customer's comes filled in, and all are drawn again
 * whenever the cart changes.
 */
export function startPromotions(): void {
	if ( promotionBlocks().length === 0 ) {
		return;
	}
	if ( ! listeningForPromotions ) {
		listeningForPromotions = true;
		document.addEventListener( CART_CHANGED, () => void showPromotions() );
		document.addEventListener( 'click', ( event ) => {
			const button =
				event.target instanceof Element
					? event.target.closest< HTMLButtonElement >(
							'[data-orillacart-restore-given]'
						)
					: null;
			if ( button ) {
				void restoreGiven( button );
			}
		} );
	}
	if ( typeof window.orillacartStorefront?.nonce !== 'string' ) {
		void showPromotions();
	}
}

/**
 * Gives the cart back the free items of a promotion the shopper removed them from. The cart then changes, so the
 * blocks and the cart page are drawn again.
 *
 * @param button The promotion's "Add it back" button.
 */
async function restoreGiven( button: HTMLButtonElement ): Promise< void > {
	const code = button.dataset.orillacartRestoreGiven ?? '';
	button.disabled = true;
	const result = await api(
		'POST',
		`/cart/given/${ encodeURIComponent( code ) }`
	);
	button.disabled = false;
	if (
		result.ok &&
		document.querySelector( '[data-orillacart-region="cart"]' )
	) {
		await reloadRegions( [ 'cart', 'mini-cart' ] );
	}
}

async function showPromotions(): Promise< void > {
	if ( promotionBlocks().length === 0 ) {
		return;
	}
	const request = ++promotionsRequest;
	const result = await api< { html: string } >( 'GET', '/cart/promotions' );
	if ( ! result.ok || request !== promotionsRequest ) {
		return;
	}
	promotionBlocks().forEach( ( block ) =>
		fillPromotions( block, result.data.html )
	);
}

/**
 * Puts the promotions in a block as its settings say: those the cart gets unless it leaves them out, then as many
 * of those still to get as it shows, the nearest first as the server sorted them. Hidden with none left.
 *
 * @param block The block's element.
 * @param html  Every promotion, as the server lists them.
 */
export function fillPromotions( block: HTMLElement, html: string ): void {
	// The shop's own template, escaped there.
	block.innerHTML = html;
	const showApplied = block.dataset.showApplied !== 'false';
	const count = Math.max( 1, Number( block.dataset.count ) || 3 );
	let offers = 0;
	block
		.querySelectorAll< HTMLElement >( '[data-orillacart-promotion]' )
		.forEach( ( item ) => {
			const applied = item.dataset.orillacartPromotion === 'applied';
			if ( applied ? ! showApplied : ++offers > count ) {
				item.remove();
			}
		} );
	block.hidden =
		block.querySelector( '[data-orillacart-promotion]' ) === null;
	if ( block.hidden ) {
		block.innerHTML = '';
	}
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
