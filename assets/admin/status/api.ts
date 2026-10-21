import apiFetch from '@wordpress/api-fetch';

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
