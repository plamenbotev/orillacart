import {
	Button,
	ComboboxControl,
	Flex,
	FlexItem,
	Notice,
	SearchControl,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { searchProducts } from '../orders/products';
import { errorMessage } from '../shared/api';
import {
	changeLevels,
	fetchCategories,
	fetchStockSheet,
	type LevelChange,
	type StockSheet,
	type StockSheetQuery,
} from './api';
import { useLatest } from '../shared/useLatest';
import { useUnsavedChanges } from '../shared/useUnsavedChanges';

const MODES: Record< string, string > = {
	tracked: __( 'Counted', 'orillacart' ),
	always_available: __( 'Always available', 'orillacart' ),
	unavailable: __( 'Not for sale', 'orillacart' ),
};

/** A changed cell: the typed text, '' for "no level". */
type Edits = Record<
	string,
	{ change: Omit< LevelChange, 'quantity' >; text: string }
>;

const cellKey = ( kind: string, id: number, stockroom: number ) =>
	`${ kind }:${ id }:${ stockroom }`;

/**
 * Shop → Stock: every product and variation, or every attribute option, with what each stockroom holds; the
 * shop counts and corrects many at once. Changes are kept while paging and filtering until they are saved.
 */
export function StockScreen() {
	const [ query, setQuery ] = useState< StockSheetQuery >( {
		kind: 'items',
		stockroom: null,
		category: null,
		search: '',
		parent: null,
		page: 1,
	} );
	const [ search, setSearch ] = useState( '' );
	const [ sheet, setSheet ] = useState< StockSheet | null >( null );
	const [ categories, setCategories ] = useState<
		{ id: number; name: string; depth: number }[]
	>( [] );
	const [ parents, setParents ] = useState< { id: number; title: string }[] >(
		[]
	);
	const [ parentSearch, setParentSearch ] = useState( '' );
	const [ edits, setEdits ] = useState< Edits >( {} );
	useUnsavedChanges( Object.keys( edits ).length > 0 );
	const [ status, setStatus ] = useState<
		| { kind: 'idle' | 'saving' | 'saved' }
		| { kind: 'error'; message: string }
	>( { kind: 'idle' } );

	useEffect( () => {
		fetchCategories()
			.then( setCategories )
			.catch( () => setCategories( [] ) );
	}, [] );

	// The search waits for a pause in typing.
	useEffect( () => {
		const timer = window.setTimeout(
			() =>
				setQuery( ( current ) =>
					current.search === search
						? current
						: { ...current, search, page: 1 }
				),
			300
		);
		return () => window.clearTimeout( timer );
	}, [ search ] );

	useEffect( () => {
		const timer = window.setTimeout( () => {
			searchProducts( parentSearch )
				.then( setParents )
				.catch( () => setParents( [] ) );
		}, 250 );
		return () => window.clearTimeout( timer );
	}, [ parentSearch ] );

	const latest = useLatest();
	useEffect( () => {
		setSheet( null );
		latest( fetchStockSheet( query ) )
			.then( setSheet )
			.catch( ( error: unknown ) =>
				setStatus( {
					kind: 'error',
					message: errorMessage( error ),
				} )
			);
	}, [ query, latest ] );

	const filter = ( change: Partial< StockSheetQuery > ) =>
		setQuery( ( current ) => ( {
			...current,
			...change,
			page: change.page ?? 1,
		} ) );

	const changed = Object.keys( edits ).length;
	const invalid = Object.values( edits ).some(
		( edit ) => edit.text !== '' && ! /^\d+$/.test( edit.text )
	);

	const save = () => {
		setStatus( { kind: 'saving' } );
		changeLevels(
			Object.values( edits ).map( ( edit ) => ( {
				...edit.change,
				quantity: edit.text === '' ? null : Number( edit.text ),
			} ) )
		)
			.then( () => {
				setEdits( {} );
				setStatus( { kind: 'saved' } );
				setQuery( ( current ) => ( { ...current } ) );
			} )
			.catch( ( error: unknown ) =>
				setStatus( {
					kind: 'error',
					message: errorMessage( error ),
				} )
			);
	};

	return (
		<>
			<h1>{ __( 'Stock', 'orillacart' ) }</h1>
			<p>
				{ __(
					'How many of each item every stockroom holds. An empty field means the stockroom holds none and keeps no count; an item no stockroom counts is not limited by stock.',
					'orillacart'
				) }
			</p>
			{ status.kind === 'error' && (
				<Notice status="error" isDismissible={ false }>
					{ status.message }
				</Notice>
			) }
			{ status.kind === 'saved' && (
				<Notice status="success" isDismissible={ false }>
					{ __( 'Stock saved.', 'orillacart' ) }
				</Notice>
			) }

			<Flex className="orillacart-stock__filters" align="flex-end" wrap>
				<FlexItem>
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Show', 'orillacart' ) }
						value={ query.kind }
						options={ [
							{
								label: __(
									'Products and variations',
									'orillacart'
								),
								value: 'items',
							},
							{
								label: __( 'Attribute options', 'orillacart' ),
								value: 'options',
							},
						] }
						onChange={ ( kind ) =>
							filter( {
								kind: kind as StockSheetQuery[ 'kind' ],
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
							{
								label: __( 'All stockrooms', 'orillacart' ),
								value: '',
							},
							...( sheet?.stockrooms ?? [] ).map(
								( stockroom ) => ( {
									label: stockroom.name,
									value: String( stockroom.id ),
								} )
							),
						] }
						onChange={ ( value ) =>
							filter( {
								stockroom:
									value === '' ? null : Number( value ),
							} )
						}
					/>
				</FlexItem>
				{ query.kind === 'items' && (
					<>
						<FlexItem>
							<SelectControl
								__next40pxDefaultSize
								label={ __( 'Category', 'orillacart' ) }
								value={
									query.category === null
										? ''
										: String( query.category )
								}
								options={ [
									{
										label: __(
											'All categories',
											'orillacart'
										),
										value: '',
									},
									...categories.map( ( category ) => ( {
										label: `${ '— '.repeat( category.depth ) }${ category.name }`,
										value: String( category.id ),
									} ) ),
								] }
								onChange={ ( value ) =>
									filter( {
										category:
											value === ''
												? null
												: Number( value ),
									} )
								}
							/>
						</FlexItem>
						<FlexItem>
							<ComboboxControl
								__next40pxDefaultSize
								label={ __( 'One product', 'orillacart' ) }
								value={
									query.parent === null
										? null
										: String( query.parent )
								}
								options={ parents.map( ( product ) => ( {
									label: product.title,
									value: String( product.id ),
								} ) ) }
								onFilterValueChange={ setParentSearch }
								onChange={ ( value ) =>
									filter( {
										parent: value ? Number( value ) : null,
									} )
								}
							/>
						</FlexItem>
					</>
				) }
				<FlexItem isBlock>
					<SearchControl
						__nextHasNoMarginBottom
						label={
							query.kind === 'items'
								? __(
										'Search by name, SKU or ID',
										'orillacart'
									)
								: __(
										'Search by option, attribute or set',
										'orillacart'
									)
						}
						value={ search }
						onChange={ setSearch }
					/>
				</FlexItem>
			</Flex>

			{ sheet === null ? (
				status.kind !== 'error' && <Spinner />
			) : (
				<>
					<table className="wp-list-table widefat striped orillacart-stock__table">
						<thead>
							<tr>
								<th
									scope="col"
									className="orillacart-stock__id"
								>
									{ __( 'ID', 'orillacart' ) }
								</th>
								<th scope="col">
									{ query.kind === 'items'
										? __( 'Product', 'orillacart' )
										: __( 'Option', 'orillacart' ) }
								</th>
								{ query.kind === 'options' && (
									<th scope="col">
										{ __( 'Belongs to', 'orillacart' ) }
									</th>
								) }
								{ query.kind === 'items' && (
									<th scope="col">
										{ __( 'SKU', 'orillacart' ) }
									</th>
								) }
								{ query.kind === 'items' && (
									<th scope="col">
										{ __( 'Stock', 'orillacart' ) }
									</th>
								) }
								{ sheet.stockrooms.map( ( stockroom ) => (
									<th
										scope="col"
										key={ stockroom.id }
										className="orillacart-stock__amount"
									>
										{ stockroom.name }
									</th>
								) ) }
							</tr>
						</thead>
						<tbody>
							{ sheet.rows.length === 0 && (
								<tr>
									<td colSpan={ 4 + sheet.stockrooms.length }>
										{ __(
											'Nothing matches.',
											'orillacart'
										) }
									</td>
								</tr>
							) }
							{ sheet.rows.map( ( row ) => (
								<tr key={ `${ row.kind }:${ row.id }` }>
									<td className="orillacart-stock__id">
										{ row.id }
									</td>
									<td>
										{ row.product_id === null ? (
											row.name
										) : (
											<a
												href={ `post.php?post=${ row.product_id }&action=edit` }
											>
												{ row.name }
											</a>
										) }
									</td>
									{ query.kind === 'options' && (
										<td>{ row.owner }</td>
									) }
									{ query.kind === 'items' && (
										<td>{ row.sku ?? '' }</td>
									) }
									{ query.kind === 'items' && (
										<td>
											{ MODES[ row.stock_mode ] ??
												row.stock_mode }
										</td>
									) }
									{ sheet.stockrooms.map( ( stockroom ) => {
										const key = cellKey(
											row.kind,
											row.id,
											stockroom.id
										);
										const saved =
											row.levels[
												String( stockroom.id )
											];
										const text =
											edits[ key ]?.text ??
											( saved === undefined
												? ''
												: String( saved ) );
										return (
											<td
												key={ stockroom.id }
												className="orillacart-stock__amount"
											>
												<input
													type="number"
													min={ 0 }
													step={ 1 }
													inputMode="numeric"
													placeholder="—"
													className={
														edits[ key ]
															? 'is-changed'
															: undefined
													}
													aria-label={ sprintf(
														/* translators: 1: item name, 2: stockroom name */
														__(
															'%1$s in %2$s',
															'orillacart'
														),
														row.name,
														stockroom.name
													) }
													value={ text }
													onChange={ ( event ) => {
														const value =
															event.target.value.trim();
														setEdits(
															( current ) => {
																const next = {
																	...current,
																};
																if (
																	value ===
																	( saved ===
																	undefined
																		? ''
																		: String(
																				saved
																			) )
																) {
																	delete next[
																		key
																	];
																} else {
																	next[
																		key
																	] = {
																		change: {
																			kind: row.kind,
																			id: row.id,
																			stockroom_id:
																				stockroom.id,
																		},
																		text: value,
																	};
																}
																return next;
															}
														);
													} }
												/>
											</td>
										);
									} ) }
								</tr>
							) ) }
						</tbody>
					</table>

					<Flex
						className="orillacart-stock__footer"
						justify="space-between"
					>
						<Flex justify="flex-start" expanded={ false }>
							<Button
								variant="primary"
								disabled={
									changed === 0 ||
									invalid ||
									status.kind === 'saving'
								}
								isBusy={ status.kind === 'saving' }
								onClick={ save }
							>
								{ changed === 0
									? __( 'Save changes', 'orillacart' )
									: sprintf(
											/* translators: %d: number of changed amounts */
											_n(
												'Save %d change',
												'Save %d changes',
												changed,
												'orillacart'
											),
											changed
										) }
							</Button>
							{ changed > 0 && (
								<Button
									variant="tertiary"
									onClick={ () => setEdits( {} ) }
								>
									{ __( 'Undo changes', 'orillacart' ) }
								</Button>
							) }
							{ invalid && (
								<span className="orillacart-stock__invalid">
									{ __(
										'Amounts are whole numbers of 0 or more.',
										'orillacart'
									) }
								</span>
							) }
						</Flex>
						<Flex justify="flex-end" expanded={ false }>
							<Button
								variant="secondary"
								disabled={ sheet.page <= 1 }
								onClick={ () =>
									filter( { page: sheet.page - 1 } )
								}
							>
								{ __( 'Previous', 'orillacart' ) }
							</Button>
							<span>
								{ sprintf(
									/* translators: 1: the page, 2: how many pages */
									__( 'Page %1$d of %2$d', 'orillacart' ),
									sheet.page,
									sheet.pages
								) }
							</span>
							<Button
								variant="secondary"
								disabled={ sheet.page >= sheet.pages }
								onClick={ () =>
									filter( { page: sheet.page + 1 } )
								}
							>
								{ __( 'Next', 'orillacart' ) }
							</Button>
						</Flex>
					</Flex>
				</>
			) }
		</>
	);
}
