import recorded from './recording.json';
import {
	breakpoint,
	children,
	describe as describeValue,
	Replay,
	type Recording,
} from '../replay';

// recording.json: a run of total() over two items recorded by the shop (design 71); the second item's price
// fails in price.ocs at line 6 after asking an API.
const recording = recorded as unknown as Recording;
const where = ( replay: Replay ) =>
	`${ replay.step?.file }:${ replay.step?.line }`;

describe( 'Replay', () => {
	it( 'starts at the first line and steps into the function a line calls', () => {
		const replay = new Replay( recording );
		expect( where( replay ) ).toBe( 'main.ocs:4' );
		replay.stepIn();
		replay.stepIn();
		replay.stepIn();
		expect( where( replay ) ).toBe( 'price.ocs:2' );
		expect(
			replay.frames().map( ( frame ) => `${ frame.fn }@${ frame.line }` )
		).toEqual( [ 'price@2', 'total@6' ] );
		expect( replay.frames()[ 0 ]?.vars ).toEqual( {
			item: { unit: 2, qty: 3 },
		} );
	} );

	it( 'steps over a call, out of a function, and back', () => {
		const replay = new Replay( recording );
		replay.goTo( 2 );
		expect( where( replay ) ).toBe( 'main.ocs:6' );
		replay.stepOver();
		expect( where( replay ) ).toBe( 'main.ocs:6' );
		expect( replay.frames()[ 0 ]?.vars.sum ).toBe( 6 );

		replay.stepBackOver();
		expect( where( replay ) ).toBe( 'main.ocs:6' );
		expect( replay.frames()[ 0 ]?.vars.sum ).toBe( 0 );

		replay.stepIn();
		replay.stepOut();
		expect( where( replay ) ).toBe( 'main.ocs:6' );
		replay.stepBack();
		expect( where( replay ) ).toBe( 'price.ocs:8' );
	} );

	it( 'runs to breakpoints forwards and backwards, and stops at the error', () => {
		const replay = new Replay( recording );
		const points = new Set( [ breakpoint( 'price.ocs', 5 ) ] );
		replay.continue( points );
		expect( where( replay ) ).toBe( 'price.ocs:5' );
		replay.continue( points );
		expect( replay.step?.k ).toBe( 'error' );
		expect( replay.atEnd ).toBe( true );

		replay.reverseContinue( new Set( [ breakpoint( 'main.ocs', 6 ) ] ) );
		expect( where( replay ) ).toBe( 'main.ocs:6' );
		expect( replay.frames()[ 0 ]?.vars.item ).toEqual( {
			unit: 9,
			qty: 1,
		} );
	} );

	it( 'shows the stack the run failed with, and what it asked the shop', () => {
		const replay = new Replay( recording );
		replay.goTo( replay.length - 1 );
		expect(
			replay
				.frames()
				.map(
					( frame ) => `${ frame.fn }@${ frame.file }:${ frame.line }`
				)
		).toEqual( [ 'price@price.ocs:6', 'total@main.ocs:6' ] );
		expect( replay.frames()[ 0 ]?.vars.answer ).toEqual( { status: 503 } );
		expect(
			replay.output().map( ( step ) => step.name ?? step.value )
		).toEqual( [ 2, 9, 'http.get' ] );
		expect( replay.sourceLine() ).toContain( 'fail error' );
	} );

	it( 'steps over the last line to the end', () => {
		const replay = new Replay( recording );
		replay.goTo( 7 );
		expect( where( replay ) ).toBe( 'main.ocs:6' );
		replay.stepOver();
		expect( replay.atEnd ).toBe( true );
	} );
} );

describe( 'recorded values', () => {
	it( 'reads what the shop marked', () => {
		expect( describeValue( { $money: '12.50' } ) ).toBe( 'money(12.50)' );
		expect( describeValue( { $text: 'abc', length: 900 } ) ).toBe(
			'"abc"… (900 characters)'
		);
		expect( describeValue( [ 1, 2 ] ) ).toBe( 'list (2)' );
		expect( describeValue( { a: 1 } ) ).toBe( 'map (1)' );
		expect(
			children( { $items: [ 1, 2 ], count: 80, list: true } )
		).toEqual( [
			[ '0', 1 ],
			[ '1', 2 ],
		] );
		expect( children( { $money: '1.00' } ) ).toEqual( [] );
	} );
} );
