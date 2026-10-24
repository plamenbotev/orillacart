import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Flex,
	FlexItem,
	RadioControl,
	TextareaControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	fetchReturns,
	type ItemReturn,
	moveReturn,
	type Order,
	type OrderReturns,
} from './api';
import { money } from './labels';
import { useConfirm } from '../shared/useConfirm';

const STATUS: Record< ItemReturn[ 'status' ], string > = {
	requested: __( 'Asked for', 'orillacart' ),
	approved: __( 'Approved: on its way back', 'orillacart' ),
	refused: __( 'Refused', 'orillacart' ),
	received: __( 'Came back', 'orillacart' ),
	refunding: __(
		'Refunded: the provider is sending the money',
		'orillacart'
	),
	refunded: __( 'Refunded', 'orillacart' ),
};

/**
 * The items customers asked to send back: approve or refuse each with an answer they read, say when the items came
 * back, and refund them: through the payment provider, as made outside the shop, or as store credit, the items back
 * in stock unless they came back broken. Shown when the order has returns.
 *
 * @param props           The card's properties.
 * @param props.order     The order.
 * @param props.onChanged Told when a return moved on, as the order follows a refund.
 * @param props.onError   Receives what went wrong.
 */
export function ReturnsCard( {
	order,
	onChanged,
	onError,
}: {
	order: Order;
	onChanged: () => void;
	onError: ( error: unknown ) => void;
} ) {
	const [ state, setState ] = useState< OrderReturns | null >( null );
	const [ busy, setBusy ] = useState( false );
	const load = () =>
		fetchReturns( order.number ).then( setState ).catch( onError );
	// eslint-disable-next-line react-hooks/exhaustive-deps
	useEffect( () => void load(), [ order.number ] );

	if ( state === null || state.returns.length === 0 ) {
		return null;
	}
	const run = ( work: Promise< unknown > ) => {
		setBusy( true );
		work.then( () => {
			onChanged();
			return load();
		} )
			.catch( onError )
			.finally( () => setBusy( false ) );
	};

	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>{ __( 'Returns', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				{ state.returns.map( ( itemReturn ) => (
					<ReturnRow
						key={ itemReturn.id }
						itemReturn={ itemReturn }
						order={ order }
						busy={ busy }
						run={ run }
					/>
				) ) }
			</CardBody>
		</Card>
	);
}

function ReturnRow( {
	itemReturn,
	order,
	busy,
	run,
}: {
	itemReturn: ItemReturn;
	order: Order;
	busy: boolean;
	run: ( work: Promise< unknown > ) => void;
} ) {
	const currency = order.currency;
	const [ answer, setAnswer ] = useState( '' );
	const [ how, setHow ] = useState< 'provider' | 'outside' | 'store_credit' >(
		'provider'
	);
	// What came back broken is not sold again, unless the shop says so.
	const [ restock, setRestock ] = useState( itemReturn.reason !== 'damaged' );
	const [ confirmation, confirm ] = useConfirm();
	const amount = money( itemReturn.amount, currency );
	const refund = () =>
		run(
			moveReturn( itemReturn.id, 'refund', {
				outside: how === 'outside',
				store_credit: how === 'store_credit',
				restock,
			} )
		);

	return (
		<div className="orillacart-return">
			<p>
				<strong>
					{ itemReturn.items
						.map(
							( item ) => `${ item.quantity } × ${ item.name }`
						)
						.join( ', ' ) }
				</strong>{ ' ' }
				— { STATUS[ itemReturn.status ] }. { itemReturn.reason_label }
				{ itemReturn.comment !== '' && `: “${ itemReturn.comment }”` }
			</p>
			{ itemReturn.answer !== '' && (
				<p className="description">{ itemReturn.answer }</p>
			) }
			{ itemReturn.status === 'requested' && (
				<>
					<TextareaControl
						label={ __( 'Answer for the customer', 'orillacart' ) }
						help={ __(
							'To approve: where and how to send the items. To refuse: why.',
							'orillacart'
						) }
						value={ answer }
						onChange={ setAnswer }
					/>
					<Flex justify="flex-start">
						<Button
							variant="primary"
							disabled={ busy }
							onClick={ () =>
								run(
									moveReturn( itemReturn.id, 'approve', {
										answer,
									} )
								)
							}
						>
							{ __( 'Approve', 'orillacart' ) }
						</Button>
						<Button
							variant="secondary"
							isDestructive
							disabled={ busy || answer.trim() === '' }
							onClick={ () =>
								run(
									moveReturn( itemReturn.id, 'refuse', {
										answer,
									} )
								)
							}
						>
							{ __( 'Refuse', 'orillacart' ) }
						</Button>
					</Flex>
				</>
			) }
			{ ( itemReturn.status === 'requested' ||
				itemReturn.status === 'approved' ) && (
				<Button
					variant="secondary"
					disabled={ busy }
					onClick={ () =>
						run( moveReturn( itemReturn.id, 'receive' ) )
					}
				>
					{ __( 'The items came back', 'orillacart' ) }
				</Button>
			) }
			{ itemReturn.status === 'received' && (
				<>
					{ confirmation }
					<RadioControl
						label={ __( 'Give the money back', 'orillacart' ) }
						selected={ how }
						options={ [
							{
								value: 'provider',
								label: __(
									'The way the customer paid',
									'orillacart'
								),
							},
							{
								value: 'outside',
								label: __(
									'I gave it back outside the shop: only record it',
									'orillacart'
								),
							},
							{
								value: 'store_credit',
								label:
									order.customer_id !== null
										? __(
												'As store credit, to spend in the shop',
												'orillacart'
											)
										: sprintf(
												/* translators: %s: email address */
												__(
													'As a gift card, sent to %s',
													'orillacart'
												),
												order.email
											),
							},
						] }
						onChange={ ( choice ) =>
							setHow( choice as typeof how )
						}
					/>
					<CheckboxControl
						label={ __(
							'Put the items back in stock',
							'orillacart'
						) }
						help={ __(
							'Leave it off for items that came back broken.',
							'orillacart'
						) }
						checked={ restock }
						onChange={ setRestock }
					/>
					<Flex align="center" justify="flex-start">
						<FlexItem>
							<Button
								variant="primary"
								disabled={ busy }
								onClick={ () =>
									confirm(
										sprintf(
											/* translators: %s: an amount of money */
											__(
												'Give %s back to the customer for these items?',
												'orillacart'
											),
											amount
										),
										refund,
										__( 'Refund', 'orillacart' )
									)
								}
							>
								{ sprintf(
									/* translators: %s: an amount of money */
									__( 'Refund %s', 'orillacart' ),
									amount
								) }
							</Button>
						</FlexItem>
					</Flex>
				</>
			) }
		</div>
	);
}
