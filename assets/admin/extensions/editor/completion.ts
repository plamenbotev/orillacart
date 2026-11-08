import type {
	Completion,
	CompletionContext,
	CompletionResult,
} from '@codemirror/autocomplete';
import { hoverTooltip, type EditorView } from '@codemirror/view';

import type { Reference } from '../api';
import {
	arity,
	describe,
	nameAt,
	suggest,
	type SuggestionKind,
} from './suggest';

/** Completion and hover for CodeMirror, over the shared suggestions (suggest.ts). */

export { localNames } from './suggest';

const TYPES: Record< SuggestionKind, string > = {
	filter: 'function',
	test: 'constant',
	namespace: 'namespace',
	function: 'function',
	keyword: 'keyword',
	declaration: 'class',
	variable: 'variable',
	'own-function': 'function',
};

export function ocsCompletion( reference: Reference ) {
	return ( context: CompletionContext ): CompletionResult | null => {
		const line = context.state.doc.lineAt( context.pos );
		const before = line.text.slice( 0, context.pos - line.from );
		const found = suggest(
			reference,
			before,
			context.state.doc.toString(),
			context.explicit
		);
		if ( ! found ) {
			return null;
		}
		const options: Completion[] = found.items.map( ( item ) => {
			const option: Completion = {
				label: item.label,
				type: TYPES[ item.kind ],
			};
			if ( item.detail ) {
				option.detail = item.detail;
			}
			if ( item.info ) {
				option.info = item.info;
			}
			if ( item.insert ) {
				option.apply = item.insert;
			}
			return option;
		} );
		return {
			from: context.pos - found.replace,
			options,
			// A further dot asks again, so `shop.` → `shop.orders.` lists the next segment.
			validFor: /^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/,
		};
	};
}

/**
 * The summary, arity and permission of the host function under the pointer.
 *
 * @param reference The site's reference.
 */
export function ocsHover( reference: Reference ) {
	return hoverTooltip( ( view: EditorView, pos: number ) => {
		const line = view.state.doc.lineAt( pos );
		const at = nameAt( line.text, pos - line.from );
		if ( ! at ) {
			return null;
		}
		const fn = describe( reference, view.state.doc.toString(), at.name );
		if ( ! fn ) {
			return null;
		}
		return {
			pos: line.from + at.start,
			end: line.from + at.end,
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
