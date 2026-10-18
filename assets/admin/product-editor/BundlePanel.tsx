import {
	Button,
	CheckboxControl,
	ComboboxControl,
	Flex,
	FlexItem,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	type Bundle,
	type BundleItem,
	fetchProduct,
	type Product,
} from '../catalog/api';
import { fetchProducts, type Named } from '../shared/CatalogTokens';

interface BundlePanelProps {
	productId: number;
	bundle: Bundle | null;
	/** The items' names, as the API gave them, for those already in the bundle. */
	names: Record< string, string >;
	hasVariations: boolean;
	onChange: ( bundle: Bundle | null ) => void;
	onError: ( error: unknown ) => void;
}

/**
 * @param item A bundle item.
 * @return What tells it apart from the bundle's other items.
 */
export const bundleItemKey = ( item: BundleItem ) =>
	`${ item.product_id }:${ item.variation_ids.join( ',' ) }`;
const key = bundleItemKey;

/**
 * @param name       The product's name.
 * @param variations The names of the variations the item lists.
 * @return The item as the panel names it: one variation is the bundle's, several are the customer's choice.
 */
export function bundleItemLabel( name: string, variations: string[] ): string {
	if ( variations.length === 0 ) {
		return name;
	}
	if ( variations.length === 1 ) {
		return `${ name } – ${ variations[ 0 ] }`;
	}
	return sprintf(
		/* translators: 1: product name, 2: its variations, separated by commas */
		__( '%1$s – the customer chooses: %2$s', 'orillacart' ),
		name,
		variations.join( ', ' )
	);
}

/**
 * Makes the product a bundle of other products: a camera kit of a camera, two batteries and a bag. It has no
 * stock of its own: it sells while every item does, and each sale takes the items' stock. An item may offer the
 * customer a choice of variations (a black or a brown bag), and may be optional. Saved with the product details.
 *
 * @param props               The panel's properties.
 * @param props.productId
 * @param props.bundle
 * @param props.names
 * @param props.hasVariations
 * @param props.onChange
 * @param props.onError
 */
export function BundlePanel( {
	productId,
	bundle,
	names,
	hasVariations,
	onChange,
	onError,
}: BundlePanelProps ) {
	const [ found, setFound ] = useState< Named[] >( [] );
	const [ picked, setPicked ] = useState< Product | null >( null );
	const [ variations, setVariations ] = useState< number[] >( [] );
	const [ known, setKnown ] = useState< Record< string, string > >( names );

	if ( hasVariations ) {
		return (
			<p>
				{ __(
					'A product with variations cannot be a bundle: make a separate product for the bundle, and put one of the variations in it.',
					'orillacart'
				) }
			</p>
		);
	}

	const toggle = (
		<CheckboxControl
			label={ __( 'This product is a bundle of others', 'orillacart' ) }
			help={ __(
				'It has no stock of its own: it sells while every item does, and each sale takes the items’ stock.',
				'orillacart'
			) }
			checked={ bundle !== null }
			onChange={ ( on ) =>
				onChange(
					on ? { items: [], pricing: 'fixed', discount: null } : null
				)
			}
		/>
	);
	if ( bundle === null ) {
		return toggle;
	}

	const add = ( product: Product, variationIds: number[] ) => {
		const item: BundleItem = {
			product_id: product.id,
			variation_ids: variationIds,
			quantity: 1,
			optional: false,
		};
		const label = bundleItemLabel(
			product.name,
			variationIds.map(
				( id ) =>
					product.variations.find( ( v ) => v.id === id )?.label ?? ''
			)
		);
		setKnown( { ...known, [ key( item ) ]: label } );
		if (
			! bundle.items.some( ( current ) => key( current ) === key( item ) )
		) {
			onChange( { ...bundle, items: [ ...bundle.items, item ] } );
		}
		setPicked( null );
		setVariations( [] );
	};

	const change = ( index: number, changes: Partial< BundleItem > ) =>
		onChange( {
			...bundle,
			items: bundle.items.map( ( current, at ) =>
				at === index ? { ...current, ...changes } : current
			),
		} );

	return (
		<>
			{ toggle }
			{ bundle.items.map( ( item, index ) => (
				<Flex key={ key( item ) } align="flex-end">
					<FlexItem isBlock>
						<TextControl
							__next40pxDefaultSize
							type="number"
							min={ 1 }
							label={
								known[ key( item ) ] ?? `#${ item.product_id }`
							}
							value={ String( item.quantity ) }
							onChange={ ( value ) =>
								change( index, {
									quantity: Math.max(
										1,
										Math.floor( Number( value ) ) || 1
									),
								} )
							}
						/>
					</FlexItem>
					<FlexItem>
						<CheckboxControl
							label={ __( 'Optional', 'orillacart' ) }
							help={ __(
								'The customer may add it.',
								'orillacart'
							) }
							checked={ item.optional }
							onChange={ ( optional ) =>
								change( index, { optional } )
							}
						/>
					</FlexItem>
					<FlexItem>
						<Button
							variant="tertiary"
							isDestructive
							onClick={ () =>
								onChange( {
									...bundle,
									items: bundle.items.filter(
										( _, at ) => at !== index
									),
								} )
							}
						>
							{ __( 'Remove', 'orillacart' ) }
						</Button>
					</FlexItem>
				</Flex>
			) ) }
			<ComboboxControl
				__next40pxDefaultSize
				label={ __( 'Add a product', 'orillacart' ) }
				value={ picked === null ? null : String( picked.id ) }
				options={ found
					.filter( ( product ) => product.id !== productId )
					.map( ( product ) => ( {
						value: String( product.id ),
						label: product.name,
					} ) ) }
				onFilterValueChange={ ( search ) => {
					if ( search.trim().length >= 2 ) {
						fetchProducts( { search } )
							.then( setFound )
							.catch( onError );
					}
				} }
				onChange={ ( value ) => {
					if ( ! value ) {
						return;
					}
					fetchProduct( Number( value ) )
						.then( ( product ) =>
							product.variations.length === 0
								? add( product, [] )
								: setPicked( product )
						)
						.catch( onError );
				} }
			/>
			{ picked !== null && (
				<fieldset>
					<legend>
						{ sprintf(
							/* translators: %s: product name */
							__(
								'Which %s: one is the bundle’s, several are the customer’s choice',
								'orillacart'
							),
							picked.name
						) }
					</legend>
					{ picked.variations.map( ( v ) => (
						<CheckboxControl
							key={ v.id }
							label={ v.label }
							checked={ variations.includes( v.id ) }
							onChange={ ( on ) =>
								setVariations(
									on
										? [ ...variations, v.id ]
										: variations.filter(
												( id ) => id !== v.id
											)
								)
							}
						/>
					) ) }
					<Button
						variant="secondary"
						disabled={ variations.length === 0 }
						onClick={ () => add( picked, variations ) }
					>
						{ __( 'Add', 'orillacart' ) }
					</Button>
				</fieldset>
			) }
			<SelectControl
				__next40pxDefaultSize
				label={ __( 'Price', 'orillacart' ) }
				value={ bundle.pricing }
				options={ [
					{
						value: 'fixed',
						label: __( 'Its own price, set above', 'orillacart' ),
					},
					{
						value: 'sum',
						label: __( 'Its items’ prices added up', 'orillacart' ),
					},
				] }
				onChange={ ( value ) =>
					onChange( {
						...bundle,
						pricing: value as Bundle[ 'pricing' ],
					} )
				}
			/>
			{ bundle.pricing === 'sum' && (
				<TextControl
					__next40pxDefaultSize
					label={ __( 'Percentage off the sum', 'orillacart' ) }
					value={ bundle.discount ?? '' }
					onChange={ ( value ) =>
						onChange( {
							...bundle,
							discount: value.trim() === '' ? null : value,
						} )
					}
				/>
			) }
		</>
	);
}
