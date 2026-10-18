import {
	Button,
	ComboboxControl,
	Flex,
	FlexItem,
	SelectControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import {
	fetchProductChoices,
	searchProducts,
	type ProductChoice,
} from './products';

export interface PickedItem {
	product_id: number;
	option_ids: number[];
	bundle_choices: number[];
	name: string;
}

/**
 * Finds a product by name and, when it offers choices, asks for them; the server checks the combination.
 *
 * @param props        The picker's properties.
 * @param props.onPick Receives the product with its chosen options.
 */
export function ProductPicker( {
	onPick,
}: {
	onPick: ( item: PickedItem ) => void;
} ) {
	const [ search, setSearch ] = useState( '' );
	const [ found, setFound ] = useState< { id: number; title: string }[] >(
		[]
	);
	const [ product, setProduct ] = useState< ProductChoice | null >( null );
	const [ chosen, setChosen ] = useState< Record< number, number > >( {} );
	const [ variation, setVariation ] = useState( '' );
	/** For a bundle: each decision's pick, by its position. */
	const [ picks, setPicks ] = useState< Record< number, number > >( {} );

	useEffect( () => {
		const timer = window.setTimeout( () => {
			searchProducts( search )
				.then( setFound )
				.catch( () => setFound( [] ) );
		}, 250 );
		return () => window.clearTimeout( timer );
	}, [ search ] );

	const choose = ( value: string | null | undefined ) => {
		setChosen( {} );
		setVariation( '' );
		setPicks( {} );
		const id = Number( value );
		if ( ! id ) {
			setProduct( null );
			return;
		}
		void fetchProductChoices( id ).then( setProduct );
	};

	return (
		<Flex align="flex-end" wrap>
			<FlexItem isBlock>
				<ComboboxControl
					__next40pxDefaultSize
					label={ __( 'Add a product', 'orillacart' ) }
					value={ product === null ? null : String( product.id ) }
					options={ found.map( ( item ) => ( {
						label: item.title,
						value: String( item.id ),
					} ) ) }
					onFilterValueChange={ setSearch }
					onChange={ choose }
				/>
			</FlexItem>
			{ product !== null && product.variations.length > 0 && (
				<FlexItem>
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Variation', 'orillacart' ) }
						value={ variation }
						options={ [
							{
								label: __( '— Choose —', 'orillacart' ),
								value: '',
							},
							...product.variations.map( ( item ) => ( {
								label: item.label,
								value: String( item.id ),
							} ) ),
						] }
						onChange={ setVariation }
					/>
				</FlexItem>
			) }
			{ product?.attributes.map( ( attribute ) => (
				<FlexItem key={ attribute.id }>
					<SelectControl
						__next40pxDefaultSize
						label={ attribute.name }
						value={ String( chosen[ attribute.id ] ?? '' ) }
						options={ [
							{
								label: __( '— None —', 'orillacart' ),
								value: '',
							},
							...attribute.options.map( ( option ) => ( {
								label: option.name,
								value: String( option.id ),
							} ) ),
						] }
						onChange={ ( value ) =>
							setChosen( ( current ) => {
								const next = { ...current };
								if ( value === '' ) {
									delete next[ attribute.id ];
								} else {
									next[ attribute.id ] = Number( value );
								}
								return next;
							} )
						}
					/>
				</FlexItem>
			) ) }
			{ product?.bundleChoices.map( ( decision, index ) => (
				<FlexItem key={ index }>
					<SelectControl
						__next40pxDefaultSize
						label={ decision.name }
						value={ String( picks[ index ] ?? '' ) }
						options={ [
							{
								label: decision.optional
									? __( '— Leave out —', 'orillacart' )
									: __( '— Choose —', 'orillacart' ),
								value: '',
							},
							...decision.choices.map( ( choice ) => ( {
								label: choice.name,
								value: String( choice.id ),
							} ) ),
						] }
						onChange={ ( value ) =>
							setPicks( ( current ) => {
								const next = { ...current };
								if ( value === '' ) {
									delete next[ index ];
								} else {
									next[ index ] = Number( value );
								}
								return next;
							} )
						}
					/>
				</FlexItem>
			) ) }
			<FlexItem>
				<Button
					variant="secondary"
					disabled={
						product === null ||
						( product.variations.length > 0 && variation === '' ) ||
						product.bundleChoices.some(
							( decision, index ) =>
								! decision.optional &&
								picks[ index ] === undefined
						)
					}
					onClick={ () => {
						if ( product === null ) {
							return;
						}
						const picked = product.variations.find(
							( item ) => String( item.id ) === variation
						);
						onPick( {
							product_id: product.id,
							option_ids: picked
								? picked.option_ids
								: Object.values( chosen ),
							bundle_choices: Object.values( picks ),
							name: picked
								? `${ product.name } – ${ picked.label }`
								: product.name,
						} );
						setProduct( null );
						setChosen( {} );
						setVariation( '' );
						setPicks( {} );
					} }
				>
					{ __( 'Add', 'orillacart' ) }
				</Button>
			</FlexItem>
		</Flex>
	);
}
