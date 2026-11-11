/**
 * Drives the debug adapter (src/adapter.ts) over the admin's recording fixture with the Debug Adapter Protocol's
 * messages, as VS Code would, and checks its answers. Bundled with esbuild on the fly; no VS Code needed.
 *
 *     node test/adapter.test.mjs
 */
import assert from 'node:assert/strict';
import {
	mkdirSync,
	mkdtempSync,
	readFileSync,
	rmSync,
	writeFileSync,
} from 'node:fs';
import { createRequire } from 'node:module';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

import * as esbuild from 'esbuild';

const here = dirname( fileURLToPath( import.meta.url ) );
const work = mkdtempSync( join( tmpdir(), 'orillacart-adapter-' ) );
const bundle = join( work, 'adapter.cjs' );
await esbuild.build( {
	entryPoints: [ join( here, '..', 'src', 'adapter.ts' ) ],
	outfile: bundle,
	bundle: true,
	platform: 'node',
	format: 'cjs',
	external: [ 'vscode' ],
	logLevel: 'warning',
} );
const { RecordingDebugAdapter, parse } = createRequire( import.meta.url )(
	bundle
);

const recording = JSON.parse(
	readFileSync(
		join(
			here,
			'..',
			'..',
			'..',
			'assets',
			'admin',
			'extensions',
			'editor',
			'test',
			'recording.json'
		),
		'utf8'
	)
);

// The extension's folder: main.ocs as it ran, price.ocs edited since, so it is served as it ran.
const folder = join( work, 'quote' );
mkdirSync( folder );
writeFileSync( join( folder, 'main.ocs' ), recording.sources[ 'main.ocs' ] );
writeFileSync(
	join( folder, 'price.ocs' ),
	'// edited since\n' + recording.sources[ 'price.ocs' ]
);

let loadedWith = null;
const adapter = new RecordingDebugAdapter( {
	load: async ( args ) => {
		loadedWith = args;
		return {
			recording: { ...recording, id: 7 },
			notes: [ 'test "x": failed' ],
		};
	},
	readFile: ( path ) => {
		try {
			return readFileSync( path, 'utf8' );
		} catch {
			return null;
		}
	},
} );

const events = [];
const waiting = new Map();
let seq = 1;
adapter.onDidSendMessage( ( message ) => {
	if ( message.type === 'response' ) {
		waiting.get( message.request_seq )?.( message );
	} else {
		events.push( message );
	}
} );
const request = ( command, args ) =>
	new Promise( ( resolve ) => {
		const id = seq++;
		waiting.set( id, resolve );
		adapter.handleMessage( {
			seq: id,
			type: 'request',
			command,
			arguments: args,
		} );
	} );
const ok = async ( command, args ) => {
	const response = await request( command, args );
	assert.equal(
		response.success,
		true,
		`${ command }: ${ response.message }`
	);
	return response.body;
};
const drain = () => new Promise( ( resolve ) => setImmediate( resolve ) );
const take = async ( name ) => {
	await drain();
	const found = events.filter( ( e ) => e.event === name );
	events.splice(
		0,
		events.length,
		...events.filter( ( e ) => e.event !== name )
	);
	return found;
};
const stopped = async ( reason ) => {
	const [ event, ...more ] = await take( 'stopped' );
	assert.ok( event, `a stopped event (${ reason })` );
	assert.equal( more.length, 0 );
	assert.equal( event.body.reason, reason );
	return event.body;
};
const top = async () =>
	( await ok( 'stackTrace', { threadId: 1 } ) ).stackFrames;
const at = async () => {
	const [ frame ] = await top();
	return `${ frame.source.name }:${ frame.line }`;
};
const outputs = async () =>
	( await take( 'output' ) ).map( ( e ) => e.body.output.trim() );
let checks = 0;
const check = async ( name, body ) => {
	await body();
	checks++;
	console.log( `  ok  ${ name }` );
};

await check( 'initialize answers the capabilities', async () => {
	const caps = await ok( 'initialize', { adapterID: 'orillacart' } );
	assert.equal( caps.supportsStepBack, true );
	assert.equal( caps.supportsConfigurationDoneRequest, true );
	assert.equal( caps.supportsEvaluateForHovers, true );
} );

await check(
	'launch loads the recording, says what ran, then initialized',
	async () => {
		await ok( 'launch', { folder, test: 'x' } );
		assert.equal( loadedWith.test, 'x' );
		const lines = await outputs();
		assert.match(
			lines[ 0 ],
			/^fn total: error \(refused: 503\), 23 steps recorded as recording #7/
		);
		assert.equal( lines[ 1 ], 'test "x": failed' );
		assert.equal( ( await take( 'initialized' ) ).length, 1 );
	}
);

await check(
	'setBreakpoints keeps them per file, relative to the folder',
	async () => {
		const body = await ok( 'setBreakpoints', {
			source: { path: join( folder, 'price.ocs' ) },
			breakpoints: [ { line: 5 } ],
		} );
		assert.equal( body.breakpoints[ 0 ].verified, true );
		assert.match(
			body.breakpoints[ 0 ].message,
			/changed since the run/,
			'price.ocs was edited since'
		);
		const other = await ok( 'setBreakpoints', {
			source: { path: join( folder, 'other.ocs' ) },
			breakpoints: [ { line: 1 } ],
		} );
		assert.equal( other.breakpoints[ 0 ].verified, false );
	}
);

await check( 'configurationDone stops on entry at the first line', async () => {
	await ok( 'configurationDone' );
	await stopped( 'entry' );
	const frames = await top();
	assert.equal( frames.length, 1 );
	assert.equal( frames[ 0 ].name, 'total' );
	assert.equal( frames[ 0 ].line, 4 );
	assert.equal(
		frames[ 0 ].source.path,
		join( folder, 'main.ocs' ),
		'the local file holds what ran'
	);
} );

await check( 'next goes line by line', async () => {
	await ok( 'next', { threadId: 1 } );
	await stopped( 'step' );
	assert.equal( await at(), 'main.ocs:5' );
	await ok( 'next', { threadId: 1 } );
	await stopped( 'step' );
	assert.equal( await at(), 'main.ocs:6' );
} );

await check(
	'stepIn enters the call; a changed file is served as it ran',
	async () => {
		await ok( 'stepIn', { threadId: 1 } );
		await stopped( 'step' );
		const frames = await top();
		assert.deepEqual(
			frames.map( ( f ) => [ f.name, f.line ] ),
			[
				[ 'price', 2 ],
				[ 'total', 6 ],
			]
		);
		const reference = frames[ 0 ].source.sourceReference;
		assert.ok(
			reference > 0,
			'price.ocs differs locally: a sourceReference'
		);
		assert.equal( frames[ 0 ].source.path, undefined );
		const source = await ok( 'source', {
			sourceReference: reference,
			source: frames[ 0 ].source,
		} );
		assert.equal( source.content, recording.sources[ 'price.ocs' ] );
	}
);

await check( 'scopes and variables show the frame, nested', async () => {
	const [ frame ] = await top();
	const { scopes } = await ok( 'scopes', { frameId: frame.id } );
	assert.equal( scopes.length, 1 );
	assert.equal( scopes[ 0 ].name, 'Locals' );
	const { variables } = await ok( 'variables', {
		variablesReference: scopes[ 0 ].variablesReference,
	} );
	assert.deepEqual(
		variables.map( ( v ) => [ v.name, v.value ] ),
		[ [ 'item', 'map (2)' ] ]
	);
	assert.ok( variables[ 0 ].variablesReference > 0 );
	const nested = await ok( 'variables', {
		variablesReference: variables[ 0 ].variablesReference,
	} );
	assert.deepEqual(
		nested.variables.map( ( v ) => [
			v.name,
			v.value,
			v.variablesReference,
		] ),
		[
			[ 'unit', '2', 0 ],
			[ 'qty', '3', 0 ],
		]
	);
	const caller = await ok( 'scopes', { frameId: 2 } );
	const callerVars = await ok( 'variables', {
		variablesReference: caller.scopes[ 0 ].variablesReference,
	} );
	assert.deepEqual(
		callerVars.variables.map( ( v ) => v.name ),
		[ 'items', 'sum', 'item' ]
	);
} );

await check(
	'evaluate looks a dotted or indexed name up in the frame',
	async () => {
		assert.equal(
			(
				await ok( 'evaluate', {
					expression: 'item.unit',
					frameId: 1,
					context: 'hover',
				} )
			).result,
			'2'
		);
		assert.equal(
			(
				await ok( 'evaluate', {
					expression: 'items[1].unit',
					frameId: 2,
					context: 'watch',
				} )
			).result,
			'9'
		);
		assert.equal(
			( await ok( 'evaluate', { expression: 'items', frameId: 2 } ) )
				.result,
			'list (2)'
		);
		const missing = await request( 'evaluate', {
			expression: 'nope',
			frameId: 1,
		} );
		assert.equal( missing.success, false );
		assert.deepEqual( parse( 'a["b c"][0].d' ), [ 'a', 'b c', '0', 'd' ] );
		assert.equal( parse( 'a + b' ), null );
	}
);

await check( 'stepBack goes back over the call', async () => {
	await ok( 'stepBack', { threadId: 1 } );
	await stopped( 'step' );
	assert.equal( await at(), 'main.ocs:6' );
} );

await check(
	'continue stops at the breakpoint, passing debug() values on the way',
	async () => {
		await take( 'output' );
		await ok( 'continue', { threadId: 1 } );
		await stopped( 'breakpoint' );
		assert.equal( await at(), 'price.ocs (as it ran):5' );
		assert.deepEqual( await outputs(), [ 'debug: 2', 'debug: 9' ] );
	}
);

await check(
	'continue ends at the error: an exception stop with the message',
	async () => {
		await ok( 'continue', { threadId: 1 } );
		const body = await stopped( 'exception' );
		assert.equal( body.text, 'refused: 503' );
		assert.deepEqual( await outputs(), [
			'http.get("https://api.example/check") → {"status":503}',
		] );
		const frames = await top();
		assert.deepEqual(
			frames.map( ( f ) => [ f.name, f.line ] ),
			[
				[ 'price', 6 ],
				[ 'total', 6 ],
			]
		);
		assert.equal(
			( await ok( 'exceptionInfo', { threadId: 1 } ) ).description,
			'refused: 503'
		);
	}
);

await check(
	'reverseContinue goes back to the breakpoint, output not repeated',
	async () => {
		await ok( 'reverseContinue', { threadId: 1 } );
		await stopped( 'breakpoint' );
		assert.equal( await at(), 'price.ocs (as it ran):5' );
		await ok( 'stepOut', { threadId: 1 } );
		await stopped( 'exception' );
		assert.deepEqual( await outputs(), [] );
	}
);

await check( 'continuing past the end terminates', async () => {
	await ok( 'continue', { threadId: 1 } );
	assert.equal( ( await take( 'terminated' ) ).length, 1 );
	assert.equal( ( await take( 'stopped' ) ).length, 0 );
	await ok( 'disconnect', {} );
} );

await check(
	'stopOnEntry false goes to the first breakpoint; a cut recording warns',
	async () => {
		const second = new RecordingDebugAdapter( {
			load: async () => ( {
				recording: { ...recording, cut: true },
				notes: [],
			} ),
			readFile: () => null,
		} );
		const seen = [];
		let n = 1;
		second.onDidSendMessage( ( m ) => seen.push( m ) );
		const send = ( command, args ) =>
			second.handleMessage( {
				seq: n++,
				type: 'request',
				command,
				arguments: args,
			} );
		send( 'initialize', {} );
		send( 'launch', { folder, recording: 3, stopOnEntry: false } );
		await drain();
		send( 'setBreakpoints', {
			source: { path: join( folder, 'main.ocs' ) },
			breakpoints: [ { line: 6 } ],
		} );
		send( 'configurationDone' );
		await drain();
		assert.ok(
			seen.some(
				( m ) =>
					m.event === 'output' &&
					m.body.category === 'important' &&
					/end is missing/.test( m.body.output )
			)
		);
		const stop = seen.find( ( m ) => m.event === 'stopped' );
		assert.equal( stop.body.reason, 'breakpoint' );
		send( 'stackTrace', { threadId: 1 } );
		await drain();
		const trace = seen.find( ( m ) => m.command === 'stackTrace' );
		assert.equal( trace.body.stackFrames[ 0 ].line, 6 );
		assert.ok(
			trace.body.stackFrames[ 0 ].source.sourceReference > 0,
			'no local file: served as it ran'
		);
	}
);

await check( 'a failed load fails the launch with its message', async () => {
	const third = new RecordingDebugAdapter( {
		load: async () => {
			throw new Error( 'The extension has no test "y".' );
		},
		readFile: () => null,
	} );
	const seen = [];
	third.onDidSendMessage( ( m ) => seen.push( m ) );
	third.handleMessage( {
		seq: 1,
		type: 'request',
		command: 'launch',
		arguments: { folder },
	} );
	await drain();
	assert.equal( seen[ 0 ].success, false );
	assert.equal( seen[ 0 ].message, 'The extension has no test "y".' );
	assert.ok( ! seen.some( ( m ) => m.event === 'initialized' ) );
} );

rmSync( work, { recursive: true, force: true } );
console.log( `${ checks } checks passed.` );
