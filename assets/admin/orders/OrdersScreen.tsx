import { Button, Notice } from '@wordpress/components';
import { DataViews, type Field, type View } from '@wordpress/dataviews';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { addQueryArgs } from '@wordpress/url';

import { fetchPaymentMethods } from '../payments/api';
import { toApiError } from '../shared/api';
import {
	deleteOrder,
	fetchOrderFacets,
	fetchOrders,
	type OrderFacets,
	type OrderPage,
	type OrderSummary,
	PAID,
	type Paid,
	STATUSES,
} from './api';
import { ordersQuery, viewArgs, viewFromArgs } from './listView';
import { STATUS_LABELS, money, when } from './labels';
import { NewOrder } from './NewOrder';
import { OrderDetail } from './OrderDetail';
import { useLatest } from '../shared/useLatest';
import { ExportDialog } from '../shared/ExportDialog';

type Payments = { value: string; label: string }[];

const NO_FACETS: OrderFacets = {
	shipping: [],
	countries: [],
	currencies: [],
	has: [],
};

const PAID_LABELS: Record< Paid, string > = {
	unpaid: __( 'Not paid', 'orillacart' ),
	paid: __( 'Paid', 'orillacart' ),
	partly_refunded: __( 'Partly refunded', 'orillacart' ),
	refunded: __( 'Refunded', 'orillacart' ),
};

/** What an order may have, as the shop reads it; an add-on's own marks keep the label it gives. */
const HAS_LABELS: Record< string, string > = {
	gift_card: __( 'Paid with a gift card', 'orillacart' ),
	backorder: __( 'Backordered items', 'orillacart' ),
	personalised: __( 'Personalised items', 'orillacart' ),
	note: __( 'A note from the customer', 'orillacart' ),
	downloads: __( 'Downloads', 'orillacart' ),
	coupon: __( 'Used a coupon', 'orillacart' ),
	return: __( 'A return', 'orillacart' ),
};

/**
 * A country's name in the admin's language, its code where the browser knows none.
 * @param code ISO 3166 code.
 */
const countryName = ( code: string ): string => {
	try {
		return (
			new Intl.DisplayNames( [ document.documentElement.lang || 'en' ], {
				type: 'region',
			} ).of( code ) ?? code
		);
	} catch {
		return code;
	}
};

/** Fields the list filters by but does not show as columns. */
const filterOnly = {
	enableHiding: false,
	enableSorting: false,
	render: () => null,
} as const;

const fields = (
	payments: Payments,
	facets: OrderFacets
): Field< OrderSummary >[] => [
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
		filterBy: { operators: [ 'isAny' ] },
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
	{
		...filterOnly,
		id: 'paid',
		label: __( 'Paid', 'orillacart' ),
		elements: PAID.map( ( value ) => ( {
			value,
			label: PAID_LABELS[ value ],
		} ) ),
		filterBy: { operators: [ 'is' ] },
	},
	{
		...filterOnly,
		id: 'shipped',
		label: __( 'Shipped', 'orillacart' ),
		elements: [
			{ value: 'yes', label: __( 'Shipped', 'orillacart' ) },
			{ value: 'no', label: __( 'Not shipped yet', 'orillacart' ) },
		],
		filterBy: { operators: [ 'is' ] },
	},
	{
		...filterOnly,
		id: 'shipping',
		label: __( 'Shipping', 'orillacart' ),
		elements: facets.shipping.map( ( name ) => ( {
			value: name,
			label: name,
		} ) ),
		filterBy: { operators: [ 'is' ] },
	},
	{
		...filterOnly,
		id: 'country',
		label: __( 'Country', 'orillacart' ),
		elements: facets.countries.map( ( code ) => ( {
			value: code,
			label: countryName( code ),
		} ) ),
		filterBy: { operators: [ 'is' ] },
	},
	{
		...filterOnly,
		id: 'delivery',
		label: __( 'Delivery day', 'orillacart' ),
		type: 'date',
		filterBy: { operators: [ 'on', 'before', 'after', 'between' ] },
	},
	{
		...filterOnly,
		id: 'has',
		label: __( 'Details', 'orillacart' ),
		elements: facets.has.map( ( { value, label } ) => ( {
			value,
			label: HAS_LABELS[ value ] ?? label,
		} ) ),
		filterBy: { operators: [ 'isAll' ] },
	},
	...( facets.currencies.length > 1
		? [
				{
					...filterOnly,
					id: 'currency',
					label: __( 'Currency', 'orillacart' ),
					elements: facets.currencies.map( ( code ) => ( {
						value: code,
						label: code,
					} ) ),
					filterBy: { operators: [ 'is' as const ] },
				},
			]
		: [] ),
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
	const [ facets, setFacets ] = useState< OrderFacets >( NO_FACETS );
	const fieldList = useMemo(
		() => fields( payments, facets ),
		[ payments, facets ]
	);
	const [ page, setPage ] = useState< OrderPage | null >( null );
	const [ selected, setSelected ] = useState< number | null >( null );
	const [ creating, setCreating ] = useState( false );
	const [ exporting, setExporting ] = useState( false );
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
		fetchOrderFacets()
			.then( setFacets )
			.catch( () => setFacets( NO_FACETS ) );
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
			</Button>{ ' ' }
			<Button variant="secondary" onClick={ () => setExporting( true ) }>
				{ __( 'Export', 'orillacart' ) }
			</Button>
			{ exporting && (
				<ExportDialog
					kind="orders"
					title={ __( 'Export the orders listed', 'orillacart' ) }
					query={ { ...ordersQuery( view ) } }
					formats={ [
						{
							value: 'csv',
							label: __( 'CSV (spreadsheet)', 'orillacart' ),
						},
						{ value: 'json', label: 'JSON' },
					] }
					onClose={ () => setExporting( false ) }
				/>
			) }
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
					'Number, name, email, phone, address, SKU, tracking, invoice or coupon',
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
