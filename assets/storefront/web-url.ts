/**
 * Whether the browser may be sent to a URL: http(s) or a path of the site, never one it would run
 * (`javascript:`, `data:`) or read another way (a backslash, a tab or line break it drops).
 * @param url Where to.
 */
export function isWebUrl( url: string ): boolean {
	if ( url === '' || /[\u0000- \u007f\\]/.test( url ) ) {
		return false;
	}
	try {
		const { protocol } = new URL( url, window.location.href );
		return protocol === 'https:' || protocol === 'http:';
	} catch {
		return false;
	}
}
