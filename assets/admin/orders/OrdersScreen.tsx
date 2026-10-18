import { Button, Notice } from '@wordpress/components';
import { DataViews, type Field, type View } from '@wordpress/dataviews';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { toApiError } from '../shared/api';
import {
	deleteOrder,
	fetchOrders,
	type OrderPage,
	type OrderSummary,
	type Status,
	STATUSES,
} from './api';
import { STATUS_LABELS, money, when } from './labels';
import { NewOrder } from './NewOrder';
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
	fields: [
		'placed_at',
		'customer',
		'status',
		'attention',
		'total',
		'payment_name',
	],
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
	const [ creating, setCreating ] = useState( false );
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
		const attention =
			view.filters?.find( ( item ) => item.field === 'attention' )
				?.value === 'yes';
		fetchOrders( {
			status,
			attention: attention ? true : undefined,
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
