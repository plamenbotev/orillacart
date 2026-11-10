import {
	Button,
	Notice,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { DataViews, type Field, type View } from '@wordpress/dataviews';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { addQueryArgs } from '@wordpress/url';

import { fetchPaymentMethods } from '../payments/api';
import { errorMessage } from '../shared/api';
import {
	changeStatus,
	deleteOrder,
	fetchOrderFacets,
	fetchOrders,
	type OrderFacets,
	type OrderPage,
	type OrderSummary,
	PAID,
	type Paid,
	type Status,
	STATUSES,
} from './api';
import { ordersQuery, viewArgs, viewFromArgs } from './listView';
import { STATUS_LABELS, money, when } from './labels';
import { NewOrder } from './NewOrder';
import { OrderDetail } from './OrderDetail';
import { useLatest } from '../shared/useLatest';
import { ExportDialog } from '../shared/ExportDialog';
import {
	Badge,
	Chips,
	EmptyState,
	Page,
	StatusBadge,
	notify,
} from '../shared/ui';
import { shopDay } from '../shared/dates';

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
	return: __( 'An open return', 'orillacart' ),
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
		render: ( { item } ) => (
			<StatusBadge
				status={ item.status }
				label={ STATUS_LABELS[ item.status ] }
			/>
		),
	},
	{
		id: 'attention',
		label: __( 'Attention', 'orillacart' ),
		enableSorting: false,
		elements: [
			{ value: 'yes', label: __( 'Needs attention', 'orillacart' ) },
		],
		filterBy: { operators: [ 'is' ] },
		render: ( { item } ) => (
			<>
				{ item.needs_attention && (
					<Badge tone="danger">
						{ __( 'Needs attention', 'orillacart' ) }
					</Badge>
				) }
				{ item.test && (
					<Badge tone="neutral">{ __( 'Test', 'orillacart' ) }</Badge>
				) }
			</>
		),
	},
	{
		id: 'total',
		label: __( 'Total', 'orillacart' ),
		type: 'number',
		getValue: ( { item } ) => Number( item.total ),
		filterBy: { operators: [ 'greaterThanOrEqual', 'lessThanOrEqual' ] },
		render: ( { item } ) => (
			<span className="oc-num">
				{ money( item.total, item.currency ) }
			</span>
		),
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

type Filters = NonNullable< View[ 'filters' ] >;

/** The quick views above the list: each a set of filters. */
const QUICK_VIEWS: { id: string; label: string; filters: () => Filters }[] = [
	{ id: 'all', label: __( 'All', 'orillacart' ), filters: () => [] },
	{
		id: 'attention',
		label: __( 'Needs attention', 'orillacart' ),
		filters: () => [ { field: 'attention', operator: 'is', value: 'yes' } ],
	},
	{
		id: 'on_hold',
		label: __( 'On hold', 'orillacart' ),
		filters: () => [
			{ field: 'status', operator: 'isAny', value: [ 'on_hold' ] },
		],
	},
	{
		id: 'ship',
		label: __( 'To ship', 'orillacart' ),
		filters: () => [
			{ field: 'status', operator: 'isAny', value: [ 'processing' ] },
		],
	},
	{
		id: 'today',
		label: __( 'Today', 'orillacart' ),
		filters: () => [
			{
				field: 'placed_at',
				operator: 'on',
				value: shopDay(),
			},
		],
	},
];

/**
 * Which quick view the filters are, or null for a view of one's own.
 *
 * @param filters The filters as set.
 */
function quickViewOf( filters: Filters | undefined ): string | null {
	const current = JSON.stringify( filters ?? [] );
	return (
		QUICK_VIEWS.find(
			( item ) => JSON.stringify( item.filters() ) === current
		)?.id ?? null
	);
}

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
	// A link can open one order (?order=165) or the new order form (?new=1): the Home screen's do.
	const [ selected, setSelected ] = useState< number | null >( () => {
		const number = Number(
			new URLSearchParams( window.location.search ).get( 'order' )
		);
		return Number.isInteger( number ) && number > 0 ? number : null;
	} );
	const [ creating, setCreating ] = useState(
		() => new URLSearchParams( window.location.search ).get( 'new' ) === '1'
	);
	const [ exporting, setExporting ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const latest = useLatest();
	const quickView = quickViewOf( view.filters );

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
			.catch( ( reason: unknown ) => setError( errorMessage( reason ) ) );
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
		<Page
			title={ __( 'Orders', 'orillacart' ) }
			actions={
				<>
					<Button
						variant="primary"
						onClick={ () => setCreating( true ) }
					>
						{ __( 'Add order', 'orillacart' ) }
					</Button>
					<Button
						variant="secondary"
						onClick={ () => setExporting( true ) }
					>
						{ __( 'Export', 'orillacart' ) }
					</Button>
				</>
			}
		>
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
			<Chips
				label={ __( 'Quick views', 'orillacart' ) }
				items={ QUICK_VIEWS.map( ( { id, label } ) => ( {
					id,
					label,
				} ) ) }
				selected={ quickView }
				onSelect={ ( id ) => {
					const chosen = QUICK_VIEWS.find(
						( item ) => item.id === id
					);
					if ( chosen ) {
						setView( {
							...view,
							page: 1,
							filters: chosen.filters(),
						} );
					}
				} }
			/>
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
						id: 'status',
						label: __( 'Change status', 'orillacart' ),
						supportsBulk: true,
						modalHeader: __( 'Change the status', 'orillacart' ),
						RenderModal: ( { items, closeModal } ) => (
							<ChangeStatus
								orders={ items }
								onDone={ ( failure, changed ) => {
									closeModal?.();
									if ( failure !== null ) {
										setError( failure );
									}
									if ( changed > 0 ) {
										notify(
											sprintf(
												/* translators: %d: how many orders */
												_n(
													'The status of %d order was changed.',
													'The status of %d orders was changed.',
													changed,
													'orillacart'
												),
												changed
											)
										);
									}
									load();
								} }
							/>
						),
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
				empty={
					<EmptyState
						title={ __( 'No orders found.', 'orillacart' ) }
						description={
							quickView === 'all' && view.search === ''
								? __(
										'Orders show here as customers place them, or when you add one.',
										'orillacart'
									)
								: __(
										'None match this view; try another, or clear the search.',
										'orillacart'
									)
						}
					/>
				}
			/>
		</Page>
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
				failure ??= errorMessage( reason );
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

/**
 * Moves several orders to a status at once, with one note for their histories; an order the move is not allowed
 * for is reported and the rest go on.
 *
 * @param props        The orders and what to do afterwards.
 * @param props.orders The orders chosen.
 * @param props.onDone Receives the first failure, or null, and how many were changed.
 */
function ChangeStatus( {
	orders,
	onDone,
}: {
	orders: OrderSummary[];
	onDone: ( failure: string | null, changed: number ) => void;
} ) {
	const [ status, setStatus ] = useState< Status >( 'processing' );
	const [ note, setNote ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const move = async () => {
		setBusy( true );
		let failure: string | null = null;
		let changed = 0;
		for ( const order of orders ) {
			try {
				await changeStatus( order.number, status, note );
				changed += 1;
			} catch ( reason ) {
				failure ??= errorMessage( reason );
			}
		}
		onDone( failure, changed );
	};

	return (
		<form
			className="oc-modal-form"
			onSubmit={ ( event ) => {
				event.preventDefault();
				move();
			} }
		>
			<p>
				{ sprintf(
					/* translators: %d: how many orders */
					_n(
						'Move %d order to:',
						'Move %d orders to:',
						orders.length,
						'orillacart'
					),
					orders.length
				) }
			</p>
			<SelectControl
				__next40pxDefaultSize
				label={ __( 'Status', 'orillacart' ) }
				value={ status }
				options={ STATUSES.map( ( value ) => ( {
					value,
					label: STATUS_LABELS[ value ],
				} ) ) }
				onChange={ ( value ) => setStatus( value as Status ) }
			/>
			<TextControl
				__next40pxDefaultSize
				label={ __( 'Note for the history (optional)', 'orillacart' ) }
				value={ note }
				onChange={ setNote }
			/>
			<div className="oc-modal-form__actions">
				<Button
					variant="primary"
					type="submit"
					isBusy={ busy }
					disabled={ busy }
				>
					{ __( 'Change status', 'orillacart' ) }
				</Button>
			</div>
		</form>
	);
}
