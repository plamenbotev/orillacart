import {
	Button,
	ComboboxControl,
	Flex,
	FlexItem,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

import { fetchStockrooms } from '../inventory/api';
import { when } from '../orders/labels';
import { searchProducts } from '../orders/products';
import { errorMessage } from '../shared/api';
import { fromLocal } from '../shared/dates';
import { useLatest } from '../shared/useLatest';
import {
	fetchStockHistory,
	type StockHistory,
	type StockHistoryQuery,
	type StockReason,
} from './api';

export const REASONS: Record< StockReason, string > = {
	sale: __( 'Sold', 'orillacart' ),
	release: __( 'Given back by an order', 'orillacart' ),
	return: __( 'Returned', 'orillacart' ),
	count: __( 'Counted', 'orillacart' ),
	import: __( 'Imported', 'orillacart' ),
	bulk_edit: __( 'Bulk edit', 'orillacart' ),
};

/**
 * The stock history: every move of a level, newest first, by product, stockroom, reason and time, with what
 * moved in and out in all for the time looked at.
 */
export function HistoryTab() {
	const [ query, setQuery ] = useState< StockHistoryQuery >( {
		product: null,
		stockroom: null,
		reason: null,
		from: null,
		before: null,
		page: 1,
	} );
	const [ history, setHistory ] = useState< StockHistory | null >( null );
	const [ rooms, setRooms ] = useState< { id: number; name: string }[] >(
		[]
	);
	const [ products, setProducts ] = useState<
		{ id: number; title: string }[]
	>( [] );
	const [ days, setDays ] = useState( { from: '', before: '' } );
	const [ error, setError ] = useState< string | null >( null );
	const latest = useLatest();

	useEffect( () => {
		fetchStockrooms()
			.then( setRooms )
			.catch( () => setRooms( [] ) );
	}, [] );
	useEffect( () => {
		setHistory( null );
		latest( fetchStockHistory( query ) )
			.then( setHistory )
			.catch( ( reason: unknown ) => setError( errorMessage( reason ) ) );
	}, [ query, latest ] );

	const change = ( changes: Partial< StockHistoryQuery > ) =>
		setQuery( { ...query, ...changes, page: 1 } );
	const day = ( value: string ) =>
		value === '' ? null : fromLocal( `${ value }T00:00` );

	return (
		<>
			{ error !== null && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }
			<Flex align="flex-end" wrap>
				<FlexItem>
					<ComboboxControl
						__next40pxDefaultSize
						label={ __( 'Product', 'orillacart' ) }
						value={
							query.product === null
								? null
								: String( query.product )
						}
						options={ products.map( ( found ) => ( {
							value: String( found.id ),
							label: found.title,
						} ) ) }
						onFilterValueChange={ ( text ) => {
							if ( text.trim().length > 1 ) {
								searchProducts( text )
									.then( setProducts )
									.catch( () => setProducts( [] ) );
							}
						} }
						onChange={ ( value ) =>
							change( {
								product:
									value === null || value === undefined
										? null
										: Number( value ),
							} )
						}
					/>
				</FlexItem>
				<FlexItem>
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Stockroom', 'orillacart' ) }
						value={
							query.stockroom === null
								? ''
								: String( query.stockroom )
						}
						options={ [
							{ value: '', label: __( 'All', 'orillacart' ) },
							...rooms.map( ( room ) => ( {
								value: String( room.id ),
								label: room.name,
							} ) ),
						] }
						onChange={ ( value ) =>
							change( {
								stockroom:
									value === '' ? null : Number( value ),
							} )
						}
					/>
				</FlexItem>
				<FlexItem>
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Why', 'orillacart' ) }
						value={ query.reason ?? '' }
						options={ [
							{ value: '', label: __( 'All', 'orillacart' ) },
							...Object.entries( REASONS ).map(
								( [ value, label ] ) => ( { value, label } )
							),
						] }
						onChange={ ( value ) =>
							change( {
								reason:
									value === ''
										? null
										: ( value as StockReason ),
							} )
						}
					/>
				</FlexItem>
				<FlexItem>
					<TextControl
						__next40pxDefaultSize
						type="date"
						label={ __( 'From', 'orillacart' ) }
						value={ days.from }
						onChange={ ( value ) => {
							setDays( { ...days, from: value } );
							change( { from: day( value ) } );
						} }
					/>
				</FlexItem>
				<FlexItem>
					<TextControl
						__next40pxDefaultSize
						type="date"
						label={ __( 'Before', 'orillacart' ) }
						value={ days.before }
						onChange={ ( value ) => {
							setDays( { ...days, before: value } );
							change( { before: day( value ) } );
						} }
					/>
				</FlexItem>
			</Flex>
			{ history === null ? (
				<Spinner />
			) : (
				<>
					{ history.totals.length > 0 && (
						<table className="widefat striped orillacart-stock-totals">
							<thead>
								<tr>
									<th>{ __( 'Stockroom', 'orillacart' ) }</th>
									<th>{ __( 'Why', 'orillacart' ) }</th>
									<th>{ __( 'In', 'orillacart' ) }</th>
									<th>{ __( 'Out', 'orillacart' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ history.totals.map( ( total ) => (
									<tr
										key={ `${ total.stockroomId }:${ total.reason }` }
									>
										<td>{ total.stockroom }</td>
										<td>{ REASONS[ total.reason ] }</td>
										<td>{ total.in }</td>
										<td>{ total.out }</td>
									</tr>
								) ) }
							</tbody>
						</table>
					) }
					<table className="widefat striped">
						<thead>
							<tr>
								<th>{ __( 'When', 'orillacart' ) }</th>
								<th>{ __( 'Item', 'orillacart' ) }</th>
								<th>{ __( 'Stockroom', 'orillacart' ) }</th>
								<th>{ __( 'Change', 'orillacart' ) }</th>
								<th>{ __( 'Then', 'orillacart' ) }</th>
								<th>{ __( 'Why', 'orillacart' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ history.movements.length === 0 && (
								<tr>
									<td colSpan={ 6 }>
										{ __(
											'Nothing moved in this time.',
											'orillacart'
										) }
									</td>
								</tr>
							) }
							{ history.movements.map( ( movement ) => (
								<tr key={ movement.id }>
									<td>{ when( movement.at ) }</td>
									<td>
										{ movement.name }
										{ movement.sku !== null && (
											<small> { movement.sku }</small>
										) }
									</td>
									<td>{ movement.stockroom }</td>
									<td>
										{ movement.delta > 0
											? `+${ movement.delta }`
											: movement.delta }
									</td>
									<td>{ movement.after }</td>
									<td>
										{ REASONS[ movement.reason ] }
										{ movement.order_id !== null && (
											<>
												{ ' ' }
												<a
													href={ addQueryArgs(
														'admin.php',
														{
															page: 'orillacart-orders',
															s: movement.order_id,
														}
													) }
												>
													{ sprintf(
														/* translators: %d: order number */
														__(
															'#%d',
															'orillacart'
														),
														movement.order_id
													) }
												</a>
											</>
										) }
										{ movement.user !== null &&
											` — ${ movement.user }` }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
					<Flex justify="flex-end">
						<Button
							variant="tertiary"
							disabled={ history.page <= 1 }
							onClick={ () =>
								setQuery( { ...query, page: query.page - 1 } )
							}
						>
							{ __( 'Newer', 'orillacart' ) }
						</Button>
						<Button
							variant="tertiary"
							disabled={ history.page >= history.pages }
							onClick={ () =>
								setQuery( { ...query, page: query.page + 1 } )
							}
						>
							{ __( 'Older', 'orillacart' ) }
						</Button>
					</Flex>
				</>
			) }
		</>
	);
}
