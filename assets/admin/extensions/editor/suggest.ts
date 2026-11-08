import type { Reference } from '../api';
import { DECLARATIONS, KEYWORDS } from './ocs';

/**
 * What to offer while writing a script, from the site's reference (host functions with arity, summary and
 * permission; filters; tests; keywords) and the names the buffer declares. Pure: no editor in sight, so the
 * admin's CodeMirror and the VS Code language server share it and the two never disagree.
 */

export interface ReferenceFunction {
	name: string;
	summary: string;
	arguments: [ number, number | null ];
	needs: string | null;
}

export type SuggestionKind =
	| 'filter'
	| 'test'
	| 'namespace'
	| 'function'
	| 'keyword'
	| 'declaration'
	| 'variable'
	| 'own-function';

export interface Suggestion {
	label: string;
	kind: SuggestionKind;
	detail?: string;
	info?: string;
	/** What to insert when it differs from the label (a call's opening paren). */
	insert?: string;
}

export interface Suggestions {
	/** How many characters before the cursor the suggestions replace. */
	replace: number;
	items: Suggestion[];
}

export function arity( fn: ReferenceFunction ): string {
	const [ least, most ] = fn.arguments;
	if ( most === null ) {
		return `${ least }+ args`;
	}
	return least === most
		? `${ least } arg${ least === 1 ? '' : 's' }`
		: `${ least }–${ most } args`;
}

/**
 * The names the buffer itself declares, by a scan good enough for completion.
 *
 * @param text The whole buffer.
 */
export function localNames( text: string ): {
	variables: string[];
	functions: string[];
	objects: string[];
} {
	const variables = new Set< string >();
	const functions = new Set< string >();
	const objects = new Set< string >();
	for ( const m of text.matchAll( /\blet\s+([A-Za-z_][A-Za-z0-9_]*)/g ) ) {
		variables.add( m[ 1 ] ?? '' );
	}
	for ( const m of text.matchAll(
		/\bfor\s+([A-Za-z_][A-Za-z0-9_]*)(?:\s*,\s*([A-Za-z_][A-Za-z0-9_]*))?\s+in\b/g
	) ) {
		variables.add( m[ 1 ] ?? '' );
		if ( m[ 2 ] ) {
			variables.add( m[ 2 ] );
		}
	}
	for ( const m of text.matchAll(
		/\bfn\b\s*([A-Za-z_][A-Za-z0-9_]*)?\s*\(([^)]*)\)/g
	) ) {
		if ( m[ 1 ] ) {
			functions.add( m[ 1 ] );
		}
		for ( const param of ( m[ 2 ] ?? '' ).split( ',' ) ) {
			const name = param.trim();
			if ( /^[A-Za-z_][A-Za-z0-9_]*$/.test( name ) ) {
				variables.add( name );
			}
		}
	}
	for ( const m of text.matchAll( /\bcatch\s+([A-Za-z_][A-Za-z0-9_]*)/g ) ) {
		variables.add( m[ 1 ] ?? '' );
	}
	for ( const m of text.matchAll( /\bobject\s+"([a-z][a-z0-9_]*)"/g ) ) {
		objects.add( m[ 1 ] ?? '' );
	}
	variables.delete( '' );
	objects.delete( '' );
	return {
		variables: [ ...variables ],
		functions: [ ...functions ],
		objects: [ ...objects ],
	};
}

/**
 * The functions of the reference with `*` replaced by each object kind the buffer declares.
 *
 * @param functions The reference's functions.
 * @param objects   The object kinds the buffer declares.
 */
export function expand(
	functions: ReferenceFunction[],
	objects: string[]
): ReferenceFunction[] {
	const out: ReferenceFunction[] = [];
	for ( const fn of functions ) {
		if ( ! fn.name.includes( '*' ) ) {
			out.push( fn );
			continue;
		}
		for ( const kind of objects ) {
			out.push( { ...fn, name: fn.name.replace( '*', kind ) } );
		}
	}
	return out;
}

/**
 * The suggestions at a cursor.
 *
 * @param reference the site's reference
 * @param before    the line up to the cursor
 * @param text      the whole buffer
 * @param explicit  whether the writer asked (Ctrl+Space) rather than typed
 */
export function suggest(
	reference: Reference,
	before: string,
	text: string,
	explicit = false
): Suggestions | null {
	const filter = /\|\s*([A-Za-z_][A-Za-z0-9_]*)?$/.exec( before );
	if ( filter ) {
		return {
			replace: filter[ 1 ]?.length ?? 0,
			items: reference.filters.map( ( label ) => ( {
				label,
				kind: 'filter',
				detail: 'filter',
			} ) ),
		};
	}
	const test = /\bis\s+(?:not\s+)?([A-Za-z_][A-Za-z0-9_]*)?$/.exec( before );
	if ( test ) {
		return {
			replace: test[ 1 ]?.length ?? 0,
			items: reference.tests.map( ( label ) => ( {
				label,
				kind: 'test',
				detail: 'test',
			} ) ),
		};
	}
	const word = /([A-Za-z_][A-Za-z0-9_.]*)$/.exec( before );
	const typed = word?.[ 1 ] ?? '';
	if ( typed === '' && ! explicit ) {
		return null;
	}
	const locals = localNames( text );
	const functions = expand( reference.functions, locals.objects );

	if ( typed.includes( '.' ) ) {
		const prefix = typed.slice( 0, typed.lastIndexOf( '.' ) + 1 );
		const items = new Map< string, Suggestion >();
		for ( const fn of functions ) {
			if ( ! fn.name.startsWith( prefix ) ) {
				continue;
			}
			const rest = fn.name.slice( prefix.length );
			const segment = rest.split( '.' )[ 0 ] ?? rest;
			if ( rest.includes( '.' ) ) {
				items.set( segment, {
					label: prefix + segment,
					kind: 'namespace',
					detail: 'namespace',
				} );
			} else {
				items.set( segment, {
					label: fn.name,
					kind: 'function',
					detail: arity( fn ),
					info:
						fn.summary +
						( fn.needs ? `  [needs ${ fn.needs }]` : '' ),
					insert: fn.name + '(',
				} );
			}
		}
		return { replace: typed.length, items: [ ...items.values() ] };
	}

	const items: Suggestion[] = [];
	if ( before.trim() === typed ) {
		for ( const label of DECLARATIONS ) {
			items.push( { label, kind: 'declaration', detail: 'declaration' } );
		}
	}
	for ( const label of reference.keywords ?? KEYWORDS ) {
		items.push( { label, kind: 'keyword' } );
	}
	// Namespaces come from the reference as it is, so `objects` is offered before any kind is declared.
	const namespaces = new Set< string >();
	for ( const fn of reference.functions ) {
		if ( fn.name.includes( '.' ) ) {
			namespaces.add( fn.name.split( '.' )[ 0 ] ?? fn.name );
		}
	}
	for ( const fn of functions ) {
		if ( ! fn.name.includes( '.' ) ) {
			items.push( {
				label: fn.name,
				kind: 'function',
				detail: arity( fn ),
				info: fn.summary,
				insert: fn.name + '(',
			} );
		}
	}
	for ( const label of namespaces ) {
		items.push( { label, kind: 'namespace', detail: 'namespace' } );
	}
	for ( const label of locals.variables ) {
		items.push( { label, kind: 'variable' } );
	}
	for ( const label of locals.functions ) {
		items.push( {
			label,
			kind: 'own-function',
			detail: 'this extension',
			insert: label + '(',
		} );
	}
	return { replace: typed.length, items };
}

/**
 * The dotted name around an offset in a line, with where it starts and ends.
 *
 * @param line   The line's text.
 * @param offset Where in the line.
 */
export function nameAt(
	line: string,
	offset: number
): { name: string; start: number; end: number } | null {
	let start = offset;
	let end = offset;
	while ( start > 0 && /[A-Za-z0-9_.]/.test( line[ start - 1 ] ?? '' ) ) {
		start--;
	}
	while ( end < line.length && /[A-Za-z0-9_.]/.test( line[ end ] ?? '' ) ) {
		end++;
	}
	const name = line.slice( start, end );
	return name === '' ? null : { name, start, end };
}

/**
 * The host function of that name, with the buffer's object kinds filled in, or null.
 *
 * @param reference The site's reference.
 * @param text      The whole buffer.
 * @param name      The dotted name.
 */
export function describe(
	reference: Reference,
	text: string,
	name: string
): ReferenceFunction | null {
	return (
		expand( reference.functions, localNames( text ).objects ).find(
			( fn ) => fn.name === name
		) ?? null
	);
}
