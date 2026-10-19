import { TextControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import './money.css';

/**
 * An amount of money as the shop's admin enters it: a decimal comma is taken as the point, the shop's decimals
 * are suggested and enforced, and what is not an amount says so under the field before it is saved.
 */
interface Props {
	label: string;
	value: string;
	onChange: ( value: string ) => void;
	help?: string | undefined;
	/** The decimals amounts have; the shop's when not given. */
	decimals?: number;
	disabled?: boolean;
}

const shopDecimals = (): number =>
	typeof window === 'undefined'
		? 2
		: ( window.orillacartMoney?.decimals ?? 2 );

/**
 * What is wrong with an amount, for the help line; null for a good one or none.
 *
 * @param value    The amount as entered, a point for the decimals.
 * @param decimals The most decimals it may have.
 */
export function amountProblem(
	value: string,
	decimals: number
): string | null {
	if ( value === '' ) {
		return null;
	}
	if ( ! /^\d{1,11}(\.\d+)?$/.test( value ) ) {
		return __( 'Use an amount such as 19.90.', 'orillacart' );
	}
	const fraction = value.split( '.' )[ 1 ] ?? '';
	if ( fraction.length > decimals ) {
		return decimals === 0
			? __(
					'Use a whole amount: the currency has no decimals.',
					'orillacart'
				)
			: sprintf(
					/* translators: %d: how many decimals the currency has */
					__( 'Use up to %d decimals.', 'orillacart' ),
					decimals
				);
	}
	return null;
}

export function MoneyControl( {
	label,
	value,
	onChange,
	help,
	decimals = shopDecimals(),
	disabled = false,
}: Props ) {
	const problem = amountProblem( value, decimals );
	return (
		<TextControl
			__next40pxDefaultSize
			label={ label }
			value={ value }
			inputMode={ decimals === 0 ? 'numeric' : 'decimal' }
			placeholder={ ( 0 ).toFixed( decimals ) }
			className={ problem === null ? '' : 'is-invalid' }
			help={ problem ?? help }
			disabled={ disabled }
			onChange={ ( typed ) =>
				onChange( typed.replace( /\s/g, '' ).replace( ',', '.' ) )
			}
		/>
	);
}
