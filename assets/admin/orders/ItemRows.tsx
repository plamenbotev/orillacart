import { Button, TextControl } from '@wordpress/components';
import { __, sprintf } from '@wordpress/i18n';
import { closeSmall } from '@wordpress/icons';

import type { EditedItem } from './api';
import { ProductPicker } from './ProductPicker';
import { MoneyControl } from '../shared/MoneyControl';

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
			<div className="oc-table-wrap">
				<table className="oc-table oc-items-table">
					<thead>
						<tr>
							<th scope="col">{ __( 'Item', 'orillacart' ) }</th>
							<th
								scope="col"
								className="oc-table__end"
								style={ { width: '7rem' } }
							>
								{ __( 'Quantity', 'orillacart' ) }
							</th>
							<th
								scope="col"
								className="oc-table__end"
								style={ { width: '10rem' } }
							>
								{ __( 'Unit price', 'orillacart' ) }
							</th>
							<th scope="col" style={ { width: '3rem' } }>
								<span className="screen-reader-text">
									{ __( 'Remove', 'orillacart' ) }
								</span>
							</th>
						</tr>
					</thead>
					<tbody>
						{ rows.map( ( { item, name }, index ) => (
							<tr key={ index }>
								<td>
									{ name ||
										sprintf(
											/* translators: %d: product id */
											__( 'Product %d', 'orillacart' ),
											item.product_id
										) }
								</td>
								<td className="oc-table__end">
									<TextControl
										__next40pxDefaultSize
										__nextHasNoMarginBottom
										type="number"
										min={ 1 }
										label={ __( 'Quantity', 'orillacart' ) }
										hideLabelFromVision
										value={ String( item.quantity ) }
										onChange={ ( value ) =>
											change( index, {
												...item,
												quantity: Math.max(
													1,
													Number.parseInt(
														value,
														10
													) || 1
												),
											} )
										}
									/>
								</td>
								<td className="oc-table__end">
									<MoneyControl
										label={ __(
											'Unit price',
											'orillacart'
										) }
										hideLabelFromVision
										placeholder={ __(
											'Catalog price',
											'orillacart'
										) }
										value={ item.unit_price ?? '' }
										onChange={ ( value ) =>
											change( index, {
												...item,
												unit_price:
													value === '' ? null : value,
											} )
										}
									/>
								</td>
								<td>
									<Button
										icon={ closeSmall }
										label={ __( 'Remove', 'orillacart' ) }
										isDestructive
										onClick={ () =>
											onChange(
												rows.filter(
													( _, i ) => i !== index
												)
											)
										}
									/>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			</div>
			<ProductPicker
				onPick={ ( picked ) =>
					onChange( [
						...rows,
						{
							item: {
								product_id: picked.product_id,
								option_ids: picked.option_ids,
								bundle_choices: picked.bundle_choices,
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
