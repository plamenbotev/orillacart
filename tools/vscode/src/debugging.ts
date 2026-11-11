import { readFileSync } from 'node:fs';
import { join } from 'node:path';

import * as vscode from 'vscode';

import {
	type LaunchArguments,
	type Loaded,
	RecordingDebugAdapter,
} from './adapter';
import {
	extensionFiles,
	extensionFolder,
	extensionId,
	functionNames,
	testNames,
} from './folder';
import { type Site, SiteError } from './site';
import type { Recording } from '../../../assets/admin/extensions/editor/replay';

/**
 * The debugger's place in VS Code (design 71): the `orillacart` debug type with its inline adapter, a
 * configuration provider that asks what to debug when launch.json says nothing, the "Debug a test…" and "Debug a
 * recording…" commands and a "Debug test" lens over each test declaration.
 */

const TYPE = 'orillacart';

interface Choice {
	name: string;
	test?: string;
	function?: string;
	arguments?: unknown[];
	recording?: number;
}

export function registerDebugger(
	context: vscode.ExtensionContext,
	site: () => Site | null,
	failure: ( e: unknown ) => string
): void {
	const factory: vscode.DebugAdapterDescriptorFactory = {
		createDebugAdapterDescriptor: () =>
			new vscode.DebugAdapterInlineImplementation(
				new RecordingDebugAdapter( {
					load: ( args ) => load( args, site, failure ),
					readFile: ( path ) => {
						try {
							return readFileSync( path, 'utf8' );
						} catch {
							return null;
						}
					},
				} )
			),
	};
	context.subscriptions.push(
		vscode.debug.registerDebugAdapterDescriptorFactory( TYPE, factory ),
		vscode.debug.registerDebugConfigurationProvider(
			TYPE,
			new Provider( site, failure )
		),
		vscode.commands.registerCommand(
			'orillacart.debugTest',
			async ( uri?: vscode.Uri, name?: string ) => {
				const folder = uri ? folderOf( uri.fsPath ) : activeFolder();
				if ( ! folder ) {
					void vscode.window.showErrorMessage(
						'Open a script of the extension to debug.'
					);
					return;
				}
				const test = name ?? ( await pickTest( folder ) );
				if ( test !== undefined ) {
					await start( folder, { name: `Test "${ test }"`, test } );
				}
			}
		),
		vscode.commands.registerCommand(
			'orillacart.debugRecording',
			async () => {
				const folder = activeFolder();
				if ( ! folder ) {
					void vscode.window.showErrorMessage(
						'Open a script of the extension to debug.'
					);
					return;
				}
				const choice = await pickRecording( folder, site, failure );
				if ( choice ) {
					await start( folder, choice );
				}
			}
		),
		vscode.languages.registerCodeLensProvider(
			{ language: 'ocs', scheme: 'file' },
			{ provideCodeLenses: lenses }
		)
	);
}

class Provider implements vscode.DebugConfigurationProvider {
	constructor(
		private readonly site: () => Site | null,
		private readonly failure: ( e: unknown ) => string
	) {}

	provideDebugConfigurations(): vscode.DebugConfiguration[] {
		return [ launch( 'OrillaCart: debug', {} ) ];
	}

	resolveDebugConfiguration(
		_folder: vscode.WorkspaceFolder | undefined,
		config: vscode.DebugConfiguration
	): vscode.DebugConfiguration {
		if ( ! config.type && ! config.request && ! config.name ) {
			return launch( 'OrillaCart', {} );
		}
		return config;
	}

	async resolveDebugConfigurationWithSubstitutedVariables(
		_folder: vscode.WorkspaceFolder | undefined,
		config: vscode.DebugConfiguration
	): Promise< vscode.DebugConfiguration | undefined > {
		config.folder ??= activeFolder();
		if ( typeof config.folder !== 'string' || config.folder === '' ) {
			void vscode.window.showErrorMessage(
				'Open a script of the extension to debug, or set "folder" in the launch configuration.'
			);
			return undefined;
		}
		if (
			config.test === undefined &&
			config.function === undefined &&
			config.recording === undefined
		) {
			const choice = await ask( config.folder, this.site, this.failure );
			if ( ! choice ) {
				return undefined;
			}
			Object.assign( config, choice );
		}
		if ( config.test !== undefined ) {
			await vscode.workspace.saveAll( false );
		}
		return config;
	}
}

function launch(
	name: string,
	attributes: Omit< Choice, 'name' > & { folder?: string }
): vscode.DebugConfiguration {
	return { type: TYPE, request: 'launch', name, ...attributes };
}

async function start( folder: string, choice: Choice ): Promise< void > {
	const workspace = vscode.workspace.getWorkspaceFolder(
		vscode.Uri.file( folder )
	);
	await vscode.debug.startDebugging(
		workspace,
		launch( choice.name, { ...choice, folder } )
	);
}

function folderOf( file: string ): string {
	const root = vscode.workspace.getWorkspaceFolder( vscode.Uri.file( file ) )
		?.uri.fsPath;
	return extensionFolder( file, root );
}

/** The extension folder of the open .ocs script, if one is open. */
function activeFolder(): string | undefined {
	const document = vscode.window.activeTextEditor?.document;
	if (
		document?.uri.scheme === 'file' &&
		document.fileName.endsWith( '.ocs' )
	) {
		return folderOf( document.fileName );
	}
	return undefined;
}

function scripts( folder: string ): Array< [ string, string ] > {
	try {
		return Object.entries( extensionFiles( folder ) ).filter(
			( [ path ] ) => path.endsWith( '.ocs' )
		);
	} catch {
		return [];
	}
}

type Item = vscode.QuickPickItem & { choice?: Choice };

function testItems( folder: string ): Item[] {
	return scripts( folder ).flatMap( ( [ path, source ] ) =>
		testNames( source ).map( ( test ) => ( {
			label: `$(beaker) test "${ test }"`,
			description: path,
			choice: { name: `Test "${ test }"`, test },
		} ) )
	);
}

async function pickTest( folder: string ): Promise< string | undefined > {
	const items = testItems( folder );
	if ( items.length === 0 ) {
		void vscode.window.showInformationMessage(
			'This extension declares no tests: add test "name" { } fn() { assert … }.'
		);
		return undefined;
	}
	const picked = await vscode.window.showQuickPick( items, {
		placeHolder: 'The test to run on the shop and debug',
	} );
	return picked?.choice?.test;
}

async function recordingItems(
	folder: string,
	site: () => Site | null,
	failure: ( e: unknown ) => string
): Promise< Item[] > {
	const id = extensionId( folder );
	const shop = id ? site() : null;
	if ( ! id || ! shop ) {
		return [];
	}
	try {
		const { recordings } = await shop.recordings( id );
		return recordings.map( ( r ) => ( {
			label: `$(history) #${ r.id } ${ r.what }`,
			description: `${ r.outcome }${ r.message ? `: ${ r.message }` : '' }`,
			detail: `${ r.source }, ${ new Date(
				r.recorded_at
			).toLocaleString() }, ${ r.steps } steps`,
			choice: { name: `Recording #${ r.id }`, recording: r.id },
		} ) );
	} catch ( e ) {
		return [
			{
				label: '$(warning) The recordings could not be listed',
				detail: failure( e ),
			},
		];
	}
}

async function pickRecording(
	folder: string,
	site: () => Site | null,
	failure: ( e: unknown ) => string
): Promise< Choice | undefined > {
	const items = await recordingItems( folder, site, failure );
	if ( items.length === 0 ) {
		void vscode.window.showInformationMessage(
			'The shop keeps no recordings of this extension: debug a test, or capture live runs on the Extensions screen.'
		);
		return undefined;
	}
	return (
		await vscode.window.showQuickPick( items, {
			placeHolder: 'The recording to step through',
		} )
	)?.choice;
}

/** What to debug, when the launch configuration does not say: a test, a function or a recording. */
async function ask(
	folder: string,
	site: () => Site | null,
	failure: ( e: unknown ) => string
): Promise< Choice | undefined > {
	const items = async (): Promise< Item[] > => {
		const separator = ( label: string ): Item => ( {
			label,
			kind: vscode.QuickPickItemKind.Separator,
		} );
		const tests = testItems( folder );
		const functions: Item[] = scripts( folder ).flatMap(
			( [ path, source ] ) =>
				functionNames( source ).map( ( fn ) => ( {
					label: `$(symbol-function) fn ${ fn }`,
					description: path,
					choice: { name: `fn ${ fn }`, function: fn },
				} ) )
		);
		const recordings = await recordingItems( folder, site, failure );
		return [
			...( tests.length ? [ separator( 'Tests' ), ...tests ] : [] ),
			...( functions.length
				? [ separator( 'Functions' ), ...functions ]
				: [] ),
			...( recordings.length
				? [ separator( 'Recordings' ), ...recordings ]
				: [] ),
		];
	};
	const picked = await vscode.window.showQuickPick( items(), {
		placeHolder: 'What to run on the shop and debug',
	} );
	const choice = picked?.choice;
	if ( ! choice?.function ) {
		return choice;
	}
	const text = await vscode.window.showInputBox( {
		prompt: `The arguments of ${ choice.function }, as a JSON list`,
		value: '[]',
		validateInput: ( value ) => {
			try {
				return Array.isArray( JSON.parse( value ) )
					? null
					: 'A JSON list, such as [1, "two"]';
			} catch {
				return 'A JSON list, such as [1, "two"]';
			}
		},
	} );
	if ( text === undefined ) {
		return undefined;
	}
	return { ...choice, arguments: JSON.parse( text ) as unknown[] };
}

function lenses( document: vscode.TextDocument ): vscode.CodeLens[] {
	const found: vscode.CodeLens[] = [];
	for ( let line = 0; line < document.lineCount; line++ ) {
		const [ test ] = testNames( document.lineAt( line ).text );
		if ( test !== undefined ) {
			found.push(
				new vscode.CodeLens( new vscode.Range( line, 0, line, 0 ), {
					title: 'Debug test',
					command: 'orillacart.debugTest',
					arguments: [ document.uri, test ],
				} )
			);
		}
	}
	return found;
}

/** Fetches the recording a launch asks for: runs the test or the function on the shop, or opens a kept one. */
async function load(
	args: LaunchArguments,
	site: () => Site | null,
	failure: ( e: unknown ) => string
): Promise< Loaded > {
	const folder = args.folder ?? '';
	const id = extensionId( folder );
	if ( ! id ) {
		throw new Error(
			"The folder's name is the extension's id: lower-case letters, digits and underscores."
		);
	}
	const shop = site();
	if ( ! shop ) {
		throw new Error(
			'Set orillacart.siteUrl and orillacart.apiKey in the settings first.'
		);
	}
	try {
		const loaded = await fetchRecording( shop, id, folder, args );
		return {
			...loaded,
			notes: [
				...loaded.notes,
				...differences( folder, loaded.recording ),
			],
		};
	} catch ( e ) {
		throw new Error(
			e instanceof SiteError || e instanceof TypeError
				? failure( e )
				: ( e as Error ).message
		);
	}
}

async function fetchRecording(
	shop: Site,
	id: string,
	folder: string,
	args: LaunchArguments
): Promise< Loaded > {
	if ( args.recording !== undefined ) {
		const answer = await shop.recording( id, args.recording );
		if ( ! Array.isArray( answer.steps ) ) {
			throw new Error(
				`The shop answered recording #${ args.recording } without its steps.`
			);
		}
		return {
			recording: answer as Recording,
			notes: [ `Recording #${ args.recording } kept by the shop.` ],
		};
	}
	if ( args.test !== undefined ) {
		const notes: string[] = [];
		let answer;
		try {
			answer = await shop.test( id, extensionFiles( folder ), args.test );
			notes.push(
				"The test ran over this folder's files, saved nowhere."
			);
		} catch ( e ) {
			if ( ! ( e instanceof SiteError && e.status === 403 ) ) {
				throw e;
			}
			answer = await shop.test( id, null, args.test );
			notes.push(
				"This API key may not write, so the test ran over the extension's files saved on the shop, not this folder's."
			);
		}
		const result = answer.tests.find( ( t ) => t.name === args.test );
		if ( ! result ) {
			throw new Error( `The extension has no test "${ args.test }".` );
		}
		notes.push(
			`test "${ result.name }": ${ result.ok ? 'passed' : 'failed' }${
				result.message ? `: ${ result.message }` : ''
			}`
		);
		if ( ! result.recording ) {
			throw new Error(
				`The shop kept no recording of test "${ result.name }"${
					result.message ? ` (${ result.message })` : ''
				}.`
			);
		}
		return { recording: result.recording, notes };
	}
	if ( args.function !== undefined ) {
		const answer = await shop.run(
			id,
			args.function,
			args.arguments ?? []
		);
		if ( ! answer.recording ) {
			throw new Error(
				`The shop kept no recording of ${ args.function }${
					answer.failure ? ` (${ answer.failure })` : ''
				}.`
			);
		}
		return {
			recording: answer.recording,
			notes: [
				"The function ran over the extension's files saved on the shop: push to run this folder's.",
				answer.ok
					? `${ args.function } returned ${ JSON.stringify( answer.result ) }`
					: `${ args.function } failed: ${ answer.failure ?? answer.stopped ?? 'no reason given' }`,
			],
		};
	}
	throw new Error(
		'Say what to debug: "test", "function" or "recording" in the launch configuration.'
	);
}

/** Which of the run's files differ from this folder's: those are shown as they ran. */
function differences( folder: string, recording: Recording ): string[] {
	return Object.entries( recording.sources ).flatMap(
		( [ file, content ] ) => {
			let local: string | null;
			try {
				local = readFileSync( join( folder, file ), 'utf8' );
			} catch {
				local = null;
			}
			const normal = ( s: string ) =>
				s.replace( /\r\n?/g, '\n' ).replace( /\n+$/, '' );
			return local !== null && normal( local ) === normal( content )
				? []
				: [
						`${ file } differs from what ran: the debugger shows it as it ran.`,
					];
		}
	);
}
