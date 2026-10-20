import {
	Button,
	Card,
	CardBody,
	CardHeader,
	ComboboxControl,
	Flex,
	FlexItem,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { fetchCustomers, type Customer } from '../customers/api';
import {
	assignOrder,
	bookShipment,
	cancelShipment,
	changeDownloadAllowance,
	fetchLabel,
	fetchShipments,
	pickedUp,
	readyForPickup,
	sendShipment,
	shipmentArrived,
	type Order,
	type OrderShipments,
	type Shipment,
	type ShipItems,
} from './api';

interface CardProps {
	order: Order;
	onSaved: ( order: Order ) => void;
	onError: ( error: unknown ) => void;
}

/**
 * Whose account the order is in: moved to another customer's, or made a guest's order.
 *
 * @param props         The card's properties.
 * @param props.order   The order.
 * @param props.onSaved Receives the changed order.
 * @param props.onError Receives what went wrong.
 */
export function AccountCard( { order, onSaved, onError }: CardProps ) {
	const [ search, setSearch ] = useState( '' );
	const [ found, setFound ] = useState< Customer[] >( [] );
	const [ chosen, setChosen ] = useState< number | null >( null );

	useEffect( () => {
		const timer = window.setTimeout( () => {
			fetchCustomers( {
				search: search || undefined,
				page: 1,
				per_page: 20,
			} )
				.then( ( page ) => setFound( page.customers ) )
				.catch( () => setFound( [] ) );
		}, 250 );
		return () => window.clearTimeout( timer );
	}, [ search ] );

	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>{ __( 'Account', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ order.customer_id === null
						? __(
								'A guest’s order: it is in no customer’s account.',
								'orillacart'
							)
						: sprintf(
								/* translators: %d: customer id */
								__(
									'In the account of customer #%d.',
									'orillacart'
								),
								order.customer_id
							) }
				</p>
				<Flex align="flex-end" wrap>
					<FlexItem isBlock>
						<ComboboxControl
							__next40pxDefaultSize
							label={ __(
								'Move it to the account of',
								'orillacart'
							) }
							value={ chosen === null ? null : String( chosen ) }
							options={ found.map( ( customer ) => ( {
								label: `${ customer.first_name } ${ customer.last_name } <${ customer.email }>`.trim(),
								value: String( customer.id ),
							} ) ) }
							onFilterValueChange={ setSearch }
							onChange={ ( value ) =>
								setChosen( value ? Number( value ) : null )
							}
						/>
					</FlexItem>
					<FlexItem>
						<Button
							variant="secondary"
							disabled={
								chosen === null || chosen === order.customer_id
							}
							onClick={ () =>
								assignOrder( order.number, chosen )
									.then( onSaved )
									.catch( onError )
							}
						>
							{ __( 'Move', 'orillacart' ) }
						</Button>
					</FlexItem>
				</Flex>
				{ order.customer_id !== null && (
					<Button
						variant="tertiary"
						onClick={ () =>
							assignOrder( order.number, null )
								.then( onSaved )
								.catch( onError )
						}
					>
						{ __( 'Make it a guest’s order', 'orillacart' ) }
					</Button>
				) }
			</CardBody>
		</Card>
	);
}

/**
 * The files the order gives, with how many downloads are left and until when; the shop can give more.
 *
 * @param props         The card's properties.
 * @param props.order   The order.
 * @param props.onSaved Receives the changed order.
 * @param props.onError Receives what went wrong.
 */
export function DownloadsCard( { order, onSaved, onError }: CardProps ) {
	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>{ __( 'Downloads', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				{ order.downloads.map( ( download ) => (
					<DownloadRow
						key={ download.id }
						order={ order }
						download={ download }
						onSaved={ onSaved }
						onError={ onError }
					/>
				) ) }
			</CardBody>
		</Card>
	);
}

function DownloadRow( {
	order,
	download,
	onSaved,
	onError,
}: CardProps & { download: Order[ 'downloads' ][ number ] } ) {
	const [ remaining, setRemaining ] = useState(
		download.remaining === null ? '' : String( download.remaining )
	);
	const [ until, setUntil ] = useState(
		download.expires_at === null ? '' : download.expires_at.slice( 0, 10 )
	);

	return (
		<Flex align="flex-end" wrap>
			<FlexItem isBlock>
				<strong>{ download.name }</strong>
				<br />
				<small>
					{ sprintf(
						/* translators: 1: product, 2: how many times it was downloaded */
						__( '%1$s · downloaded %2$d times', 'orillacart' ),
						download.product,
						download.downloads
					) }
				</small>
			</FlexItem>
			<FlexItem>
				<TextControl
					__next40pxDefaultSize
					type="number"
					min={ 0 }
					label={ __( 'Downloads left', 'orillacart' ) }
					help={ __( 'Empty: no limit', 'orillacart' ) }
					value={ remaining }
					onChange={ setRemaining }
				/>
			</FlexItem>
			<FlexItem>
				<TextControl
					__next40pxDefaultSize
					type="date"
					label={ __( 'Last day', 'orillacart' ) }
					help={ __( 'Empty: no end', 'orillacart' ) }
					value={ until }
					onChange={ setUntil }
				/>
			</FlexItem>
			<FlexItem>
				<Button
					variant="secondary"
					onClick={ () =>
						changeDownloadAllowance(
							order.number,
							download.id,
							remaining === ''
								? null
								: Math.max(
										0,
										Number.parseInt( remaining, 10 ) || 0
									),
							until === '' ? null : until
						)
							.then( onSaved )
							.catch( onError )
					}
				>
					{ __( 'Save', 'orillacart' ) }
				</Button>
			</FlexItem>
		</Flex>
	);
}

const SHIPMENT_STATUS: Record< string, string > = {
	booked: __( 'Booked', 'orillacart' ),
	on_the_way: __( 'On the way', 'orillacart' ),
	delivered: __( 'Delivered', 'orillacart' ),
	returned: __( 'Coming back', 'orillacart' ),
	cancelled: __( 'Cancelled', 'orillacart' ),
};

/**
 * The order's shipments: booked with the courier of the order's carrier (print the label, cancel it before the
 * courier picks it up), or sent by the shop itself with each parcel's tracking number (then marked arrived). A
 * shipment carries some or all of what is left to send. Shown for orders that ship.
 *
 * @param props           The card's properties.
 * @param props.order     The order.
 * @param props.onError   Receives what went wrong.
 * @param props.onChanged Told when a shipment changed, as the order may have followed it.
 */
export function ShipmentsCard( {
	order,
	onError,
	onChanged,
}: {
	order: Order;
	onError: ( error: unknown ) => void;
	onChanged?: () => void;
} ) {
	const [ state, setState ] = useState< OrderShipments | null >( null );
	const [ busy, setBusy ] = useState( false );
	const load = () =>
		fetchShipments( order.number ).then( setState ).catch( onError );
	// eslint-disable-next-line react-hooks/exhaustive-deps
	useEffect( () => void load(), [ order.number ] );

	if ( state === null ) {
		return <Spinner />;
	}
	if (
		! state.sendable &&
		state.shipments.length === 0 &&
		state.pickup === null
	) {
		return null;
	}
	const run = ( work: Promise< unknown > ) => {
		setBusy( true );
		return work
			.then( () => {
				onChanged?.();
				return load();
			} )
			.catch( onError )
			.finally( () => setBusy( false ) );
	};

	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>{ __( 'Shipments', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				{ state.shipments.map( ( shipment ) => (
					<ShipmentRow
						key={ shipment.id }
						shipment={ shipment }
						busy={ busy }
						run={ run }
						onError={ onError }
					/>
				) ) }
				{ state.pickup !== null && (
					<Flex align="center" wrap>
						<FlexItem isBlock>
							{ sprintf(
								/* translators: %s: the shop's place, with its address and hours */
								__( 'Collected at %s', 'orillacart' ),
								state.pickup
							) }
						</FlexItem>
						{ state.collectable && (
							<>
								<FlexItem>
									<Button
										variant="secondary"
										disabled={ busy }
										onClick={ () =>
											run(
												readyForPickup( order.number )
											)
										}
									>
										{ __(
											'Tell the customer it is ready',
											'orillacart'
										) }
									</Button>
								</FlexItem>
								<FlexItem>
									<Button
										variant="primary"
										disabled={ busy }
										onClick={ () =>
											run( pickedUp( order.number ) )
										}
									>
										{ __( 'Collected', 'orillacart' ) }
									</Button>
								</FlexItem>
							</>
						) }
					</Flex>
				) }
				{ state.sendable && (
					<NewShipment
						key={ JSON.stringify( state.left ) }
						order={ order }
						state={ state }
						busy={ busy }
						run={ run }
					/>
				) }
			</CardBody>
		</Card>
	);
}

function ShipmentRow( {
	shipment,
	busy,
	run,
	onError,
}: {
	shipment: Shipment;
	busy: boolean;
	run: ( work: Promise< unknown > ) => Promise< unknown >;
	onError: ( error: unknown ) => void;
} ) {
	const parcels = shipment.parcels.length > 1 ? shipment.parcels : [];
	return (
		<Flex align="center" wrap className="orillacart-shipment">
			<FlexItem isBlock>
				<strong>
					{ shipment.carrier } { shipment.number }
				</strong>{ ' ' }
				— { SHIPMENT_STATUS[ shipment.status ] }
				{ shipment.tracking !== '' && `: ${ shipment.tracking }` }
				{ shipment.tracking_url !== null && (
					<>
						{ ' ' }
						<a
							href={ shipment.tracking_url }
							target="_blank"
							rel="noreferrer"
						>
							{ __( 'Follow it', 'orillacart' ) }
						</a>
					</>
				) }
				<br />
				<small>
					{ shipment.items
						.map(
							( item ) => `${ item.quantity } × ${ item.name }`
						)
						.join( ', ' ) }
					{ parcels.length > 0 &&
						' · ' +
							sprintf(
								/* translators: %s: tracking numbers */
								__( 'Parcels: %s', 'orillacart' ),
								parcels
									.map( ( parcel ) => parcel.number )
									.join( ', ' )
							) }
				</small>
			</FlexItem>
			{ ! shipment.sent_by_shop && shipment.status !== 'cancelled' && (
				<FlexItem>
					<Button
						variant="secondary"
						disabled={ busy }
						onClick={ () =>
							fetchLabel( shipment.id )
								.then( ( file ) =>
									window.open(
										URL.createObjectURL( file ),
										'_blank'
									)
								)
								.catch( onError )
						}
					>
						{ __( 'Print the label', 'orillacart' ) }
					</Button>
				</FlexItem>
			) }
			{ shipment.status === 'booked' && (
				<FlexItem>
					<Button
						variant="tertiary"
						isDestructive
						disabled={ busy }
						onClick={ () => run( cancelShipment( shipment.id ) ) }
					>
						{ __( 'Cancel the shipment', 'orillacart' ) }
					</Button>
				</FlexItem>
			) }
			{ shipment.sent_by_shop && shipment.status === 'on_the_way' && (
				<>
					<FlexItem>
						<Button
							variant="secondary"
							disabled={ busy }
							onClick={ () =>
								run(
									shipmentArrived( shipment.id, 'delivered' )
								)
							}
						>
							{ __( 'It arrived', 'orillacart' ) }
						</Button>
					</FlexItem>
					<FlexItem>
						<Button
							variant="tertiary"
							disabled={ busy }
							onClick={ () =>
								run(
									shipmentArrived( shipment.id, 'returned' )
								)
							}
						>
							{ __( 'It came back', 'orillacart' ) }
						</Button>
					</FlexItem>
				</>
			) }
		</Flex>
	);
}

/**
 * A shipment of what is left: how many of each item, then booked with the carrier's courier, or recorded as sent
 * by the shop with each parcel's tracking number.
 * @param props
 * @param props.order
 * @param props.state
 * @param props.busy
 * @param props.run
 */
function NewShipment( {
	order,
	state,
	busy,
	run,
}: {
	order: Order;
	state: OrderShipments;
	busy: boolean;
	run: ( work: Promise< unknown > ) => Promise< unknown >;
} ) {
	const [ quantities, setQuantities ] = useState< Record< number, number > >(
		Object.fromEntries(
			state.left.map( ( item ) => [ item.line, item.quantity ] )
		)
	);
	const [ parcels, setParcels ] = useState( 1 );
	const [ carrier, setCarrier ] = useState( state.carrier ?? '' );
	const [ tracking, setTracking ] = useState<
		{ number: string; url: string }[]
	>( [ { number: '', url: '' } ] );
	const items: ShipItems = state.left
		.map( ( item ) => ( {
			line: item.line,
			quantity: quantities[ item.line ] ?? 0,
		} ) )
		.filter( ( item ) => item.quantity > 0 );
	const setTrackingAt = (
		index: number,
		change: Partial< { number: string; url: string } >
	) =>
		setTracking(
			tracking.map( ( parcel, at ) =>
				at === index ? { ...parcel, ...change } : parcel
			)
		);

	return (
		<div className="orillacart-new-shipment">
			<h3>{ __( 'Send', 'orillacart' ) }</h3>
			{ state.left.map( ( item ) => (
				<TextControl
					key={ item.line }
					__next40pxDefaultSize
					type="number"
					min={ 0 }
					max={ item.quantity }
					label={ sprintf(
						/* translators: 1: product name, 2: how many are left to send */
						__( '%1$s (%2$d left)', 'orillacart' ),
						item.name,
						item.quantity
					) }
					value={ String( quantities[ item.line ] ?? 0 ) }
					onChange={ ( value ) =>
						setQuantities( {
							...quantities,
							[ item.line ]: Math.min(
								item.quantity,
								Math.max( 0, Number.parseInt( value, 10 ) || 0 )
							),
						} )
					}
				/>
			) ) }
			{ state.bookable && (
				<Flex align="flex-end">
					<FlexItem>
						<TextControl
							__next40pxDefaultSize
							type="number"
							min={ 1 }
							label={ __( 'Parcels', 'orillacart' ) }
							value={ String( parcels ) }
							onChange={ ( value ) =>
								setParcels(
									Math.max(
										1,
										Number.parseInt( value, 10 ) || 1
									)
								)
							}
						/>
					</FlexItem>
					<FlexItem>
						<Button
							variant="primary"
							isBusy={ busy }
							disabled={ busy || items.length === 0 }
							onClick={ () =>
								run(
									bookShipment( order.number, parcels, items )
								)
							}
						>
							{ sprintf(
								/* translators: %s: carrier name */
								__( 'Book with %s', 'orillacart' ),
								state.carrier ?? ''
							) }
						</Button>
					</FlexItem>
				</Flex>
			) }
			<details open={ ! state.bookable }>
				<summary>
					{ __( 'Record a shipment you sent', 'orillacart' ) }
				</summary>
				<TextControl
					__next40pxDefaultSize
					label={ __( 'Sent with', 'orillacart' ) }
					value={ carrier }
					onChange={ setCarrier }
				/>
				{ tracking.map( ( parcel, index ) => (
					<Flex key={ index } align="flex-end">
						<FlexItem isBlock>
							<TextControl
								__next40pxDefaultSize
								label={ sprintf(
									/* translators: %d: parcel number, from 1 */
									__(
										'Tracking number of parcel %d',
										'orillacart'
									),
									index + 1
								) }
								value={ parcel.number }
								onChange={ ( number ) =>
									setTrackingAt( index, { number } )
								}
							/>
						</FlexItem>
						<FlexItem isBlock>
							<TextControl
								__next40pxDefaultSize
								type="url"
								label={ sprintf(
									/* translators: %d: parcel number, from 1 */
									__(
										'Tracking link of parcel %d',
										'orillacart'
									),
									index + 1
								) }
								value={ parcel.url }
								onChange={ ( url ) =>
									setTrackingAt( index, { url } )
								}
							/>
						</FlexItem>
					</Flex>
				) ) }
				<Button
					variant="tertiary"
					onClick={ () =>
						setTracking( [ ...tracking, { number: '', url: '' } ] )
					}
				>
					{ __( 'Add a parcel', 'orillacart' ) }
				</Button>
				<Button
					variant="secondary"
					isBusy={ busy }
					disabled={
						busy ||
						items.length === 0 ||
						carrier.trim() === '' ||
						tracking.some(
							( parcel ) => parcel.number.trim() === ''
						)
					}
					onClick={ () =>
						run(
							sendShipment( order.number, {
								carrier: carrier.trim(),
								parcels: tracking.map( ( parcel ) => ( {
									number: parcel.number.trim(),
									tracking_url:
										parcel.url.trim() === ''
											? null
											: parcel.url.trim(),
								} ) ),
								items,
							} )
						)
					}
				>
					{ __( 'Record the shipment', 'orillacart' ) }
				</Button>
			</details>
		</div>
	);
}
