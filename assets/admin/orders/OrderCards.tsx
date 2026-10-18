import {
	Button,
	Card,
	CardBody,
	CardHeader,
	ComboboxControl,
	Flex,
	FlexItem,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { fetchCustomers, type Customer } from '../customers/api';
import { assignOrder, changeDownloadAllowance, type Order } from './api';

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
