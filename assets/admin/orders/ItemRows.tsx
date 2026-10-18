import { Button, Flex, FlexItem, TextControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';

import type { EditedItem } from './api';
import { ProductPicker } from './ProductPicker';

/** An item being edited, with the name to show: order lines have one, picked products bring theirs. */
export interface ItemRow {
	item: EditedItem;
	name: string;
}

/**
 * The items of an order being taken or changed: quantities, the shop's own unit prices, and products to add.
 *
 * @param props          The rows and what to do when they change.
 * @param props.rows     The items.
 * @param props.onChange Receives the changed rows.
 */
export function ItemRows( {
	rows,
	onChange,
}: {
	rows: ItemRow[];
	onChange: ( rows: ItemRow[] ) => void;
} ) {
	const change = ( index: number, item: EditedItem ) =>
		onChange(
			rows.map( ( row, i ) => ( i === index ? { ...row, item } : row ) )
		);

	return (
		<>
			{ rows.map( ( { item, name }, index ) => (
				<Flex key={ index } align="flex-end">
					<FlexItem isBlock>
						{ name ||
							sprintf(
								/* translators: %d: product id */
								__( 'Product %d', 'orillacart' ),
								item.product_id
							) }
					</FlexItem>
					<FlexItem>
						<TextControl
							__next40pxDefaultSize
							type="number"
							min={ 1 }
							label={ __( 'Quantity', 'orillacart' ) }
							value={ String( item.quantity ) }
							onChange={ ( value ) =>
								change( index, {
									...item,
									quantity: Math.max(
										1,
										Number.parseInt( value, 10 ) || 1
									),
								} )
							}
						/>
					</FlexItem>
					<FlexItem>
						<TextControl
							__next40pxDefaultSize
							label={ __( 'Unit price', 'orillacart' ) }
							help={ __(
								'Empty for the catalog price',
								'orillacart'
							) }
							value={ item.unit_price ?? '' }
							onChange={ ( value ) =>
								change( index, {
									...item,
									unit_price: value === '' ? null : value,
								} )
							}
						/>
					</FlexItem>
					<FlexItem>
						<Button
							variant="tertiary"
							isDestructive
							onClick={ () =>
								onChange(
									rows.filter( ( _, i ) => i !== index )
								)
							}
						>
							{ __( 'Remove', 'orillacart' ) }
						</Button>
					</FlexItem>
				</Flex>
			) ) }
			<ProductPicker
				onPick={ ( picked ) =>
					onChange( [
						...rows,
						{
							item: {
								product_id: picked.product_id,
								option_ids: picked.option_ids,
								quantity: 1,
								unit_price: null,
							},
							name: picked.name,
						},
					] )
				}
			/>
		</>
	);
}
