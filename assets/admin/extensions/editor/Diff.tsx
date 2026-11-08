import { __ } from '@wordpress/i18n';

/** A line of a diff: kept, taken out of the original, or added by the override. */
export interface DiffLine {
	kind: 'same' | 'removed' | 'added';
	text: string;
}

/**
 * The line diff of two texts by the longest common subsequence: small enough for templates, which are a few
 * hundred lines at most.
 *
 * @param from The original.
 * @param to   The override.
 */
export function diffLines( from: string, to: string ): DiffLine[] {
	const a = from.split( '\n' );
	const b = to.split( '\n' );
	const n = a.length;
	const m = b.length;
	const table: number[][] = Array.from( { length: n + 1 }, () =>
		new Array< number >( m + 1 ).fill( 0 )
	);
	for ( let i = n - 1; i >= 0; i-- ) {
		for ( let j = m - 1; j >= 0; j-- ) {
			table[ i ]![ j ] =
				a[ i ] === b[ j ]
					? table[ i + 1 ]![ j + 1 ]! + 1
					: Math.max( table[ i + 1 ]![ j ]!, table[ i ]![ j + 1 ]! );
		}
	}
	const lines: DiffLine[] = [];
	let i = 0;
	let j = 0;
	while ( i < n && j < m ) {
		if ( a[ i ] === b[ j ] ) {
			lines.push( { kind: 'same', text: a[ i ] ?? '' } );
			i++;
			j++;
		} else if ( table[ i + 1 ]![ j ]! >= table[ i ]![ j + 1 ]! ) {
			lines.push( { kind: 'removed', text: a[ i ] ?? '' } );
			i++;
		} else {
			lines.push( { kind: 'added', text: b[ j ] ?? '' } );
			j++;
		}
	}
	for ( ; i < n; i++ ) {
		lines.push( { kind: 'removed', text: a[ i ] ?? '' } );
	}
	for ( ; j < m; j++ ) {
		lines.push( { kind: 'added', text: b[ j ] ?? '' } );
	}
	return lines;
}

/**
 * The override against the shop's template, line by line.
 *
 * @param props
 * @param props.original The shop's template.
 * @param props.edited   The extension's override.
 */
const MARKS: Record< DiffLine[ 'kind' ], string > = {
	same: ' ',
	removed: '−',
	added: '+',
};

export function Diff( {
	original,
	edited,
}: {
	original: string;
	edited: string;
} ) {
	const lines = diffLines( original, edited );
	const changed = lines.filter( ( l ) => l.kind !== 'same' ).length;
	return (
		<div className="oc-diff">
			<p className="oc-muted">
				{ changed === 0
					? __(
							"The override is the same as the shop's template.",
							'orillacart'
						)
					: __(
							"Lines taken out of the shop's template are marked −, lines the override adds +.",
							'orillacart'
						) }
			</p>
			<pre className="oc-code oc-diff__lines">
				{ lines.map( ( line, index ) => (
					<span
						key={ index }
						className={ `oc-diff__line oc-diff__line--${ line.kind }` }
					>
						{ MARKS[ line.kind ] } { line.text }
						{ '\n' }
					</span>
				) ) }
			</pre>
		</div>
	);
}
