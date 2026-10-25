import type { Answers, CountryProfile } from '../api';

/** What every step of the setup is given. */
export interface StepProps {
	answers: Answers;
	/** What the setup knows of the shop's country; null before it is chosen. */
	profile: CountryProfile | null;
	/** Keeps the step's answers and goes on to the next step. */
	save: ( answers: Record< string, unknown > ) => Promise< void >;
	next: () => void;
	fail: ( failure: unknown ) => void;
}

/**
 * A step's answers as given, or an empty object.
 *
 * @param answers Every step's answers.
 * @param step    The step's key.
 */
export function answered(
	answers: Answers,
	step: string
): Record< string, unknown > {
	return answers[ step ] ?? {};
}

/**
 * A text answer, or the default.
 *
 * @param given    The step's answers.
 * @param key      The answer's key.
 * @param fallback What it is when not given.
 */
export function text(
	given: Record< string, unknown >,
	key: string,
	fallback = ''
): string {
	const value = given[ key ];
	return typeof value === 'string' ? value : fallback;
}

/**
 * A yes/no answer, or the default.
 *
 * @param given    The step's answers.
 * @param key      The answer's key.
 * @param fallback What it is when not given.
 */
export function flag(
	given: Record< string, unknown >,
	key: string,
	fallback: boolean
): boolean {
	const value = given[ key ];
	return typeof value === 'boolean' ? value : fallback;
}
