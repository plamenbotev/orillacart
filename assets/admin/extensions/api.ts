import apiFetch from '@wordpress/api-fetch';

import type { Recording } from './editor/replay';

const BASE = '/orillacart/v1';

export type ExtensionState = 'draft' | 'enabled' | 'disabled' | 'stopped';

export interface ExtensionFile {
	path: string;
	/**
	 * Null in an answer that leaves it out (a save answers with the file saved only; a check, a delete or a
	 * settings save with none): as it was when last read. withContents() fills it in.
	 */
	content: string | null;
	updated_at: string;
	/** The shop's template this file overrides, when it does. */
	overrides?: string | null;
	/** Whether a release changed that template since the override was saved. */
	stale?: boolean;
}

/**
 * The extension as answered, with each file's content left out taken from the copy read before.
 *
 * @param next   The extension as answered.
 * @param before The extension as read before, or null.
 */
export function withContents(
	next: Extension,
	before: Extension | null
): Extension {
	if ( next.files === undefined || next.files === null ) {
		return next;
	}
	const known = new Map(
		( before?.id === next.id ? ( before.files ?? [] ) : [] ).map(
			( file ) => [ file.path, file.content ]
		)
	);
	return {
		...next,
		files: next.files.map( ( file ) =>
			file.content === null
				? { ...file, content: known.get( file.path ) ?? null }
				: file
		),
	};
}

export interface ExtensionVersion {
	number: number;
	note: string;
	created_at: string;
	checksum: string;
	files: string[];
	current: boolean;
}

export interface ExtensionRun {
	at: string;
	what: string;
	outcome: 'ok' | 'refused' | 'unavailable' | 'error' | 'stopped';
	message: string | null;
	used: Record< string, number >;
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
	/** The routes the scripts declare, when they compile; the editor completes their paths. */
	routes?: ExtensionRoute[] | null;
}

export interface ExtensionRoute {
	method: string;
	path: string;
	who: string;
}

/** A function on a failed run's stack: innermost first. */
export interface StackEntry {
	fn: string;
	file: string;
	line: number;
}

/** A recording as a debugged run answers it (design 71): its id once kept, null when it was not. */
export type DebugRecording = Recording & { id: number | null };

export interface RunResult {
	ok: boolean;
	result: unknown;
	failure: { kind: string; message: string; data: unknown } | null;
	stopped: string | null;
	used: Record< string, number >;
	/** Where it failed, innermost first; empty for a run that did not. */
	stack?: StackEntry[];
	/** The run's recording, when it was debugged. */
	recording?: DebugRecording | null;
}

export interface TestResult {
	name: string;
	ok: boolean;
	message: string | null;
	file: string | null;
	line: number | null;
	http: { method: string; url: string; status: number }[];
	jobs: { name: string; payload: unknown; at: string | null }[];
	used: Record< string, number >;
	effects?: unknown;
	/** Where it failed, innermost first. */
	stack?: StackEntry[];
	/** The test's recording, for the one test debugged. */
	recording?: DebugRecording | null;
}

/** A recording kept for the debugger, as the list shows it. */
export interface RecordingSummary {
	id: number;
	source: 'test' | 'console' | 'live';
	what: string;
	outcome: string;
	message: string | null;
	/** How many steps it holds. */
	steps: number;
	recorded_at: string;
}

/** The live capture armed for an extension: what it records, how many runs are left, until when. */
export interface Capture {
	what: string | null;
	left: number;
	until: string;
}

/** A recording as opened: its summary with the whole run. */
export type KeptRecording = Omit< RecordingSummary, 'steps' > &
	Recording & { id: number };

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

/**
 * Runs one of the extension's functions from the console.
 *
 * @param id    The extension's id.
 * @param fn    The function's name.
 * @param args  Its arguments.
 * @param debug Whether to record the run for the debugger.
 */
export const runFunction = (
	id: string,
	fn: string,
	args: unknown[],
	debug = false
) =>
	apiFetch< RunResult >( {
		path: `${ BASE }/extensions/${ id }/run`,
		method: 'POST',
		data: debug
			? { function: fn, arguments: args, debug: true }
			: { function: fn, arguments: args },
	} );

/**
 * Runs the extension's own tests, over the files given (the editor's buffers) or the saved ones.
 *
 * @param id    The extension's id.
 * @param files The files by path, in place of the saved ones.
 * @param debug A test's name: that test is recorded for the debugger.
 */
export const runTests = (
	id: string,
	files?: Record< string, string >,
	debug?: string
) =>
	apiFetch< { tests: TestResult[] } >( {
		path: `${ BASE }/extensions/${ id }/test`,
		method: 'POST',
		data: {
			...( files ? { files } : {} ),
			...( debug ? { debug } : {} ),
		},
	} );

/**
 * The recordings kept of the extension's runs, newest first, and its live capture, if one is armed.
 *
 * @param id The extension's id.
 */
export const fetchRecordings = ( id: string ) =>
	apiFetch< { recordings: RecordingSummary[]; capture: Capture | null } >( {
		path: `${ BASE }/extensions/${ id }/recordings`,
	} );

/**
 * One recording, whole.
 *
 * @param id        The extension's id.
 * @param recording The recording's id.
 */
export const fetchRecording = ( id: string, recording: number ) =>
	apiFetch< KeptRecording >( {
		path: `${ BASE }/extensions/${ id }/recordings/${ recording }`,
	} );

export const deleteRecording = ( id: string, recording: number ) =>
	apiFetch< { deleted: boolean } >( {
		path: `${ BASE }/extensions/${ id }/recordings/${ recording }`,
		method: 'DELETE',
	} );

/**
 * Records the extension's next real runs (live): customers' data among what they hold.
 *
 * @param id              The extension's id.
 * @param capture         What to record: the handlers whose name starts with `what`, how many runs, for how long.
 * @param capture.what    The handlers' names start with this; every handler when empty.
 * @param capture.runs    How many runs, 1 to 20.
 * @param capture.minutes For how many minutes at most, 1 to 60.
 */
export const startCapture = (
	id: string,
	capture: { what?: string; runs: number; minutes: number }
) =>
	apiFetch< { capture: Capture | null } >( {
		path: `${ BASE }/extensions/${ id }/capture`,
		method: 'POST',
		data: capture.what
			? capture
			: { runs: capture.runs, minutes: capture.minutes },
	} );

export const stopCapture = ( id: string ) =>
	apiFetch< { capture: null } >( {
		path: `${ BASE }/extensions/${ id }/capture`,
		method: 'DELETE',
	} );

export const fetchVersions = ( id: string ) =>
	apiFetch< { versions: ExtensionVersion[] } >( {
		path: `${ BASE }/extensions/${ id }/versions`,
	} );

export const restoreVersion = ( id: string, number: number ) =>
	apiFetch< Extension >( {
		path: `${ BASE }/extensions/${ id }/versions/${ number }/restore`,
		method: 'POST',
	} );

/** One of the extension's words, with its forms in each other language. */
export interface ExtensionWord {
	key: string;
	text: string;
	plural: string | null;
	context: string;
	/** Where it stands: `main.ocs, line 12`, `settings: merchant_id, label`. */
	where: string[];
	/** Its forms by locale: as many as the language has for a plural, one otherwise. */
	translations: Record< string, string[] >;
}

/** A language to translate into, with how many plural forms it has. */
export interface WordLanguage {
	locale: string;
	name: string;
	forms: number;
}

export interface ExtensionWords {
	languages: WordLanguage[];
	words: ExtensionWord[];
}

export const fetchTranslations = ( id: string ) =>
	apiFetch< ExtensionWords >( {
		path: `${ BASE }/extensions/${ id }/translations`,
	} );

/**
 * Saves translations by word key and locale; the words and languages not sent keep theirs.
 *
 * @param id           The extension's id.
 * @param translations The forms by word key, then locale.
 */
export const saveTranslations = (
	id: string,
	translations: Record< string, Record< string, string[] > >
) =>
	apiFetch< ExtensionWords >( {
		path: `${ BASE }/extensions/${ id }/translations`,
		method: 'PUT',
		data: { translations },
	} );

export const fetchRuns = ( id: string ) =>
	apiFetch< { runs: ExtensionRun[] } >( {
		path: `${ BASE }/extensions/${ id }/runs`,
	} );

/**
 * The shop's own source of the template a file overrides; null for a file that overrides nothing.
 *
 * @param id   The extension's id.
 * @param path The file's path.
 */
export const fetchOriginal = ( id: string, path: string ) =>
	apiFetch< { original: string | null } >( {
		path: `${ BASE }/extensions/${ id }/files/${ encodeURIComponent( path ) }/original`,
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

/**
 * Installs an extension from a zip of its files.
 *
 * @param file The zip.
 */
export const uploadExtension = ( file: File ) => {
	const body = new FormData();
	body.append( 'file', file );
	return apiFetch< { extension: Extension; skipped: string[] } >( {
		path: `${ BASE }/extensions/upload`,
		method: 'POST',
		body,
	} );
};

/**
 * The extension as a zip, base64, with its file name.
 *
 * @param id The extension's id.
 */
export const fetchArchive = ( id: string ) =>
	apiFetch< { filename: string; content: string; mime_type: string } >( {
		path: `${ BASE }/extensions/${ id }/archive`,
	} );

export const fetchReference = () =>
	apiFetch< Reference >( { path: `${ BASE }/script-reference` } );
