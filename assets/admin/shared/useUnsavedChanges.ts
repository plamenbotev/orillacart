import {
	createContext,
	useCallback,
	useContext,
	useEffect,
	useState,
} from '@wordpress/element';

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

/** A decimal as the shop saves it or the owner types it: "2.5", "2.50", "-3", "4.". */
const DECIMAL = /^\s*-?\d+(?:\.\d*)?\s*$/;

/**
 * Whether two forms' values differ, by what they hold. The fields named as decimals compare as numbers, at any
 * depth ("2.5" typed is "2.50" saved); an empty one stays apart from 0.
 *
 * @param a        One form's values.
 * @param b        The other's.
 * @param decimals The keys whose string values are decimals.
 */
export const differs = (
	a: unknown,
	b: unknown,
	decimals: readonly string[] = []
): boolean => {
	const replacer =
		decimals.length === 0
			? undefined
			: ( key: string, value: unknown ) =>
					decimals.includes( key ) &&
					typeof value === 'string' &&
					DECIMAL.test( value )
						? String( Number( value ) )
						: value;
	return JSON.stringify( a, replacer ) !== JSON.stringify( b, replacer );
};

/**
 * Guards a form against what was last loaded or saved (useUnsavedChanges): the form is unsaved while it differs
 * from that copy.
 *
 * @param form  The form's values; null while it loads.
 * @param scope The guard's part, as for useUnsavedChanges.
 * @return The function to give each value loaded or saved, as the form is set to it.
 */
export function useSavedCopy< T >(
	form: T | null,
	scope?: symbol
): ( saved: T | null ) => void {
	const [ saved, setSaved ] = useState< { value: T | null } >( {
		value: null,
	} );
	useUnsavedChanges(
		form !== null && saved.value !== null && differs( form, saved.value ),
		scope
	);
	return useCallback( ( value: T | null ) => setSaved( { value } ), [] );
}
