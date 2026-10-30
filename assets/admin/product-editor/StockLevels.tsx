import {
	Button,
	Flex,
	FlexItem,
	Notice,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	fetchStock,
	type Stock,
	type StockItemKind,
	type Stockroom,
} from '../inventory/api';
import { changeLevels, changedLevels } from '../stock/api';
import { differs, useUnsavedChanges } from '../shared/useUnsavedChanges';

interface Props {
	kind: StockItemKind;
	id: number;
	stockrooms: Stockroom[];
	onError: ( error: unknown ) => void;
}

/**
 * How many of one item each stockroom holds. An empty field means the stockroom holds none of it;
 * with every field empty, it is at 0 in one ("Always available" sells without limit). Saving sends only the stockrooms changed here, as the
 * stock sheet does: another stockroom's level, owed items (below zero) and sales made meanwhile stay as they are.
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
	// The levels that moved since they were loaded (a sale), as they are now, by stockroom: shown until saved.
	const [ moved, setMoved ] = useState< Record<
		number,
		number | null
	> | null >( null );
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
		setMoved( null );
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

	const saved = savedQuantities( stock );
	const loadedLevel = ( stockroom: number ): number | null =>
		stock.levels.find( ( level ) => level.stockroom_id === stockroom )
			?.quantity ?? null;
	/**
	 * @param rebased The levels the owner saw had moved, and saves over: sent as what they were.
	 */
	const save = ( rebased: Record< number, number | null > = {} ) => {
		const changes = stockrooms
			.filter(
				( stockroom ) =>
					( quantities[ stockroom.id ] ?? '' ).trim() !==
					( saved[ stockroom.id ] ?? '' )
			)
			.map( ( stockroom ) => {
				const typed = ( quantities[ stockroom.id ] ?? '' ).trim();
				return {
					kind,
					id,
					stockroom_id: stockroom.id,
					quantity: typed === '' ? null : Number( typed ),
					// What the box loaded: a sale made since refuses the save, rather than be overwritten.
					was:
						stockroom.id in rebased
							? ( rebased[ stockroom.id ] ?? null )
							: loadedLevel( stockroom.id ),
				};
			} );
		if (
			changes.some(
				( change ) =>
					change.quantity !== null &&
					! Number.isInteger( change.quantity )
			)
		) {
			onError(
				new Error( __( 'Count stock in whole numbers.', 'orillacart' ) )
			);
			return;
		}
		if ( changes.length === 0 ) {
			return;
		}
		changeLevels( changes )
			.then( () => fetchStock( kind, id ) )
			.then( show )
			.catch( ( error: unknown ) => {
				const now = changedLevels( error );
				if ( now === null ) {
					onError( error );
					return;
				}
				// Nothing was saved: what was typed stays, beside what the stockrooms hold now.
				setMoved(
					Object.fromEntries(
						now.map( ( level ) => [
							level.stockroom_id,
							level.quantity,
						] )
					)
				);
			} );
	};
	const typedIn = ( stockroom: number ): string =>
		( quantities[ stockroom ] ?? '' ).trim();
	const stockroomName = ( stockroom: number ) =>
		stockrooms.find( ( candidate ) => candidate.id === stockroom )?.name ??
		`#${ stockroom }`;

	return (
		<div className="orillacart-stock">
			{ moved !== null && (
				<Notice status="warning" isDismissible={ false }>
					<p>
						{ __(
							'The stock changed since you opened the product: sales or another save moved it. Nothing was saved.',
							'orillacart'
						) }
					</p>
					<ul>
						{ Object.entries( moved ).map(
							( [ stockroom, now ] ) => (
								<li key={ stockroom }>
									{ sprintf(
										/* translators: 1: stockroom name, 2: the level now, 3: what was typed */
										__(
											'%1$s now holds %2$s; you typed %3$s.',
											'orillacart'
										),
										stockroomName( Number( stockroom ) ),
										now === null
											? __( 'none', 'orillacart' )
											: String( now ),
										typedIn( Number( stockroom ) ) === ''
											? __( 'none', 'orillacart' )
											: typedIn( Number( stockroom ) )
									) }
								</li>
							)
						) }
					</ul>
					<Button variant="secondary" onClick={ () => save( moved ) }>
						{ __( 'Save what I typed anyway', 'orillacart' ) }
					</Button>
				</Notice>
			) }
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
