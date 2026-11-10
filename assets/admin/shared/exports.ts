import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

declare global {
	interface Window {
		/** Where exports and files download from, and a nonce for each action; printed by AdminScripts. */
		orillacartExport?: { url: string; nonces: Record< string, string > };
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
 * The address of a download from admin-post.php, with the nonce of its own action.
 *
 * @param action The admin-post action.
 * @param args   What else the address carries.
 */
function downloadUrl(
	action: string,
	args: Record< string, unknown >
): string {
	const target = window.orillacartExport ?? { url: '', nonces: {} };

	return addQueryArgs( target.url, {
		...args,
		action,
		_wpnonce: target.nonces[ action ] ?? '',
	} );
}

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

	return downloadUrl( `orillacart_export_${ kind }`, { ...filters, format } );
}

/**
 * The address the copy of a customer's tax exemption certificate downloads from, for shop managers.
 *
 * @param customerId  The customer.
 * @param exemptionId The certificate.
 */
export function exemptionFileUrl(
	customerId: number,
	exemptionId: number
): string {
	return downloadUrl( 'orillacart_exemption_file', {
		customer: customerId,
		exemption: exemptionId,
	} );
}

/**
 * The address a file a shopper sent with an item downloads from, for shop managers.
 *
 * @param orderId  The order.
 * @param uploadId The file.
 */
export function orderUploadUrl( orderId: number, uploadId: number ): string {
	return downloadUrl( 'orillacart_order_upload', {
		order: orderId,
		upload: uploadId,
	} );
}
