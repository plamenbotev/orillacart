import {
	Button,
	Flex,
	FlexItem,
	SelectControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import {
	deleteVariation,
	fetchVariationContent,
	saveVariation,
	saveVariationContent,
	type VariationContent,
	type Attribute,
	type Variation,
	type VariationInput,
} from '../catalog/api';
import type { Stockroom } from '../inventory/api';
import { BACKORDERS, orNull, STOCK_MODES, withSalePrice } from './fields';
import { GalleryField, ImageField } from '../catalog/MediaFields';
import { StockLevels } from './StockLevels';
import { differs, useUnsavedChanges } from '../shared/useUnsavedChanges';
import { MoneyControl } from '../shared/MoneyControl';

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

const NO_CONTENT: VariationContent = {
	title: '',
	description: '',
	image: 0,
	gallery: [],
};

/**
 * What a variation's form starts with: the variation as saved, or nothing for a new one.
 *
 * @param variation The variation; null for the form that adds one.
 */
const variationInput = ( variation: Variation | null ): VariationInput => ( {
	option_ids: variation?.option_ids ?? [],
	price: variation?.price ?? '',
	sale: variation?.sale ?? null,
	sku: variation?.sku ?? null,
	stock_mode: variation?.stock_mode ?? 'tracked',
	backorders: variation?.backorders ?? null,
	backorder_limit: variation?.backorder_limit ?? null,
	weight: variation?.weight ?? null,
	length: variation?.length ?? null,
	width: variation?.width ?? null,
	height: variation?.height ?? null,
} );

function VariationRow( {
	productId,
	attributes,
	variation,
	stockrooms,
	onChanged,
	onError,
}: RowProps ) {
	const [ draft, setDraft ] = useState< VariationInput >(
		variationInput( variation )
	);
	const [ content, setContent ] = useState< VariationContent >( NO_CONTENT );
	const [ savedContent, setSavedContent ] =
		useState< VariationContent >( NO_CONTENT );
	useUnsavedChanges(
		differs( draft, variationInput( variation ) ) ||
			differs( content, savedContent )
	);
	const variationId = variation?.id;
	useEffect( () => {
		if ( variationId !== undefined ) {
			fetchVariationContent( variationId )
				.then( ( loaded ) => {
					setContent( loaded );
					setSavedContent( loaded );
				} )
				.catch( onError );
		}
	}, [ variationId, onError ] );
	const save = () =>
		saveVariation( productId, variation?.id ?? null, draft )
			.then( ( saved ) => saveVariationContent( saved.id, content ) )
			.then( onChanged )
			.catch( onError );

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
		// Saved variations fold to their options and price; the form for a new one stays open.
		<details className="orillacart-variation" open={ variation === null }>
			<summary>
				{ variation === null
					? __( 'New variation', 'orillacart' )
					: `${ variation.label } — ${ variation.price }` }
			</summary>
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
			<MoneyControl
				label={ __( 'Price', 'orillacart' ) }
				value={ draft.price }
				onChange={ ( price ) => setDraft( { ...draft, price } ) }
			/>
			<MoneyControl
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
			{ draft.stock_mode === 'tracked' && (
				<SelectControl
					__next40pxDefaultSize
					label={ __( 'When out of stock', 'orillacart' ) }
					value={ draft.backorders ?? '' }
					options={ [
						{
							label: __( 'As the product', 'orillacart' ),
							value: '',
						},
						...BACKORDERS,
					] }
					onChange={ ( value ) =>
						setDraft( {
							...draft,
							backorders:
								value === ''
									? null
									: ( value as VariationInput[ 'backorders' ] ),
						} )
					}
				/>
			) }
			{ draft.stock_mode === 'tracked' && draft.backorders !== 'no' && (
				<TextControl
					__next40pxDefaultSize
					type="number"
					min={ 1 }
					label={ __( 'Owe at most', 'orillacart' ) }
					help={ __( 'Empty: as the product.', 'orillacart' ) }
					value={ draft.backorder_limit ?? '' }
					onChange={ ( value ) =>
						setDraft( {
							...draft,
							backorder_limit:
								value === '' ? null : Number( value ),
						} )
					}
				/>
			) }
			<p className="orillacart-variation__hint">
				{ __(
					'Weight and size: empty for the product’s.',
					'orillacart'
				) }
			</p>
			<Flex wrap>
				{ (
					[
						[ 'weight', __( 'Weight', 'orillacart' ) ],
						[ 'length', __( 'Length', 'orillacart' ) ],
						[ 'width', __( 'Width', 'orillacart' ) ],
						[ 'height', __( 'Height', 'orillacart' ) ],
					] as const
				 ).map( ( [ field, label ] ) => (
					<FlexItem key={ field }>
						<TextControl
							__next40pxDefaultSize
							label={ label }
							value={ draft[ field ] ?? '' }
							onChange={ ( value ) =>
								setDraft( {
									...draft,
									[ field ]: orNull( value ),
								} )
							}
						/>
					</FlexItem>
				) ) }
			</Flex>
			<TextControl
				__next40pxDefaultSize
				label={ __( 'Name', 'orillacart' ) }
				help={ __(
					'Empty: the product’s name with the options.',
					'orillacart'
				) }
				value={ content.title }
				onChange={ ( title ) => setContent( { ...content, title } ) }
			/>
			<TextareaControl
				label={ __( 'Description', 'orillacart' ) }
				help={ __(
					'Shown on the product page when this variation is chosen.',
					'orillacart'
				) }
				value={ content.description }
				onChange={ ( description ) =>
					setContent( { ...content, description } )
				}
			/>
			<ImageField
				label={ __( 'Image', 'orillacart' ) }
				value={ content.image }
				onChange={ ( image ) => setContent( { ...content, image } ) }
			/>
			<GalleryField
				label={ __( 'Gallery', 'orillacart' ) }
				value={ content.gallery }
				onChange={ ( gallery ) =>
					setContent( { ...content, gallery } )
				}
			/>
			<Flex justify="flex-start">
				<Button
					variant="secondary"
					disabled={
						draft.option_ids.length === 0 || draft.price === ''
					}
					onClick={ save }
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
		</details>
	);
}
