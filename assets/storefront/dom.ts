/**
 * Page helpers: reloading server-rendered regions, messages, field errors and form bodies.
 */
import { __ } from '@wordpress/i18n';

import type { ApiResult, Violation } from './api';

/**
 * Replaces the page's regions with the same regions rendered anew: the server's templates stay the only
 * place that knows how the shop looks.
 * @param names Regions to replace.
 * @param url   The page to render them from.
 */
export async function reloadRegions(
	names: string[],
	url: string = window.location.href
): Promise< void > {
	const response = await fetch( url, {
		credentials: 'same-origin',
		cache: 'no-store',
	} );
	const fresh = new DOMParser().parseFromString(
		await response.text(),
		'text/html'
	);
	for ( const name of names ) {
		const selector = `[data-orillacart-region="${ name }"]`;
		const replacements = fresh.querySelectorAll( selector );
		document.querySelectorAll( selector ).forEach( ( region, index ) => {
			const replacement = replacements[ index ];
			if ( replacement ) {
				region.replaceWith( document.importNode( replacement, true ) );
			}
		} );
	}
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

export function clearErrors( form: HTMLElement ): void {
	form.querySelectorAll( '.orillacart-field__error' ).forEach( ( error ) =>
		error.remove()
	);
	form.querySelectorAll( '[aria-invalid]' ).forEach( ( field ) => {
		field.removeAttribute( 'aria-invalid' );
		field.removeAttribute( 'aria-describedby' );
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
		field.setAttribute( 'aria-describedby', error.id );
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

type Json =
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
