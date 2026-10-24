import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

export interface Check {
	id: string;
	check: string;
	status: 'good' | 'recommended' | 'critical';
	finding: string;
	description: string;
	action_url: string;
	action_label: string;
}

export interface Section {
	title: string;
	fields: { label: string; value: string }[];
}

/** How the shop stands, as GET /orillacart/v1/status returns it. */
export interface Status {
	checks: Check[];
	sections: Section[];
}

export function fetchStatus(): Promise< Status > {
	return apiFetch< Status >( { path: '/orillacart/v1/status' } );
}

/**
 * The report as plain text, for a support request.
 *
 * @param status The status.
 */
export function reportText( status: Status ): string {
	const lines = [ '### Checks', '' ];
	for ( const check of status.checks ) {
		lines.push(
			`${ check.check }: ${ check.status } — ${ check.finding }`
		);
	}
	for ( const section of status.sections ) {
		lines.push( '', `### ${ section.title }`, '' );
		for ( const field of section.fields ) {
			lines.push( `${ field.label }: ${ field.value }` );
		}
	}
	return lines.join( '\n' );
}

export interface ChangeRecord {
	id: number;
	user_id: number | null;
	user: string;
	area: string;
	route: string;
	path: string;
	values: Record< string, unknown >;
	made_at: string;
}

/** A page of the change log, as GET /orillacart/v1/change-log returns it. */
export interface ChangeLogPage {
	records: ChangeRecord[];
	total: number;
	page: number;
	pages: number;
	areas: string[];
}

export function fetchChangeLog(
	area: string,
	page: number
): Promise< ChangeLogPage > {
	return apiFetch< ChangeLogPage >( {
		path: addQueryArgs( '/orillacart/v1/change-log', {
			...( area === '' ? {} : { area } ),
			page,
		} ),
	} );
}

/** Something that went wrong which the shop should hear of, the same message once with how often it happened. */
export interface Problem {
	id: number;
	level: 'warning' | 'error' | 'critical' | 'alert' | 'emergency';
	message: string;
	count: number;
	first_at: string;
	last_at: string;
}

/** A page of the problems, as GET /orillacart/v1/problems returns it: the latest seen first. */
export interface ProblemPage {
	problems: Problem[];
	total: number;
	page: number;
	per_page: number;
}

export function fetchProblems( page: number ): Promise< ProblemPage > {
	return apiFetch< ProblemPage >( {
		path: addQueryArgs( '/orillacart/v1/problems', { page } ),
	} );
}

export function clearProblems(): Promise< void > {
	return apiFetch( {
		path: '/orillacart/v1/problems',
		method: 'DELETE',
		parse: false,
	} ).then( () => undefined );
}
