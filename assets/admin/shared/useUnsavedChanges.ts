import { useEffect } from '@wordpress/element';

/** The forms on the page that have changes not saved. */
const unsaved = new Set< symbol >();

/**
 * While a form has changes not saved, leaving the page (a link, closing the tab, reloading) asks first, as the
 * block editor does for posts; and the screen's own Close, or opening another row, asks too (useLeaveGuard).
 *
 * @param dirty Whether the form differs from what is saved.
 */
export function useUnsavedChanges( dirty: boolean ): void {
	useEffect( () => {
		if ( ! dirty ) {
			return undefined;
		}
		const form = Symbol( 'unsaved form' );
		unsaved.add( form );
		const warn = ( event: BeforeUnloadEvent ) => {
			event.preventDefault();
			// Older browsers show the prompt only for a returnValue.
			event.returnValue = '';
		};
		window.addEventListener( 'beforeunload', warn );
		return () => {
			unsaved.delete( form );
			window.removeEventListener( 'beforeunload', warn );
		};
	}, [ dirty ] );
}

/** Whether a form on the page has changes not saved. */
export const hasUnsavedChanges = (): boolean => unsaved.size > 0;

/**
 * Whether two forms' values differ, by what they hold.
 *
 * @param a One form's values.
 * @param b The other's.
 */
export const differs = ( a: unknown, b: unknown ): boolean =>
	JSON.stringify( a ) !== JSON.stringify( b );
