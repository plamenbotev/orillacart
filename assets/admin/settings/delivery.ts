import apiFetch from '@wordpress/api-fetch';

export type DeliveryMethod = 'php' | 'x_sendfile' | 'x_accel_redirect';

/** How bought files are sent, and what the last test found. */
export interface DownloadDelivery {
	method: DeliveryMethod;
	/** For Nginx: the internal location mapped to the downloads folder. */
	internal_prefix: string;
	/** Whether the test file went through the method asked for; null when nothing was tested. */
	works: boolean | null;
	message: string;
	/** What the web server needs for the method asked for. */
	server_rule: string;
}

const PATH = '/orillacart/v1/download-delivery';

export function fetchDownloadDelivery(): Promise< DownloadDelivery > {
	return apiFetch< DownloadDelivery >( { path: PATH } );
}

/**
 * Tests the method and keeps it when the web server sent the test file.
 *
 * @param method         The method to test.
 * @param internalPrefix For Nginx, the internal location.
 * @return The method in use afterwards, and the test's outcome.
 */
export function changeDownloadDelivery(
	method: DeliveryMethod,
	internalPrefix: string
): Promise< DownloadDelivery > {
	return apiFetch< DownloadDelivery >( {
		path: PATH,
		method: 'PUT',
		data: { method, internal_prefix: internalPrefix },
	} );
}
