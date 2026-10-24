import { createContext, useContext, useEffect } from '@wordpress/element';

/**
 * The part of the screen a leave guard looks after (useLeaveGuard): the forms inside it register there, so the
 * guard asks only about what it would close, not about another form that stays open.
 */
export const UnsavedScope = createContext< symbol | null >( null );

/** The forms on the page that have changes not saved, each with the guard's part it is in. */
const unsaved = new Map< symbol, symbol | null >();

/**
 * While a form has changes not saved, leaving the page (a link, closing the tab, reloading) asks first, as the
 * block editor does for posts; and the screen's own Close, or opening another row, asks too, for a form inside
 * that guard's part (useLeaveGuard).
 *
 * @param dirty Whether the form differs from what is saved.
 * @param scope The guard's part, for a form not rendered inside it; the part around it otherwise.
 */
export function useUnsavedChanges( dirty: boolean, scope?: symbol ): void {
	const around = useContext( UnsavedScope );
	const part = scope ?? around;
	useEffect( () => {
		if ( ! dirty ) {
			return undefined;
		}
		const form = Symbol( 'unsaved form' );
		unsaved.set( form, part );
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
	}, [ dirty, part ] );
}

/**
 * Whether a form has changes not saved: in the guard's part, or anywhere on the page.
 *
 * @param scope The guard's part; any form when not given.
 */
export const hasUnsavedChanges = ( scope?: symbol ): boolean =>
	scope === undefined
		? unsaved.size > 0
		: Array.from( unsaved.values() ).includes( scope );

/**
 * Whether two forms' values differ, by what they hold.
 *
 * @param a One form's values.
 * @param b The other's.
 */
export const differs = ( a: unknown, b: unknown ): boolean =>
	JSON.stringify( a ) !== JSON.stringify( b );
