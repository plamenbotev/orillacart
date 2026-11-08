import type { Diagnostic } from '@codemirror/lint';
import type { EditorState } from '@codemirror/state';

import type { Finding } from '../api';

/** The findings as CodeMirror diagnostics, each on its line from the column to the end of the word there. */
export function toDiagnostics(
	state: EditorState,
	findings: Finding[]
): Diagnostic[] {
	const out: Diagnostic[] = [];
	for ( const finding of findings ) {
		if ( finding.line < 1 || finding.line > state.doc.lines ) {
			continue;
		}
		const line = state.doc.line( finding.line );
		const column = Math.max(
			0,
			Math.min( finding.column - 1, line.length )
		);
		const rest = line.text.slice( column );
		const word = /^[A-Za-z_][A-Za-z0-9_.]*/.exec( rest );
		const length = word ? word[ 0 ].length : Math.min( 1, rest.length );
		out.push( {
			from: line.from + column,
			to: line.from + column + length,
			severity: 'error',
			message: finding.message,
		} );
	}
	return out;
}
