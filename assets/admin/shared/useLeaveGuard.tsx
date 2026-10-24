import {
	useCallback,
	useState,
	type Element as WPElement,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { ReactNode } from 'react';

import { useConfirm } from './useConfirm';
import { hasUnsavedChanges, UnsavedScope } from './useUnsavedChanges';

/**
 * Asks before the screen closes a form or opens another row while a form in the guard's part has changes not
 * saved (useUnsavedChanges); goes straight on otherwise. The part is what the screen renders inside the third
 * value, `<Guarded>…</Guarded>`: a form elsewhere on the page that stays open is no reason to ask.
 *
 * @return The dialog to render, the function that runs the change once the shop agrees, the part's wrapper, and
 *         the part itself, for a form rendered outside the wrapper (useUnsavedChanges' scope).
 */
export function useLeaveGuard(): [
	WPElement | null,
	( leave: () => void ) => void,
	( props: { children: ReactNode } ) => WPElement,
	symbol,
] {
	const [ dialog, confirm ] = useConfirm();
	const [ scope ] = useState( () => Symbol( 'guarded part' ) );
	const guard = useCallback(
		( leave: () => void ) => {
			if ( ! hasUnsavedChanges( scope ) ) {
				leave();
				return;
			}
			confirm(
				__(
					'The changes you made are not saved. Leave them?',
					'orillacart'
				),
				leave,
				__( 'Leave without saving', 'orillacart' )
			);
		},
		[ confirm, scope ]
	);
	const [ Guarded ] = useState( () => {
		const Part = ( { children }: { children: ReactNode } ) => (
			<UnsavedScope.Provider value={ scope }>
				{ children }
			</UnsavedScope.Provider>
		);
		return Part;
	} );

	return [ dialog, guard, Guarded, scope ];
}
