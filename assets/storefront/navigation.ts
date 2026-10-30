/**
 * Leaving the page, in one place: tests stand in for it, as jsdom cannot.
 */

/**
 * Goes to another page.
 * @param url Where to.
 */
export function navigate( url: string ): void {
	window.location.assign( url );
}

/** Loads the page anew. */
export function reloadPage(): void {
	window.location.reload();
}
