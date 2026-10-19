import { useEffect } from '@wordpress/element';

/**
 * While a form has changes not saved, leaving the page (a link, closing the tab, reloading) asks first, as the
 * block editor does for posts.
 *
 * @param dirty Whether the form differs from what is saved.
 */
export function useUnsavedChanges( dirty: boolean ): void {
	useEffect( () => {
		if ( ! dirty ) {
			return undefined;
		}
		const warn = ( event: BeforeUnloadEvent ) => {
			event.preventDefault();
			// Older browsers show the prompt only for a returnValue.
			event.returnValue = '';
		};
		window.addEventListener( 'beforeunload', warn );
		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ dirty ] );
}

/**
 * Whether two forms' values differ, by what they hold.
 *
 * @param a One form's values.
 * @param b The other's.
 */
export const differs = ( a: unknown, b: unknown ): boolean =>
	JSON.stringify( a ) !== JSON.stringify( b );
