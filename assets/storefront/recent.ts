/**
 * The products the shopper viewed lately, newest first, kept in their browser: product pages add theirs, and
 * the "Recently viewed" sections show the others.
 */
import { showCards } from './cards';
import { storedIds, storeIds } from './storage';

const KEY = 'orillacart_viewed';
const MOST = 12;

/**
 * Remembers the product of the page, and fills the sections in.
 */
export async function startRecentlyViewed(): Promise< void > {
	if ( ! window.orillacartStorefront?.recentlyViewed ) {
		return;
	}
	const viewed = storedIds( KEY );
	const page = document.querySelector< HTMLElement >(
		'[data-orillacart-viewed]'
	);
	if ( page ) {
		const productId = Number( page.dataset.orillacartViewed );
		storeIds(
			KEY,
			[ productId, ...viewed.filter( ( id ) => id !== productId ) ].slice(
				0,
				MOST
			)
		);
	}
	for ( const section of Array.from(
		document.querySelectorAll< HTMLElement >(
			'[data-orillacart-recently-viewed]'
		)
	) ) {
		const exclude = Number( section.dataset.exclude ?? 0 );
		const list = section.querySelector< HTMLElement >(
			'[data-orillacart-cards]'
		);
		const ids = viewed
			.filter( ( id ) => id !== exclude )
			.slice( 0, Number( section.dataset.count ?? 4 ) );
		if ( list && ids.length > 0 ) {
			section.hidden = ( await showCards( list, ids ) ).length === 0;
		}
	}
}
