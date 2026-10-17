import {
	Button,
	Flex,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import {
	deleteVariation,
	saveVariation,
	type Attribute,
	type Variation,
	type VariationInput,
} from '../catalog/api';
import type { Stockroom } from '../inventory/api';
import { orNull, STOCK_MODES, withSalePrice } from './fields';
import { StockLevels } from './StockLevels';

interface Props {
	productId: number;
	/** The attributes the product offers; variations choose among their saved options. */
	attributes: Attribute[];
	variations: Variation[];
	stockrooms: Stockroom[];
	onChanged: () => void;
	onError: ( error: unknown ) => void;
}

/**
 * The product's variations: each combination of options sold as its own item, with its own price and stock.
 * @param root0
 * @param root0.productId
 * @param root0.attributes
 * @param root0.variations
 * @param root0.stockrooms
 * @param root0.onChanged
 * @param root0.onError
 */
export function VariationsPanel( {
	productId,
	attributes,
	variations,
	stockrooms,
	onChanged,
	onError,
}: Props ) {
	if ( attributes.length === 0 ) {
		return (
			<p>
				{ __(
					'Add attributes and save the product first; variations combine their options.',
					'orillacart'
				) }
			</p>
		);
	}

	return (
		<>
			{ variations.map( ( variation ) => (
				<VariationRow
					key={ variation.id }
					productId={ productId }
					attributes={ attributes }
					variation={ variation }
					stockrooms={ stockrooms }
					onChanged={ onChanged }
					onError={ onError }
				/>
			) ) }
			<VariationRow
				key={ `new-${ variations.length }` }
				productId={ productId }
				attributes={ attributes }
				variation={ null }
				stockrooms={ stockrooms }
				onChanged={ onChanged }
				onError={ onError }
			/>
		</>
	);
}

interface RowProps {
	productId: number;
	attributes: Attribute[];
	/** Null for the form that adds a variation. */
	variation: Variation | null;
	stockrooms: Stockroom[];
	onChanged: () => void;
	onError: ( error: unknown ) => void;
}

function VariationRow( {
	productId,
	attributes,
	variation,
	stockrooms,
	onChanged,
	onError,
}: RowProps ) {
	const [ draft, setDraft ] = useState< VariationInput >( {
		option_ids: variation?.option_ids ?? [],
		price: variation?.price ?? '',
		sale: variation?.sale ?? null,
		sku: variation?.sku ?? null,
		stock_mode: variation?.stock_mode ?? 'tracked',
	} );

	const chosen = ( attribute: Attribute ) =>
		attribute.options.find( ( option ) =>
			draft.option_ids.includes( option.id )
		)?.id;
	const choose = ( attribute: Attribute, value: string ) => {
		const others = draft.option_ids.filter(
			( id ) => ! attribute.options.some( ( option ) => option.id === id )
		);
		setDraft( {
			...draft,
			option_ids:
				value === ''
					? others
					: [ ...others, Number.parseInt( value, 10 ) ],
		} );
	};

	return (
		<fieldset className="orillacart-variation">
			<legend>
				{ variation?.label ?? __( 'New variation', 'orillacart' ) }
			</legend>
			{ attributes.map( ( attribute ) => (
				<SelectControl
					key={ attribute.id }
					__next40pxDefaultSize
					label={ attribute.name }
					value={ String( chosen( attribute ) ?? '' ) }
					options={ [
						{ label: __( '— Any —', 'orillacart' ), value: '' },
						...attribute.options.map( ( option ) => ( {
							label: option.name,
							value: String( option.id ),
						} ) ),
					] }
					onChange={ ( value ) => choose( attribute, value ) }
				/>
			) ) }
			<TextControl
				__next40pxDefaultSize
				label={ __( 'Price', 'orillacart' ) }
				value={ draft.price }
				onChange={ ( price ) => setDraft( { ...draft, price } ) }
			/>
			<TextControl
				__next40pxDefaultSize
				label={ __( 'Sale price', 'orillacart' ) }
				value={ draft.sale?.price ?? '' }
				onChange={ ( price ) =>
					setDraft( {
						...draft,
						sale: withSalePrice( draft.sale, price ),
					} )
				}
			/>
			<TextControl
				__next40pxDefaultSize
				label={ __( 'SKU', 'orillacart' ) }
				value={ draft.sku ?? '' }
				onChange={ ( sku ) =>
					setDraft( { ...draft, sku: orNull( sku ) } )
				}
			/>
			<SelectControl
				__next40pxDefaultSize
				label={ __( 'Stock', 'orillacart' ) }
				value={ draft.stock_mode }
				options={ STOCK_MODES }
				onChange={ ( mode ) =>
					setDraft( {
						...draft,
						stock_mode: mode as VariationInput[ 'stock_mode' ],
					} )
				}
			/>
			<Flex justify="flex-start">
				<Button
					variant="secondary"
					disabled={
						draft.option_ids.length === 0 || draft.price === ''
					}
					onClick={ () =>
						saveVariation( productId, variation?.id ?? null, draft )
							.then( onChanged )
							.catch( onError )
					}
				>
					{ variation === null
						? __( 'Add variation', 'orillacart' )
						: __( 'Save variation', 'orillacart' ) }
				</Button>
				{ variation !== null && (
					<Button
						variant="tertiary"
						isDestructive
						onClick={ () =>
							deleteVariation( productId, variation.id )
								.then( onChanged )
								.catch( onError )
						}
					>
						{ __( 'Delete', 'orillacart' ) }
					</Button>
				) }
			</Flex>
			{ variation !== null && draft.stock_mode === 'tracked' && (
				<StockLevels
					kind="variation"
					id={ variation.id }
					stockrooms={ stockrooms }
					onError={ onError }
				/>
			) }
		</fieldset>
	);
}
