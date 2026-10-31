/**
 * The shop page's search, filters and sort: plain GET forms and links that work without this script. With it,
 * a change shows the matching products at once: the products region, and those of the blocks placed around it
 * (filters, sort, count, pages, active filters), are rendered anew on the server for the new address, which the
 * browser's history keeps.
 */
import { reloadRegions } from './dom';
import { bindAddToCart } from './product';

/** The regions a change renders anew: the products, and the blocks of their own that show the same choices. */
const REGIONS = [
	'products',
	'filters',
	'products-sort',
	'products-count',
	'products-pagination',
	'active-filters',
];

/** With the script, choices apply as they change: the filters and sort blocks need no button. */
function hideApplyButtons(): void {
	document
		.querySelectorAll< HTMLButtonElement >(
			'.orillacart-product-filters__apply, [data-orillacart-apply]'
		)
		.forEach( ( button ) => {
			button.hidden = true;
		} );
}

/**
 * The address the form would go to, leaving out empty fields.
 * @param form The filters form.
 */
export function filtersUrl( form: HTMLFormElement ): string {
	const url = new URL( form.action, window.location.href );
	url.search = '';
	for ( const [ name, value ] of new FormData( form ) ) {
		if ( typeof value === 'string' && value.trim() !== '' ) {
			url.searchParams.append( name, value );
		}
	}
	return url.toString();
}

/** The latest filters asked for, and the way to stop the one before: an older answer arriving late is dropped. */
let latest: AbortController | null = null;

async function show( url: string ): Promise< void > {
	latest?.abort();
	const asked = new AbortController();
	latest = asked;
	document
		.querySelectorAll( '[data-orillacart-region="products"]' )
		.forEach( ( region ) => region.setAttribute( 'aria-busy', 'true' ) );
	try {
		await reloadRegions( REGIONS, url, asked.signal );
	} catch {
		if ( ! asked.signal.aborted ) {
			window.location.assign( url );
		}
		return;
	}
	if ( asked.signal.aborted ) {
		return;
	}
	hideApplyButtons();
	document
		.querySelectorAll< HTMLFormElement >(
			'[data-orillacart-region="products"] form[data-orillacart-add-to-cart]'
		)
		.forEach( bindAddToCart );
}

/**
 * Listens on the document, so the form keeps working after its region is replaced.
 */
export function bindShop(): void {
	if (
		! document.querySelector(
			'form[data-orillacart-filters], a[data-orillacart-shop-link]'
		)
	) {
		return;
	}
	hideApplyButtons();
	const go = ( url: string ) => {
		// A filters block on a page without products sends the shopper to the shop page.
		if (
			new URL( url, window.location.href ).pathname !==
				window.location.pathname ||
			! document.querySelector( '[data-orillacart-region="products"]' )
		) {
			window.location.assign( url );
			return;
		}
		window.history.pushState( { orillacart: true }, '', url );
		void show( url );
	};
	const apply = ( form: HTMLFormElement ) => go( filtersUrl( form ) );
	// An active filter's link drops it on this very page.
	document.addEventListener( 'click', ( event ) => {
		const link =
			event.target instanceof Element
				? event.target.closest< HTMLAnchorElement >(
						'a[data-orillacart-shop-link]'
					)
				: null;
		// A new tab or window is the browser's to open.
		if (
			! link ||
			event.button !== 0 ||
			event.metaKey ||
			event.ctrlKey ||
			event.shiftKey ||
			event.altKey
		) {
			return;
		}
		event.preventDefault();
		go( link.href );
	} );
	document.addEventListener( 'change', ( event ) => {
		const field = event.target;
		const form =
			field instanceof HTMLElement
				? field.closest< HTMLFormElement >(
						'form[data-orillacart-filters]'
					)
				: null;
		// Words wait for the button or Enter; everything else applies when it changes.
		if (
			form &&
			! ( field instanceof HTMLInputElement && field.type === 'search' )
		) {
			apply( form );
		}
	} );
	document.addEventListener( 'submit', ( event ) => {
		const form = event.target;
		if (
			form instanceof HTMLFormElement &&
			form.matches( 'form[data-orillacart-filters]' )
		) {
			event.preventDefault();
			apply( form );
		}
	} );
	window.addEventListener( 'popstate', () => {
		void show( window.location.href );
	} );
}
