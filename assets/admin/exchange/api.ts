import apiFetch from '@wordpress/api-fetch';

import type { ExportColumn } from '../shared/exports';

/** A products import, as the screen shows it. */
export interface ProductImport {
	id: number;
	filename: string;
	status: 'ready' | 'running' | 'done' | 'failed';
	/** The file's columns the import reads. */
	columns: ExportColumn[];
	/** The file's columns it does not: unknown, or only exported. */
	ignored: string[];
	rows: number;
	done: number;
	created: number;
	updated: number;
	failed: number;
	/** "Row 7: …", the first two hundred. */
	errors: string[];
	created_at: string;
	finished_at: string | null;
	/** Running with no job left to go on with it: it can only be discarded. */
	stalled: boolean;
}

const BASE = '/orillacart/v1/imports/products';

export function latestImport(): Promise< ProductImport | null > {
	return apiFetch< { import: ProductImport | null } >( {
		path: `${ BASE }/latest`,
	} ).then( ( answer ) => answer.import );
}

/**
 * @param id The import.
 */
export function fetchImport( id: number ): Promise< ProductImport > {
	return apiFetch< ProductImport >( { path: `${ BASE }/${ id }` } );
}

/**
 * Reads the file, ready to start.
 *
 * @param file The products file.
 */
export function uploadImport( file: File ): Promise< ProductImport > {
	const body = new FormData();
	body.append( 'file', file );

	return apiFetch< ProductImport >( { path: BASE, method: 'POST', body } );
}

/**
 * @param id The import.
 */
export function startImport( id: number ): Promise< ProductImport > {
	return apiFetch< ProductImport >( {
		path: `${ BASE }/${ id }/start`,
		method: 'POST',
	} );
}

/**
 * @param id The import.
 */
export function discardImport( id: number ): Promise< unknown > {
	return apiFetch( { path: `${ BASE }/${ id }`, method: 'DELETE' } );
}
