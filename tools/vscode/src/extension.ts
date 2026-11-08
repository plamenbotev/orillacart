import {
	mkdirSync,
	readdirSync,
	readFileSync,
	statSync,
	writeFileSync,
} from 'node:fs';
import { basename, dirname, join, relative } from 'node:path';

import * as vscode from 'vscode';
import {
	LanguageClient,
	TransportKind,
	type LanguageClientOptions,
	type ServerOptions,
} from 'vscode-languageclient/node';

import { Site, SiteError } from './site';

/**
 * The client: starts the language server for `.ocs` files and adds three commands. Pull writes an extension's
 * files from the site into a folder named after it; push sends a folder's files back, installing the extension
 * when the site has none of that id; check asks the server to check the open scripts now.
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
		vscode.commands.registerCommand( 'orillacart.pushExtension', push )
	);
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
			writeFileSync( target, file.content );
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

async function push(): Promise< void > {
	const shop = site();
	if ( ! shop ) {
		return;
	}
	const active = vscode.window.activeTextEditor?.document.uri.fsPath;
	const folder = active
		? dirname( active )
		: vscode.workspace.workspaceFolders?.[ 0 ]?.uri.fsPath;
	if ( ! folder ) {
		void vscode.window.showErrorMessage(
			'Open a script of the extension to push.'
		);
		return;
	}
	const id = basename( folder )
		.toLowerCase()
		.replace( /[^a-z0-9_]+/g, '_' )
		.replace( /^[^a-z]+/, '' );
	if ( id === '' ) {
		void vscode.window.showErrorMessage(
			"The folder's name is the extension's id: lower-case letters, digits and underscores."
		);
		return;
	}
	const files: Record< string, string > = {};
	const walk = ( dir: string ) => {
		for ( const name of readdirSync( dir ) ) {
			const path = join( dir, name );
			if ( statSync( path ).isDirectory() ) {
				if ( name !== 'node_modules' && ! name.startsWith( '.' ) ) {
					walk( path );
				}
			} else if (
				name.endsWith( '.ocs' ) ||
				name.endsWith( '.twig' ) ||
				name.endsWith( '.html' )
			) {
				files[ relative( folder, path ).split( '\\' ).join( '/' ) ] =
					readFileSync( path, 'utf8' );
			}
		}
	};
	walk( folder );
	if ( Object.keys( files ).length === 0 ) {
		void vscode.window.showErrorMessage(
			'No .ocs or template files here.'
		);
		return;
	}
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
