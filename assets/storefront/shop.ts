/**
 * The shop page's search and filters: a plain GET form that works without this script. With it, a change
 * shows the matching products at once: the products region is rendered anew on the server for the new
 * address, which the browser's history keeps.
 */
import { reloadRegions } from './dom';
import { bindAddToCart } from './product';

const REGION = 'products';

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
	const region = document.querySelector(
		`[data-orillacart-region="${ REGION }"]`
	);
	region?.setAttribute( 'aria-busy', 'true' );
	try {
		await reloadRegions( [ REGION ], url );
	} catch {
		window.location.assign( url );
		return;
	}
	document
		.querySelectorAll< HTMLFormElement >(
			`[data-orillacart-region="${ REGION }"] form[data-orillacart-add-to-cart]`
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
	const apply = ( form: HTMLFormElement ) => {
		const url = filtersUrl( form );
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
