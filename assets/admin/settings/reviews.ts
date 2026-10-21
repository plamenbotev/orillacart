import apiFetch from '@wordpress/api-fetch';

/** Who may review products, as GET /orillacart/v1/review-settings returns it. */
export interface ReviewSettings {
	policy: 'off' | 'anyone' | 'buyers';
}

const PATH = '/orillacart/v1/review-settings';

export function fetchReviewSettings(): Promise< ReviewSettings > {
	return apiFetch< ReviewSettings >( { path: PATH } );
}

export function saveReviewSettings(
	settings: ReviewSettings
): Promise< ReviewSettings > {
	return apiFetch< ReviewSettings >( {
		path: PATH,
		method: 'PUT',
		data: settings,
	} );
}
