import {
	Button,
	CheckboxControl,
	Flex,
	FlexItem,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { TranslationFields, useOtherLanguages } from '../shared/Translations';
import type { AdjustmentKind, AttributeInput } from './api';
import { SortableItem, move } from './SortableItem';

interface Props {
	/** The whole list, in display order. */
	attributes: AttributeInput[];
	onChange: ( attributes: AttributeInput[] ) => void;
}

type Option = AttributeInput[ 'options' ][ number ];

let lastKey = 0;
const newKey = () => `new-${ ++lastKey }`;

/**
 * Keys for items that have no id yet, kept in step with the list as items are added, removed and moved,
 * so each item keeps its own open or closed state.
 */
function useKeys() {
	const keys = useRef< { attributes: string[]; options: string[][] } >( {
		attributes: [],
		options: [],
	} );
	return {
		attribute: ( index: number, attribute: AttributeInput ) =>
			attribute.id !== undefined
				? `id-${ attribute.id }`
				: ( keys.current.attributes[ index ] ??= newKey() ),
		option: ( index: number, optionIndex: number, option: Option ) =>
			option.id !== undefined
				? `id-${ option.id }`
				: ( ( keys.current.options[ index ] ??= [] )[ optionIndex ] ??=
						newKey() ),
		moveAttribute: ( from: number, to: number ) => {
			keys.current.attributes = move( keys.current.attributes, from, to );
			keys.current.options = move( keys.current.options, from, to );
		},
		removeAttribute: ( index: number ) => {
			keys.current.attributes.splice( index, 1 );
			keys.current.options.splice( index, 1 );
		},
		moveOption: ( index: number, from: number, to: number ) => {
			keys.current.options[ index ] = move(
				keys.current.options[ index ] ?? [],
				from,
				to
			);
		},
		removeOption: ( index: number, optionIndex: number ) => {
			keys.current.options[ index ]?.splice( optionIndex, 1 );
		},
	};
}

/**
 * Edits a list of attributes with their options, for an attribute set or a product's own attributes.
 * Attributes and options are dragged into order (or moved with their arrows) and fold away; saved ones start
 * folded. Nothing is saved here; the owner sends the whole list, whose order is the display order.
 *
 * @param props            The editor's properties.
 * @param props.attributes The attributes, in display order.
 * @param props.onChange   Receives the changed list.
 */
export function AttributesEditor( { attributes, onChange }: Props ) {
	const keys = useKeys();
	// Names in the site's other languages, under each name; none on a site in one language.
	const languages = useOtherLanguages();
	const update = ( index: number, attribute: AttributeInput ) =>
		onChange(
			attributes.map( ( current, i ) =>
				i === index ? attribute : current
			)
		);
	const updateOption = (
		index: number,
		optionIndex: number,
		option: Option
	) => {
		const attribute = attributes[ index ];
		if ( attribute ) {
			update( index, {
				...attribute,
				options: attribute.options.map( ( current, i ) =>
					i === optionIndex ? option : current
				),
			} );
		}
	};

	return (
		<div className="orillacart-attributes">
			{ attributes.map( ( attribute, index ) => (
				<SortableItem
					key={ keys.attribute( index, attribute ) }
					group="attribute"
					index={ index }
					count={ attributes.length }
					title={ attribute.name }
					initiallyOpen={ attribute.id === undefined }
					onMove={ ( from, to ) => {
						keys.moveAttribute( from, to );
						onChange( move( attributes, from, to ) );
					} }
					actions={
						<Button
							size="small"
							variant="tertiary"
							isDestructive
							onClick={ () => {
								keys.removeAttribute( index );
								onChange(
									attributes.filter( ( _, i ) => i !== index )
								);
							} }
						>
							{ sprintf(
								/* translators: %s: what is removed: a product, an attribute or a gift card */
								__( 'Remove %s', 'orillacart' ),
								attribute.name ||
									__( 'attribute', 'orillacart' )
							) }
						</Button>
					}
				>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'Attribute', 'orillacart' ) }
						value={ attribute.name }
						onChange={ ( name ) =>
							update( index, { ...attribute, name } )
						}
					/>
					<TranslationFields
						label={ __( 'Attribute', 'orillacart' ) }
						languages={ languages }
						value={ attribute.translations }
						onChange={ ( translations ) =>
							update( index, { ...attribute, translations } )
						}
					/>
					<CheckboxControl
						label={ __(
							'Customers must choose one',
							'orillacart'
						) }
						checked={ attribute.required }
						onChange={ ( required ) =>
							update( index, { ...attribute, required } )
						}
					/>
					<CheckboxControl
						label={ __( 'Hide the option prices', 'orillacart' ) }
						checked={ attribute.price_hidden }
						onChange={ ( priceHidden ) =>
							update( index, {
								...attribute,
								price_hidden: priceHidden,
							} )
						}
					/>
					<CheckboxControl
						label={ __(
							'Show in the product’s specifications',
							'orillacart'
						) }
						checked={ attribute.in_specifications !== false }
						onChange={ ( inSpecifications ) =>
							update( index, {
								...attribute,
								in_specifications: inSpecifications,
							} )
						}
					/>
					{ attribute.options.map( ( option, optionIndex ) => (
						<SortableItem
							key={ keys.option( index, optionIndex, option ) }
							group={ `option-${ index }` }
							index={ optionIndex }
							count={ attribute.options.length }
							title={ option.name }
							initiallyOpen={ option.id === undefined }
							onMove={ ( from, to ) => {
								keys.moveOption( index, from, to );
								update( index, {
									...attribute,
									options: move(
										attribute.options,
										from,
										to
									),
								} );
							} }
							actions={
								<Button
									size="small"
									variant="tertiary"
									isDestructive
									label={ sprintf(
										/* translators: %s: option name */
										__( 'Remove option %s', 'orillacart' ),
										option.name
									) }
									onClick={ () => {
										keys.removeOption( index, optionIndex );
										update( index, {
											...attribute,
											options: attribute.options.filter(
												( _, i ) => i !== optionIndex
											),
										} );
									} }
								>
									×
								</Button>
							}
						>
							<Flex align="flex-end">
								<FlexItem isBlock>
									<TextControl
										__next40pxDefaultSize
										label={ __( 'Option', 'orillacart' ) }
										value={ option.name }
										onChange={ ( name ) =>
											updateOption( index, optionIndex, {
												...option,
												name,
											} )
										}
									/>
								</FlexItem>
								<FlexItem>
									<TextControl
										__next40pxDefaultSize
										label={ __(
											'Price change',
											'orillacart'
										) }
										value={ option.adjustment }
										onChange={ ( adjustment ) =>
											updateOption( index, optionIndex, {
												...option,
												adjustment,
											} )
										}
									/>
								</FlexItem>
								<FlexItem>
									<SelectControl
										__next40pxDefaultSize
										label={ __( 'As', 'orillacart' ) }
										value={ option.adjustment_kind }
										options={ [
											{
												value: 'amount',
												label: __(
													'Amount',
													'orillacart'
												),
											},
											{
												value: 'percent',
												label: __(
													'% of the price',
													'orillacart'
												),
											},
										] }
										onChange={ ( kind ) =>
											updateOption( index, optionIndex, {
												...option,
												adjustment_kind:
													kind as AdjustmentKind,
											} )
										}
									/>
								</FlexItem>
							</Flex>
							<TranslationFields
								label={ __( 'Option', 'orillacart' ) }
								languages={ languages }
								value={ option.translations }
								onChange={ ( translations ) =>
									updateOption( index, optionIndex, {
										...option,
										translations,
									} )
								}
							/>
						</SortableItem>
					) ) }
					<Button
						variant="secondary"
						onClick={ () =>
							update( index, {
								...attribute,
								options: [
									...attribute.options,
									{
										name: '',
										adjustment: '0',
										adjustment_kind: 'amount',
									},
								],
							} )
						}
					>
						{ __( 'Add option', 'orillacart' ) }
					</Button>
				</SortableItem>
			) ) }
			<Button
				variant="secondary"
				onClick={ () =>
					onChange( [
						...attributes,
						{
							name: '',
							required: false,
							price_hidden: false,
							in_specifications: true,
							options: [
								{
									name: '',
									adjustment: '0',
									adjustment_kind: 'amount',
								},
							],
						},
					] )
				}
			>
				{ __( 'Add attribute', 'orillacart' ) }
			</Button>
		</div>
	);
}
