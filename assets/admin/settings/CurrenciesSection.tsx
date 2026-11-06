import { Button, SelectControl, TextControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import type { OfferedCurrency } from './currencies';
import { Field, Row, Section } from '../shared/ui';

const ROUNDINGS = [
	{ value: 'decimals', label: __( 'To the cent', 'orillacart' ) },
	{ value: 'whole', label: __( 'To whole units (37)', 'orillacart' ) },
	{
		value: 'ninety_nine',
		label: __( 'Just under the next unit (36.99)', 'orillacart' ),
	},
];

/**
 * The currencies shoppers may choose besides the shop's own: each with the shop's rate for it and how its
 * converted prices are rounded. Shoppers choose with the Currency switcher block, and pay in what they chose. Part
 * of the General settings form: saved with it.
 *
 * @param props          The section's properties.
 * @param props.currency The shop's own currency.
 * @param props.offered  The other currencies as the form holds them.
 * @param props.onChange Receives the list as it changes.
 */
export function CurrenciesSection( {
	currency,
	offered,
	onChange,
}: {
	currency: string;
	offered: OfferedCurrency[];
	onChange: ( offered: OfferedCurrency[] ) => void;
} ) {
	const change = ( index: number, changed: Partial< OfferedCurrency > ) =>
		onChange(
			offered.map( ( one, at ) =>
				at === index ? { ...one, ...changed } : one
			)
		);

	return (
		<Section
			title={ __( 'Other currencies', 'orillacart' ) }
			description={ sprintf(
				/* translators: %s: the shop's currency */
				__(
					'Prices stay in %s. A shopper who chooses another currency with the Currency switcher block sees them converted at your rate, and orders and pays in it. Orders keep the prices they were placed at.',
					'orillacart'
				),
				currency
			) }
		>
			{ offered.map( ( one, index ) => (
				<Row key={ index }>
					<Field size="xs">
						<TextControl
							__next40pxDefaultSize
							label={ __( 'Offered currency', 'orillacart' ) }
							maxLength={ 3 }
							placeholder="USD"
							value={ one.code }
							onChange={ ( code ) =>
								change( index, { code: code.toUpperCase() } )
							}
						/>
					</Field>
					<Field size="s">
						<TextControl
							__next40pxDefaultSize
							label={ sprintf(
								/* translators: 1: the shop's currency, 2: the other currency */
								__( '1 %1$s is … %2$s', 'orillacart' ),
								currency,
								one.code || '…'
							) }
							inputMode="decimal"
							value={ one.rate }
							onChange={ ( rate ) =>
								change( index, {
									rate: rate.replace( ',', '.' ),
								} )
							}
						/>
					</Field>
					<Field size="m">
						<SelectControl
							__next40pxDefaultSize
							label={ __( 'Round prices', 'orillacart' ) }
							value={ one.rounding }
							options={ ROUNDINGS }
							onChange={ ( rounding ) =>
								change( index, {
									rounding:
										rounding as OfferedCurrency[ 'rounding' ],
								} )
							}
						/>
					</Field>
					<Field size="xs" className="oc-field--aligned">
						<Button
							variant="tertiary"
							isDestructive
							onClick={ () =>
								onChange(
									offered.filter( ( _, at ) => at !== index )
								)
							}
						>
							{ __( 'Remove', 'orillacart' ) }
						</Button>
					</Field>
				</Row>
			) ) }
			<Button
				variant="secondary"
				onClick={ () =>
					onChange( [
						...offered,
						{ code: '', rate: '', rounding: 'decimals' },
					] )
				}
			>
				{ __( 'Add a currency', 'orillacart' ) }
			</Button>
		</Section>
	);
}
