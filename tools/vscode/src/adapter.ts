import { basename, isAbsolute, join, relative } from 'node:path';

import type * as vscode from 'vscode';

import {
	breakpoint,
	children,
	describe,
	type Frame,
	type RecordedValue,
	type Recording,
	Replay,
	type Step,
} from '../../../assets/admin/extensions/editor/replay';

/**
 * The debugger of design 71, as an inline debug adapter: the run already happened on the shop and was recorded,
 * so this adapter speaks the Debug Adapter Protocol over the recording, stepping with the admin's own Replay
 * (assets/admin/extensions/editor/replay.ts) forwards and backwards. Nothing pauses the shop.
 *
 * It needs no `vscode` at run time (the host is handed in), so a plain node script can drive it in a test.
 */

/** A launch configuration's attributes, after VS Code resolved them. */
export interface LaunchArguments {
	/** The extension's folder, absolute: recorded file paths are relative to it. */
	folder?: string;
	test?: string;
	function?: string;
	arguments?: unknown[];
	recording?: number;
	stopOnEntry?: boolean;
}

/** What a launch fetched: the recording and lines to say about it first (which run, what the shop answered). */
export interface Loaded {
	recording: Recording;
	notes: string[];
}

export interface AdapterHost {
	/** Fetches the recording a launch asks for; throws with a message for the user when it cannot. */
	load( args: LaunchArguments ): Promise< Loaded >;
	/** A local file's content, or null when there is none. */
	readFile( path: string ): string | null;
}

interface Request {
	seq: number;
	type: 'request';
	command: string;
	arguments?: Record< string, unknown >;
}

type Handle =
	| { kind: 'scope'; vars: Record< string, RecordedValue > }
	| { kind: 'value'; value: RecordedValue };

type Message = Record< string, unknown >;

const THREAD = 1;

export class RecordingDebugAdapter implements vscode.DebugAdapter {
	private readonly listeners = new Set< ( message: Message ) => void >();
	private seq = 1;
	private replay: Replay | null = null;
	private folder = '';
	private stopOnEntry = true;
	/** file → lines with a breakpoint */
	private readonly lines = new Map< string, number[] >();
	private breakpoints: ReadonlySet< string > = new Set();
	/** The highest step whose call or debug() output is out. */
	private passed = -1;
	private handles = new Map< number, Handle >();
	private nextHandle = 1;
	/** file → sourceReference, for files whose local content is not what ran. */
	private readonly references = new Map< string, number >();
	private readonly local = new Map< string, boolean >();
	private ended = false;

	constructor( private readonly host: AdapterHost ) {}

	readonly onDidSendMessage = (
		listener: ( message: vscode.DebugProtocolMessage ) => unknown,
		thisArgs?: unknown,
		disposables?: vscode.Disposable[]
	): vscode.Disposable => {
		const bound = ( message: Message ) =>
			listener.call( thisArgs, message as vscode.DebugProtocolMessage );
		this.listeners.add( bound );
		const disposable = { dispose: () => this.listeners.delete( bound ) };
		disposables?.push( disposable );
		return disposable;
	};

	dispose(): void {
		this.listeners.clear();
	}

	handleMessage( message: vscode.DebugProtocolMessage ): void {
		const request = message as Request;
		if ( request.type !== 'request' ) {
			return;
		}
		void this.dispatch( request ).catch( ( e: unknown ) =>
			this.respond( request, undefined, ( e as Error ).message )
		);
	}

	private async dispatch( request: Request ): Promise< void > {
		const args = request.arguments ?? {};
		switch ( request.command ) {
			case 'initialize':
				this.respond( request, {
					supportsConfigurationDoneRequest: true,
					supportsStepBack: true,
					supportsEvaluateForHovers: true,
					supportsExceptionInfoRequest: true,
					supportsTerminateRequest: true,
				} );
				return;
			case 'launch':
				await this.launch( request, args as LaunchArguments );
				return;
			case 'setBreakpoints':
				this.respond( request, this.setBreakpoints( args ) );
				return;
			case 'setExceptionBreakpoints':
				this.respond( request, {} );
				return;
			case 'configurationDone':
				this.respond( request );
				this.start();
				return;
			case 'threads':
				this.respond( request, {
					threads: [
						{
							id: THREAD,
							name: this.replay?.recording.what ?? 'run',
						},
					],
				} );
				return;
			case 'stackTrace':
				this.respond( request, this.stackTrace() );
				return;
			case 'scopes':
				this.respond( request, this.scopes( Number( args.frameId ) ) );
				return;
			case 'variables':
				this.respond(
					request,
					this.variables( Number( args.variablesReference ) )
				);
				return;
			case 'evaluate':
				this.evaluate( request, args );
				return;
			case 'source':
				this.source( request, args );
				return;
			case 'exceptionInfo':
				this.respond( request, {
					exceptionId: 'error',
					description:
						this.replay?.step?.message ??
						this.replay?.recording.message ??
						'The run failed.',
					breakMode: 'always',
				} );
				return;
			case 'continue':
				this.move( request, 'continue' );
				return;
			case 'next':
				this.move( request, 'next' );
				return;
			case 'stepIn':
				this.move( request, 'stepIn' );
				return;
			case 'stepOut':
				this.move( request, 'stepOut' );
				return;
			case 'stepBack':
				this.move( request, 'stepBack' );
				return;
			case 'reverseContinue':
				this.move( request, 'reverseContinue' );
				return;
			case 'pause':
				this.respond( request );
				this.stopped( 'pause' );
				return;
			case 'disconnect':
			case 'terminate':
				this.respond( request );
				if ( request.command === 'terminate' && ! this.ended ) {
					this.ended = true;
					this.event( 'terminated' );
				}
				return;
			default:
				this.respond(
					request,
					undefined,
					`"${ request.command }" is not something a recorded run can do.`
				);
		}
	}

	private async launch(
		request: Request,
		args: LaunchArguments
	): Promise< void > {
		this.folder = args.folder ?? '';
		this.stopOnEntry = args.stopOnEntry !== false;
		let loaded: Loaded;
		try {
			loaded = await this.host.load( args );
		} catch ( e ) {
			this.respond( request, undefined, ( e as Error ).message );
			return;
		}
		const recording = loaded.recording;
		this.replay = new Replay( recording );
		this.respond( request );
		this.output(
			`${ recording.what }: ${ recording.outcome }${
				recording.message ? ` (${ recording.message })` : ''
			}, ${ recording.steps.length } steps recorded${
				recording.id ? ` as recording #${ recording.id }` : ''
			}. The run already happened on the shop: stepping moves over its recording, backwards too.\n`
		);
		for ( const note of loaded.notes ) {
			this.output( `${ note }\n` );
		}
		if ( recording.cut ) {
			this.output(
				'Warning: the run made more steps than a recording keeps, so its end is missing.\n',
				'important'
			);
		}
		this.event( 'initialized' );
	}

	private start(): void {
		const replay = this.replay;
		if ( ! replay ) {
			return;
		}
		if ( replay.length === 0 ) {
			this.output( 'The recording has no lines to stop at.\n' );
			this.terminate();
			return;
		}
		replay.goTo( 0 );
		const first = replay.step;
		if ( this.stopOnEntry ) {
			this.passOutput();
			this.stopped( first?.k === 'error' ? 'exception' : 'entry' );
		} else if ( first && this.hits( first ) ) {
			this.passOutput();
			this.stopped( first.k === 'error' ? 'exception' : 'breakpoint' );
		} else {
			replay.continue( this.breakpoints );
			this.passOutput();
			this.stopped( this.reason( 'continue' ) );
		}
	}

	private move(
		request: Request,
		how:
			| 'continue'
			| 'next'
			| 'stepIn'
			| 'stepOut'
			| 'stepBack'
			| 'reverseContinue'
	): void {
		const replay = this.replay;
		if ( ! replay || this.ended ) {
			this.respond( request, undefined, 'The run is over.' );
			return;
		}
		const forward = how !== 'stepBack' && how !== 'reverseContinue';
		this.respond(
			request,
			how === 'continue' ? { allThreadsContinued: true } : undefined
		);
		if ( forward && replay.atEnd ) {
			this.terminate();
			return;
		}
		switch ( how ) {
			case 'continue':
				replay.continue( this.breakpoints );
				break;
			case 'next':
				replay.stepOver();
				break;
			case 'stepIn':
				replay.stepIn();
				break;
			case 'stepOut':
				replay.stepOut();
				break;
			case 'stepBack':
				// The step back button sits by step over: its reverse, calls run through backwards.
				replay.stepBackOver();
				break;
			case 'reverseContinue':
				replay.reverseContinue( this.breakpoints );
				break;
		}
		this.passOutput();
		this.stopped( this.reason( how ) );
	}

	private reason( how: string ): string {
		const step = this.replay?.step;
		if ( step?.k === 'error' ) {
			return 'exception';
		}
		if (
			( how === 'continue' || how === 'reverseContinue' ) &&
			step &&
			this.hits( step )
		) {
			return 'breakpoint';
		}
		return 'step';
	}

	private hits( step: Step ): boolean {
		return (
			step.k === 'error' ||
			( !! step.file &&
				!! step.line &&
				this.breakpoints.has( breakpoint( step.file, step.line ) ) )
		);
	}

	private stopped( reason: string ): void {
		this.handles = new Map();
		this.nextHandle = 1;
		const replay = this.replay;
		const step = replay?.step;
		const body: Message = {
			reason,
			threadId: THREAD,
			allThreadsStopped: true,
		};
		if ( reason === 'exception' ) {
			body.description = 'The run failed here';
			body.text = step?.message ?? replay?.recording.message ?? '';
		} else if ( replay && reason === 'step' && replay.atEnd ) {
			body.description = 'The end of the run';
		} else if ( replay && reason === 'step' && replay.atStart ) {
			body.description = 'The start of the run';
		}
		this.event( 'stopped', body );
	}

	private terminate(): void {
		const replay = this.replay;
		if ( replay ) {
			this.passOutput( replay.recording.steps.length - 1 );
		}
		this.ended = true;
		this.event( 'terminated' );
	}

	/** Sends the calls, debug() values and returns the replay passes on its way forward, once each. */
	private passOutput( to: number = this.replay?.at ?? -1 ): void {
		const replay = this.replay;
		if ( ! replay || to <= this.passed ) {
			return;
		}
		const steps = replay.recording.steps;
		for ( let index = this.passed + 1; index <= to; index++ ) {
			const step = steps[ index ];
			if ( ! step ) {
				continue;
			}
			let text: string | null = null;
			if ( step.k === 'call' ) {
				text = `${ step.name ?? 'call' }(${ ( step.args ?? [] )
					.map( short )
					.join( ', ' ) }) → ${
					step.error ? `error: ${ step.error }` : short( step.result )
				}`;
			} else if ( step.k === 'debug' ) {
				text = `debug: ${ short( step.value ) }`;
			} else if ( step.k === 'return' && step.depth === 1 ) {
				text = `returned ${ short( step.value ) }`;
			}
			if ( text !== null ) {
				this.output(
					`${ text }\n`,
					'console',
					step.file
						? {
								source: this.sourceOf( step.file ),
								line: step.line ?? 0,
							}
						: {}
				);
			}
		}
		this.passed = to;
	}

	private setBreakpoints( args: Record< string, unknown > ): Message {
		const source = ( args.source ?? {} ) as {
			path?: string;
			sourceReference?: number;
		};
		const file = this.fileOf( source );
		const wanted = (
			( args.breakpoints ?? [] ) as Array< { line: number } >
		 ).map( ( b ) => b.line );
		if ( file !== null ) {
			this.lines.set( file, wanted );
			this.breakpoints = new Set(
				[ ...this.lines ].flatMap( ( [ f, ls ] ) =>
					ls.map( ( l ) => breakpoint( f, l ) )
				)
			);
		}
		const ran =
			file !== null &&
			( ! this.replay || file in this.replay.recording.sources );
		const changed =
			ran &&
			file !== null &&
			!! this.replay &&
			! source.sourceReference &&
			'sourceReference' in this.sourceOf( file );
		return {
			breakpoints: wanted.map( ( line ) =>
				ran
					? {
							verified: true,
							line,
							...( changed
								? {
										message:
											'This file changed since the run: the line is the line of the file as it ran.',
									}
								: {} ),
						}
					: {
							verified: false,
							line,
							message: "This file is not one of the run's files.",
						}
			),
		};
	}

	/** A source's file as the recording names it, relative to the folder; null when outside it. */
	private fileOf( source: {
		path?: string;
		sourceReference?: number;
	} ): string | null {
		if ( source.sourceReference ) {
			for ( const [ file, reference ] of this.references ) {
				if ( reference === source.sourceReference ) {
					return file;
				}
			}
		}
		if ( ! source.path ) {
			return null;
		}
		const path = relative( this.folder, source.path );
		if ( path.startsWith( '..' ) || isAbsolute( path ) ) {
			return null;
		}
		return path.split( '\\' ).join( '/' );
	}

	/** The local file when it holds what ran, else the recorded text by reference: lines always match the run. */
	private sourceOf( file: string ): Message {
		const recorded = this.replay?.recording.sources[ file ];
		const path = join( this.folder, file );
		let same = this.local.get( file );
		if ( same === undefined ) {
			const content = this.host.readFile( path );
			same =
				recorded === undefined ||
				( content !== null &&
					normal( content ) === normal( recorded ) );
			this.local.set( file, same );
		}
		if ( same ) {
			return { name: basename( file ), path };
		}
		let reference = this.references.get( file );
		if ( reference === undefined ) {
			reference = this.references.size + 1;
			this.references.set( file, reference );
		}
		return {
			name: `${ basename( file ) } (as it ran)`,
			sourceReference: reference,
			origin: 'the file as it ran on the shop',
		};
	}

	private source( request: Request, args: Record< string, unknown > ): void {
		const file = this.fileOf( {
			...( typeof args.sourceReference === 'number'
				? { sourceReference: args.sourceReference }
				: {} ),
			...( ( args.source as { path?: string } | undefined )?.path
				? { path: ( args.source as { path: string } ).path }
				: {} ),
		} );
		const content =
			file === null ? undefined : this.replay?.recording.sources[ file ];
		if ( content === undefined ) {
			this.respond(
				request,
				undefined,
				'The recording has no such file.'
			);
			return;
		}
		this.respond( request, { content, mimeType: 'text/x-ocs' } );
	}

	private frames(): Frame[] {
		const replay = this.replay;
		if ( ! replay ) {
			return [];
		}
		const frames = replay.frames();
		const step = replay.step;
		if ( frames.length === 0 && step?.file && step.line ) {
			return [
				{
					fn: replay.recording.what,
					file: step.file,
					line: step.line,
					vars: {},
					at: replay.at,
				},
			];
		}
		return frames;
	}

	private stackTrace(): Message {
		const frames = this.frames();
		return {
			stackFrames: frames.map( ( frame, index ) => ( {
				id: index + 1,
				name: frame.fn,
				source: this.sourceOf( frame.file ),
				line: frame.line,
				column: 1,
			} ) ),
			totalFrames: frames.length,
		};
	}

	private scopes( frameId: number ): Message {
		const frame = this.frames()[ frameId - 1 ];
		if ( ! frame ) {
			return { scopes: [] };
		}
		return {
			scopes: [
				{
					name: 'Locals',
					presentationHint: 'locals',
					variablesReference: this.handle( {
						kind: 'scope',
						vars: frame.vars,
					} ),
					namedVariables: Object.keys( frame.vars ).length,
					expensive: false,
				},
			],
		};
	}

	private variables( reference: number ): Message {
		const handle = this.handles.get( reference );
		if ( ! handle ) {
			return { variables: [] };
		}
		const entries =
			handle.kind === 'scope'
				? Object.entries( handle.vars )
				: children( handle.value );
		return {
			variables: entries.map( ( [ name, value ] ) => ( {
				name,
				value: describe( value ),
				variablesReference: this.reference( value ),
			} ) ),
		};
	}

	private evaluate(
		request: Request,
		args: Record< string, unknown >
	): void {
		const frames = this.frames();
		const frame = frames[ Number( args.frameId ?? 1 ) - 1 ] ?? frames[ 0 ];
		const path = parse( String( args.expression ?? '' ) );
		if ( ! frame || ! path ) {
			this.respond(
				request,
				undefined,
				'Only a name in scope, with .key or [index] after it, can be looked up in a recording.'
			);
			return;
		}
		const [ name, ...rest ] = path;
		let value: RecordedValue | undefined =
			name !== undefined && name in frame.vars
				? frame.vars[ name ]
				: undefined;
		for ( const part of rest ) {
			if ( value === undefined ) {
				break;
			}
			value = children( value ).find(
				( [ key ] ) => key === part
			)?.[ 1 ];
		}
		if ( value === undefined ) {
			this.respond(
				request,
				undefined,
				`${ String( args.expression ) } is not in scope here.`
			);
			return;
		}
		this.respond( request, {
			result: describe( value ),
			variablesReference: this.reference( value ),
		} );
	}

	private reference( value: RecordedValue ): number {
		return children( value ).length > 0
			? this.handle( { kind: 'value', value } )
			: 0;
	}

	private handle( handle: Handle ): number {
		const id = this.nextHandle++;
		this.handles.set( id, handle );
		return id;
	}

	private output(
		output: string,
		category = 'console',
		where: Message = {}
	): void {
		this.event( 'output', { category, output, ...where } );
	}

	private respond(
		request: Request,
		body?: unknown,
		failure?: string
	): void {
		this.send( {
			type: 'response',
			request_seq: request.seq,
			command: request.command,
			success: failure === undefined,
			...( failure === undefined ? {} : { message: failure } ),
			...( body === undefined ? {} : { body } ),
		} );
	}

	private event( event: string, body?: unknown ): void {
		this.send( {
			type: 'event',
			event,
			...( body === undefined ? {} : { body } ),
		} );
	}

	private send( message: Message ): void {
		const sent = { seq: this.seq++, ...message };
		for ( const listener of this.listeners ) {
			listener( sent );
		}
	}
}

/** A name with `.key`, `[index]` or `["key"]` after it, as its parts; null for anything else. */
export function parse( expression: string ): string[] | null {
	const text = expression.trim();
	const head = /^[A-Za-z_][A-Za-z0-9_]*/.exec( text );
	if ( ! head ) {
		return null;
	}
	const parts = [ head[ 0 ] ];
	const tail =
		/\s*(?:\.\s*([A-Za-z0-9_]+)|\[\s*(?:(\d+)|"((?:[^"\\]|\\.)*)"|'([^']*)')\s*\])/y;
	tail.lastIndex = head[ 0 ].length;
	while ( tail.lastIndex < text.length ) {
		const match = tail.exec( text );
		if ( ! match ) {
			return null;
		}
		if ( match[ 3 ] !== undefined ) {
			try {
				parts.push( JSON.parse( `"${ match[ 3 ] }"` ) as string );
			} catch {
				return null;
			}
		} else {
			parts.push( match[ 1 ] ?? match[ 2 ] ?? match[ 4 ] ?? '' );
		}
	}
	return parts;
}

function short( value: RecordedValue | undefined ): string {
	if ( value === undefined ) {
		return 'nothing';
	}
	const text = JSON.stringify( value );
	return text.length > 200 ? `${ text.slice( 0, 199 ) }…` : text;
}

function normal( text: string ): string {
	return text.replace( /\r\n?/g, '\n' ).replace( /\n+$/, '' );
}
