import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Flex,
	FlexItem,
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

const STATUS: Record< ItemReturn[ 'status' ], string > = {
	requested: __( 'Asked for', 'orillacart' ),
	approved: __( 'Approved: on its way back', 'orillacart' ),
	refused: __( 'Refused', 'orillacart' ),
	received: __( 'Came back', 'orillacart' ),
	refunded: __( 'Refunded', 'orillacart' ),
};

/**
 * The items customers asked to send back: approve or refuse each with an answer they read, say when the items came
 * back, and refund them, which puts them back in stock. Shown when the order has returns.
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
						currency={ order.currency }
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
	currency,
	busy,
	run,
}: {
	itemReturn: ItemReturn;
	currency: string;
	busy: boolean;
	run: ( work: Promise< unknown > ) => void;
} ) {
	const [ answer, setAnswer ] = useState( '' );
	const [ outside, setOutside ] = useState( false );

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
				<Flex align="center" justify="flex-start">
					<FlexItem>
						<CheckboxControl
							label={ __(
								'I gave the money back outside the shop',
								'orillacart'
							) }
							checked={ outside }
							onChange={ setOutside }
						/>
					</FlexItem>
					<FlexItem>
						<Button
							variant="primary"
							disabled={ busy }
							onClick={ () =>
								run(
									moveReturn( itemReturn.id, 'refund', {
										outside,
									} )
								)
							}
						>
							{ sprintf(
								/* translators: %s: an amount of money */
								__( 'Refund %s', 'orillacart' ),
								money( itemReturn.amount, currency )
							) }
						</Button>
					</FlexItem>
				</Flex>
			) }
		</div>
	);
}
