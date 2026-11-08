import { readdirSync, readFileSync, statSync } from 'node:fs';
import { basename, dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

import {
	CompletionItemKind,
	createConnection,
	DiagnosticSeverity,
	type Diagnostic,
	type InitializeResult,
	MarkupKind,
	ProposedFeatures,
	TextDocuments,
	TextDocumentSyncKind,
} from 'vscode-languageserver/node';
import { TextDocument } from 'vscode-languageserver-textdocument';

import {
	arity,
	describe,
	nameAt,
	suggest,
	type SuggestionKind,
} from '../../../assets/admin/extensions/editor/suggest';
import { type Finding, type Reference, Site, SiteError } from './site';

/**
 * The language server: a proxy to the shop. Diagnostics come from the site's static check over the open
 * script and the other `.ocs` files of its folder (a package compiles together); completion and hover from the
 * site's reference, through the same suggestion code the admin's editor uses. Nothing of the language is
 * implemented here.
 */

interface Settings {
	siteUrl: string;
	apiKey: string;
}

const connection = createConnection( ProposedFeatures.all );
const documents = new TextDocuments( TextDocument );
let site = new Site( '', '' );
let reference: Reference | null = null;
let referenceFor = '';
const timers = new Map< string, ReturnType< typeof setTimeout > >();

const KINDS: Record< SuggestionKind, CompletionItemKind > = {
	filter: CompletionItemKind.Function,
	test: CompletionItemKind.Constant,
	namespace: CompletionItemKind.Module,
	function: CompletionItemKind.Function,
	keyword: CompletionItemKind.Keyword,
	declaration: CompletionItemKind.Class,
	variable: CompletionItemKind.Variable,
	'own-function': CompletionItemKind.Method,
};

connection.onInitialize( (): InitializeResult => ( {
	capabilities: {
		textDocumentSync: TextDocumentSyncKind.Incremental,
		completionProvider: { triggerCharacters: [ '.', '|' ] },
		hoverProvider: true,
	},
} ) );

connection.onInitialized( () => {
	void readSettings();
} );

connection.onDidChangeConfiguration( () => {
	void readSettings().then( () => {
		for ( const document of documents.all() ) {
			scheduleCheck( document, 0 );
		}
	} );
} );

async function readSettings(): Promise< void > {
	const settings = ( await connection.workspace.getConfiguration(
		'orillacart'
	) ) as Partial< Settings > | null;
	site = new Site( settings?.siteUrl ?? '', settings?.apiKey ?? '' );
	if ( ! site.configured ) {
		connection.console.warn(
			'orillacart.siteUrl and orillacart.apiKey are not set: scripts are not checked.'
		);
	}
}

async function loadReference(): Promise< Reference | null > {
	if ( ! site.configured ) {
		return null;
	}
	const key = JSON.stringify( site );
	if ( reference && referenceFor === key ) {
		return reference;
	}
	try {
		reference = await site.reference();
		referenceFor = key;
	} catch ( e ) {
		connection.console.error(
			`The site's reference could not be read: ${ ( e as Error ).message }`
		);
		reference = null;
	}
	return reference;
}

/** The `.ocs` files beside the document, the document's own text in place of its file. */
function packageFiles( document: TextDocument ): {
	files: Record< string, string >;
	own: string;
	id: string;
} {
	const path = fileURLToPath( document.uri );
	const folder = dirname( path );
	const own = basename( path );
	const files: Record< string, string > = {};
	try {
		for ( const name of readdirSync( folder ).sort() ) {
			if (
				! name.endsWith( '.ocs' ) ||
				! statSync( join( folder, name ) ).isFile()
			) {
				continue;
			}
			const open = documents.get( `file://${ join( folder, name ) }` );
			files[ name ] =
				name === own
					? document.getText()
					: ( open?.getText() ??
						readFileSync( join( folder, name ), 'utf8' ) );
		}
	} catch {
		files[ own ] = document.getText();
	}
	const id =
		basename( folder )
			.toLowerCase()
			.replace( /[^a-z0-9_]+/g, '_' )
			.replace( /^[^a-z]+/, '' ) || 'draft';
	return { files, own, id };
}

function scheduleCheck( document: TextDocument, delay = 400 ): void {
	const pending = timers.get( document.uri );
	if ( pending ) {
		clearTimeout( pending );
	}
	timers.set(
		document.uri,
		setTimeout( () => {
			timers.delete( document.uri );
			void check( document );
		}, delay )
	);
}

async function check( document: TextDocument ): Promise< void > {
	if ( ! site.configured || ! document.uri.endsWith( '.ocs' ) ) {
		return;
	}
	const { files, own, id } = packageFiles( document );
	let findings: Finding[];
	try {
		findings = ( await site.check( files, id ) ).findings;
	} catch ( e ) {
		const message =
			e instanceof SiteError
				? `The site refused the check (${ e.status }): ${ e.message }`
				: `The site could not be reached: ${ ( e as Error ).message }`;
		connection.console.error( message );
		void connection.sendDiagnostics( {
			uri: document.uri,
			diagnostics: [
				{
					range: {
						start: { line: 0, character: 0 },
						end: { line: 0, character: 0 },
					},
					severity: DiagnosticSeverity.Information,
					source: 'orillacart',
					message,
				},
			],
		} );
		return;
	}
	const diagnostics: Diagnostic[] = [];
	for ( const finding of findings ) {
		if ( finding.file !== own && finding.file !== '' ) {
			continue;
		}
		const line = Math.max( 0, finding.line - 1 );
		const text = document
			.getText( {
				start: { line, character: 0 },
				end: { line: line + 1, character: 0 },
			} )
			.replace( /\n$/, '' );
		const column = Math.max(
			0,
			Math.min( finding.column - 1, text.length )
		);
		const word = /^[A-Za-z_][A-Za-z0-9_.]*/.exec( text.slice( column ) );
		const length = word
			? word[ 0 ].length
			: Math.min( 1, text.length - column );
		diagnostics.push( {
			range: {
				start: { line, character: column },
				end: { line, character: column + length },
			},
			severity: DiagnosticSeverity.Error,
			source: 'orillacart',
			message: finding.message,
		} );
	}
	void connection.sendDiagnostics( { uri: document.uri, diagnostics } );
}

documents.onDidOpen( ( event ) => scheduleCheck( event.document, 0 ) );
documents.onDidChangeContent( ( event ) => scheduleCheck( event.document ) );
documents.onDidClose( ( event ) => {
	void connection.sendDiagnostics( {
		uri: event.document.uri,
		diagnostics: [],
	} );
} );

connection.onRequest( 'orillacart/checkNow', () => {
	for ( const document of documents.all() ) {
		scheduleCheck( document, 0 );
	}
} );

connection.onCompletion( async ( params ) => {
	const document = documents.get( params.textDocument.uri );
	const ref = await loadReference();
	if ( ! document || ! ref ) {
		return [];
	}
	const line = params.position.line;
	const before = document.getText( {
		start: { line, character: 0 },
		end: params.position,
	} );
	const found = suggest(
		ref,
		before,
		document.getText(),
		params.context?.triggerKind === 1
	);
	if ( ! found ) {
		return [];
	}
	const start = {
		line,
		character: params.position.character - found.replace,
	};
	return found.items.map( ( item ) => ( {
		label: item.label,
		kind: KINDS[ item.kind ],
		...( item.detail ? { detail: item.detail } : {} ),
		...( item.info ? { documentation: item.info } : {} ),
		textEdit: {
			range: { start, end: params.position },
			newText: item.insert ?? item.label,
		},
	} ) );
} );

connection.onHover( async ( params ) => {
	const document = documents.get( params.textDocument.uri );
	const ref = await loadReference();
	if ( ! document || ! ref ) {
		return null;
	}
	const line = params.position.line;
	const text = document
		.getText( {
			start: { line, character: 0 },
			end: { line: line + 1, character: 0 },
		} )
		.replace( /\n$/, '' );
	const at = nameAt( text, params.position.character );
	if ( ! at ) {
		return null;
	}
	const fn = describe( ref, document.getText(), at.name );
	if ( ! fn ) {
		return null;
	}
	return {
		contents: {
			kind: MarkupKind.Markdown,
			value: `**${ fn.name }** · ${ arity( fn ) }${ fn.needs ? ` · needs \`${ fn.needs }\`` : '' }\n\n${ fn.summary }`,
		},
		range: {
			start: { line, character: at.start },
			end: { line, character: at.end },
		},
	};
} );

documents.listen( connection );
connection.listen();
