import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Flex,
	FlexItem,
	RadioControl,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import {
	fetchRefunds,
	giveUpRefund,
	type Order,
	type OrderRefunds,
	type Refund,
	refundOrder,
	retryRefund,
} from './api';
import { currencyDecimals, money, when } from './labels';
import { useConfirm } from '../shared/useConfirm';
import { useLatest } from '../shared/useLatest';
import { MoneyControl } from '../shared/MoneyControl';

interface RefundsCardProps {
	order: Order;
	onRefunded: ( message: string ) => void;
	onError: ( error: unknown ) => void;
}

/**
 * @param value    An amount.
 * @param decimals The decimals of the order's currency, as refunds are made in.
 * @return The amount to those decimals.
 */
const decimal = ( value: number, decimals: number ) =>
	value.toFixed( decimals );

/**
 * What the chosen items and shipping cost the customer, tax included and discounts taken off: the amount the
 * form suggests. With prices that include tax, the tax is in them already.
 *
 * @param order      The order.
 * @param quantities Items to refund by line position.
 * @param shipping   Whether the shipping charge is refunded.
 */
export function suggestedAmount(
	order: Order,
	quantities: Record< number, number >,
	shipping: boolean
): number {
	const added = order.prices_include_tax ? 0 : 1;
	let amount = 0;
	order.lines.forEach( ( line, position ) => {
		const quantity = quantities[ position ] ?? 0;
		if ( quantity > 0 ) {
			amount +=
				( ( Number( line.total ) -
					Number( line.discount ?? 0 ) +
					added * Number( line.tax ) ) *
					quantity ) /
				line.quantity;
		}
	} );
	if ( shipping ) {
		amount +=
			Number( order.shipping_total ) +
			added * Number( order.shipping_tax );
	}
	return amount;
}

const METHOD_LABELS: Record< Refund[ 'method' ], string > = {
	provider: __( 'through the payment provider', 'orillacart' ),
	manual: __( 'made outside the shop', 'orillacart' ),
	external: __( 'made at the payment provider’s site', 'orillacart' ),
	store_credit: __( 'as store credit', 'orillacart' ),
};

/**
 * The order's refunds, and a form to give money back: chosen items (the amount follows, and the shop can change
 * it), the shipping, or an amount alone. Through the payment provider when it can refund, otherwise recorded as
 * made outside the shop.
 *
 * @param props            The card's properties.
 * @param props.order      The order refunded.
 * @param props.onRefunded Told of each refund made, with a message for the shop.
 * @param props.onError    Shows what went wrong.
 */
export function RefundsCard( {
	order,
	onRefunded,
	onError,
}: RefundsCardProps ) {
	const [ state, setState ] = useState< OrderRefunds | null >( null );
	const [ quantities, setQuantities ] = useState< Record< number, number > >(
		{}
	);
	const [ shipping, setShipping ] = useState( false );
	const [ amount, setAmount ] = useState< string | null >( null );
	const [ reason, setReason ] = useState( '' );
	const [ restock, setRestock ] = useState( true );
	const [ how, setHow ] = useState< 'provider' | 'outside' | 'store_credit' >(
		'provider'
	);
	const outside = how === 'outside';
	const [ busy, setBusy ] = useState( false );
	const [ confirmation, confirm ] = useConfirm();

	const latest = useLatest();
	useEffect( () => {
		latest( fetchRefunds( order.number ) )
			.then( setState )
			.catch( onError );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ order.number, order.refunded_total ] );

	if ( state === null ) {
		return <Spinner />;
	}
	if ( ! state.paid && state.refunds.length === 0 ) {
		return null;
	}

	const refundable = Number( state.refundable );
	const suggested = Math.min(
		refundable,
		suggestedAmount( order, quantities, shipping )
	);
	const decimals = currencyDecimals( order.currency );
	const value = amount ?? decimal( suggested, decimals );
	const items = Object.values( quantities ).some( ( count ) => count > 0 );
	const throughProvider = state.provider !== null && how === 'provider';

	const run = ( work: Promise< OrderRefunds >, message: string ) => {
		setBusy( true );
		work.then( ( fresh ) => {
			setState( fresh );
			setQuantities( {} );
			setShipping( false );
			setAmount( null );
			setReason( '' );
			onRefunded( message );
		} )
			.catch( ( error: unknown ) => {
				// A refund that waits for the provider shows in the list.
				latest( fetchRefunds( order.number ) )
					.then( setState )
					.catch( onError );
				onError( error );
			} )
			.finally( () => setBusy( false ) );
	};
	const submit = () =>
		run(
			refundOrder( order.number, {
				amount: value,
				lines: Object.entries( quantities )
					.filter( ( [ , count ] ) => count > 0 )
					.map( ( [ line, count ] ) => ( {
						line: Number( line ),
						quantity: count,
					} ) ),
				shipping,
				restock: items && restock,
				reason,
				outside:
					outside ||
					( state.provider === null && how === 'provider' ),
				store_credit: how === 'store_credit',
			} ),
			sprintf(
				/* translators: %s: amount */
				__( '%s was refunded.', 'orillacart' ),
				money( value, order.currency )
			)
		);

	return (
		<Card className="orillacart-card">
			{ confirmation }
			<CardHeader>
				<h2>{ __( 'Refunds', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				{ state.refunds.length > 0 && (
					<ul className="orillacart-refunds">
						{ state.refunds.map( ( refund ) => (
							<li key={ refund.id }>
								<RefundRow
									refund={ refund }
									order={ order }
									provider={ state.provider }
								/>
								{ refund.status === 'pending' && (
									<Flex justify="flex-start">
										<Button
											variant="secondary"
											isBusy={ busy }
											disabled={ busy }
											onClick={ () =>
												run(
													retryRefund( refund.id ),
													__(
														'The refund went through.',
														'orillacart'
													)
												)
											}
										>
											{ __( 'Try again', 'orillacart' ) }
										</Button>
										<Button
											variant="tertiary"
											isDestructive
											disabled={ busy }
											onClick={ () =>
												run(
													giveUpRefund( refund.id ),
													__(
														'The refund was given up.',
														'orillacart'
													)
												)
											}
										>
											{ __(
												'Give up (it did not go through)',
												'orillacart'
											) }
										</Button>
									</Flex>
								) }
							</li>
						) ) }
					</ul>
				) }
				{ refundable > 0 && state.paid && (
					<>
						<p>
							{ sprintf(
								/* translators: %s: amount */
								__( '%s is left to refund.', 'orillacart' ),
								money( state.refundable, order.currency )
							) }
						</p>
						{ order.lines.map( ( line, position ) => {
							const left = line.quantity - line.refunded_quantity;
							if ( left <= 0 ) {
								return null;
							}
							return (
								<TextControl
									__next40pxDefaultSize
									key={ position }
									type="number"
									min={ 0 }
									max={ left }
									label={ sprintf(
										/* translators: 1: product, 2: items left to refund */
										__(
											'%1$s: items to refund (of %2$d)',
											'orillacart'
										),
										line.name,
										left
									) }
									value={ String(
										quantities[ position ] ?? 0
									) }
									onChange={ ( count ) => {
										setQuantities( {
											...quantities,
											[ position ]: Math.max(
												0,
												Math.min(
													left,
													Math.floor(
														Number( count ) || 0
													)
												)
											),
										} );
										setAmount( null );
									} }
								/>
							);
						} ) }
						{ order.shipping !== null &&
							! order.shipping_refunded && (
								<CheckboxControl
									label={ sprintf(
										/* translators: %s: shipping charge */
										__(
											'Refund the shipping (%s)',
											'orillacart'
										),
										money(
											decimal(
												Number( order.shipping_total ) +
													Number(
														order.shipping_tax
													),
												decimals
											),
											order.currency
										)
									) }
									checked={ shipping }
									onChange={ ( on ) => {
										setShipping( on );
										setAmount( null );
									} }
								/>
							) }
						<MoneyControl
							label={ __( 'Amount to refund', 'orillacart' ) }
							help={ __(
								'Follows the items and shipping you chose, tax included. Change it to keep a fee, or refund an amount alone as a goodwill gesture.',
								'orillacart'
							) }
							value={ value }
							decimals={ decimals }
							onChange={ setAmount }
						/>
						<TextControl
							__next40pxDefaultSize
							label={ __(
								'Reason (for the history)',
								'orillacart'
							) }
							value={ reason }
							onChange={ setReason }
						/>
						{ items && (
							<CheckboxControl
								label={ __(
									'Put the refunded items back in stock',
									'orillacart'
								) }
								checked={ restock }
								onChange={ setRestock }
							/>
						) }
						<RadioControl
							label={ __(
								'How the money goes back',
								'orillacart'
							) }
							selected={ how }
							options={ [
								state.provider !== null
									? {
											value: 'provider',
											label: sprintf(
												/* translators: %s: payment method, such as PayPal */
												__(
													'Through %s',
													'orillacart'
												),
												state.provider
											),
										}
									: {
											value: 'provider',
											label: sprintf(
												/* translators: %s: payment method */
												__(
													'I gave it back outside the shop, as %s cannot from here: only record it',
													'orillacart'
												),
												order.payment_name ||
													__(
														'this payment method',
														'orillacart'
													)
											),
										},
								...( state.provider !== null
									? [
											{
												value: 'outside',
												label: __(
													'I gave it back outside the shop: only record it',
													'orillacart'
												),
											},
										]
									: [] ),
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
						<Button
							variant="primary"
							isBusy={ busy }
							disabled={
								busy ||
								! ( Number( value ) > 0 ) ||
								Number( value ) > refundable
							}
							onClick={ () => {
								const amountShown = money(
									value,
									order.currency
								);
								let question: string = sprintf(
									/* translators: %s: amount */
									__(
										'Record that %s was given back outside the shop? No money moves from here; the order shows it refunded.',
										'orillacart'
									),
									amountShown
								);
								if ( throughProvider ) {
									question = sprintf(
										/* translators: 1: amount, 2: payment method */
										__(
											'Give %1$s back to the customer through %2$s? The money leaves at once.',
											'orillacart'
										),
										amountShown,
										state.provider ?? ''
									);
								} else if ( how === 'store_credit' ) {
									question = sprintf(
										/* translators: 1: amount, 2: the customer's email */
										__(
											'Give %1$s back as a gift card or store credit for %2$s? It can be spent at once.',
											'orillacart'
										),
										amountShown,
										order.email
									);
								}
								confirm(
									question,
									submit,
									__( 'Refund', 'orillacart' )
								);
							} }
						>
							{ throughProvider
								? sprintf(
										/* translators: 1: amount, 2: payment method */
										__(
											'Refund %1$s through %2$s',
											'orillacart'
										),
										money( value, order.currency ),
										state.provider ?? ''
									)
								: sprintf(
										/* translators: %s: amount */
										__(
											'Record a refund of %s',
											'orillacart'
										),
										money( value, order.currency )
									) }
						</Button>
					</>
				) }
			</CardBody>
		</Card>
	);
}

function RefundRow( {
	refund,
	order,
	provider,
}: {
	refund: Refund;
	order: Order;
	provider: string | null;
} ) {
	const items = refund.lines
		.map( ( { line, quantity } ) =>
			order.lines[ line ]
				? `${ order.lines[ line ].name } × ${ quantity }`
				: ''
		)
		.filter( ( text ) => text !== '' );
	if ( refund.shipping ) {
		items.push( __( 'shipping', 'orillacart' ) );
	}
	const how =
		refund.method === 'provider' && provider !== null
			? sprintf(
					/* translators: %s: payment method */
					__( 'through %s', 'orillacart' ),
					provider
				)
			: METHOD_LABELS[ refund.method ];

	return (
		<Flex align="flex-start" wrap>
			<FlexItem isBlock>
				<strong>{ money( refund.amount, refund.currency ) }</strong>{ ' ' }
				{ how }
				{ refund.status === 'pending' && (
					<strong className="orillacart-refunds__pending">
						{ ' ' }
						{ __( '— waiting for the provider', 'orillacart' ) }
					</strong>
				) }
				{ refund.status === 'failed' && (
					<strong className="orillacart-refunds__failed">
						{ ' ' }
						{ __( '— not refunded', 'orillacart' ) }
					</strong>
				) }
				{ refund.status === 'accepted' && (
					<strong className="orillacart-refunds__pending">
						{ ' ' }
						{ __(
							'— the provider is still sending the money',
							'orillacart'
						) }
					</strong>
				) }
				<br />
				<small>
					{ when( refund.created_at ) }
					{ items.length > 0 && ` · ${ items.join( ', ' ) }` }
					{ refund.restock &&
						` · ${ __( 'back in stock', 'orillacart' ) }` }
					{ refund.provider_reference !== null &&
						` · ${ refund.provider_reference }` }
				</small>
				{ refund.reason !== '' && (
					<>
						<br />
						<small>{ refund.reason }</small>
					</>
				) }
				{ refund.error !== null && refund.status !== 'done' && (
					<>
						<br />
						<small className="orillacart-refunds__failed">
							{ refund.error }
						</small>
					</>
				) }
			</FlexItem>
		</Flex>
	);
}
