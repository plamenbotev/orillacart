import { Notice } from '@wordpress/components';
import { DataViews, type Field, type View } from '@wordpress/dataviews';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import '../shared/dataviews.css';

import { toApiError } from '../shared/api';
import {
	fetchOrders,
	type OrderPage,
	type OrderSummary,
	type Status,
	STATUSES,
} from './api';
import { STATUS_LABELS, money, when } from './labels';
import { OrderDetail } from './OrderDetail';

const FIELDS: Field< OrderSummary >[] = [
	{
		id: 'number',
		label: __( 'Order', 'orillacart' ),
		enableHiding: false,
		enableSorting: false,
		render: ( { item } ) => <strong>#{ item.number }</strong>,
	},
	{
		id: 'placed_at',
		label: __( 'Placed', 'orillacart' ),
		enableSorting: false,
		render: ( { item } ) => when( item.placed_at ),
	},
	{
		id: 'customer',
		label: __( 'Customer', 'orillacart' ),
		enableSorting: false,
		render: ( { item } ) => (
			<>
				{ item.customer }
				<br />
				<small>{ item.email }</small>
			</>
		),
	},
	{
		id: 'status',
		label: __( 'Status', 'orillacart' ),
		enableSorting: false,
		elements: STATUSES.map( ( status ) => ( {
			value: status,
			label: STATUS_LABELS[ status ],
		} ) ),
		filterBy: { operators: [ 'is' ] },
		render: ( { item } ) => STATUS_LABELS[ item.status ],
	},
	{
		id: 'total',
		label: __( 'Total', 'orillacart' ),
		enableSorting: false,
		render: ( { item } ) => money( item.total, item.currency ),
	},
	{
		id: 'payment_name',
		label: __( 'Payment', 'orillacart' ),
		enableSorting: false,
	},
];

const DEFAULT_VIEW: View = {
	type: 'table',
	page: 1,
	perPage: 20,
	search: '',
	filters: [],
	fields: [ 'placed_at', 'customer', 'status', 'total', 'payment_name' ],
	titleField: 'number',
	layout: {},
};

/**
 * The Shop → Orders screen: every order, newest first, and one order at a time to work on.
 */
export function OrdersScreen() {
	const [ view, setView ] = useState< View >( DEFAULT_VIEW );
	const [ page, setPage ] = useState< OrderPage | null >( null );
	const [ selected, setSelected ] = useState< number | null >( null );
	const [ error, setError ] = useState< string | null >( null );

	const load = useCallback( () => {
		const filter = view.filters?.find(
			( item ) => item.field === 'status'
		);
		const status =
			typeof filter?.value === 'string' &&
			( STATUSES as readonly string[] ).includes( filter.value )
				? ( filter.value as Status )
				: undefined;
		fetchOrders( {
			status,
			search: view.search === '' ? undefined : view.search,
			page: view.page ?? 1,
			per_page: view.perPage ?? 20,
		} )
			.then( setPage )
			.catch( ( reason: unknown ) =>
				setError( toApiError( reason ).message )
			);
	}, [ view ] );

	useEffect( load, [ load ] );

	if ( selected !== null ) {
		return (
			<OrderDetail
				id={ selected }
				onBack={ () => {
					setSelected( null );
					load();
				} }
			/>
		);
	}

	return (
		<>
			<h1>{ __( 'Orders', 'orillacart' ) }</h1>
			{ error !== null && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }
			<DataViews< OrderSummary >
				view={ view }
				onChangeView={ setView }
				fields={ FIELDS }
				data={ page?.orders ?? [] }
				getItemId={ ( item ) => String( item.number ) }
				isLoading={ page === null }
				paginationInfo={ {
					totalItems: page?.total ?? 0,
					totalPages: page?.pages ?? 1,
				} }
				defaultLayouts={ { table: {} } }
				searchLabel={ __(
					'Search by number, email or name',
					'orillacart'
				) }
				onClickItem={ ( item ) => setSelected( item.number ) }
				isItemClickable={ () => true }
				actions={ [
					{
						id: 'open',
						label: __( 'Open', 'orillacart' ),
						isPrimary: true,
						callback: ( [ item ] ) => {
							if ( item !== undefined ) {
								setSelected( item.number );
							}
						},
					},
				] }
				empty={ <p>{ __( 'No orders found.', 'orillacart' ) }</p> }
			/>
		</>
	);
}
