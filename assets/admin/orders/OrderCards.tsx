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
	type Order,
	type OrderShipments,
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
 * Shipments booked with the courier of the order's carrier: book one, print its label, cancel it before the
 * courier picks it up. Shown only for orders whose carrier's shipping method books shipments, or that have some.
 *
 * @param props         The card's properties.
 * @param props.order   The order.
 * @param props.onError Receives what went wrong.
 */
export function ShipmentsCard( {
	order,
	onError,
}: {
	order: Order;
	onError: ( error: unknown ) => void;
} ) {
	const [ state, setState ] = useState< OrderShipments | null >( null );
	const [ parcels, setParcels ] = useState( 1 );
	const [ busy, setBusy ] = useState( false );
	const load = () =>
		fetchShipments( order.number ).then( setState ).catch( onError );
	// eslint-disable-next-line react-hooks/exhaustive-deps
	useEffect( () => void load(), [ order.number ] );

	if ( state === null ) {
		return <Spinner />;
	}
	if ( ! state.bookable && state.shipments.length === 0 ) {
		return null;
	}
	const run = ( work: Promise< unknown > ) => {
		setBusy( true );
		work.then( load )
			.catch( onError )
			.finally( () => setBusy( false ) );
	};
	const open = state.shipments.some(
		( shipment ) =>
			shipment.status === 'booked' || shipment.status === 'on_the_way'
	);

	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>{ __( 'Shipments', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				{ state.shipments.map( ( shipment ) => (
					<Flex key={ shipment.id } align="center" wrap>
						<FlexItem isBlock>
							<strong>
								{ shipment.carrier } { shipment.number }
							</strong>{ ' ' }
							— { SHIPMENT_STATUS[ shipment.status ] }
							{ shipment.tracking !== '' &&
								`: ${ shipment.tracking }` }
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
						</FlexItem>
						{ shipment.status !== 'cancelled' && (
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
									onClick={ () =>
										run( cancelShipment( shipment.id ) )
									}
								>
									{ __(
										'Cancel the shipment',
										'orillacart'
									) }
								</Button>
							</FlexItem>
						) }
					</Flex>
				) ) }
				{ state.bookable && ! open && (
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
								disabled={ busy }
								onClick={ () =>
									run( bookShipment( order.number, parcels ) )
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
			</CardBody>
		</Card>
	);
}
