import {
	defaultHighlightStyle,
	StreamLanguage,
	syntaxHighlighting,
} from '@codemirror/language';
import { jinja2 } from '@codemirror/legacy-modes/mode/jinja2';
import {
	EditorState,
	RangeSet,
	RangeSetBuilder,
	StateEffect,
	StateField,
	type Extension,
} from '@codemirror/state';
import {
	Decoration,
	type DecorationSet,
	EditorView,
	gutter,
	GutterMarker,
	lineNumbers,
} from '@codemirror/view';
import { useEffect, useRef } from '@wordpress/element';

import { ocs } from './ocs';

/** The marks on a source: the line the debugger is at and the lines with a breakpoint. */
interface Marks {
	line: number | null;
	points: number[];
}

const setMarks = StateEffect.define< Marks >();

class Dot extends GutterMarker {
	override toDOM() {
		const dot = document.createElement( 'span' );
		dot.className = 'oc-debugger__dot';
		dot.textContent = '●';
		return dot;
	}
}

const DOT = new Dot();
const CURRENT = Decoration.line( { class: 'oc-debugger__current' } );

const marked = StateField.define< {
	marks: Marks;
	dots: RangeSet< GutterMarker >;
	lines: DecorationSet;
} >( {
	create: () => ( {
		marks: { line: null, points: [] },
		dots: RangeSet.empty,
		lines: Decoration.none,
	} ),
	update( value, transaction ) {
		for ( const effect of transaction.effects ) {
			if ( effect.is( setMarks ) ) {
				const doc = transaction.state.doc;
				const dots = new RangeSetBuilder< GutterMarker >();
				for ( const point of effect.value.points ) {
					if ( point >= 1 && point <= doc.lines ) {
						const from = doc.line( point ).from;
						dots.add( from, from, DOT );
					}
				}
				const line = effect.value.line;
				return {
					marks: effect.value,
					dots: dots.finish(),
					lines:
						line !== null && line >= 1 && line <= doc.lines
							? Decoration.set( [
									CURRENT.range( doc.line( line ).from ),
								] )
							: Decoration.none,
				};
			}
		}
		return value;
	},
	provide: ( field ) =>
		EditorView.decorations.from( field, ( value ) => value.lines ),
} );

/**
 * A file as a recorded run ran it, read-only: the line the debugger is at highlighted and scrolled to, and a
 * breakpoint toggled by clicking the gutter or the line's number.
 *
 * @param props
 * @param props.value       The file's content.
 * @param props.path        Its path: its extension picks the highlighting.
 * @param props.label       The accessible name of the view.
 * @param props.line        The line to highlight; null for none.
 * @param props.breakpoints The lines with a breakpoint.
 * @param props.onToggle    Called with a line whose gutter was clicked.
 */
export function SourceView( {
	value,
	path,
	label,
	line,
	breakpoints,
	onToggle,
}: {
	value: string;
	path: string;
	label: string;
	line: number | null;
	breakpoints: number[];
	onToggle: ( line: number ) => void;
} ) {
	const host = useRef< HTMLDivElement >( null );
	const view = useRef< EditorView | null >( null );
	const toggle = useRef( onToggle );
	toggle.current = onToggle;

	useEffect( () => {
		if ( ! host.current ) {
			return undefined;
		}
		const click = {
			mousedown: ( editor: EditorView, block: { from: number } ) => {
				toggle.current( editor.state.doc.lineAt( block.from ).number );
				return true;
			},
		};
		const editor = new EditorView( {
			parent: host.current,
			state: EditorState.create( {
				doc: value,
				extensions: [
					marked,
					gutter( {
						class: 'oc-debugger__breakpoints',
						markers: ( v ) => v.state.field( marked ).dots,
						initialSpacer: () => DOT,
						domEventHandlers: click,
					} ),
					lineNumbers( { domEventHandlers: click } ),
					EditorState.readOnly.of( true ),
					EditorView.editable.of( false ),
					syntaxHighlighting( defaultHighlightStyle, {
						fallback: true,
					} ),
					languageFor( path ),
					EditorView.contentAttributes.of( {
						'aria-label': label,
						'aria-readonly': 'true',
						tabindex: '0',
					} ),
					EditorView.theme( {
						'&': {
							maxHeight: '28rem',
							fontSize: '13px',
							border: '1px solid #949494',
							borderRadius: '2px',
							backgroundColor: '#fff',
						},
						'.cm-scroller': {
							fontFamily:
								'Menlo, Consolas, Monaco, "Liberation Mono", monospace',
							overflow: 'auto',
						},
						'.cm-gutterElement': { cursor: 'pointer' },
						'&.cm-focused': { outline: '2px solid #3858e9' },
					} ),
				],
			} ),
		} );
		view.current = editor;
		return () => {
			editor.destroy();
			view.current = null;
		};
		// A file is a view of its own: the parent keys it by path, and the marks change through the effect below.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ value, path ] );

	useEffect( () => {
		const editor = view.current;
		if ( ! editor ) {
			return;
		}
		editor.dispatch( {
			effects: setMarks.of( { line, points: breakpoints } ),
		} );
	}, [ line, breakpoints, value, path ] );

	// Scrolled to the line when the debugger moves, not when a breakpoint changes: the manager may have scrolled.
	useEffect( () => {
		const editor = view.current;
		const doc = editor?.state.doc;
		if (
			! editor ||
			! doc ||
			line === null ||
			line < 1 ||
			line > doc.lines
		) {
			return;
		}
		editor.dispatch( {
			effects: EditorView.scrollIntoView( doc.line( line ).from, {
				y: 'center',
			} ),
		} );
	}, [ line, value, path ] );

	useEffect( () => {
		view.current?.contentDOM.setAttribute( 'aria-label', label );
	}, [ label ] );

	return <div className="oc-editor oc-debugger__source" ref={ host } />;
}

function languageFor( path: string ): Extension {
	if ( path.endsWith( '.ocs' ) ) {
		return ocs;
	}
	if ( path.endsWith( '.twig' ) ) {
		return StreamLanguage.define( jinja2 );
	}
	return [];
}
