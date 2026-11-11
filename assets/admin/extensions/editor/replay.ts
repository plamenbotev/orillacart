/**
 * Stepping through a recording of an extension's run (design 71), forwards and backwards. The run already
 * happened: every step is in the recording, so the debugger moves over it instead of pausing the shop. Shared by
 * the Extensions screen's stepper and the VS Code debugger (tools/vscode), which imports this file.
 *
 * A position is the index of a step the debugger can stop at: a line reached, or the error the run ended with.
 */

/** A recorded value as the shop shaped it: JSON, with money, cut texts and long lists marked. */
export type RecordedValue =
	| null
	| boolean
	| number
	| string
	| RecordedValue[]
	| { [ key: string ]: RecordedValue };

export interface Step {
	k: 'enter' | 'line' | 'return' | 'unwind' | 'call' | 'debug' | 'error';
	depth: number;
	file?: string;
	line?: number;
	/** enter: the function as the script names it */
	fn?: string;
	/** line: the variables in scope there */
	vars?: Record< string, RecordedValue >;
	/** return, debug: the value */
	value?: RecordedValue;
	/** call: the host function, its arguments and answer, or the error it threw */
	name?: string;
	args?: RecordedValue[];
	result?: RecordedValue;
	error?: string | null;
	/** error: what the run ended with */
	message?: string;
}

export interface StackFrame {
	fn: string;
	file: string;
	line: number;
}

export interface Recording {
	id?: number | null;
	what: string;
	outcome: string;
	message: string | null;
	/** true when the run made more steps than a recording keeps: the end is missing */
	cut: boolean;
	/** the `.ocs` files the run ran, path → content */
	sources: Record< string, string >;
	steps: Step[];
	/** where it failed, innermost first */
	stack: StackFrame[];
}

/** A function running at a position, innermost first, with the line it is at and its variables there. */
export interface Frame {
	fn: string;
	file: string;
	line: number;
	vars: Record< string, RecordedValue >;
	/** the index of the step it is at */
	at: number;
}

/**
 * A breakpoint: `file:line`.
 *
 * @param file The file.
 * @param line The line.
 */
export const breakpoint = ( file: string, line: number ): string =>
	`${ file }:${ line }`;

export class Replay {
	readonly recording: Recording;
	/** The indexes of the steps the debugger can stop at, in order. */
	readonly stops: number[];
	private position = 0;

	constructor( recording: Recording ) {
		this.recording = recording;
		this.stops = recording.steps.flatMap( ( step, index ) =>
			step.k === 'line' || step.k === 'error' ? [ index ] : []
		);
	}

	/** The index of the step the debugger is at, or -1 for a recording without lines. */
	get at(): number {
		return this.stops[ this.position ] ?? -1;
	}

	get step(): Step | null {
		return this.recording.steps[ this.at ] ?? null;
	}

	/** How far along the run this is, as the stop's number from 0, of `length`. */
	get index(): number {
		return this.position;
	}

	get length(): number {
		return this.stops.length;
	}

	get atStart(): boolean {
		return this.position <= 0;
	}

	get atEnd(): boolean {
		return this.position >= this.stops.length - 1;
	}

	/**
	 * Moves to a stop by its number; answers whether it moved.
	 *
	 * @param index The stop's number, from 0.
	 */
	goTo( index: number ): boolean {
		const next = Math.max( 0, Math.min( this.stops.length - 1, index ) );
		const moved = next !== this.position;
		this.position = next;
		return moved;
	}

	/** The next line, into any function it calls. */
	stepIn(): boolean {
		return this.goTo( this.position + 1 );
	}

	/** The next line of this function, or of the one it returns to: calls run through; the end, past the last. */
	stepOver(): boolean {
		return (
			this.forward( ( step, depth ) => step.depth <= depth ) ||
			this.goTo( this.stops.length - 1 )
		);
	}

	/** The line after this function returns; the end when the run ended inside it. */
	stepOut(): boolean {
		return (
			this.forward( ( step, depth ) => step.depth < depth ) ||
			this.goTo( this.stops.length - 1 )
		);
	}

	/** The line before, wherever it was. */
	stepBack(): boolean {
		return this.goTo( this.position - 1 );
	}

	/** The line before in this function, or the call that led here: calls run through backwards. */
	stepBackOver(): boolean {
		return (
			this.backward( ( step, depth ) => step.depth <= depth ) ||
			this.goTo( 0 )
		);
	}

	/**
	 * On to the next breakpoint, or the end.
	 *
	 * @param breakpoints The breakpoints, as breakpoint() makes them.
	 */
	continue( breakpoints: ReadonlySet< string > ): boolean {
		return (
			this.forward( ( step ) => this.hits( step, breakpoints ) ) ||
			this.goTo( this.stops.length - 1 )
		);
	}

	/**
	 * Back to the breakpoint before, or the start.
	 *
	 * @param breakpoints The breakpoints, as breakpoint() makes them.
	 */
	reverseContinue( breakpoints: ReadonlySet< string > ): boolean {
		return (
			this.backward( ( step ) => this.hits( step, breakpoints ) ) ||
			this.goTo( 0 )
		);
	}

	/**
	 * The functions running at a step, innermost first, each at its latest line with its variables there. The
	 * recording is walked from its start, so it is right however the debugger got here.
	 *
	 * @param at The step's index; the current one when left out.
	 */
	frames( at: number = this.at ): Frame[] {
		const frames: Frame[] = [];
		const steps = this.recording.steps;
		for ( let index = 0; index <= at && index < steps.length; index++ ) {
			const step = steps[ index ];
			if ( ! step ) {
				continue;
			}
			if ( step.k === 'enter' ) {
				frames.push( {
					fn: step.fn ?? 'fn',
					file: step.file ?? '',
					line: step.line ?? 0,
					vars: {},
					at: index,
				} );
			} else if ( step.k === 'return' || step.k === 'unwind' ) {
				frames.splice( Math.max( 0, step.depth - 1 ) );
			} else if ( step.k === 'line' ) {
				const frame = frames[ step.depth - 1 ];
				if ( frame ) {
					frame.file = step.file ?? frame.file;
					frame.line = step.line ?? frame.line;
					frame.vars = step.vars ?? {};
					frame.at = index;
				}
			} else if ( step.k === 'error' ) {
				const frame = frames[ frames.length - 1 ];
				if ( frame && step.line ) {
					frame.file = step.file ?? frame.file;
					frame.line = step.line;
					frame.at = index;
				}
			}
		}
		// A run that ended in an error has left its functions by the time the error is recorded: its stack is
		// the recording's own.
		if (
			frames.length === 0 &&
			steps[ at ]?.k === 'error' &&
			this.recording.stack.length > 0
		) {
			return this.recording.stack.map( ( frame ) => ( {
				...frame,
				vars: this.lastVars( at ),
				at,
			} ) );
		}
		return frames.reverse();
	}

	/**
	 * The calls to the shop and what debug() marked, up to a step, oldest first.
	 *
	 * @param at The step's index; the current one when left out.
	 */
	output( at: number = this.at ): Step[] {
		return this.recording.steps
			.slice( 0, at + 1 )
			.filter( ( step ) => step.k === 'call' || step.k === 'debug' );
	}

	/**
	 * The source line of a step, as its file has it.
	 *
	 * @param step The step; the current one when left out.
	 */
	sourceLine( step: Step | null = this.step ): string {
		if ( ! step?.file || ! step.line ) {
			return '';
		}
		return (
			( this.recording.sources[ step.file ] ?? '' ).split( '\n' )[
				step.line - 1
			] ?? ''
		);
	}

	private hits( step: Step, breakpoints: ReadonlySet< string > ): boolean {
		return (
			step.k === 'error' ||
			( !! step.file &&
				!! step.line &&
				breakpoints.has( breakpoint( step.file, step.line ) ) )
		);
	}

	private lastVars( at: number ): Record< string, RecordedValue > {
		for ( let index = at; index >= 0; index-- ) {
			const step = this.recording.steps[ index ];
			if ( step?.k === 'line' ) {
				return step.vars ?? {};
			}
		}
		return {};
	}

	private forward(
		matches: ( step: Step, depth: number ) => boolean
	): boolean {
		const depth = this.step?.depth ?? 0;
		for ( let next = this.position + 1; next < this.stops.length; next++ ) {
			const step = this.recording.steps[ this.stops[ next ] ?? -1 ];
			if ( step && matches( step, depth ) ) {
				return this.goTo( next );
			}
		}
		return false;
	}

	private backward(
		matches: ( step: Step, depth: number ) => boolean
	): boolean {
		const depth = this.step?.depth ?? 0;
		for ( let previous = this.position - 1; previous >= 0; previous-- ) {
			const step = this.recording.steps[ this.stops[ previous ] ?? -1 ];
			if ( step && matches( step, depth ) ) {
				return this.goTo( previous );
			}
		}
		return false;
	}
}

/**
 * A recorded value in a line of text: what a variables panel shows next to its name.
 *
 * @param value The recorded value.
 */
export function describe( value: RecordedValue | undefined ): string {
	if ( value === undefined ) {
		return 'undefined';
	}
	if ( value === null ) {
		return 'null';
	}
	if ( typeof value === 'string' ) {
		return JSON.stringify( value );
	}
	if ( typeof value !== 'object' ) {
		return String( value );
	}
	if ( Array.isArray( value ) ) {
		return `list (${ value.length })`;
	}
	if ( '$money' in value ) {
		return `money(${ String( value.$money ) })`;
	}
	if ( '$text' in value ) {
		return `${ JSON.stringify( value.$text ) }… (${ String(
			value.length
		) } characters)`;
	}
	if ( '$items' in value ) {
		return `${ value.list ? 'list' : 'map' } (${ String(
			value.count
		) }, the first shown)`;
	}
	if ( '$more' in value ) {
		return `${ value.list ? 'list' : 'map' } (${ String(
			value.$more
		) }, too deep to show)`;
	}
	if ( '$fn' in value ) {
		return 'fn';
	}
	if ( '$bytes' in value ) {
		return `bytes (${ String( value.$bytes ) })`;
	}
	if ( '$value' in value ) {
		return String( value.$value );
	}
	return `map (${ Object.keys( value ).length })`;
}

/**
 * The parts of a value a tree opens into: a list's items, a map's keys; none for a plain value.
 *
 * @param value The recorded value.
 */
export function children(
	value: RecordedValue | undefined
): Array< [ string, RecordedValue ] > {
	if ( value === null || typeof value !== 'object' ) {
		return [];
	}
	if ( Array.isArray( value ) ) {
		return value.map( ( item, index ) => [ String( index ), item ] );
	}
	if ( '$items' in value ) {
		return children( value.$items );
	}
	if (
		'$money' in value ||
		'$text' in value ||
		'$more' in value ||
		'$fn' in value ||
		'$bytes' in value ||
		'$value' in value
	) {
		return [];
	}
	return Object.entries( value );
}
