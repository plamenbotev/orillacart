import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * The save button of a long form, kept at the bottom of the window: however far one scrolled, it is at hand, and
 * it says when there are unsaved changes. Submits the form by its id.
 *
 * @param props
 * @param props.dirty Whether the form has unsaved changes.
 * @param props.busy  Whether a save is running.
 * @param props.form  The form's id.
 * @param props.label The button's label; "Save" by default.
 */
export function SaveBar( {
	dirty,
	busy,
	form,
	label = __( 'Save', 'orillacart' ),
}: {
	dirty: boolean;
	busy: boolean;
	form: string;
	label?: string;
} ) {
	return (
		<div
			className={
				dirty ? 'oc-save-bar oc-save-bar--dirty' : 'oc-save-bar'
			}
			role="region"
			aria-label={ label }
		>
			<span className="oc-save-bar__hint">
				{ dirty ? __( 'You have unsaved changes.', 'orillacart' ) : '' }
			</span>
			<Button
				variant="primary"
				type="submit"
				form={ form }
				isBusy={ busy }
				disabled={ busy }
			>
				{ label }
			</Button>
		</div>
	);
}
