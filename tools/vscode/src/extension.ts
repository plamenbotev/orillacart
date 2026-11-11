import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';

import * as vscode from 'vscode';
import {
	LanguageClient,
	TransportKind,
	type LanguageClientOptions,
	type ServerOptions,
} from 'vscode-languageclient/node';

import { registerDebugger } from './debugging';
import { extensionFiles, extensionFolder, extensionId } from './folder';
import { Site, SiteError } from './site';

/**
 * The client: starts the language server for `.ocs` files and adds three commands. Pull writes an extension's
 * files from the site into a folder named after it; push sends a folder's files back, installing the extension
 * when the site has none of that id; check asks the server to check the open scripts now; run tests sends the
 * folder's files and runs the extension's own tests on the site, the outcomes in an output channel. The debugger
 * (debugging.ts) steps through recordings of runs on the site.
 */

let client: LanguageClient | undefined;

export function activate( context: vscode.ExtensionContext ): void {
	const server = context.asAbsolutePath( join( 'dist', 'server.js' ) );
	const serverOptions: ServerOptions = {
		run: { module: server, transport: TransportKind.ipc },
		debug: {
			module: server,
			transport: TransportKind.ipc,
			options: { execArgv: [ '--nolazy', '--inspect=6009' ] },
		},
	};
	const clientOptions: LanguageClientOptions = {
		documentSelector: [ { scheme: 'file', language: 'ocs' } ],
		synchronize: { configurationSection: 'orillacart' },
	};
	client = new LanguageClient(
		'orillacart',
		'OrillaCart scripts',
		serverOptions,
		clientOptions
	);
	void client.start();

	context.subscriptions.push(
		vscode.commands.registerCommand( 'orillacart.checkNow', () =>
			client?.sendRequest( 'orillacart/checkNow' )
		),
		vscode.commands.registerCommand( 'orillacart.pullExtension', pull ),
		vscode.commands.registerCommand( 'orillacart.pushExtension', push ),
		vscode.commands.registerCommand( 'orillacart.runTests', runTests )
	);
	registerDebugger( context, site, failure );
}

export function deactivate(): Thenable< void > | undefined {
	return client?.stop();
}

function site(): Site | null {
	const settings = vscode.workspace.getConfiguration( 'orillacart' );
	const instance = new Site(
		settings.get< string >( 'siteUrl', '' ),
		settings.get< string >( 'apiKey', '' )
	);
	if ( ! instance.configured ) {
		void vscode.window.showErrorMessage(
			'Set orillacart.siteUrl and orillacart.apiKey in the settings first.'
		);
		return null;
	}
	return instance;
}

async function pull(): Promise< void > {
	const shop = site();
	if ( ! shop ) {
		return;
	}
	const id = await vscode.window.showInputBox( {
		prompt: "The extension's id on the site",
		validateInput: ( v ) =>
			/^[a-z][a-z0-9_]*$/.test( v )
				? null
				: 'lower-case letters, digits and underscores',
	} );
	if ( ! id ) {
		return;
	}
	const root = vscode.workspace.workspaceFolders?.[ 0 ]?.uri.fsPath;
	if ( ! root ) {
		void vscode.window.showErrorMessage( 'Open a folder to pull into.' );
		return;
	}
	try {
		const extension = await shop.extension( id );
		const folder = join( root, id );
		for ( const file of extension.files ?? [] ) {
			const target = join( folder, file.path );
			mkdirSync( dirname( target ), { recursive: true } );
			writeFileSync( target, file.content ?? '' );
		}
		void vscode.window.showInformationMessage(
			`Pulled ${ extension.files?.length ?? 0 } files of "${ extension.name }" into ${ id }/.`
		);
		const main = extension.files?.find( ( f ) =>
			f.path.endsWith( '.ocs' )
		);
		if ( main ) {
			await vscode.window.showTextDocument(
				vscode.Uri.file( join( folder, main.path ) )
			);
		}
	} catch ( e ) {
		void vscode.window.showErrorMessage( failure( e ) );
	}
}

/** The open script's folder as an extension: its id from the folder's name and its files; null with a message when there is none. */
function current(
	purpose: string
): { id: string; folder: string; files: Record< string, string > } | null {
	const active = vscode.window.activeTextEditor?.document.uri.fsPath;
	const folder = active
		? extensionFolder(
				active,
				vscode.workspace.getWorkspaceFolder( vscode.Uri.file( active ) )
					?.uri.fsPath
			)
		: vscode.workspace.workspaceFolders?.[ 0 ]?.uri.fsPath;
	if ( ! folder ) {
		void vscode.window.showErrorMessage(
			`Open a script of the extension to ${ purpose }.`
		);
		return null;
	}
	const id = extensionId( folder );
	if ( id === null ) {
		void vscode.window.showErrorMessage(
			"The folder's name is the extension's id: lower-case letters, digits and underscores."
		);
		return null;
	}
	const files = extensionFiles( folder );
	if ( Object.keys( files ).length === 0 ) {
		void vscode.window.showErrorMessage(
			'No .ocs or template files here.'
		);
		return null;
	}
	return { id, folder, files };
}

let tests: vscode.OutputChannel | undefined;

async function runTests(): Promise< void > {
	const shop = site();
	const here = shop ? current( 'test' ) : null;
	if ( ! shop || ! here ) {
		return;
	}
	await vscode.workspace.saveAll();
	tests ??= vscode.window.createOutputChannel( 'OrillaCart tests' );
	try {
		const answer = await shop.test( here.id, here.files );
		tests.clear();
		for ( const t of answer.tests ) {
			const where =
				t.line === null ? '' : ` (${ t.file ?? '' } line ${ t.line })`;
			tests.appendLine(
				`${ t.ok ? '  ok  ' : ' FAIL ' } ${ t.name }${ t.message ? ': ' + t.message : '' }${ where }`
			);
			for ( const h of t.http ) {
				tests.appendLine(
					`        ${ h.method } ${ h.url } → ${ h.status }`
				);
			}
		}
		const failed = answer.tests.filter( ( t ) => ! t.ok ).length;
		tests.show( true );
		if ( answer.tests.length === 0 ) {
			void vscode.window.showInformationMessage(
				`"${ here.id }" declares no tests: add test "name" { } fn() { assert … }.`
			);
		} else if ( failed === 0 ) {
			void vscode.window.showInformationMessage(
				`${ answer.tests.length } test${ answer.tests.length === 1 ? '' : 's' } passed.`
			);
		} else {
			void vscode.window.showWarningMessage(
				`${ failed } of ${ answer.tests.length } tests failed; see the OrillaCart tests output.`
			);
		}
	} catch ( e ) {
		void vscode.window.showErrorMessage( failure( e ) );
	}
}

async function push(): Promise< void > {
	const shop = site();
	const here = shop ? current( 'push' ) : null;
	if ( ! shop || ! here ) {
		return;
	}
	const { id, files } = here;
	await vscode.workspace.saveAll();
	try {
		let extension;
		try {
			extension = await shop.extension( id );
		} catch ( e ) {
			if ( ! ( e instanceof SiteError && e.status === 404 ) ) {
				throw e;
			}
			extension = await shop.install( id, id, files );
			void vscode.window.showInformationMessage(
				`Installed "${ id }" on the site as a draft with ${ Object.keys( files ).length } files.`
			);
			return;
		}
		let last = extension;
		for ( const [ path, content ] of Object.entries( files ) ) {
			if (
				extension.files?.find( ( f ) => f.path === path )?.content !==
				content
			) {
				last = await shop.writeFile( id, path, content );
			}
		}
		const findings = last.findings ?? [];
		void vscode.window.showInformationMessage(
			findings.length === 0
				? `Pushed "${ id }": the scripts compile.`
				: `Pushed "${ id }" with ${ findings.length } finding${ findings.length === 1 ? '' : 's' }: ${ findings[ 0 ] }`
		);
	} catch ( e ) {
		void vscode.window.showErrorMessage( failure( e ) );
	}
}

function failure( e: unknown ): string {
	return e instanceof SiteError
		? `The site answered ${ e.status }: ${ e.message }`
		: `The site could not be reached: ${ ( e as Error ).message }`;
}
