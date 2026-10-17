import {
	Button,
	CheckboxControl,
	Flex,
	FlexItem,
	TextControl,
} from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import type { AttributeInput } from './api';

interface Props {
	/** The whole list, in display order. */
	attributes: AttributeInput[];
	onChange: ( attributes: AttributeInput[] ) => void;
}

/**
 * Edits a list of attributes with their options, for an attribute set or a product's own attributes.
 * Nothing is saved here; the owner sends the whole list.
 * @param root0
 * @param root0.attributes
 * @param root0.onChange
 */
export function AttributesEditor( { attributes, onChange }: Props ) {
	const update = ( index: number, attribute: AttributeInput ) =>
		onChange(
			attributes.map( ( current, i ) =>
				i === index ? attribute : current
			)
		);

	return (
		<div className="orillacart-attributes">
			{ attributes.map( ( attribute, index ) => (
				<fieldset
					key={ attribute.id ?? `new-${ index }` }
					className="orillacart-attribute"
				>
					<Flex align="flex-end">
						<FlexItem isBlock>
							<TextControl
								__next40pxDefaultSize
								label={ __( 'Attribute', 'orillacart' ) }
								value={ attribute.name }
								onChange={ ( name ) =>
									update( index, { ...attribute, name } )
								}
							/>
						</FlexItem>
						<Button
							variant="tertiary"
							isDestructive
							onClick={ () =>
								onChange(
									attributes.filter( ( _, i ) => i !== index )
								)
							}
						>
							{ sprintf(
								/* translators: %s: attribute name */
								__( 'Remove %s', 'orillacart' ),
								attribute.name ||
									__( 'attribute', 'orillacart' )
							) }
						</Button>
					</Flex>
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
					{ attribute.options.map( ( option, optionIndex ) => (
						<Flex
							key={ option.id ?? `new-${ optionIndex }` }
							align="flex-end"
						>
							<FlexItem isBlock>
								<TextControl
									__next40pxDefaultSize
									label={ __( 'Option', 'orillacart' ) }
									value={ option.name }
									onChange={ ( name ) =>
										update( index, {
											...attribute,
											options: attribute.options.map(
												( current, i ) =>
													i === optionIndex
														? { ...current, name }
														: current
											),
										} )
									}
								/>
							</FlexItem>
							<FlexItem>
								<TextControl
									__next40pxDefaultSize
									label={ __( 'Price change', 'orillacart' ) }
									value={ option.adjustment }
									onChange={ ( adjustment ) =>
										update( index, {
											...attribute,
											options: attribute.options.map(
												( current, i ) =>
													i === optionIndex
														? {
																...current,
																adjustment,
															}
														: current
											),
										} )
									}
								/>
							</FlexItem>
							<Button
								variant="tertiary"
								isDestructive
								label={ sprintf(
									/* translators: %s: option name */
									__( 'Remove option %s', 'orillacart' ),
									option.name
								) }
								onClick={ () =>
									update( index, {
										...attribute,
										options: attribute.options.filter(
											( _, i ) => i !== optionIndex
										),
									} )
								}
							>
								×
							</Button>
						</Flex>
					) ) }
					<Button
						variant="secondary"
						onClick={ () =>
							update( index, {
								...attribute,
								options: [
									...attribute.options,
									{ name: '', adjustment: '0' },
								],
							} )
						}
					>
						{ __( 'Add option', 'orillacart' ) }
					</Button>
				</fieldset>
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
							options: [ { name: '', adjustment: '0' } ],
						},
					] )
				}
			>
				{ __( 'Add attribute', 'orillacart' ) }
			</Button>
		</div>
	);
}
