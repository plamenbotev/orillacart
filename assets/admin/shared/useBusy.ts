import { useCallback, useRef, useState } from '@wordpress/element';

/**
 * Whether a request of a form or card is running, and a way to run one: while it runs, a second click starts
 * nothing, so an email is sent or a note added once however often the button is pressed.
 *
 * @return The busy flag, for the button's isBusy and disabled, and the function that runs a request.
 */
export function useBusy(): [
	boolean,
	( work: () => Promise< unknown > ) => void,
] {
	const [ busy, setBusy ] = useState( false );
	// The flag in state shows on the next render; the ref stops a second click in the same one.
	const running = useRef( false );
	const run = useCallback( ( work: () => Promise< unknown > ) => {
		if ( running.current ) {
			return;
		}
		running.current = true;
		setBusy( true );
		work().finally( () => {
			running.current = false;
			setBusy( false );
		} );
	}, [] );

	return [ busy, run ];
}
