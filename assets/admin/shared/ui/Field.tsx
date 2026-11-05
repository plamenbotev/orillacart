import type { ReactNode } from 'react';

/** How wide a field is, by what goes in it: a code or a count (xs), a short value (s), a name (m), a sentence (l). */
export type FieldSize = 'xs' | 's' | 'm' | 'l' | 'full';

/**
 * A field's width says what goes in it: a currency code is not as wide as an address line. Wraps one control.
 *
 * @param props
 * @param props.size      The width.
 * @param props.className More classes.
 * @param props.children  The control.
 */
export function Field( {
	size = 'm',
	className,
	children,
}: {
	size?: FieldSize;
	className?: string;
	children: ReactNode;
} ) {
	return (
		<div
			className={ [ 'oc-field', `oc-field--${ size }`, className ]
				.filter( Boolean )
				.join( ' ' ) }
		>
			{ children }
		</div>
	);
}

/**
 * Fields side by side, wrapping on a narrow screen: a country with its state and postcode, an amount with its
 * percentage.
 *
 * @param props
 * @param props.children The fields.
 */
export function Row( { children }: { children: ReactNode } ) {
	return <div className="oc-row">{ children }</div>;
}
