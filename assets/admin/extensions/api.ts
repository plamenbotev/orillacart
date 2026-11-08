import apiFetch from '@wordpress/api-fetch';

const BASE = '/orillacart/v1';

export type ExtensionState = 'draft' | 'enabled' | 'disabled' | 'stopped';

export interface ExtensionFile {
	path: string;
	content: string;
	updated_at: string;
}

/** A declared setting with its shown value: secrets come as whether one is set. */
export interface ExtensionSetting {
	key: string;
	label: string;
	type: 'text' | 'secret' | 'boolean' | 'number' | 'choice';
	required: boolean;
	help: string;
	choices: Record< string, string >;
	value: string | boolean | null;
	set?: boolean;
}

export interface Extension {
	id: string;
	name: string;
	version: string;
	state: ExtensionState;
	/** The files changed since it was enabled: it runs the old ones until enabled again. */
	stale: boolean;
	granted: string[];
	needs: string[];
	failures: number;
	last_problem: string | null;
	created_at: string;
	updated_at: string;
	files: ExtensionFile[] | null;
	settings: ExtensionSetting[] | null;
	findings: string[] | null;
}

export interface RunResult {
	ok: boolean;
	result: unknown;
	failure: { kind: string; message: string; data: unknown } | null;
	stopped: string | null;
	used: Record< string, number >;
}

export interface Reference {
	language: string;
	declarations: Record< string, string >;
	functions: {
		name: string;
		summary: string;
		arguments: [ number, number | null ];
		needs: string | null;
	}[];
	filters: string[];
	tests: string[];
	keywords: string[];
	slots: string[];
}

export const fetchExtensions = () =>
	apiFetch< { extensions: Extension[] } >( {
		path: `${ BASE }/extensions`,
	} ).then( ( data ) => data.extensions );

export const fetchExtension = ( id: string ) =>
	apiFetch< Extension >( { path: `${ BASE }/extensions/${ id }` } );

export const installExtension = (
	id: string,
	name: string,
	files: Record< string, string > = {}
) =>
	apiFetch< Extension >( {
		path: `${ BASE }/extensions`,
		method: 'POST',
		data: { id, name, files },
	} );

export const writeFile = ( id: string, path: string, content: string ) =>
	apiFetch< Extension >( {
		path: `${ BASE }/extensions/${ id }/files/${ encodeURIComponent( path ) }`,
		method: 'PUT',
		data: { content },
	} );

export const deleteFile = ( id: string, path: string ) =>
	apiFetch< Extension >( {
		path: `${ BASE }/extensions/${ id }/files/${ encodeURIComponent( path ) }`,
		method: 'DELETE',
	} );

export interface Finding {
	file: string;
	line: number;
	column: number;
	message: string;
}

/**
 * The static check over files as they are in the editor, saved nowhere.
 *
 * @param files The files by path.
 * @param id    The extension's id, for the scope of the check.
 */
export const checkFiles = ( files: Record< string, string >, id?: string ) =>
	apiFetch< { findings: Finding[] } >( {
		path: `${ BASE }/script/check`,
		method: 'POST',
		data: { files, id },
	} );

export const checkExtension = ( id: string ) =>
	apiFetch< Extension >( {
		path: `${ BASE }/extensions/${ id }/check`,
		method: 'POST',
	} );

export const enableExtension = ( id: string, grant: string[] ) =>
	apiFetch< Extension >( {
		path: `${ BASE }/extensions/${ id }/enable`,
		method: 'POST',
		data: { grant },
	} );

export const disableExtension = ( id: string ) =>
	apiFetch< Extension >( {
		path: `${ BASE }/extensions/${ id }/disable`,
		method: 'POST',
	} );

export const uninstallExtension = ( id: string ) =>
	apiFetch< void >( {
		path: `${ BASE }/extensions/${ id }`,
		method: 'DELETE',
	} );

export const saveSettings = (
	id: string,
	settings: Record< string, string | boolean >
) =>
	apiFetch< Extension >( {
		path: `${ BASE }/extensions/${ id }/settings`,
		method: 'PUT',
		data: { settings },
	} );

export const runFunction = ( id: string, fn: string, args: unknown[] ) =>
	apiFetch< RunResult >( {
		path: `${ BASE }/extensions/${ id }/run`,
		method: 'POST',
		data: { function: fn, arguments: args },
	} );

export const exportExtension = ( id: string ) =>
	apiFetch< {
		id: string;
		name: string;
		version: string;
		files: Record< string, string >;
		format: string;
	} >( {
		path: `${ BASE }/extensions/${ id }/export`,
	} );

export const importExtension = ( payload: unknown ) =>
	apiFetch< Extension >( {
		path: `${ BASE }/extensions/import`,
		method: 'POST',
		data: payload as object,
	} );

export const fetchReference = () =>
	apiFetch< Reference >( { path: `${ BASE }/script-reference` } );
