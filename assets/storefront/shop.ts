/**
 * The shop page's search and filters: plain GET forms that work without this script. With it, a change
 * shows the matching products at once: the products region, and a Product filters block's, are rendered anew on
 * the server for the new address, which the browser's history keeps.
 */
import { reloadRegions } from './dom';
import { bindAddToCart } from './product';

/** The regions a change renders anew: the products, and the filters when they are a block of their own. */
const REGIONS = [ 'products', 'filters' ];

/** With the script, choices apply as they change: the filters block needs no button. */
function hideApplyButtons(): void {
	document
		.querySelectorAll< HTMLButtonElement >(
			'.orillacart-product-filters__apply'
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

async function show( url: string ): Promise< void > {
	document
		.querySelectorAll( '[data-orillacart-region="products"]' )
		.forEach( ( region ) => region.setAttribute( 'aria-busy', 'true' ) );
	try {
		await reloadRegions( REGIONS, url );
	} catch {
		window.location.assign( url );
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
	if ( ! document.querySelector( 'form[data-orillacart-filters]' ) ) {
		return;
	}
	hideApplyButtons();
	const apply = ( form: HTMLFormElement ) => {
		const url = filtersUrl( form );
		// A filters block on a page without products sends the shopper to the shop page.
		if (
			new URL( url ).pathname !== window.location.pathname ||
			! document.querySelector( '[data-orillacart-region="products"]' )
		) {
			window.location.assign( url );
			return;
		}
		window.history.pushState( { orillacart: true }, '', url );
		void show( url );
	};
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
