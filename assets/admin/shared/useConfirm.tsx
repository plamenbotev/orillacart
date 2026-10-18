import { __experimentalConfirmDialog as ConfirmDialog } from '@wordpress/components';
import {
	useCallback,
	useState,
	type Element as WPElement,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';

type Question = { message: string; confirm: string; onConfirm: () => void };

/**
 * One way for every screen to ask before something that cannot be undone, such as deleting or giving money
 * back: a dialog in the admin's own look, not the browser's.
 *
 * @return The dialog to render (null while nothing is asked), and the function that asks: the message, the work
 * to do once the shop agrees, and the confirming button's label.
 */
export function useConfirm(): [
	WPElement | null,
	( message: string, onConfirm: () => void, confirm?: string ) => void,
] {
	const [ question, setQuestion ] = useState< Question | null >( null );
	const ask = useCallback(
		( message: string, onConfirm: () => void, confirm?: string ) =>
			setQuestion( {
				message,
				onConfirm,
				confirm: confirm ?? __( 'Delete', 'orillacart' ),
			} ),
		[]
	);
	const dialog =
		question === null ? null : (
			<ConfirmDialog
				isOpen
				confirmButtonText={ question.confirm }
				cancelButtonText={ __( 'Cancel', 'orillacart' ) }
				onConfirm={ () => {
					setQuestion( null );
					question.onConfirm();
				} }
				onCancel={ () => setQuestion( null ) }
			>
				{ question.message }
			</ConfirmDialog>
		);

	return [ dialog, ask ];
}
