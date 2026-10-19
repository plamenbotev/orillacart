import { Button, Flex, FlexItem, TextControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	fetchStock,
	saveStock,
	type Stock,
	type StockItemKind,
	type Stockroom,
} from '../inventory/api';
import { differs, useUnsavedChanges } from '../shared/useUnsavedChanges';

interface Props {
	kind: StockItemKind;
	id: number;
	stockrooms: Stockroom[];
	onError: ( error: unknown ) => void;
}

/**
 * How many of one item each stockroom holds. An empty field means the stockroom holds none of it;
 * with every field empty, stock does not limit the item.
 * @param root0
 * @param root0.kind
 * @param root0.id
 * @param root0.stockrooms
 * @param root0.onError
 */
export function StockLevels( { kind, id, stockrooms, onError }: Props ) {
	const [ stock, setStock ] = useState< Stock | null >( null );
	const [ quantities, setQuantities ] = useState< Record< number, string > >(
		{}
	);
	const savedQuantities = ( loaded: Stock ): Record< number, string > =>
		Object.fromEntries(
			loaded.levels.map( ( level ) => [
				level.stockroom_id,
				String( level.quantity ),
			] )
		);
	useUnsavedChanges(
		stock !== null && differs( quantities, savedQuantities( stock ) )
	);

	const show = ( loaded: Stock ) => {
		setStock( loaded );
		setQuantities( savedQuantities( loaded ) );
	};

	useEffect( () => {
		fetchStock( kind, id ).then( show ).catch( onError );
		// eslint-disable-next-line react-hooks/exhaustive-deps -- onError changes on every render
	}, [ kind, id ] );

	if ( stock === null ) {
		return null;
	}
	if ( stockrooms.length === 0 ) {
		return (
			<p>
				{ __(
					'Add a stockroom under Shop → Stockrooms to count stock.',
					'orillacart'
				) }
			</p>
		);
	}

	const save = () =>
		saveStock(
			kind,
			id,
			stockrooms
				.filter(
					( stockroom ) =>
						( quantities[ stockroom.id ] ?? '' ).trim() !== ''
				)
				.map( ( stockroom ) => ( {
					stockroom_id: stockroom.id,
					quantity: Math.max(
						0,
						Number.parseInt(
							quantities[ stockroom.id ] ?? '0',
							10
						) || 0
					),
				} ) )
		)
			.then( show )
			.catch( onError );

	return (
		<div className="orillacart-stock">
			{ stockrooms.map( ( stockroom ) => (
				<TextControl
					key={ stockroom.id }
					__next40pxDefaultSize
					type="number"
					min={ 0 }
					label={
						stockroom.active
							? stockroom.name
							: sprintf(
									/* translators: %s: stockroom name */
									__( '%s (inactive)', 'orillacart' ),
									stockroom.name
								)
					}
					value={ quantities[ stockroom.id ] ?? '' }
					onChange={ ( value ) =>
						setQuantities( {
							...quantities,
							[ stockroom.id ]: value,
						} )
					}
				/>
			) ) }
			<Flex align="center">
				<FlexItem>
					{ stock.available === null
						? __( 'Not limited by stock', 'orillacart' )
						: sprintf(
								/* translators: %d: number of items */
								__( '%d available', 'orillacart' ),
								stock.available
							) }
				</FlexItem>
				<Button variant="secondary" onClick={ () => void save() }>
					{ __( 'Save stock', 'orillacart' ) }
				</Button>
			</Flex>
		</div>
	);
}
