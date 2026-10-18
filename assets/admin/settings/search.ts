import apiFetch from '@wordpress/api-fetch';

export type Engine = 'database' | 'opensearch';

/** The search engine, how far behind its index is, and what the last change found. */
export interface SearchEngine {
	engine: Engine;
	host: string;
	username: string;
	/** The password never comes back; this says whether one is saved. */
	password_set: boolean;
	index: string;
	/** Whether OpenSearch answers shoppers: its index was built. */
	ready: boolean;
	due: number;
	later: number;
	/** Products that failed too often, with the last error, by product id. */
	parked: Record< string, string >;
	next_at: string | null;
	works: boolean | null;
	message: string;
	/** Workers that run all the time (wp orillacart jobs work --loop), seen lately. */
	workers: number;
}

export interface SearchEngineInput {
	engine: Engine;
	host: string;
	username: string;
	password: string;
	index: string;
}

const PATH = '/orillacart/v1/search-engine';

export function fetchSearchEngine(): Promise< SearchEngine > {
	return apiFetch< SearchEngine >( { path: PATH } );
}

/**
 * Switches after reaching OpenSearch; the answer says what the check found.
 * @param input
 */
export function changeSearchEngine(
	input: SearchEngineInput
): Promise< SearchEngine > {
	return apiFetch< SearchEngine >( {
		path: PATH,
		method: 'PUT',
		data: input,
	} );
}

export function rebuildSearchIndex(): Promise< SearchEngine > {
	return apiFetch< SearchEngine >( {
		path: `${ PATH }/rebuild`,
		method: 'POST',
	} );
}
