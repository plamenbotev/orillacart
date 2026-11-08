import type {
	Completion,
	CompletionContext,
	CompletionResult,
} from '@codemirror/autocomplete';
import { hoverTooltip, type EditorView } from '@codemirror/view';

import type { Reference } from '../api';
import { DECLARATIONS, KEYWORDS } from './ocs';

/**
 * Completion and hover from the site's script reference (host functions with their arity, summary and
 * permission; filters; tests; keywords; slots) plus the names the buffer declares: `let` variables, parameters,
 * top-level functions and the extension's own object kinds for `objects.<kind>.…`.
 */

export interface ReferenceFunction {
	name: string;
	summary: string;
	arguments: [ number, number | null ];
	needs: string | null;
}

function arity( fn: ReferenceFunction ): string {
	const [ least, most ] = fn.arguments;
	if ( most === null ) {
		return `${ least }+ args`;
	}
	return least === most
		? `${ least } arg${ least === 1 ? '' : 's' }`
		: `${ least }–${ most } args`;
}

/** The names the buffer itself declares, by a scan good enough for completion. */
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

/** The functions of the reference with `*` replaced by each object kind the buffer declares. */
function expand(
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

export function ocsCompletion( reference: Reference ) {
	return ( context: CompletionContext ): CompletionResult | null => {
		const line = context.state.doc.lineAt( context.pos );
		const before = line.text.slice( 0, context.pos - line.from );
		const text = context.state.doc.toString();
		const locals = localNames( text );
		const functions = expand( reference.functions, locals.objects );

		const filter = /\|\s*([A-Za-z_][A-Za-z0-9_]*)?$/.exec( before );
		if ( filter ) {
			return {
				from: context.pos - ( filter[ 1 ]?.length ?? 0 ),
				options: reference.filters.map( ( name ) => ( {
					label: name,
					type: 'function',
					detail: 'filter',
				} ) ),
				validFor: /^[A-Za-z_][A-Za-z0-9_]*$/,
			};
		}
		const test = /\bis\s+(?:not\s+)?([A-Za-z_][A-Za-z0-9_]*)?$/.exec(
			before
		);
		if ( test ) {
			return {
				from: context.pos - ( test[ 1 ]?.length ?? 0 ),
				options: reference.tests.map( ( name ) => ( {
					label: name,
					type: 'constant',
					detail: 'test',
				} ) ),
				validFor: /^[A-Za-z_][A-Za-z0-9_]*$/,
			};
		}
		const word = /([A-Za-z_][A-Za-z0-9_.]*)$/.exec( before );
		const typed = word?.[ 1 ] ?? '';
		if ( typed === '' && ! context.explicit ) {
			return null;
		}
		const from = context.pos - typed.length;

		if ( typed.includes( '.' ) ) {
			const prefix = typed.slice( 0, typed.lastIndexOf( '.' ) + 1 );
			const options = new Map< string, Completion >();
			for ( const fn of functions ) {
				if ( ! fn.name.startsWith( prefix ) ) {
					continue;
				}
				const rest = fn.name.slice( prefix.length );
				const segment = rest.split( '.' )[ 0 ] ?? rest;
				if ( rest.includes( '.' ) ) {
					options.set( segment, {
						label: prefix + segment,
						type: 'namespace',
						detail: 'namespace',
					} );
				} else {
					options.set( segment, {
						label: fn.name,
						type: 'function',
						detail: arity( fn ),
						info:
							fn.summary +
							( fn.needs ? `  [needs ${ fn.needs }]` : '' ),
						apply: fn.name + '(',
					} );
				}
			}
			// A further dot asks again, so `shop.` → `shop.orders.` lists the next segment.
			return {
				from,
				options: [ ...options.values() ],
				validFor: /^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/,
			};
		}

		const options: Completion[] = [];
		const atLineStart = before.trim() === typed;
		if ( atLineStart ) {
			for ( const kind of DECLARATIONS ) {
				options.push( {
					label: kind,
					type: 'class',
					detail: 'declaration',
				} );
			}
		}
		for ( const keyword of reference.keywords ?? KEYWORDS ) {
			options.push( { label: keyword, type: 'keyword' } );
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
				options.push( {
					label: fn.name,
					type: 'function',
					detail: arity( fn ),
					info: fn.summary,
					apply: fn.name + '(',
				} );
			}
		}
		for ( const ns of namespaces ) {
			options.push( {
				label: ns,
				type: 'namespace',
				detail: 'namespace',
			} );
		}
		for ( const name of locals.variables ) {
			options.push( { label: name, type: 'variable' } );
		}
		for ( const name of locals.functions ) {
			options.push( {
				label: name,
				type: 'function',
				detail: 'this extension',
				apply: name + '(',
			} );
		}
		return {
			from,
			options,
			validFor: /^[A-Za-z_][A-Za-z0-9_]*$/,
		};
	};
}

/** The summary, arity and permission of the host function under the pointer. */
export function ocsHover( reference: Reference ) {
	return hoverTooltip( ( view: EditorView, pos: number ) => {
		const line = view.state.doc.lineAt( pos );
		const offset = pos - line.from;
		const text = line.text;
		let start = offset;
		let end = offset;
		while ( start > 0 && /[A-Za-z0-9_.]/.test( text[ start - 1 ] ?? '' ) ) {
			start--;
		}
		while (
			end < text.length &&
			/[A-Za-z0-9_.]/.test( text[ end ] ?? '' )
		) {
			end++;
		}
		const name = text.slice( start, end );
		if ( name === '' ) {
			return null;
		}
		const objects = localNames( view.state.doc.toString() ).objects;
		const fn = expand( reference.functions, objects ).find(
			( item ) => item.name === name
		);
		if ( ! fn ) {
			return null;
		}
		return {
			pos: line.from + start,
			end: line.from + end,
			above: true,
			create: () => {
				const dom = document.createElement( 'div' );
				dom.className = 'oc-editor-hover';
				const head = document.createElement( 'strong' );
				head.textContent = `${ fn.name } · ${ arity( fn ) }${
					fn.needs ? ` · needs ${ fn.needs }` : ''
				}`;
				const body = document.createElement( 'div' );
				body.textContent = fn.summary;
				dom.append( head, body );
				return { dom };
			},
		};
	} );
}
