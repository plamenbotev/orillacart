import apiFetch from '@wordpress/api-fetch';

/** The shop's settings as a file, as GET /orillacart/v1/settings-archive returns it. */
export function fetchSettingsArchive(): Promise< Record< string, unknown > > {
	return apiFetch( { path: '/orillacart/v1/settings-archive' } );
}

/**
 * Takes a file of settings in, section by section.
 *
 * @param file The file, as JSON.
 */
export function importSettingsArchive(
	file: unknown
): Promise< { imported: string[]; refused: Record< string, string > } > {
	return apiFetch( {
		path: '/orillacart/v1/settings-archive',
		method: 'PUT',
		data: file as Record< string, unknown >,
	} );
}
