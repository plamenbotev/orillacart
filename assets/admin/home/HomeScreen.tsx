import { Button } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { check } from '@wordpress/icons';

import { errorMessage } from '../shared/api';
import { STATUS_LABELS, money, when } from '../orders/labels';
import type { OrderSummary } from '../orders/api';
import {
	Badge,
	DataTable,
	EmptyState,
	Page,
	Section,
	Skeleton,
	StatusBadge,
} from '../shared/ui';
import { adminUrl, fetchHome, type Home, type NeedsYouLine } from './api';

/**
 * What a line of "Needs you" says, by what waits: the count in the shop's language; an add-on's line says its own.
 *
 * @param line The line.
 */
export function lineText( line: NeedsYouLine ): string {
	const n = line.count;
	switch ( line.kind ) {
		case 'attention':
			/* translators: %d: how many orders */
			return sprintf(
				_n(
					'%d order needs attention',
					'%d orders need attention',
					n,
					'orillacart'
				),
				n
			);
		case 'on_hold':
			/* translators: %d: how many orders */
			return sprintf(
				_n(
					'%d order on hold since before yesterday',
					'%d orders on hold since before yesterday',
					n,
					'orillacart'
				),
				n
			);
		case 'ship':
			/* translators: %d: how many orders */
			return sprintf(
				_n( '%d order to ship', '%d orders to ship', n, 'orillacart' ),
				n
			);
		case 'low_stock':
			/* translators: %d: how many items */
			return sprintf(
				_n(
					'%d item low in stock',
					'%d items low in stock',
					n,
					'orillacart'
				),
				n
			);
		case 'failed_jobs':
			/* translators: %d: how many jobs */
			return sprintf(
				_n(
					'%d background job failed',
					'%d background jobs failed',
					n,
					'orillacart'
				),
				n
			);
		case 'status':
			/* translators: %d: how many checks */
			return sprintf(
				_n(
					'%d status check worth a look',
					'%d status checks worth a look',
					n,
					'orillacart'
				),
				n
			);
		default:
			return line.text;
	}
}

/** A few days' net sales as a line, in the tile: the shape of the week, not the figures. */
function Sparkline( { points }: { points: number[] } ) {
	if ( points.length < 2 ) {
		return null;
	}
	const width = 120;
	const height = 32;
	const most = Math.max( ...points, 0 );
	const step = width / ( points.length - 1 );
	const path = points
		.map( ( value, index ) => {
			const x = index * step;
			const y =
				most === 0
					? height - 1
					: height - 1 - ( value / most ) * ( height - 2 );
			return `${ index === 0 ? 'M' : 'L' }${ x.toFixed( 1 ) } ${ y.toFixed( 1 ) }`;
		} )
		.join( ' ' );

	return (
		<svg
			className="oc-sparkline"
			viewBox={ `0 0 ${ width } ${ height }` }
			width={ width }
			height={ height }
			aria-hidden="true"
		>
			<path
				d={ path }
				fill="none"
				stroke="currentColor"
				strokeWidth="1.5"
			/>
		</svg>
	);
}

function Tile( {
	label,
	value,
	detail,
	url,
	children,
}: {
	label: string;
	value: string;
	detail?: string;
	url?: string;
	children?: React.ReactNode;
} ) {
	const body = (
		<>
			<span className="oc-tile__label">{ label }</span>
			<span className="oc-tile__value">{ value }</span>
			{ detail && <span className="oc-tile__detail">{ detail }</span> }
			{ children }
		</>
	);

	return url ? (
		<a className="oc-tile oc-tile--link" href={ url }>
			{ body }
		</a>
	) : (
		<div className="oc-tile">{ body }</div>
	);
}

const ORDERS_URL = 'admin.php?page=orillacart-orders';

/**
 * The Shop → Home screen (design 52): today's and the week's figures, what needs the shop manager, the latest
 * orders, and what add-ons put there.
 */
export function HomeScreen() {
	const [ home, setHome ] = useState< Home | null >( null );
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		fetchHome()
			.then( setHome )
			.catch( ( failure: unknown ) =>
				setError( errorMessage( failure ) )
			);
	}, [] );

	const columns = [
		{
			id: 'number',
			label: __( 'Order', 'orillacart' ),
			nowrap: true,
			render: ( order: OrderSummary ) => (
				<a href={ `${ ORDERS_URL }&order=${ order.number }` }>
					<strong>#{ order.number }</strong>
				</a>
			),
		},
		{
			id: 'placed',
			label: __( 'Placed', 'orillacart' ),
			nowrap: true,
			render: ( order: OrderSummary ) => when( order.placed_at ),
		},
		{
			id: 'customer',
			label: __( 'Customer', 'orillacart' ),
			render: ( order: OrderSummary ) => order.customer,
		},
		{
			id: 'status',
			label: __( 'Status', 'orillacart' ),
			render: ( order: OrderSummary ) => (
				<StatusBadge
					status={ order.status }
					label={ STATUS_LABELS[ order.status ] }
				/>
			),
		},
		{
			id: 'total',
			label: __( 'Total', 'orillacart' ),
			align: 'end' as const,
			render: ( order: OrderSummary ) =>
				money( order.total, order.currency ),
		},
	];

	return (
		<Page
			title={ __( 'Home', 'orillacart' ) }
			actions={
				<Button variant="primary" href={ `${ ORDERS_URL }&new=1` }>
					{ __( 'Add order', 'orillacart' ) }
				</Button>
			}
			error={ error }
			onDismissError={ () => setError( null ) }
		>
			{ home === null && error === null && (
				<Section>
					<Skeleton
						lines={ 4 }
						widths={ [ '40%', '70%', '55%', '30%' ] }
					/>
				</Section>
			) }
			{ home !== null && (
				<>
					{ ! home.setup_finished && (
						<Section
							title={ __( 'Set up the shop', 'orillacart' ) }
							description={ __(
								'Where it sells from, how it is paid and ships, and its first products, in a few steps.',
								'orillacart'
							) }
							action={
								<Button
									variant="primary"
									href="admin.php?page=orillacart-setup"
								>
									{ __( 'Continue the setup', 'orillacart' ) }
								</Button>
							}
						>
							<p>
								{ __(
									'The shop opens to customers once the setup is done.',
									'orillacart'
								) }
							</p>
						</Section>
					) }
					<div className="oc-tiles">
						<Tile
							label={ __( 'Today', 'orillacart' ) }
							value={ money( home.today.net, home.currency ) }
							detail={ sprintf(
								/* translators: %d: how many orders */
								_n(
									'%d order',
									'%d orders',
									home.today.orders,
									'orillacart'
								),
								home.today.orders
							) }
							url="admin.php?page=orillacart-reports"
						/>
						<Tile
							label={ __( 'Last 7 days', 'orillacart' ) }
							value={ money( home.week.net, home.currency ) }
							detail={ sprintf(
								/* translators: %d: how many orders */
								_n(
									'%d order',
									'%d orders',
									home.week.orders,
									'orillacart'
								),
								home.week.orders
							) }
							url="admin.php?page=orillacart-reports"
						>
							<Sparkline
								points={ home.week.series.map( ( point ) =>
									Number( point.net )
								) }
							/>
						</Tile>
						<Tile
							label={ __( 'Average order', 'orillacart' ) }
							value={ money(
								home.week.average_order,
								home.currency
							) }
							detail={ __( 'last 7 days', 'orillacart' ) }
						/>
						<Tile
							label={ __( 'Items sold', 'orillacart' ) }
							value={ String( home.week.items ) }
							detail={ __( 'last 7 days', 'orillacart' ) }
						/>
						{ home.tiles.map( ( tile, index ) => (
							<Tile
								key={ index }
								label={ tile.label }
								value={ tile.value }
								detail={ tile.detail }
								url={ adminUrl( tile.url ) }
							/>
						) ) }
					</div>
					<Section title={ __( 'Needs you', 'orillacart' ) }>
						{ home.needs_you.length === 0 ? (
							<EmptyState
								icon={ check }
								title={ __(
									'Nothing needs you.',
									'orillacart'
								) }
								description={ __(
									'Orders that wait, low stock, failed jobs and status warnings show here.',
									'orillacart'
								) }
							/>
						) : (
							<ul className="oc-needs">
								{ home.needs_you.map( ( line, index ) => (
									<li
										key={ index }
										className={ `oc-needs__line oc-needs__line--${ line.tone }` }
									>
										<Badge
											tone={
												line.tone === 'attention'
													? 'danger'
													: line.tone
											}
										>
											{ line.tone === 'attention'
												? __(
														'Attention',
														'orillacart'
													)
												: line.tone === 'info'
													? __(
															'To do',
															'orillacart'
														)
													: __(
															'Worth a look',
															'orillacart'
														) }
										</Badge>
										{ line.url ? (
											<a href={ adminUrl( line.url ) }>
												{ lineText( line ) }
											</a>
										) : (
											<span>{ lineText( line ) }</span>
										) }
									</li>
								) ) }
							</ul>
						) }
					</Section>
					<Section
						title={ __( 'Latest orders', 'orillacart' ) }
						action={
							<Button variant="tertiary" href={ ORDERS_URL }>
								{ __( 'All orders', 'orillacart' ) }
							</Button>
						}
					>
						<DataTable
							columns={ columns }
							rows={ home.recent }
							rowKey={ ( order ) => order.number }
							empty={
								<EmptyState
									title={ __(
										'No orders yet.',
										'orillacart'
									) }
									description={ __(
										'The first orders show here as they come.',
										'orillacart'
									) }
								/>
							}
						/>
					</Section>
				</>
			) }
		</Page>
	);
}
