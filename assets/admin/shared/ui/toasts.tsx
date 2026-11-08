import { Snackbar } from '@wordpress/components';
import {
	useCallback,
	useEffect,
	useSyncExternalStore,
} from '@wordpress/element';

/**
 * Toasts: "Saved", shown a few seconds at the bottom of the screen. One store for the page, so a card deep in
 * a screen says "Saved" without passing a callback down: notify() from anywhere, <Toasts /> once (the Page has it).
 */
export interface Toast {
	id: string;
	content: string;
	/** A problem stays until dismissed; the rest go by themselves. */
	explicitDismiss?: boolean;
}

let toasts: Toast[] = [];
const listeners = new Set< () => void >();
let next = 1;

const emit = () => listeners.forEach( ( listener ) => listener() );

/**
 * Shows a toast.
 *
 * @param content         What it says.
 * @param explicitDismiss Stays until dismissed; otherwise it goes by itself.
 */
export function notify( content: string, explicitDismiss = false ) {
	toasts = [ ...toasts, { id: String( next++ ), content, explicitDismiss } ];
	emit();
}

/**
 * Takes a toast away, by its id.
 *
 * @param id The toast's id.
 */
export function dismiss( id: string ) {
	toasts = toasts.filter( ( toast ) => toast.id !== id );
	emit();
}

/** Clears the toasts: tests call it between cases. */
export function forgetToasts() {
	toasts = [];
	emit();
}

const subscribe = ( listener: () => void ) => {
	listeners.add( listener );
	return () => listeners.delete( listener );
};

export function Toasts() {
	const current = useSyncExternalStore(
		subscribe,
		() => toasts,
		() => toasts
	);
	const onRemove = useCallback( ( id: string ) => dismiss( id ), [] );
	// A page going away takes its toasts: the next one (the next test, too) starts clean.
	useEffect( () => forgetToasts, [] );

	// Each Snackbar on its own, not a SnackbarList: the list animates with framer-motion, which jsdom cannot
	// measure (it scrolls the window and leaves the toast transparent), and the toasts need no animation.
	return (
		<div className="oc-toasts components-snackbar-list">
			{ current.map( ( toast ) => (
				<div
					key={ toast.id }
					className="components-snackbar-list__notice-container"
				>
					<Snackbar
						explicitDismiss={ toast.explicitDismiss ?? false }
						onRemove={ () => onRemove( toast.id ) }
					>
						{ toast.content }
					</Snackbar>
				</div>
			) ) }
		</div>
	);
}
