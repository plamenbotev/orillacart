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

const key = ( item: BundleItem ) =>
	`${ item.product_id }:${ item.variation_id ?? 0 }`;

/**
 * Makes the product a bundle of other products: a camera kit of a camera, two batteries and a bag. It has no
 * stock of its own: it sells while every item does, and each sale takes the items' stock. Saved with the
 * product details.
 *
 * @param props The panel's properties.
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
	const [ variation, setVariation ] = useState( '' );
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

	const add = ( product: Product, variationId: number | null ) => {
		const item = {
			product_id: product.id,
			variation_id: variationId,
			quantity: 1,
		};
		const label =
			variationId === null
				? product.name
				: `${ product.name } – ${
						product.variations.find( ( v ) => v.id === variationId )
							?.label ?? ''
					}`;
		setKnown( { ...known, [ key( item ) ]: label } );
		if (
			! bundle.items.some( ( current ) => key( current ) === key( item ) )
		) {
			onChange( { ...bundle, items: [ ...bundle.items, item ] } );
		}
		setPicked( null );
		setVariation( '' );
	};

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
								onChange( {
									...bundle,
									items: bundle.items.map( ( current, at ) =>
										at === index
											? {
													...current,
													quantity: Math.max(
														1,
														Math.floor(
															Number( value )
														) || 1
													),
												}
											: current
									),
								} )
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
								? add( product, null )
								: setPicked( product )
						)
						.catch( onError );
				} }
			/>
			{ picked !== null && (
				<Flex align="flex-end">
					<FlexItem isBlock>
						<SelectControl
							__next40pxDefaultSize
							label={ sprintf(
								/* translators: %s: product name */
								__( 'Which %s', 'orillacart' ),
								picked.name
							) }
							value={ variation }
							options={ [
								{
									value: '',
									label: __( 'Choose', 'orillacart' ),
								},
								...picked.variations.map( ( v ) => ( {
									value: String( v.id ),
									label: v.label,
								} ) ),
							] }
							onChange={ setVariation }
						/>
					</FlexItem>
					<FlexItem>
						<Button
							variant="secondary"
							disabled={ variation === '' }
							onClick={ () => add( picked, Number( variation ) ) }
						>
							{ __( 'Add', 'orillacart' ) }
						</Button>
					</FlexItem>
				</Flex>
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
