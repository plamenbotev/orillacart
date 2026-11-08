import apiFetch from '@wordpress/api-fetch';

const BASE = '/orillacart/v1';

/** The shop's kinds an extension declares fields on, as the REST paths name them. */
export type RecordKind = 'products' | 'orders' | 'customers';

/** One field an extension declared, as its declaration describes it. */
export interface FieldSpec {
	name: string;
	kind: string;
	label: string;
	help: string | null;
	required: boolean;
	default: unknown;
	/** The choices of a `choice`. */
	of: string[];
	/** Shown, written by the extension's scripts only. */
	readonly: boolean;
	filter: boolean;
	column: boolean;
}

/** The fields one extension declared on a kind: a block of the record's "Extensions" section. */
export interface FieldSection {
	extension: string;
	name: string;
	fields: FieldSpec[];
}

/** A record's values, by extension and then by field. */
export type ExtensionValues = Record< string, Record< string, unknown > >;

/**
 * The fields the enabled extensions declare on a kind.
 *
 * @param kind The kind.
 * @return The sections, one per extension that declares any.
 */
export function fetchRecordFields(
	kind: RecordKind
): Promise< FieldSection[] > {
	return apiFetch< { sections?: FieldSection[] } | unknown[] >( {
		path: `${ BASE }/record-fields/${ kind }`,
	} ).then( ( answer ) =>
		! Array.isArray( answer ) && Array.isArray( answer?.sections )
			? answer.sections
			: []
	);
}

/**
 * Saves an extension's fields on a record; the fields sent change, null clears one.
 *
 * @param kind      The record's kind.
 * @param id        The record's id, or the order's number.
 * @param extension The extension's id.
 * @param fields    The values by field.
 * @return The extension's fields on the record afterwards.
 */
export function saveRecordFields(
	kind: RecordKind,
	id: number,
	extension: string,
	fields: Record< string, unknown >
): Promise< Record< string, unknown > > {
	return apiFetch< { fields: Record< string, unknown > } >( {
		path: `${ BASE }/${ kind }/${ id }/extensions/${ extension }`,
		method: 'PUT',
		data: { fields },
	} ).then( ( answer ) => answer.fields );
}
