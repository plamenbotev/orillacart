import recorded from './recording.json';
import {
	backwards,
	commandForKey,
	describeDeep,
	linesOf,
	outcomeTone,
	outputLine,
	perform,
	toggled,
} from '../debugging';
import { breakpoint, Replay, type Recording } from '../replay';

// recording.json: total() over two items; the second fails in price.ocs at line 6 after asking an API.
const recording = recorded as unknown as Recording;
const where = ( replay: Replay ) =>
	`${ replay.step?.file }:${ replay.step?.line }`;

describe( 'the debugger’s commands', () => {
	it( 'moves the replay as the toolbar asks, and to either end', () => {
		const replay = new Replay( recording );
		perform( replay, 'end', new Set() );
		expect( replay.atEnd ).toBe( true );
		expect( replay.step?.k ).toBe( 'error' );
		perform( replay, 'start', new Set() );
		expect( replay.atStart ).toBe( true );

		perform( replay, 'in', new Set() );
		perform( replay, 'in', new Set() );
		perform( replay, 'in', new Set() );
		expect( where( replay ) ).toBe( 'price.ocs:2' );
		perform( replay, 'out', new Set() );
		expect( where( replay ) ).toBe( 'main.ocs:6' );
		perform( replay, 'back', new Set() );
		expect( where( replay ) ).toBe( 'price.ocs:8' );
		perform( replay, 'backOver', new Set() );
		expect( where( replay ) ).toBe( 'price.ocs:4' );
	} );

	it( 'continues to a breakpoint and back to the one before', () => {
		const replay = new Replay( recording );
		const points = new Set( [ breakpoint( 'price.ocs', 5 ) ] );
		expect( perform( replay, 'continue', points ) ).toBe( true );
		expect( where( replay ) ).toBe( 'price.ocs:5' );
		perform( replay, 'end', points );
		perform( replay, 'reverse', points );
		expect( where( replay ) ).toBe( 'price.ocs:5' );
		expect( perform( replay, 'start', points ) ).toBe( true );
		expect( perform( replay, 'start', points ) ).toBe( false );
	} );

	it( 'tells the backward commands from the forward ones', () => {
		expect(
			[ 'start', 'back', 'backOver', 'reverse' ].every( ( c ) =>
				backwards( c as Parameters< typeof backwards >[ 0 ] )
			)
		).toBe( true );
		expect(
			[ 'in', 'over', 'out', 'continue', 'end' ].some( ( c ) =>
				backwards( c as Parameters< typeof backwards >[ 0 ] )
			)
		).toBe( false );
	} );
} );

describe( 'the debugger’s keys', () => {
	const key = ( name: string, shiftKey = false, ctrlKey = false ) =>
		commandForKey( { key: name, shiftKey, ctrlKey } );

	it( 'reads the function keys as a debugger has them', () => {
		expect( key( 'F10' ) ).toBe( 'over' );
		expect( key( 'F11' ) ).toBe( 'in' );
		expect( key( 'F11', true ) ).toBe( 'out' );
		expect( key( 'F5' ) ).toBe( 'continue' );
		expect( key( 'F5', true ) ).toBe( 'reverse' );
		expect( key( 'F8' ) ).toBe( 'back' );
		expect( key( 'F8', true ) ).toBe( 'backOver' );
		expect( key( 'F9' ) ).toBe( 'toggle' );
	} );

	it( 'leaves other keys and the browser’s combinations alone', () => {
		expect( key( 'a' ) ).toBeNull();
		expect( key( 'F10', true ) ).toBeNull();
		expect( key( 'F5', false, true ) ).toBeNull();
	} );
} );

describe( 'breakpoints', () => {
	it( 'toggles one in a new set and lists a file’s lines in order', () => {
		const empty = new Set< string >();
		const one = toggled( empty, 'main.ocs', 6 );
		expect( empty.size ).toBe( 0 );
		const two = toggled( toggled( one, 'main.ocs', 2 ), 'price.ocs', 5 );
		expect( linesOf( two, 'main.ocs' ) ).toEqual( [ 2, 6 ] );
		expect( linesOf( two, 'price.ocs' ) ).toEqual( [ 5 ] );
		expect( linesOf( toggled( two, 'main.ocs', 6 ), 'main.ocs' ) ).toEqual(
			[ 2 ]
		);
	} );
} );

describe( 'the output', () => {
	it( 'shows the calls to the shop with their answers and debug() values', () => {
		const replay = new Replay( recording );
		replay.goTo( replay.length - 1 );
		const lines = replay.output().map( outputLine );
		expect( lines[ 0 ] ).toMatchObject( {
			kind: 'debug',
			value: '2',
			where: 'price.ocs:3',
		} );
		expect( lines[ 2 ] ).toMatchObject( {
			kind: 'call',
			label: 'http.get("https://api.example/check")',
			error: null,
		} );
		expect( lines[ 2 ]?.value ).toContain( '503' );
	} );

	it( 'shows the error a call threw', () => {
		expect(
			outputLine( {
				k: 'call',
				depth: 1,
				name: 'orders.find',
				args: [ 7 ],
				error: 'not granted',
			} )
		).toMatchObject( {
			label: 'orders.find(7)',
			value: '',
			error: 'not granted',
		} );
	} );

	it( 'writes values in a line, marked ones as the shop marked them, long ones cut', () => {
		expect( describeDeep( { a: [ 1, 'b' ] } ) ).toBe( '{"a":[1,"b"]}' );
		expect( describeDeep( { $money: '1.50' } ) ).toBe( 'money(1.50)' );
		expect( describeDeep( 'x'.repeat( 500 ), 20 ) ).toHaveLength( 20 );
	} );

	it( 'gives each outcome a tone', () => {
		expect( outcomeTone( 'ok' ) ).toBe( 'success' );
		expect( outcomeTone( 'refused' ) ).toBe( 'info' );
		expect( outcomeTone( 'error' ) ).toBe( 'danger' );
	} );
} );
