import { useCallback, type Element as WPElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { useConfirm } from './useConfirm';
import { hasUnsavedChanges } from './useUnsavedChanges';

/**
 * Asks before the screen closes a form or opens another row while a form has changes not saved
 * (useUnsavedChanges); goes straight on otherwise.
 *
 * @return The dialog to render, and the function that runs the change once the shop agrees.
 */
export function useLeaveGuard(): [
	WPElement | null,
	( leave: () => void ) => void,
] {
	const [ dialog, confirm ] = useConfirm();
	const guard = useCallback(
		( leave: () => void ) => {
			if ( ! hasUnsavedChanges() ) {
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
		[ confirm ]
	);

	return [ dialog, guard ];
}
