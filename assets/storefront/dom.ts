/**
 * Page helpers: reloading server-rendered regions, messages, field errors and form bodies.
 */
import { __ } from '@wordpress/i18n';

import type { ApiResult, Violation } from './api';

/**
 * Replaces the page's regions with the same regions rendered anew: the server's templates stay the only
 * place that knows how the shop looks.
 * @param names  Regions to replace.
 * @param url    The page to render them from.
 * @param signal Stops the call (a newer one replaces it): it then throws, and nothing on the page changes.
 * @return The regions on the page the fresh page did not have (a cart emptied meanwhile renders a page without
 *         the checkout's summary): those are left as they were, and the caller decides what to do.
 */
export async function reloadRegions(
	names: string[],
	url: string = window.location.href,
	signal?: AbortSignal
): Promise< string[] > {
	const response = await fetch( url, {
		credentials: 'same-origin',
		cache: 'no-store',
		signal: signal ?? null,
	} );
	if ( ! response.ok ) {
		// An error page has none of the regions: say so rather than leave the page as it was.
		throw new Error( `The page answered ${ response.status }.` );
	}
	const html = await response.text();
	// Stopped while the page was read: a newer call draws the regions.
	if ( signal?.aborted ) {
		throw new DOMException( 'Replaced by a newer call.', 'AbortError' );
	}
	const fresh = new DOMParser().parseFromString( html, 'text/html' );
	const missing: string[] = [];
	for ( const name of names ) {
		const selector = `[data-orillacart-region="${ name }"]`;
		const replacements = fresh.querySelectorAll( selector );
		document.querySelectorAll( selector ).forEach( ( region, index ) => {
			const replacement = replacements[ index ];
			if ( ! replacement ) {
				if ( ! missing.includes( name ) ) {
					missing.push( name );
				}
				return;
			}
			const next = document.importNode( replacement, true );
			const restore = keepTyping( region, next );
			region.replaceWith( next );
			restore();
		} );
	}
	return missing;
}

/**
 * Carries what the customer is doing in a region over to its new copy: what they typed in a field and did not send
 * (a code half entered while the totals change), and where the keyboard is, so a refresh neither loses the code
 * nor throws a keyboard user back to the top of the page.
 * @param old  The region on the page.
 * @param next Its new copy, not yet on the page.
 * @return Puts the keyboard back, once the new copy is on the page.
 */
function keepTyping( old: Element, next: Element ): () => void {
	old.querySelectorAll< HTMLInputElement | HTMLTextAreaElement >(
		'input[id]:not([type="radio"]):not([type="checkbox"]):not([type="hidden"]), textarea[id]'
	).forEach( ( field ) => {
		const copy = withAttribute( next, 'id', field.id );
		if (
			( copy instanceof HTMLInputElement ||
				copy instanceof HTMLTextAreaElement ) &&
			field.value !== field.defaultValue
		) {
			copy.value = field.value;
		}
	} );
	const focused = old.ownerDocument.activeElement;
	const found =
		focused instanceof HTMLElement && old.contains( focused )
			? sameIn( next, focused )
			: null;

	return () => {
		if ( ! found || ! found.isConnected ) {
			return;
		}
		found.focus( { preventScroll: true } );
		if (
			focused instanceof HTMLInputElement &&
			found instanceof HTMLInputElement &&
			focused.selectionStart !== null
		) {
			try {
				found.setSelectionRange(
					focused.selectionStart,
					focused.selectionEnd
				);
			} catch {
				// Not a field with a caret (a number field).
			}
		}
	};
}

/**
 * The element in a region's new copy that stands where the given one did: by its id, by its name and value (a
 * radio button), or by the data attribute that names what it does (a button "remove this code").
 * @param next    The new copy.
 * @param element The element in the region on the page.
 */
function sameIn( next: Element, element: HTMLElement ): HTMLElement | null {
	if ( element.id ) {
		return withAttribute( next, 'id', element.id );
	}
	const name = element.getAttribute( 'name' );
	if ( name ) {
		const value = element.getAttribute( 'value' );
		return (
			Array.from( next.querySelectorAll< HTMLElement >( '[name]' ) ).find(
				( candidate ) =>
					candidate.getAttribute( 'name' ) === name &&
					candidate.getAttribute( 'value' ) === value
			) ?? null
		);
	}
	const named = Array.from( element.attributes ).find( ( attribute ) =>
		attribute.name.startsWith( 'data-orillacart-' )
	);
	return named ? withAttribute( next, named.name, named.value ) : null;
}

/**
 * The first element in the scope whose attribute has this value; compared rather than put in a selector, so no
 * value needs escaping.
 * @param scope     Where to look.
 * @param attribute The attribute's name.
 * @param value     Its value.
 */
function withAttribute(
	scope: Element,
	attribute: string,
	value: string
): HTMLElement | null {
	return (
		Array.from(
			scope.querySelectorAll< HTMLElement >( `[${ attribute }]` )
		).find( ( element ) => element.getAttribute( attribute ) === value ) ??
		null
	);
}

export function showMessage(
	scope: Element | null,
	text: string,
	kind: 'success' | 'error' = 'success',
	link?: { url: string; label: string }
): void {
	const box = scope?.querySelector< HTMLElement >(
		'[data-orillacart-message]'
	);
	if ( ! box ) {
		return;
	}
	box.className = `orillacart-message orillacart-message--${ kind }`;
	box.textContent = text;
	if ( link ) {
		const anchor = document.createElement( 'a' );
		anchor.href = link.url;
		anchor.textContent = link.label;
		box.append( ' ', anchor );
	}
}

/**
 * Adds an id to, or takes it from, the ids an attribute lists (aria-describedby), keeping the others: a field's
 * help text stays described when an error comes and goes.
 * @param element   The field.
 * @param attribute The attribute.
 * @param id        The id.
 * @param add       Whether to add it, or take it away.
 */
function toggleToken(
	element: Element,
	attribute: string,
	id: string,
	add: boolean
): void {
	const ids = ( element.getAttribute( attribute ) ?? '' )
		.split( /\s+/ )
		.filter( ( token ) => token !== '' && token !== id );
	if ( add ) {
		ids.push( id );
	}
	if ( ids.length > 0 ) {
		element.setAttribute( attribute, ids.join( ' ' ) );
	} else {
		element.removeAttribute( attribute );
	}
}

export function clearErrors( form: HTMLElement ): void {
	form.querySelectorAll( '.orillacart-field__error' ).forEach( ( error ) => {
		form.querySelectorAll( '[aria-describedby]' ).forEach( ( field ) =>
			toggleToken( field, 'aria-describedby', error.id, false )
		);
		error.remove();
	} );
	form.querySelectorAll( '[aria-invalid]' ).forEach( ( field ) => {
		field.removeAttribute( 'aria-invalid' );
	} );
}

/**
 * Shows each problem next to its field; returns the problems no field on the form is named for.
 * @param form       The form.
 * @param violations The API's problems, by field.
 */
export function showErrors(
	form: HTMLElement,
	violations: Violation[]
): Violation[] {
	const unplaced: Violation[] = [];
	violations.forEach( ( violation, index ) => {
		const field = form.querySelector< HTMLElement >(
			`[data-orillacart-field="${ CSS.escape( violation.field ) }"]`
		);
		if ( ! field || field.closest( '[hidden]' ) ) {
			unplaced.push( violation );
			return;
		}
		const error = document.createElement( 'span' );
		error.className = 'orillacart-field__error';
		error.id = `orillacart-error-${ index }`;
		error.textContent = violation.message;
		field.setAttribute( 'aria-invalid', 'true' );
		toggleToken( field, 'aria-describedby', error.id, true );
		( field.tagName === 'FIELDSET'
			? field
			: ( field.parentElement ?? field )
		).append( error );
	} );
	form.querySelector< HTMLElement >( '[aria-invalid="true"]' )?.focus();
	return unplaced;
}

/**
 * The message for a failed call, with the problems no field shows.
 * @param result   The failed call.
 * @param unplaced Problems no field shows.
 */
export function failure(
	result: Extract< ApiResult< unknown >, { ok: false } >,
	unplaced: Violation[] = []
): string {
	if ( unplaced.length > 0 ) {
		return unplaced.map( ( violation ) => violation.message ).join( ' ' );
	}
	if ( result.violations.length > 0 ) {
		return __( 'Check the marked fields.', 'orillacart' );
	}
	return (
		result.message || __( 'Something went wrong. Try again.', 'orillacart' )
	);
}

export type Json =
	string | number | boolean | null | Json[] | { [ key: string ]: Json };

export type FieldTypes = {
	/** Fields whose values gather in an array of numbers, such as option_ids. */
	lists?: string[];
	/** Fields sent as numbers; left out when empty. */
	numbers?: string[];
	/** Hidden fields sent as true, like a checked box. */
	flags?: string[];
};

/**
 * The form's enabled fields as the API's JSON: "billing.city" becomes { billing: { city } } and checked
 * boxes are true; `types` names the fields that are arrays, numbers or flags.
 * @param form  The form.
 * @param types Which fields are arrays, numbers or flags.
 */
export function formBody(
	form: HTMLFormElement,
	types: FieldTypes = {}
): Record< string, Json > {
	const body: Record< string, Json > = {};
	for ( const element of Array.from( form.elements ) ) {
		if ( ! (
			element instanceof HTMLInputElement ||
			element instanceof HTMLSelectElement ||
			element instanceof HTMLTextAreaElement
		) ) {
			continue;
		}
		if (
			! element.name ||
			element.disabled ||
			element.closest( 'fieldset:disabled' )
		) {
			continue;
		}
		let value: Json = element.value;
		if (
			element instanceof HTMLInputElement &&
			( element.type === 'checkbox' || element.type === 'radio' )
		) {
			if ( ! element.checked ) {
				continue;
			}
			// A checked box is a flag, unless it is one of a list's values.
			value =
				element.type === 'checkbox' &&
				! types.lists?.includes( element.name )
					? true
					: element.value;
		}
		if ( types.flags?.includes( element.name ) ) {
			value = true;
		}
		if (
			types.numbers?.includes( element.name ) ||
			types.lists?.includes( element.name )
		) {
			if ( value === '' ) {
				continue;
			}
			value = Number( value );
		}
		const path = element.name.split( '.' );
		const last = path.pop() as string;
		let target = body;
		for ( const key of path ) {
			const next = target[ key ];
			target = (
				next !== null &&
				typeof next === 'object' &&
				! Array.isArray( next )
					? next
					: ( target[ key ] = {} )
			) as Record< string, Json >;
		}
		if ( types.lists?.includes( element.name ) ) {
			const list = Array.isArray( target[ last ] )
				? ( target[ last ] as Json[] )
				: [];
			list.push( value );
			target[ last ] = list;
		} else {
			target[ last ] = value;
		}
	}
	return body;
}

/**
 * Keeps a state list to the chosen country's states, and hides it for countries without states.
 * @param scope The element holding the address fields.
 */
export function bindStates( scope: HTMLElement ): void {
	const data =
		document.querySelector( '[data-orillacart-states]' )?.textContent ??
		'{}';
	const states = JSON.parse( data ) as Record<
		string,
		{ code: string; name: string }[]
	>;
	scope
		.querySelectorAll< HTMLSelectElement >( '[data-orillacart-country]' )
		.forEach( ( country ) => {
			country.addEventListener( 'change', () => {
				const address = country.closest( '[data-orillacart-address]' );
				const select = address?.querySelector< HTMLSelectElement >(
					'[data-orillacart-state]'
				);
				const field = address?.querySelector< HTMLElement >(
					'[data-orillacart-state-field]'
				);
				if ( ! select || ! field ) {
					return;
				}
				const list = states[ country.value ] ?? [];
				const placeholder = select.options[ 0 ]?.cloneNode( true );
				select.replaceChildren(
					...( placeholder ? [ placeholder ] : [] )
				);
				for ( const state of list ) {
					select.append( new Option( state.name, state.code ) );
				}
				field.hidden = list.length === 0;
				select.disabled = list.length === 0;
				select.required = list.length > 0;
			} );
		} );
}
