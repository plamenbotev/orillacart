import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { chevronDown, chevronUp, info } from '@wordpress/icons';
import type { ReactNode } from 'react';

/**
 * The long help behind an "i": one line stays under the field, the rest shows on hover or focus.
 *
 * @param props
 * @param props.text What it says.
 */
export function Hint( { text }: { text: string } ) {
	return (
		<Button
			className="oc-hint"
			icon={ info }
			iconSize={ 16 }
			size="small"
			label={ text }
			showTooltip
			tooltipPosition="top center"
		/>
	);
}

/**
 * The rare options of a section, opened on demand, so the common case is what one sees.
 *
 * @param props
 * @param props.label    What the toggle says closed; "More options" by default.
 * @param props.open     Open to start with.
 * @param props.children The options.
 */
export function More( {
	label = __( 'More options', 'orillacart' ),
	open: openAtFirst = false,
	children,
}: {
	label?: string;
	open?: boolean;
	children: ReactNode;
} ) {
	const [ open, setOpen ] = useState( openAtFirst );

	return (
		<div className="oc-more">
			<Button
				className="oc-more__toggle"
				variant="link"
				icon={ open ? chevronUp : chevronDown }
				iconPosition="right"
				aria-expanded={ open }
				onClick={ () => setOpen( ! open ) }
			>
				{ label }
			</Button>
			{ open && <div className="oc-more__body">{ children }</div> }
		</div>
	);
}
