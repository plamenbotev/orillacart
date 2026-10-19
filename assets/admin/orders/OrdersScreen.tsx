import { Button, Notice } from '@wordpress/components';
import { DataViews, type Field, type View } from '@wordpress/dataviews';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { addQueryArgs } from '@wordpress/url';

import { fetchPaymentMethods } from '../payments/api';
import { toApiError } from '../shared/api';
import {
	deleteOrder,
	fetchOrders,
	type OrderPage,
	type OrderSummary,
	STATUSES,
} from './api';
import { ordersQuery, viewArgs, viewFromArgs } from './listView';
import { STATUS_LABELS, money, when } from './labels';
import { NewOrder } from './NewOrder';
import { OrderDetail } from './OrderDetail';
import { useLatest } from '../shared/useLatest';

type Payments = { value: string; label: string }[];

const fields = ( payments: Payments ): Field< OrderSummary >[] => [
	{
		id: 'number',
		label: __( 'Order', 'orillacart' ),
		enableHiding: false,
		render: ( { item } ) => <strong>#{ item.number }</strong>,
	},
	{
		id: 'placed_at',
		label: __( 'Placed', 'orillacart' ),
		type: 'date',
		enableSorting: false,
		filterBy: { operators: [ 'on', 'before', 'after', 'between' ] },
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
		id: 'attention',
		label: __( 'Attention', 'orillacart' ),
		enableSorting: false,
		elements: [
			{ value: 'yes', label: __( 'Needs attention', 'orillacart' ) },
		],
		filterBy: { operators: [ 'is' ] },
		render: ( { item } ) =>
			item.needs_attention ? (
				<strong className="orillacart-orders__attention">
					{ __( 'Needs attention', 'orillacart' ) }
				</strong>
			) : null,
	},
	{
		id: 'total',
		label: __( 'Total', 'orillacart' ),
		type: 'number',
		getValue: ( { item } ) => Number( item.total ),
		filterBy: { operators: [ 'greaterThanOrEqual', 'lessThanOrEqual' ] },
		render: ( { item } ) => money( item.total, item.currency ),
	},
	{
		id: 'payment',
		label: __( 'Payment', 'orillacart' ),
		enableSorting: false,
		elements: payments,
		filterBy: { operators: [ 'is' ] },
		getValue: ( { item } ) => item.payment_name,
		render: ( { item } ) => item.payment_name,
	},
	{
		id: 'placed_by',
		label: __( 'Placed by', 'orillacart' ),
		enableSorting: false,
		elements: [
			{ value: 'account', label: __( 'A customer', 'orillacart' ) },
			{ value: 'guest', label: __( 'A guest', 'orillacart' ) },
		],
		filterBy: { operators: [ 'is' ] },
		render: ( { item } ) =>
			item.customer_id === null
				? __( 'A guest', 'orillacart' )
				: __( 'A customer', 'orillacart' ),
	},
];

const DEFAULT_VIEW: View = {
	type: 'table',
	page: 1,
	perPage: 20,
	search: '',
	filters: [],
	fields: [
		'placed_at',
		'customer',
		'status',
		'attention',
		'total',
		'payment',
	],
	titleField: 'number',
	layout: {},
};

/**
 * The Shop → Orders screen: every order, newest first, and one order at a time to work on.
 */
export function OrdersScreen() {
	const [ view, setView ] = useState< View >( () =>
		viewFromArgs( window.location.search, DEFAULT_VIEW )
	);
	const [ payments, setPayments ] = useState< Payments >( [] );
	const fieldList = useMemo( () => fields( payments ), [ payments ] );
	const [ page, setPage ] = useState< OrderPage | null >( null );
	const [ selected, setSelected ] = useState< number | null >( null );
	const [ creating, setCreating ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const latest = useLatest();

	useEffect( () => {
		fetchPaymentMethods()
			.then( ( methods ) =>
				setPayments(
					methods.map( ( method ) => ( {
						value: method.gateway,
						label: method.name,
					} ) )
				)
			)
			.catch( () => setPayments( [] ) );
	}, [] );

	// The address keeps the list as it is, for a reload, the back button or a link to it.
	useEffect( () => {
		const adminPage = new URLSearchParams( window.location.search ).get(
			'page'
		);
		window.history.replaceState(
			null,
			'',
			addQueryArgs( window.location.pathname, {
				...( adminPage === null ? {} : { page: adminPage } ),
				...viewArgs( view ),
			} )
		);
	}, [ view ] );

	const load = useCallback( () => {
		latest( fetchOrders( ordersQuery( view ) ) )
			.then( setPage )
			.catch( ( reason: unknown ) =>
				setError( toApiError( reason ).message )
			);
	}, [ view, latest ] );

	useEffect( load, [ load ] );

	if ( creating ) {
		return (
			<NewOrder
				onCreated={ ( id ) => {
					setCreating( false );
					setSelected( id );
				} }
				onBack={ () => setCreating( false ) }
			/>
		);
	}

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
			<h1 className="wp-heading-inline">
				{ __( 'Orders', 'orillacart' ) }
			</h1>{ ' ' }
			<Button variant="secondary" onClick={ () => setCreating( true ) }>
				{ __( 'Add order', 'orillacart' ) }
			</Button>
			{ error !== null && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }
			<DataViews< OrderSummary >
				view={ view }
				onChangeView={ setView }
				fields={ fieldList }
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
					{
						id: 'delete',
						label: __( 'Delete', 'orillacart' ),
						supportsBulk: true,
						isEligible: ( item ) =>
							! item.paid &&
							[ 'cancelled', 'failed' ].includes( item.status ),
						modalHeader: __( 'Delete orders', 'orillacart' ),
						RenderModal: ( { items, closeModal } ) => (
							<DeleteOrders
								orders={ items }
								onDone={ ( failure ) => {
									closeModal?.();
									if ( failure !== null ) {
										setError( failure );
									}
									load();
								} }
							/>
						),
					},
				] }
				empty={ <p>{ __( 'No orders found.', 'orillacart' ) }</p> }
			/>
		</>
	);
}

/**
 * Confirms and deletes orders that are not paid; those waiting for payment give their stock back.
 *
 * @param props        The orders and what to do afterwards.
 * @param props.orders The orders chosen.
 * @param props.onDone Receives the first failure, or null.
 */
function DeleteOrders( {
	orders,
	onDone,
}: {
	orders: OrderSummary[];
	onDone: ( failure: string | null ) => void;
} ) {
	const [ busy, setBusy ] = useState( false );
	const remove = async () => {
		setBusy( true );
		let failure: string | null = null;
		for ( const order of orders ) {
			try {
				await deleteOrder( order.number );
			} catch ( reason ) {
				failure ??= toApiError( reason ).message;
			}
		}
		onDone( failure );
	};

	return (
		<>
			<p>
				{ sprintf(
					/* translators: %s: order numbers */
					__( 'Delete %s for good?', 'orillacart' ),
					orders.map( ( order ) => `#${ order.number }` ).join( ', ' )
				) }
			</p>
			<Button
				variant="primary"
				isDestructive
				isBusy={ busy }
				disabled={ busy }
				onClick={ () => void remove() }
			>
				{ __( 'Delete', 'orillacart' ) }
			</Button>
		</>
	);
}
