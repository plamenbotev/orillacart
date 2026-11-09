import {
	autocompletion,
	closeBrackets,
	closeBracketsKeymap,
	completionKeymap,
} from '@codemirror/autocomplete';
import {
	defaultKeymap,
	history,
	historyKeymap,
	indentWithTab,
} from '@codemirror/commands';
import { css } from '@codemirror/lang-css';
import { html } from '@codemirror/lang-html';
import { javascript } from '@codemirror/lang-javascript';
import {
	bracketMatching,
	defaultHighlightStyle,
	indentOnInput,
	StreamLanguage,
	syntaxHighlighting,
} from '@codemirror/language';
import { jinja2 } from '@codemirror/legacy-modes/mode/jinja2';
import { lintGutter, lintKeymap, setDiagnostics } from '@codemirror/lint';
import { Compartment, EditorState, type Extension } from '@codemirror/state';
import {
	drawSelection,
	EditorView,
	highlightActiveLine,
	highlightActiveLineGutter,
	keymap,
	lineNumbers,
} from '@codemirror/view';
import { useEffect, useRef } from '@wordpress/element';

import type { ExtensionRoute, Finding, Reference } from '../api';
import { browserCompletion } from './browser';
import { ocsCompletion, ocsHover } from './completion';
import { toDiagnostics } from './diagnostics';
import { ocs } from './ocs';

/**
 * The code editor of the Extensions screen: CodeMirror with the script language's highlighting, completion and
 * hover from the site's reference, and the static check's findings underlined where they are. Templates get
 * Twig-style or HTML highlighting by their extension. The value is controlled: a change outside (opening another
 * file, a save's answer) replaces the document, a keystroke calls onChange.
 *
 * @param props
 * @param props.value     The document.
 * @param props.onChange  Called with the document after a keystroke.
 * @param props.path      The file's path: its extension picks the language.
 * @param props.label     The accessible name of the editor.
 * @param props.findings  The check's findings, underlined where they are.
 * @param props.reference The site's reference, for completion and hover; null before it is loaded.
 * @param props.routes    The extension's routes, for a script's completion of their paths.
 * @param props.disabled  Read-only while a request is in flight.
 */
export function CodeEditor( {
	value,
	onChange,
	path,
	label,
	findings,
	reference,
	routes = null,
	disabled,
}: {
	value: string;
	onChange: ( value: string ) => void;
	path: string;
	label: string;
	findings: Finding[];
	reference: Reference | null;
	routes?: ExtensionRoute[] | null;
	disabled?: boolean;
} ) {
	const host = useRef< HTMLDivElement >( null );
	const view = useRef< EditorView | null >( null );
	const language = useRef( new Compartment() );
	const editable = useRef( new Compartment() );
	const assist = useRef( new Compartment() );
	const latest = useRef( onChange );
	latest.current = onChange;

	useEffect( () => {
		if ( ! host.current ) {
			return undefined;
		}
		const state = EditorState.create( {
			doc: value,
			extensions: [
				lineNumbers(),
				highlightActiveLineGutter(),
				highlightActiveLine(),
				history(),
				drawSelection(),
				indentOnInput(),
				bracketMatching(),
				closeBrackets(),
				lintGutter(),
				syntaxHighlighting( defaultHighlightStyle, { fallback: true } ),
				keymap.of( [
					...closeBracketsKeymap,
					...defaultKeymap,
					...historyKeymap,
					...completionKeymap,
					...lintKeymap,
					indentWithTab,
				] ),
				language.current.of( languageFor( path ) ),
				editable.current.of( EditorView.editable.of( ! disabled ) ),
				assist.current.of( [] ),
				EditorView.updateListener.of( ( update ) => {
					if ( update.docChanged ) {
						latest.current( update.state.doc.toString() );
					}
				} ),
				EditorView.contentAttributes.of( {
					'aria-label': label,
					role: 'textbox',
					'aria-multiline': 'true',
				} ),
				EditorView.theme( {
					'&': {
						minHeight: '24rem',
						maxHeight: '70vh',
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
					'&.cm-focused': { outline: '2px solid #3858e9' },
				} ),
			],
		} );
		view.current = new EditorView( { state, parent: host.current } );
		return () => {
			view.current?.destroy();
			view.current = null;
		};
		// The editor is made once; everything that changes goes through the effects below.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	useEffect( () => {
		const editor = view.current;
		if ( editor && editor.state.doc.toString() !== value ) {
			editor.dispatch( {
				changes: {
					from: 0,
					to: editor.state.doc.length,
					insert: value,
				},
			} );
		}
	}, [ value ] );

	useEffect( () => {
		view.current?.dispatch( {
			effects: language.current.reconfigure( languageFor( path ) ),
		} );
	}, [ path ] );

	useEffect( () => {
		view.current?.dispatch( {
			effects: editable.current.reconfigure(
				EditorView.editable.of( ! disabled )
			),
		} );
	}, [ disabled ] );

	useEffect( () => {
		const editor = view.current;
		if ( ! editor ) {
			return;
		}
		const extensions: Extension[] = [];
		if ( reference && path.endsWith( '.ocs' ) ) {
			extensions.push(
				autocompletion( {
					override: [ ocsCompletion( reference ) ],
					activateOnTyping: true,
				} ),
				ocsHover( reference )
			);
		} else if ( path.endsWith( '.js' ) ) {
			// The language's own completions (names in the buffer, snippets), and the shop's client for the
			// extension's routes (design 59).
			extensions.push(
				autocompletion( { activateOnTyping: true } ),
				javascript().language.data.of( {
					autocomplete: browserCompletion( routes ?? [] ),
				} )
			);
		} else if ( path.endsWith( '.css' ) || path.endsWith( '.twig' ) ) {
			extensions.push( autocompletion( { activateOnTyping: true } ) );
		}
		editor.dispatch( {
			effects: assist.current.reconfigure( extensions ),
		} );
	}, [ reference, path, routes ] );

	useEffect( () => {
		const editor = view.current;
		if ( ! editor ) {
			return;
		}
		editor.dispatch(
			setDiagnostics(
				editor.state,
				toDiagnostics( editor.state, findings )
			)
		);
	}, [ findings, value ] );

	useEffect( () => {
		if ( view.current ) {
			view.current.contentDOM.setAttribute( 'aria-label', label );
		}
	}, [ label ] );

	return <div className="oc-editor" ref={ host } />;
}

function languageFor( path: string ): Extension {
	if ( path.endsWith( '.ocs' ) ) {
		return ocs;
	}
	if ( path.endsWith( '.twig' ) ) {
		return StreamLanguage.define( jinja2 );
	}
	if ( path.endsWith( '.html' ) || path.endsWith( '.htm' ) ) {
		return html();
	}
	// The files for the browser (design 59): assets/*.js, *.css, *.svg.
	if ( path.endsWith( '.js' ) ) {
		return javascript();
	}
	if ( path.endsWith( '.css' ) ) {
		return css();
	}
	if ( path.endsWith( '.svg' ) ) {
		return html();
	}
	return [];
}
