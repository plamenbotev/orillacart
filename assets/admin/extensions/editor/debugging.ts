/**
 * What the Extensions screen's debugger (design 71) does with a recording beyond stepping: the commands its
 * toolbar and keys give, the keys themselves, and the lines its output and call stack show. The stepping is
 * replay.ts's, shared with the VS Code debugger.
 */
import { breakpoint, describe, type Replay, type Step } from './replay';

export type Command =
	| 'start'
	| 'back'
	| 'backOver'
	| 'in'
	| 'over'
	| 'out'
	| 'continue'
	| 'reverse'
	| 'end';

/**
 * Moves a replay by a command; answers whether it moved.
 *
 * @param replay      The replay.
 * @param command     The command.
 * @param breakpoints The breakpoints, as `breakpoint(file, line)`, for continue and reverse continue.
 */
export function perform(
	replay: Replay,
	command: Command,
	breakpoints: ReadonlySet< string >
): boolean {
	switch ( command ) {
		case 'start':
			return replay.goTo( 0 );
		case 'back':
			return replay.stepBack();
		case 'backOver':
			return replay.stepBackOver();
		case 'in':
			return replay.stepIn();
		case 'over':
			return replay.stepOver();
		case 'out':
			return replay.stepOut();
		case 'continue':
			return replay.continue( breakpoints );
		case 'reverse':
			return replay.reverseContinue( breakpoints );
		case 'end':
			return replay.goTo( replay.length - 1 );
	}
	return false;
}

/**
 * Whether a command moves backwards: disabled at the start, as the forward ones are at the end.
 *
 * @param command The command.
 */
export const backwards = ( command: Command ): boolean =>
	command === 'start' ||
	command === 'back' ||
	command === 'backOver' ||
	command === 'reverse';

/**
 * The debugger's keys: F10 over, F11 in, Shift+F11 out, F5 continue, Shift+F5 reverse continue, F8 back, Shift+F8
 * back over, F9 toggles a breakpoint at the line shown.
 *
 * @param event          The key pressed.
 * @param event.key      Its name, as KeyboardEvent has it.
 * @param event.shiftKey Whether Shift was held.
 * @param event.ctrlKey  Whether Control was held: such keys are the browser's.
 * @param event.altKey   Whether Alt was held: such keys are the browser's.
 * @param event.metaKey  Whether Meta was held: such keys are the browser's.
 */
export function commandForKey( event: {
	key: string;
	shiftKey: boolean;
	ctrlKey?: boolean;
	altKey?: boolean;
	metaKey?: boolean;
} ): Command | 'toggle' | null {
	if ( event.ctrlKey || event.altKey || event.metaKey ) {
		return null;
	}
	const shifted = event.shiftKey;
	switch ( event.key ) {
		case 'F5':
			return shifted ? 'reverse' : 'continue';
		case 'F8':
			return shifted ? 'backOver' : 'back';
		case 'F9':
			return shifted ? null : 'toggle';
		case 'F10':
			return shifted ? null : 'over';
		case 'F11':
			return shifted ? 'out' : 'in';
	}
	return null;
}

/**
 * Adds a breakpoint, or removes it when it is set; a new set, for React's state.
 *
 * @param breakpoints The breakpoints.
 * @param file        The file.
 * @param line        The line.
 */
export function toggled(
	breakpoints: ReadonlySet< string >,
	file: string,
	line: number
): Set< string > {
	const next = new Set( breakpoints );
	const point = breakpoint( file, line );
	if ( next.has( point ) ) {
		next.delete( point );
	} else {
		next.add( point );
	}
	return next;
}

/**
 * The lines of a file with a breakpoint, in order.
 *
 * @param breakpoints The breakpoints.
 * @param file        The file.
 */
export function linesOf(
	breakpoints: ReadonlySet< string >,
	file: string
): number[] {
	const prefix = `${ file }:`;
	return [ ...breakpoints ]
		.filter( ( point ) => point.startsWith( prefix ) )
		.map( ( point ) => Number( point.slice( prefix.length ) ) )
		.filter( ( line ) => Number.isInteger( line ) && line > 0 )
		.sort( ( a, b ) => a - b );
}

/** A line of the debugger's output: what was asked or marked, and what came back. */
export interface OutputLine {
	kind: 'call' | 'debug';
	/** `http.get("https://…")`, `debug`. */
	label: string;
	/** The answer, or the value debug() was given. */
	value: string;
	/** The error the call threw, if it did. */
	error: string | null;
	/** `price.ocs:3`, where the step was, if the recording says. */
	where: string;
}

/**
 * A call to the shop or a debug() value, as the output list shows it.
 *
 * @param step The recorded step: a call or a debug.
 */
export function outputLine( step: Step ): OutputLine {
	const where = step.file && step.line ? `${ step.file }:${ step.line }` : '';
	if ( step.k === 'debug' ) {
		return {
			kind: 'debug',
			label: 'debug',
			value: describeDeep( step.value ),
			error: null,
			where,
		};
	}
	return {
		kind: 'call',
		label: `${ step.name ?? '?' }(${ ( step.args ?? [] )
			.map( ( arg ) => describeDeep( arg ) )
			.join( ', ' ) })`,
		value: step.error ? '' : describeDeep( step.result ),
		error: step.error ?? null,
		where,
	};
}

/**
 * A recorded value in one line, as far as it goes: plain values as describe() has them, lists and maps as JSON,
 * cut short past a length.
 *
 * @param value The value.
 * @param limit The most characters shown.
 */
export function describeDeep(
	value: Step[ 'value' ] | undefined,
	limit = 200
): string {
	// A recording is JSON as the shop wrote it: no cycles to stop stringify.
	const text =
		value !== null && typeof value === 'object' && ! isMarked( value )
			? JSON.stringify( value )
			: describe( value );
	return text.length > limit ? `${ text.slice( 0, limit - 1 ) }…` : text;
}

const MARKS = [
	'$money',
	'$text',
	'$items',
	'$more',
	'$fn',
	'$bytes',
	'$value',
];

function isMarked( value: object ): boolean {
	return ! Array.isArray( value ) && MARKS.some( ( mark ) => mark in value );
}

/**
 * A tone for a run's outcome, as the screen's badges have them.
 *
 * @param outcome The outcome: ok, refused, unavailable, error, stopped.
 */
export function outcomeTone(
	outcome: string
): 'success' | 'info' | 'warning' | 'danger' {
	if ( outcome === 'ok' ) {
		return 'success';
	}
	if ( outcome === 'refused' ) {
		return 'info';
	}
	if ( outcome === 'unavailable' ) {
		return 'warning';
	}
	return 'danger';
}
