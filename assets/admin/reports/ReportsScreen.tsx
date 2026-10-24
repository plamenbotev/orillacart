import type { ReactNode } from 'react';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Flex,
	FlexItem,
	Notice,
	SelectControl,
	Spinner,
	TabPanel,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { money } from '../orders/labels';
import { errorMessage } from '../shared/api';
import { shopDateTime, shopToday } from '../shared/dates';
import {
	type CartsReport,
	type CartTotals,
	type Compare,
	type CustomersReport,
	fetchReport,
	type Frame,
	type Interval,
	type RankingReport,
	type CategoryRow,
	type CouponRow,
	type ProductRow,
	rebuildReports,
	type ReportQuery,
	type Reports,
	type SalesReport,
	type Share,
	type Tab,
	type TaxRow,
	type Totals,
} from './api';
import {
	bucketLabel,
	CustomersChart,
	RankingChart,
	TrendChart,
} from './charts';
import { download, toCsv } from './csv';
import { change, day, PRESETS, presetOf } from './periods';
import './reports.css';

const TABS: { name: Tab; title: string }[] = [
	{ name: 'sales', title: __( 'Overview', 'orillacart' ) },
	{ name: 'products', title: __( 'Products', 'orillacart' ) },
	{ name: 'categories', title: __( 'Categories', 'orillacart' ) },
	{ name: 'coupons', title: __( 'Coupons', 'orillacart' ) },
	{ name: 'customers', title: __( 'Customers', 'orillacart' ) },
	{ name: 'carts', title: __( 'Carts', 'orillacart' ) },
	{ name: 'taxes', title: __( 'Taxes', 'orillacart' ) },
];

/**
 * The report the address asks for, so a report can be bookmarked or sent to a colleague.
 * @param today
 */
function fromAddress( today: Date ): { tab: Tab; query: ReportQuery } {
	const params = new URLSearchParams( window.location.search );
	const [ start, end ] = PRESETS[ 3 ]!.range( today );
	const valid = ( value: string | null ) =>
		value !== null && /^\d{4}-\d{2}-\d{2}$/.test( value ) ? value : null;
	const tab = params.get( 'report' );
	const interval = params.get( 'interval' );
	const compare = params.get( 'compare' );
	return {
		tab: TABS.some( ( known ) => known.name === tab )
			? ( tab as Tab )
			: 'sales',
		query: {
			from: valid( params.get( 'from' ) ) ?? day( start ),
			to: valid( params.get( 'to' ) ) ?? day( end ),
			interval:
				interval === 'day' ||
				interval === 'week' ||
				interval === 'month'
					? interval
					: '',
			compare:
				compare === 'year' || compare === 'none' ? compare : 'previous',
			limit: 10,
			sort: params.get( 'sort' ) === 'quantity' ? 'quantity' : 'net',
		},
	};
}

function toAddress( tab: Tab, query: ReportQuery ): void {
	const params = new URLSearchParams( window.location.search );
	params.set( 'report', tab );
	params.set( 'from', query.from );
	params.set( 'to', query.to );
	params.set( 'compare', query.compare );
	params.set( 'sort', query.sort );
	if ( query.interval === '' ) {
		params.delete( 'interval' );
	} else {
		params.set( 'interval', query.interval );
	}
	window.history.replaceState(
		null,
		'',
		`${ window.location.pathname }?${ params.toString() }`
	);
}

const number = ( value: number ) => value.toLocaleString();

/**
 * Shop → Reports: the shop's sales, best products, categories and coupons, and its customers, over any days,
 * against the days before or a year before. Figures come from the facts counted for each order in the
 * background, so the screen is quick however many orders the shop has.
 */
export function ReportsScreen() {
	const [ today ] = useState( () => shopToday() );
	const [ initial ] = useState( () => fromAddress( today ) );
	const [ tab, setTab ] = useState< Tab >( initial.tab );
	const [ query, setQuery ] = useState< ReportQuery >( initial.query );
	const [ report, setReport ] = useState< Reports[ Tab ] | null >( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );
	const [ notice, setNotice ] = useState< string | null >( null );
	const [ reload, setReload ] = useState( 0 );

	useEffect( () => {
		toAddress( tab, query );
		let current = true;
		setLoading( true );
		setError( null );
		fetchReport( tab, query )
			.then( ( data ) => {
				if ( current ) {
					setReport( data );
				}
			} )
			.catch( ( caught: unknown ) => {
				if ( current ) {
					setError( errorMessage( caught ) );
				}
			} )
			.finally( () => {
				if ( current ) {
					setLoading( false );
				}
			} );
		return () => {
			current = false;
		};
	}, [ tab, query, reload ] );

	const update = ( changes: Partial< ReportQuery > ) =>
		setQuery( { ...query, ...changes } );
	const preset = presetOf( query.from, query.to, today );

	return (
		<div className="orillacart-reports">
			<Flex
				className="orillacart-reports__toolbar"
				align="flex-end"
				wrap
				gap={ 3 }
			>
				<FlexItem>
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Period', 'orillacart' ) }
						value={ preset }
						options={ [
							...PRESETS.map( ( item ) => ( {
								value: item.key,
								label: item.label,
							} ) ),
							{
								value: 'custom',
								label: __( 'Chosen days', 'orillacart' ),
							},
						] }
						onChange={ ( key ) => {
							const chosen = PRESETS.find(
								( item ) => item.key === key
							);
							if ( chosen ) {
								const [ start, end ] = chosen.range( today );
								update( {
									from: day( start ),
									to: day( end ),
								} );
							}
						} }
					/>
				</FlexItem>
				<FlexItem>
					<TextControl
						__next40pxDefaultSize
						type="date"
						label={ __( 'From', 'orillacart' ) }
						value={ query.from }
						max={ query.to }
						onChange={ ( from ) =>
							from !== '' && update( { from } )
						}
					/>
				</FlexItem>
				<FlexItem>
					<TextControl
						__next40pxDefaultSize
						type="date"
						label={ __( 'To', 'orillacart' ) }
						value={ query.to }
						min={ query.from }
						onChange={ ( to ) => to !== '' && update( { to } ) }
					/>
				</FlexItem>
				<FlexItem>
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Compare with', 'orillacart' ) }
						value={ query.compare }
						options={ [
							{
								value: 'previous',
								label: __( 'The days before', 'orillacart' ),
							},
							{
								value: 'year',
								label: __(
									'The same days a year before',
									'orillacart'
								),
							},
							{
								value: 'none',
								label: __( 'Nothing', 'orillacart' ),
							},
						] }
						onChange={ ( compare ) =>
							update( { compare: compare as Compare } )
						}
					/>
				</FlexItem>
				{ ( tab === 'sales' || tab === 'customers' ) && (
					<FlexItem>
						<SelectControl
							__next40pxDefaultSize
							label={ __( 'Show by', 'orillacart' ) }
							value={ query.interval }
							options={ [
								{
									value: '',
									label: __(
										'What suits the period',
										'orillacart'
									),
								},
								{
									value: 'day',
									label: __( 'Day', 'orillacart' ),
								},
								{
									value: 'week',
									label: __( 'Week', 'orillacart' ),
								},
								{
									value: 'month',
									label: __( 'Month', 'orillacart' ),
								},
							] }
							onChange={ ( interval ) =>
								update( {
									interval: interval as Interval | '',
								} )
							}
						/>
					</FlexItem>
				) }
				<FlexItem>
					<Button
						variant="secondary"
						disabled={ report === null || loading }
						onClick={ () => report && exportCsv( tab, report ) }
					>
						{ __( 'Download CSV', 'orillacart' ) }
					</Button>
				</FlexItem>
			</Flex>

			{ error !== null && (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) }
			{ notice !== null && (
				<Notice status="success" onRemove={ () => setNotice( null ) }>
					{ notice }
				</Notice>
			) }
			{ report !== null && (
				<FrameNotices
					frame={ report.frame }
					onRefresh={ () => setReload( reload + 1 ) }
					onRebuild={ () =>
						rebuildReports()
							.then( () => {
								setNotice(
									__(
										'Every order is being counted again in the background.',
										'orillacart'
									)
								);
								setReload( reload + 1 );
							} )
							.catch( ( caught: unknown ) =>
								setError( errorMessage( caught ) )
							)
					}
				/>
			) }

			<TabPanel
				className="orillacart-reports__tabs"
				tabs={ TABS }
				initialTabName={ tab }
				onSelect={ ( name ) => {
					if ( name !== tab ) {
						setReport( null );
						setTab( name as Tab );
					}
				} }
			>
				{ () =>
					report === null || loading ? (
						<div className="orillacart-reports__loading">
							<Spinner />
						</div>
					) : (
						<TabContent
							tab={ tab }
							report={ report }
							query={ query }
							onQuery={ update }
						/>
					)
				}
			</TabPanel>
		</div>
	);
}

function TabContent( {
	tab,
	report,
	query,
	onQuery,
}: {
	tab: Tab;
	report: Reports[ Tab ];
	query: ReportQuery;
	onQuery: ( changes: Partial< ReportQuery > ) => void;
} ) {
	switch ( tab ) {
		case 'sales':
			return <SalesTab report={ report as SalesReport } />;
		case 'products':
			return (
				<ProductsTab
					report={ report as RankingReport< ProductRow > }
					query={ query }
					onQuery={ onQuery }
				/>
			);
		case 'categories':
			return (
				<CategoriesTab
					report={ report as RankingReport< CategoryRow > }
					query={ query }
					onQuery={ onQuery }
				/>
			);
		case 'coupons':
			return (
				<CouponsTab
					report={ report as RankingReport< CouponRow > }
					query={ query }
					onQuery={ onQuery }
				/>
			);
		case 'customers':
			return <CustomersTab report={ report as CustomersReport } />;
		case 'taxes':
			return <TaxesTab report={ report as RankingReport< TaxRow > } />;
		case 'carts':
			return (
				<CartsTab
					report={ report as CartsReport }
					query={ query }
					onQuery={ onQuery }
				/>
			);
	}
}

/**
 * What the report leaves out or has not counted yet.
 * @param props
 * @param props.frame     What the report says of itself.
 * @param props.onRefresh Asks for the report again.
 * @param props.onRebuild Counts every order again.
 */
function FrameNotices( {
	frame,
	onRefresh,
	onRebuild,
}: {
	frame: Frame;
	onRefresh: () => void;
	onRebuild: () => void;
} ) {
	return (
		<>
			{ frame.pending > 0 && (
				<Notice
					status="info"
					isDismissible={ false }
					actions={ [
						{
							label: __( 'Refresh', 'orillacart' ),
							onClick: onRefresh,
						},
					] }
				>
					{ sprintf(
						/* translators: %d: how many orders wait to be counted */
						_n(
							'%d order is still being counted; the figures will follow.',
							'%d orders are still being counted; the figures will follow.',
							frame.pending,
							'orillacart'
						),
						frame.pending
					) }
				</Notice>
			) }
			{ frame.other_currencies.length > 0 && (
				<Notice status="warning" isDismissible={ false }>
					{ sprintf(
						/* translators: 1: the shop's currency, 2: other currencies */
						__(
							'The figures are in %1$s. Orders paid in %2$s are left out.',
							'orillacart'
						),
						frame.currency,
						frame.other_currencies.join( ', ' )
					) }
				</Notice>
			) }
			<p className="orillacart-reports__about">
				{ frame.previous_from !== null && frame.previous_to !== null
					? sprintf(
							/* translators: 1, 2: first and last day; 3, 4: first and last day compared with */
							__(
								'%1$s to %2$s, compared with %3$s to %4$s.',
								'orillacart'
							),
							frame.from,
							frame.to,
							frame.previous_from,
							frame.previous_to
						)
					: sprintf(
							/* translators: 1, 2: first and last day */
							__( '%1$s to %2$s.', 'orillacart' ),
							frame.from,
							frame.to
						) }{ ' ' }
				{ __(
					'Paid orders count on the day they were placed; refunds on the day the money went back.',
					'orillacart'
				) }{ ' ' }
				<Button variant="link" onClick={ onRebuild }>
					{ __( 'Count every order again', 'orillacart' ) }
				</Button>
			</p>
		</>
	);
}

/**
 * One figure, and how it changed against the compared period.
 * @param props
 * @param props.label         What it is.
 * @param props.value         This period's figure, shown.
 * @param props.current       This period's figure, as a number.
 * @param props.previous      The compared period's, or null.
 * @param props.lowerIsBetter For refunds and discounts: a rise is shown in red.
 */
function Kpi( {
	label,
	value,
	current,
	previous,
	lowerIsBetter = false,
}: {
	label: string;
	value: string;
	current: number;
	previous: number | null;
	lowerIsBetter?: boolean;
} ) {
	const delta = change( current, previous );
	const good = delta !== null && ( lowerIsBetter ? delta < 0 : delta > 0 );
	const bad = delta !== null && ( lowerIsBetter ? delta > 0 : delta < 0 );
	return (
		<div className="orillacart-reports__kpi">
			<span className="orillacart-reports__kpi-label">{ label }</span>
			<strong className="orillacart-reports__kpi-value">{ value }</strong>
			{ previous !== null && (
				<span
					className={ `orillacart-reports__delta${ good ? ' is-good' : '' }${ bad ? ' is-bad' : '' }` }
				>
					{ delta === null
						? __( 'new', 'orillacart' )
						: `${ arrow( delta ) } ${ Math.abs( delta ).toFixed(
								1
							) } %` }
				</span>
			) }
		</div>
	);
}

/**
 * @param delta A change, in percent.
 * @return An arrow up for a rise, down for a fall.
 */
function arrow( delta: number ): string {
	if ( delta > 0 ) {
		return '▲';
	}
	return delta < 0 ? '▼' : '';
}

function totalsKpis(
	totals: Totals,
	previous: Totals | null,
	currency: string
) {
	const amount = ( value: string ) => money( value, currency );
	const was = ( pick: ( figures: Totals ) => string | number ) =>
		previous === null ? null : Number( pick( previous ) );
	return [
		{
			label: __( 'Net sales', 'orillacart' ),
			value: amount( totals.net ),
			current: Number( totals.net ),
			previous: was( ( t ) => t.net ),
		},
		{
			label: __( 'Orders', 'orillacart' ),
			value: number( totals.orders ),
			current: totals.orders,
			previous: was( ( t ) => t.orders ),
		},
		{
			label: __( 'Average order', 'orillacart' ),
			value: amount( totals.average_order ),
			current: Number( totals.average_order ),
			previous: was( ( t ) => t.average_order ),
		},
		{
			label: __( 'Items sold', 'orillacart' ),
			value: number( totals.items ),
			current: totals.items,
			previous: was( ( t ) => t.items ),
		},
		{
			label: __( 'Total sales', 'orillacart' ),
			value: amount( totals.total_sales ),
			current: Number( totals.total_sales ),
			previous: was( ( t ) => t.total_sales ),
		},
		{
			label: __( 'Gross sales', 'orillacart' ),
			value: amount( totals.gross ),
			current: Number( totals.gross ),
			previous: was( ( t ) => t.gross ),
		},
		{
			label: __( 'Discounts', 'orillacart' ),
			value: amount( totals.discounts ),
			current: Number( totals.discounts ),
			previous: was( ( t ) => t.discounts ),
			lowerIsBetter: true,
		},
		{
			label: __( 'Refunds', 'orillacart' ),
			value: amount( totals.refunds ),
			current: Number( totals.refunds ),
			previous: was( ( t ) => t.refunds ),
			lowerIsBetter: true,
		},
		{
			label: __( 'Shipping', 'orillacart' ),
			value: amount( totals.shipping ),
			current: Number( totals.shipping ),
			previous: was( ( t ) => t.shipping ),
		},
		{
			label: __( 'Taxes', 'orillacart' ),
			value: amount( totals.taxes ),
			current: Number( totals.taxes ),
			previous: was( ( t ) => t.taxes ),
		},
	];
}

function SalesTab( { report }: { report: SalesReport } ) {
	const [ metric, setMetric ] = useState< 'net' | 'orders' | 'total' >(
		'net'
	);
	const currency = report.frame.currency;
	const format = ( value: number ) =>
		metric === 'orders'
			? number( value )
			: money( String( value ), currency );
	const figure = ( point: SalesReport[ 'series' ][ number ] ) => {
		if ( metric === 'orders' ) {
			return { current: point.orders, previous: point.previous_orders };
		}
		if ( metric === 'net' ) {
			return {
				current: Number( point.net ),
				previous:
					point.previous_net === null
						? null
						: Number( point.previous_net ),
			};
		}
		// The compared period's total sales are not kept per day.
		return { current: Number( point.total ), previous: null };
	};
	const data = report.series.map( ( point ) => ( {
		label: bucketLabel( point.date, report.frame.interval ),
		...figure( point ),
	} ) );
	const names = {
		net: __( 'Net sales', 'orillacart' ),
		orders: __( 'Orders', 'orillacart' ),
		total: __( 'Total sales', 'orillacart' ),
	};
	return (
		<>
			<div className="orillacart-reports__kpis">
				{ totalsKpis( report.totals, report.previous, currency ).map(
					( kpi ) => (
						<Kpi key={ kpi.label } { ...kpi } />
					)
				) }
			</div>
			<Card>
				<CardHeader>
					<h2>{ names[ metric ] }</h2>
					<SelectControl
						__next40pxDefaultSize
						hideLabelFromVision
						label={ __( 'Figure', 'orillacart' ) }
						value={ metric }
						options={ [
							{ value: 'net', label: names.net },
							{ value: 'orders', label: names.orders },
							{ value: 'total', label: names.total },
						] }
						onChange={ ( value ) =>
							setMetric( value as typeof metric )
						}
					/>
				</CardHeader>
				<CardBody>
					<TrendChart
						data={ data }
						format={ format }
						currentName={ names[ metric ] }
						previousName={
							report.previous === null || metric === 'total'
								? null
								: __( 'Compared period', 'orillacart' )
						}
					/>
				</CardBody>
			</Card>
			<div className="orillacart-reports__shares">
				<Shares
					title={ __( 'Payment methods', 'orillacart' ) }
					shares={ report.payments }
					currency={ currency }
				/>
				<Shares
					title={ __( 'Countries', 'orillacart' ) }
					shares={ report.countries }
					currency={ currency }
					label={ countryName }
				/>
				<Shares
					title={ __( 'Shipping', 'orillacart' ) }
					shares={ report.shipping }
					currency={ currency }
					label={ ( key ) =>
						key === '' ? __( 'Not shipped', 'orillacart' ) : key
					}
				/>
			</div>
		</>
	);
}

function countryName( code: string ): string {
	try {
		return (
			new Intl.DisplayNames( undefined, { type: 'region' } ).of( code ) ??
			code
		);
	} catch {
		return code;
	}
}

/**
 * How paid orders split between payment methods, countries or shipping: each one's share of the sales.
 * @param props
 * @param props.title
 * @param props.shares
 * @param props.currency
 * @param props.label    Names a key.
 */
function Shares( {
	title,
	shares,
	currency,
	label = ( key ) => key,
}: {
	title: string;
	shares: Share[];
	currency: string;
	label?: ( key: string ) => string;
} ) {
	const sum = shares.reduce(
		( total, share ) => total + Number( share.total ),
		0
	);
	return (
		<Card>
			<CardHeader>
				<h2>{ title }</h2>
			</CardHeader>
			<CardBody>
				{ shares.length === 0 ? (
					<p className="orillacart-reports__empty">
						{ __( 'No paid orders in these days.', 'orillacart' ) }
					</p>
				) : (
					<ul className="orillacart-reports__share-list">
						{ shares.map( ( share ) => {
							const percent =
								sum === 0
									? 0
									: ( Number( share.total ) / sum ) * 100;
							return (
								<li key={ share.key }>
									<span className="orillacart-reports__share-name">
										{ label( share.key ) }
									</span>
									<span className="orillacart-reports__share-figure">
										{ money( share.total, currency ) } ·{ ' ' }
										{ sprintf(
											/* translators: %d: number of orders */
											_n(
												'%d order',
												'%d orders',
												share.orders,
												'orillacart'
											),
											share.orders
										) }
									</span>
									<span
										className="orillacart-reports__share-bar"
										aria-hidden="true"
									>
										<span
											style={ { width: `${ percent }%` } }
										/>
									</span>
								</li>
							);
						} ) }
					</ul>
				) }
			</CardBody>
		</Card>
	);
}

/**
 * The change against the compared period, as a cell.
 * @param props
 * @param props.current
 * @param props.previous
 */
function Change( {
	current,
	previous,
}: {
	current: number;
	previous: number | null;
} ) {
	if ( previous === null ) {
		return null;
	}
	const delta = change( current, previous );
	return (
		<span
			className={ `orillacart-reports__delta${ delta !== null && delta > 0 ? ' is-good' : '' }${ delta !== null && delta < 0 ? ' is-bad' : '' }` }
		>
			{ delta === null
				? __( 'new', 'orillacart' )
				: `${ delta > 0 ? '+' : '' }${ delta.toFixed( 1 ) } %` }
		</span>
	);
}

function LimitControl( {
	query,
	onQuery,
}: {
	query: ReportQuery;
	onQuery: ( changes: Partial< ReportQuery > ) => void;
} ) {
	return (
		<SelectControl
			__next40pxDefaultSize
			label={ __( 'Show', 'orillacart' ) }
			value={ String( query.limit ) }
			options={ [ 10, 25, 50, 100 ].map( ( limit ) => ( {
				value: String( limit ),
				label: sprintf(
					/* translators: %d: how many rows */
					__( 'The best %d', 'orillacart' ),
					limit
				),
			} ) ) }
			onChange={ ( limit ) => onQuery( { limit: Number( limit ) } ) }
		/>
	);
}

function ProductsTab( {
	report,
	query,
	onQuery,
}: {
	report: RankingReport< ProductRow >;
	query: ReportQuery;
	onQuery: ( changes: Partial< ReportQuery > ) => void;
} ) {
	const currency = report.frame.currency;
	const byQuantity = query.sort === 'quantity';
	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'Products', 'orillacart' ) }</h2>
				<Flex justify="flex-end" gap={ 3 } expanded={ false }>
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Rank by', 'orillacart' ) }
						value={ query.sort }
						options={ [
							{
								value: 'net',
								label: __( 'Net sales', 'orillacart' ),
							},
							{
								value: 'quantity',
								label: __( 'Items sold', 'orillacart' ),
							},
						] }
						onChange={ ( sort ) =>
							onQuery( { sort: sort as ReportQuery[ 'sort' ] } )
						}
					/>
					<LimitControl query={ query } onQuery={ onQuery } />
				</Flex>
			</CardHeader>
			<CardBody>
				<RankingChart
					name={
						byQuantity
							? __( 'Items sold', 'orillacart' )
							: __( 'Net sales', 'orillacart' )
					}
					format={
						byQuantity
							? number
							: ( value ) => money( String( value ), currency )
					}
					rows={ report.rows.slice( 0, 10 ).map( ( row ) => ( {
						label: row.name,
						value: byQuantity ? row.quantity : Number( row.net ),
					} ) ) }
				/>
				<RankingTable
					empty={ report.rows.length === 0 }
					header={ [
						__( 'Product', 'orillacart' ),
						__( 'Items sold', 'orillacart' ),
						__( 'Net sales', 'orillacart' ),
						__( 'Orders', 'orillacart' ),
						__( 'Change', 'orillacart' ),
					] }
					rows={ report.rows.map( ( row ) => ( {
						key: String( row.product_id ),
						cells: [
							<a
								key="name"
								href={ `post.php?post=${ row.product_id }&action=edit` }
							>
								{ row.name }
							</a>,
							number( row.quantity ),
							money( row.net, currency ),
							number( row.orders ),
							byQuantity ? (
								<Change
									key="change"
									current={ row.quantity }
									previous={ row.previous_quantity }
								/>
							) : (
								<Change
									key="change"
									current={ Number( row.net ) }
									previous={
										row.previous_net === null
											? null
											: Number( row.previous_net )
									}
								/>
							),
						],
					} ) ) }
				/>
			</CardBody>
		</Card>
	);
}

function CategoriesTab( {
	report,
	query,
	onQuery,
}: {
	report: RankingReport< CategoryRow >;
	query: ReportQuery;
	onQuery: ( changes: Partial< ReportQuery > ) => void;
} ) {
	const currency = report.frame.currency;
	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'Categories', 'orillacart' ) }</h2>
				<LimitControl query={ query } onQuery={ onQuery } />
			</CardHeader>
			<CardBody>
				<p className="orillacart-reports__about">
					{ __(
						'A product in two categories counts in both.',
						'orillacart'
					) }
				</p>
				<RankingChart
					name={ __( 'Net sales', 'orillacart' ) }
					format={ ( value ) => money( String( value ), currency ) }
					rows={ report.rows.slice( 0, 10 ).map( ( row ) => ( {
						label: row.name,
						value: Number( row.net ),
					} ) ) }
				/>
				<RankingTable
					empty={ report.rows.length === 0 }
					header={ [
						__( 'Category', 'orillacart' ),
						__( 'Items sold', 'orillacart' ),
						__( 'Net sales', 'orillacart' ),
						__( 'Orders', 'orillacart' ),
						__( 'Change', 'orillacart' ),
					] }
					rows={ report.rows.map( ( row ) => ( {
						key: String( row.category_id ),
						cells: [
							row.name,
							number( row.quantity ),
							money( row.net, currency ),
							number( row.orders ),
							<Change
								key="change"
								current={ Number( row.net ) }
								previous={
									row.previous_net === null
										? null
										: Number( row.previous_net )
								}
							/>,
						],
					} ) ) }
				/>
			</CardBody>
		</Card>
	);
}

function CouponsTab( {
	report,
	query,
	onQuery,
}: {
	report: RankingReport< CouponRow >;
	query: ReportQuery;
	onQuery: ( changes: Partial< ReportQuery > ) => void;
} ) {
	const currency = report.frame.currency;
	return (
		<Card>
			<CardHeader>
				<h2>
					{ __( 'Coupons and automatic discounts', 'orillacart' ) }
				</h2>
				<LimitControl query={ query } onQuery={ onQuery } />
			</CardHeader>
			<CardBody>
				<RankingChart
					name={ __( 'Discount given', 'orillacart' ) }
					format={ ( value ) => money( String( value ), currency ) }
					rows={ report.rows.slice( 0, 10 ).map( ( row ) => ( {
						label: row.code,
						value: Number( row.amount ),
					} ) ) }
				/>
				<RankingTable
					empty={ report.rows.length === 0 }
					header={ [
						__( 'Coupon', 'orillacart' ),
						__( 'Orders', 'orillacart' ),
						__( 'Discount given', 'orillacart' ),
						__( 'Change in orders', 'orillacart' ),
					] }
					rows={ report.rows.map( ( row ) => ( {
						key: row.code,
						cells: [
							<code key="code">{ row.code }</code>,
							number( row.orders ),
							money( row.amount, currency ),
							<Change
								key="change"
								current={ row.orders }
								previous={ row.previous_orders }
							/>,
						],
					} ) ) }
				/>
			</CardBody>
		</Card>
	);
}

const TAX_LEVELS: Record< TaxRow[ 'level' ], string > = {
	country: __( 'Country', 'orillacart' ),
	region: __( 'State', 'orillacart' ),
	local: __( 'Local', 'orillacart' ),
};

/**
 * The tax of the period's paid sales by jurisdiction and rate, for filing returns: taxable and exempt sales,
 * the tax, and what refunds gave back.
 * @param props
 * @param props.report
 */
function TaxesTab( { report }: { report: RankingReport< TaxRow > } ) {
	const currency = report.frame.currency;
	return (
		<Card>
			<CardHeader>
				<h2>{ __( 'Tax by jurisdiction', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				<RankingTable
					empty={ report.rows.length === 0 }
					header={ [
						__( 'Jurisdiction', 'orillacart' ),
						__( 'Code', 'orillacart' ),
						__( 'Rate', 'orillacart' ),
						__( 'Orders', 'orillacart' ),
						__( 'Taxable sales', 'orillacart' ),
						__( 'Exempt sales', 'orillacart' ),
						__( 'Tax', 'orillacart' ),
						__( 'Refunded tax', 'orillacart' ),
					] }
					rows={ report.rows.map( ( row ) => ( {
						key: `${ row.code }|${ row.percent }`,
						cells: [
							<span key="name">
								{ row.jurisdiction }
								<br />
								<small>
									{ TAX_LEVELS[ row.level ] } ·{ ' ' }
									{ [ row.country, row.region ]
										.filter( Boolean )
										.join( '-' ) }
								</small>
							</span>,
							<code key="code">{ row.code }</code>,
							`${ row.percent } %`,
							number( row.orders ),
							money( row.taxable, currency ),
							money( row.exempt, currency ),
							money( row.tax, currency ),
							money( row.refunded, currency ),
						],
					} ) ) }
				/>
			</CardBody>
		</Card>
	);
}

/**
 * Carts left at checkout: how many, worth what, reminded, and ordered after all; and the latest of them.
 * @param props
 * @param props.report
 * @param props.query
 * @param props.onQuery
 */
function CartsTab( {
	report,
	query,
	onQuery,
}: {
	report: CartsReport;
	query: ReportQuery;
	onQuery: ( changes: Partial< ReportQuery > ) => void;
} ) {
	const currency = report.frame.currency;
	const { totals, previous } = report;
	const was = ( pick: ( figures: CartTotals ) => string | number ) =>
		previous === null ? null : Number( pick( previous ) );
	const rate = ( figures: CartTotals ) =>
		figures.abandoned === 0
			? 0
			: ( figures.recovered / figures.abandoned ) * 100;
	const kpis = [
		{
			label: __( 'Carts left at checkout', 'orillacart' ),
			value: number( totals.abandoned ),
			current: totals.abandoned,
			previous: was( ( t ) => t.abandoned ),
			lowerIsBetter: true,
		},
		{
			label: __( 'Worth', 'orillacart' ),
			value: money( totals.value, currency ),
			current: Number( totals.value ),
			previous: was( ( t ) => t.value ),
			lowerIsBetter: true,
		},
		{
			label: __( 'Reminded', 'orillacart' ),
			value: number( totals.reminded ),
			current: totals.reminded,
			previous: was( ( t ) => t.reminded ),
		},
		{
			label: __( 'Ordered after all', 'orillacart' ),
			value: number( totals.recovered ),
			current: totals.recovered,
			previous: was( ( t ) => t.recovered ),
		},
		{
			label: __( 'Recovered sales', 'orillacart' ),
			value: money( totals.recovered_value, currency ),
			current: Number( totals.recovered_value ),
			previous: was( ( t ) => t.recovered_value ),
		},
		{
			label: __( 'Recovery rate', 'orillacart' ),
			value: `${ rate( totals ).toFixed( 1 ) } %`,
			current: rate( totals ),
			previous: previous === null ? null : rate( previous ),
		},
	];
	const status = ( row: CartsReport[ 'latest' ][ number ] ) => {
		if ( row.order_id !== null ) {
			return sprintf(
				/* translators: %d: order number */
				__( 'Ordered: #%d', 'orillacart' ),
				row.order_id
			);
		}
		return row.reminded_at !== null
			? __( 'Reminded', 'orillacart' )
			: __( 'Left', 'orillacart' );
	};
	return (
		<>
			<div className="orillacart-reports__kpis">
				{ kpis.map( ( kpi ) => (
					<Kpi key={ kpi.label } { ...kpi } />
				) ) }
			</div>
			<p className="description">
				{ sprintf(
					/* translators: %d: how many of the carts ordered after all were reminded */
					_n(
						'%d of the carts ordered after all was reminded first.',
						'%d of the carts ordered after all were reminded first.',
						totals.recovered_after_reminder,
						'orillacart'
					),
					totals.recovered_after_reminder
				) }
			</p>
			<Card>
				<CardHeader>
					<h2>{ __( 'Latest carts left', 'orillacart' ) }</h2>
					<LimitControl query={ query } onQuery={ onQuery } />
				</CardHeader>
				<CardBody>
					{ report.latest.length === 0 ? (
						<p className="orillacart-reports__empty">
							{ __(
								'No cart was left at checkout in these days.',
								'orillacart'
							) }
						</p>
					) : (
						<RankingTable
							empty={ false }
							header={ [
								__( 'Left', 'orillacart' ),
								__( 'Customer', 'orillacart' ),
								__( 'Items', 'orillacart' ),
								__( 'Worth', 'orillacart' ),
								__( 'Since', 'orillacart' ),
							] }
							rows={ report.latest.map( ( row ) => ( {
								key: String( row.id ),
								cells: [
									shopDateTime( row.abandoned_at ),
									row.email ??
										( row.customer_id !== null
											? sprintf(
													/* translators: %d: customer id */
													__(
														'Customer #%d',
														'orillacart'
													),
													row.customer_id
												)
											: __( 'Visitor', 'orillacart' ) ),
									number( row.items ),
									money( row.subtotal, currency ),
									status( row ),
								],
							} ) ) }
						/>
					) }
				</CardBody>
			</Card>
		</>
	);
}

function CustomersTab( { report }: { report: CustomersReport } ) {
	const currency = report.frame.currency;
	const returning = report.totals.customers - report.totals.new_customers;
	const previousReturning =
		report.previous === null
			? null
			: report.previous.customers - report.previous.new_customers;
	return (
		<>
			<div className="orillacart-reports__kpis">
				<Kpi
					label={ __( 'Customers', 'orillacart' ) }
					value={ number( report.totals.customers ) }
					current={ report.totals.customers }
					previous={ report.previous?.customers ?? null }
				/>
				<Kpi
					label={ __( 'New customers', 'orillacart' ) }
					value={ number( report.totals.new_customers ) }
					current={ report.totals.new_customers }
					previous={ report.previous?.new_customers ?? null }
				/>
				<Kpi
					label={ __( 'Returning customers', 'orillacart' ) }
					value={ number( returning ) }
					current={ returning }
					previous={ previousReturning }
				/>
				<Kpi
					label={ __( 'Spent per customer', 'orillacart' ) }
					value={ money(
						report.totals.customers === 0
							? '0'
							: String(
									Number( report.totals.total ) /
										report.totals.customers
								),
						currency
					) }
					current={
						report.totals.customers === 0
							? 0
							: Number( report.totals.total ) /
								report.totals.customers
					}
					previous={
						report.previous === null ||
						report.previous.customers === 0
							? null
							: Number( report.previous.total ) /
								report.previous.customers
					}
				/>
			</div>
			<Card>
				<CardHeader>
					<h2>
						{ __(
							'Orders by new and returning customers',
							'orillacart'
						) }
					</h2>
				</CardHeader>
				<CardBody>
					<CustomersChart
						data={ report.series.map( ( point ) => ( {
							label: bucketLabel(
								point.date,
								report.frame.interval
							),
							new: point.new,
							returning: point.returning,
						} ) ) }
					/>
				</CardBody>
			</Card>
			<Card>
				<CardHeader>
					<h2>{ __( 'Who spent the most', 'orillacart' ) }</h2>
				</CardHeader>
				<CardBody>
					<RankingTable
						empty={ report.top.length === 0 }
						header={ [
							__( 'Customer', 'orillacart' ),
							__( 'Orders', 'orillacart' ),
							__( 'Spent', 'orillacart' ),
						] }
						rows={ report.top.map( ( row, index ) => ( {
							key: `${ row.customer_id ?? 'guest' }-${ index }`,
							cells: [
								row.customer_id === null ? (
									`${ row.name } (${ __( 'guest', 'orillacart' ) })`
								) : (
									<a
										key="name"
										href={ `user-edit.php?user_id=${ row.customer_id }` }
									>
										{ row.name }
									</a>
								),
								number( row.orders ),
								money( row.total, currency ),
							],
						} ) ) }
					/>
				</CardBody>
			</Card>
		</>
	);
}

function RankingTable( {
	header,
	rows,
	empty,
}: {
	header: string[];
	rows: { key: string; cells: ReactNode[] }[];
	empty: boolean;
} ) {
	if ( empty ) {
		return (
			<p className="orillacart-reports__empty">
				{ __( 'Nothing was sold in these days.', 'orillacart' ) }
			</p>
		);
	}
	return (
		<table className="widefat striped orillacart-reports__table">
			<thead>
				<tr>
					{ header.map( ( title, index ) => (
						<th
							key={ title }
							scope="col"
							className={ index > 0 ? 'is-number' : undefined }
						>
							{ title }
						</th>
					) ) }
				</tr>
			</thead>
			<tbody>
				{ rows.map( ( row ) => (
					<tr key={ row.key }>
						{ row.cells.map( ( cell, index ) => (
							<td
								key={ index }
								className={
									index > 0 ? 'is-number' : undefined
								}
							>
								{ cell }
							</td>
						) ) }
					</tr>
				) ) }
			</tbody>
		</table>
	);
}

/**
 * The report on screen as a CSV file, named after its days.
 * @param tab
 * @param report
 */
function exportCsv( tab: Tab, report: Reports[ Tab ] ): void {
	const name = `orillacart-${ tab }-${ report.frame.from }-${ report.frame.to }.csv`;
	switch ( tab ) {
		case 'sales': {
			const sales = report as SalesReport;
			download(
				name,
				toCsv(
					[
						'date',
						'orders',
						'net_sales',
						'total_sales',
						'refunds',
						'previous_orders',
						'previous_net_sales',
					],
					sales.series.map( ( point ) => [
						point.date,
						point.orders,
						point.net,
						point.total,
						point.refunds,
						point.previous_orders,
						point.previous_net,
					] )
				)
			);
			return;
		}
		case 'products': {
			const products = report as RankingReport< ProductRow >;
			download(
				name,
				toCsv(
					[
						'product_id',
						'product',
						'items_sold',
						'net_sales',
						'orders',
						'previous_items_sold',
						'previous_net_sales',
					],
					products.rows.map( ( row ) => [
						row.product_id,
						row.name,
						row.quantity,
						row.net,
						row.orders,
						row.previous_quantity,
						row.previous_net,
					] )
				)
			);
			return;
		}
		case 'categories': {
			const categories = report as RankingReport< CategoryRow >;
			download(
				name,
				toCsv(
					[
						'category_id',
						'category',
						'items_sold',
						'net_sales',
						'orders',
						'previous_items_sold',
						'previous_net_sales',
					],
					categories.rows.map( ( row ) => [
						row.category_id,
						row.name,
						row.quantity,
						row.net,
						row.orders,
						row.previous_quantity,
						row.previous_net,
					] )
				)
			);
			return;
		}
		case 'coupons': {
			const coupons = report as RankingReport< CouponRow >;
			download(
				name,
				toCsv(
					[
						'coupon',
						'orders',
						'discount',
						'previous_orders',
						'previous_discount',
					],
					coupons.rows.map( ( row ) => [
						row.code,
						row.orders,
						row.amount,
						row.previous_orders,
						row.previous_amount,
					] )
				)
			);
			return;
		}
		case 'taxes': {
			const taxes = report as RankingReport< TaxRow >;
			download(
				name,
				toCsv(
					[
						'country',
						'region',
						'level',
						'code',
						'jurisdiction',
						'rate',
						'orders',
						'taxable_sales',
						'exempt_sales',
						'tax',
						'refunded_tax',
					],
					taxes.rows.map( ( row ) => [
						row.country,
						row.region,
						row.level,
						row.code,
						row.jurisdiction,
						row.percent,
						row.orders,
						row.taxable,
						row.exempt,
						row.tax,
						row.refunded,
					] )
				)
			);
			return;
		}
		case 'carts': {
			const carts = report as CartsReport;
			download(
				name,
				toCsv(
					[
						'left_at',
						'customer_id',
						'email',
						'items',
						'worth',
						'reminded_at',
						'order',
						'order_total',
					],
					carts.latest.map( ( row ) => [
						row.abandoned_at,
						row.customer_id,
						row.email,
						row.items,
						row.subtotal,
						row.reminded_at,
						row.order_id,
						row.order_total,
					] )
				)
			);
			return;
		}
		case 'customers': {
			const customers = report as CustomersReport;
			download(
				name,
				toCsv(
					[ 'customer_id', 'customer', 'orders', 'spent' ],
					customers.top.map( ( row ) => [
						row.customer_id,
						row.name,
						row.orders,
						row.total,
					] )
				)
			);
		}
	}
}
