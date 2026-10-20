import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

declare global {
	interface Window {
		/** Where exports download from, and the nonce for it; printed by AdminScripts. */
		orillacartExport?: { url: string; nonce: string };
	}
}

export type ExportKind = 'orders' | 'products';

/** A column the shop can export: its own or an add-on's. */
export interface ExportColumn {
	id: string;
	label: string;
	add_on: boolean;
	chosen: boolean;
	/** For products: whether the import reads it back. */
	importable: boolean;
}

const BASE = '/orillacart/v1/exports';

/**
 * The columns, the chosen ones first in their order.
 *
 * @param kind What is exported.
 */
export function fetchExportColumns(
	kind: ExportKind
): Promise< ExportColumn[] > {
	return apiFetch< ExportColumn[] >( {
		path: `${ BASE }/${ kind }/columns`,
	} );
}

/**
 * Keeps the columns to export, in this order.
 *
 * @param kind    What is exported.
 * @param columns The columns' ids.
 */
export function chooseExportColumns(
	kind: ExportKind,
	columns: string[]
): Promise< ExportColumn[] > {
	return apiFetch< ExportColumn[] >( {
		path: `${ BASE }/${ kind }/columns`,
		method: 'PUT',
		data: { columns },
	} );
}

/**
 * The address the file downloads from, with the list's filters.
 *
 * @param kind   What is exported.
 * @param format csv or json.
 * @param query  The filters, as the list asks the API with them.
 */
export function exportUrl(
	kind: ExportKind | 'subscribers',
	format: string,
	query: Record< string, unknown >
): string {
	const target = window.orillacartExport ?? { url: '', nonce: '' };
	const filters = Object.fromEntries(
		Object.entries( query ).filter(
			( [ name, value ] ) =>
				value !== undefined &&
				value !== null &&
				value !== '' &&
				name !== 'page' &&
				name !== 'per_page'
		)
	);

	return addQueryArgs( target.url, {
		...filters,
		action: `orillacart_export_${ kind }`,
		format,
		_wpnonce: target.nonce,
	} );
}
