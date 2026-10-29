import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Flex,
	FlexItem,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import type { Address } from '../shared/address';
import { AddressFields } from '../shared/AddressFields';
import { errorMessage } from '../shared/api';
import { orderUploadUrl } from '../shared/exports';
import { useBusy } from '../shared/useBusy';
import { useConfirm } from '../shared/useConfirm';
import { useLeaveGuard } from '../shared/useLeaveGuard';
import { differs, useUnsavedChanges } from '../shared/useUnsavedChanges';
import {
	adjustPrice,
	addNote,
	anonymiseOrder,
	changeContact,
	changeStatus,
	deleteOrder,
	editItems,
	EMAILS,
	fetchOrder,
	fetchOrderActions,
	fetchPickupPoints,
	fetchInvoices,
	fetchShippingOptions,
	type Invoice,
	markHandled,
	markPaid,
	removeAdjustment,
	restockOrder,
	type Order,
	type PickupPoint,
	type OrderAction,
	CLOSED_STATUSES,
	runOrderAction,
	sendEmail,
	type ShippingOption,
	type Status,
} from './api';
import { EMAIL_LABELS, STATUS_LABELS, money, when } from './labels';
import { AccountCard, DownloadsCard, ShipmentsCard } from './OrderCards';
import { ReturnsCard } from './ReturnsCard';
import { type ItemRow, ItemRows } from './ItemRows';
import { RefundsCard } from './RefundsCard';
import { useOtherLanguages } from '../shared/Translations';

type Message = { status: 'success' | 'error'; text: string } | null;

interface OrderDetailProps {
	id: number;
	onBack: () => void;
}

/**
 * One order: what was bought, by whom, its history, and what the shop can do with it next.
 *
 * @param props        The order to show and how to leave it.
 * @param props.id     The order number.
 * @param props.onBack Goes back to the list.
 */
/**
 * Why the shop has to look at the order, such as a payment disputed after it was paid, and a way to say it did.
 * @param props
 * @param props.order
 * @param props.onHandled
 * @param props.onError
 */
function AttentionNotice( {
	order,
	onHandled,
	onError,
}: {
	order: Order;
	onHandled: ( saved: Order ) => void;
	onError: ( error: unknown ) => void;
} ) {
	const [ busy, setBusy ] = useState( false );

	return (
		<Notice status="warning" isDismissible={ false }>
			<strong>
				{ __( 'This order needs your attention.', 'orillacart' ) }
			</strong>
			<p className="orillacart-orders__attention-reason">
				{ order.attention }
			</p>
			<Button
				variant="secondary"
				isBusy={ busy }
				disabled={ busy }
				onClick={ () => {
					setBusy( true );
					markHandled( order.number )
						.then( onHandled )
						.catch( onError )
						.finally( () => setBusy( false ) );
				} }
			>
				{ __( 'Mark as handled', 'orillacart' ) }
			</Button>
		</Notice>
	);
}

export function OrderDetail( { id, onBack }: OrderDetailProps ) {
	const [ order, setOrder ] = useState< Order | null >( null );
	const [ message, setMessage ] = useState< Message >( null );
	const [ leaving, guard, Guarded ] = useLeaveGuard();

	const fail = ( error: unknown ) =>
		setMessage( { status: 'error', text: errorMessage( error ) } );
	const done = ( text: string ) => ( saved: Order ) => {
		setOrder( saved );
		setMessage( { status: 'success', text } );
	};

	useEffect( () => {
		fetchOrder( id ).then( setOrder ).catch( fail );
	}, [ id ] );

	const back = (
		<Button variant="link" onClick={ () => guard( onBack ) }>
			{ __( '← All orders', 'orillacart' ) }
		</Button>
	);
	const notice = message !== null && (
		<Notice status={ message.status } onRemove={ () => setMessage( null ) }>
			{ message.text }
		</Notice>
	);

	if ( order === null ) {
		return (
			<>
				{ back }
				{ message !== null ? notice : <Spinner /> }
			</>
		);
	}

	return (
		<>
			{ leaving }
			<Guarded>
				{ back }
				<h1>
					{ sprintf(
						/* translators: 1: order number, 2: status */
						__( 'Order #%1$d — %2$s', 'orillacart' ),
						order.number,
						STATUS_LABELS[ order.status ]
					) }
				</h1>
				{ notice }
				{ order.attention !== null && (
					<AttentionNotice
						order={ order }
						onHandled={ done(
							__(
								'The order is marked as handled.',
								'orillacart'
							)
						) }
						onError={ fail }
					/>
				) }
				<Summary order={ order } />
				<InvoicesList order={ order } />
				{ order.status === 'on_hold' && (
					<ItemsCard
						// Seeded from the order: a fresh one, from another card or a refund, seeds it again.
						key={ JSON.stringify( [
							order.lines,
							order.shipping_option,
							order.pickup_point,
						] ) }
						order={ order }
						onSaved={ done(
							__( 'The items were changed.', 'orillacart' )
						) }
						onError={ fail }
					/>
				) }
				{ order.status === 'on_hold' && (
					<PriceChangesCard
						order={ order }
						onSaved={ done(
							__( 'The price was changed.', 'orillacart' )
						) }
						onError={ fail }
					/>
				) }
				{ order.paid_at === null &&
					! CLOSED_STATUSES.includes( order.status ) && (
						<PaymentCard
							order={ order }
							onSaved={ done(
								__(
									'The order is marked as paid.',
									'orillacart'
								)
							) }
							onError={ fail }
						/>
					) }
				{ order.next_statuses.length > 0 && (
					<StatusCard
						order={ order }
						onSaved={ done(
							__( 'The status was changed.', 'orillacart' )
						) }
						onError={ fail }
					/>
				) }
				{ order.lines.some( ( line ) => line.restockable > 0 ) && (
					<RestockCard
						order={ order }
						onSaved={ done(
							__( 'The items went back in stock.', 'orillacart' )
						) }
						onError={ fail }
					/>
				) }
				<ReturnsCard
					order={ order }
					onChanged={ () =>
						fetchOrder( order.number )
							.then( setOrder )
							.catch( fail )
					}
					onError={ fail }
				/>
				<RefundsCard
					// What was refunded changes when a return is refunded: the card loads its refunds again.
					key={ order.refunded_total }
					order={ order }
					onRefunded={ ( text ) =>
						fetchOrder( order.number )
							.then( done( text ) )
							.catch( fail )
					}
					onError={ fail }
				/>
				{ order.shipping !== null && (
					<ShipmentsCard
						order={ order }
						onError={ fail }
						onChanged={ () =>
							fetchOrder( order.number )
								.then( setOrder )
								.catch( fail )
						}
					/>
				) }
				<ActionsCard
					order={ order }
					onRan={ ( result, label ) =>
						done( result.message || label )( result.order )
					}
					onError={ fail }
				/>
				<NotesCard
					order={ order }
					onSaved={ done(
						__( 'The note was added.', 'orillacart' )
					) }
					onError={ fail }
				/>
				<ContactCard
					key={ JSON.stringify( [
						order.email,
						order.billing,
						order.shipping_address,
						order.fields,
					] ) }
					order={ order }
					onSaved={ done(
						__( 'The customer’s details were saved.', 'orillacart' )
					) }
					onError={ fail }
				/>
				<AccountCard
					order={ order }
					onSaved={ done(
						__( 'The order’s account was changed.', 'orillacart' )
					) }
					onError={ fail }
				/>
				{ order.downloads.length > 0 && (
					<DownloadsCard
						order={ order }
						onSaved={ done(
							__( 'The downloads were changed.', 'orillacart' )
						) }
						onError={ fail }
					/>
				) }
				<EmailCard
					order={ order }
					onSent={ ( sent ) =>
						setMessage(
							sent
								? {
										status: 'success',
										text: __(
											'The email was sent.',
											'orillacart'
										),
									}
								: {
										status: 'error',
										text: __(
											'The email could not be sent; see the site’s error log.',
											'orillacart'
										),
									}
						)
					}
					onError={ fail }
				/>
				<Flex justify="flex-start">
					{ ! order.anonymised && (
						<AnonymiseButton
							order={ order }
							onSaved={ done(
								__(
									'The customer’s details were erased from the order.',
									'orillacart'
								)
							) }
							onError={ fail }
						/>
					) }
					{ order.paid_at === null &&
						[ 'cancelled', 'failed' ].includes( order.status ) && (
							<DeleteButton
								order={ order }
								onDeleted={ onBack }
								onError={ fail }
							/>
						) }
				</Flex>
			</Guarded>
		</>
	);
}

/**
 * Erases the customer's details from the order, as a privacy request asks, keeping its sums for the books.
 * @param props
 * @param props.order
 * @param props.onSaved
 * @param props.onError
 */
function AnonymiseButton( { order, onSaved, onError }: CardProps ) {
	const [ busy, run ] = useBusy();
	const [ confirmation, confirm ] = useConfirm();

	return (
		<>
			{ confirmation }
			<Button
				variant="secondary"
				isBusy={ busy }
				disabled={ busy }
				onClick={ () =>
					confirm(
						__(
							'The customer’s name, email, addresses, phone, note and downloads go from this order for good. What was bought and paid stays, for the books.',
							'orillacart'
						),
						() =>
							run( () =>
								anonymiseOrder( order.number )
									.then( onSaved )
									.catch( onError )
							),
						__( 'Erase', 'orillacart' )
					)
				}
			>
				{ __( 'Erase the customer’s details', 'orillacart' ) }
			</Button>
		</>
	);
}

function DeleteButton( {
	order,
	onDeleted,
	onError,
}: {
	order: Order;
	onDeleted: () => void;
	onError: ( error: unknown ) => void;
} ) {
	const [ confirming, setConfirming ] = useState( false );
	const [ busy, run ] = useBusy();

	if ( ! confirming ) {
		return (
			<Button
				variant="secondary"
				isDestructive
				onClick={ () => setConfirming( true ) }
			>
				{ __( 'Delete this order for good', 'orillacart' ) }
			</Button>
		);
	}

	return (
		<Notice status="warning" isDismissible={ false }>
			<p>
				{ __( 'The order and its history go for good.', 'orillacart' ) }
			</p>
			<Button
				variant="primary"
				isDestructive
				isBusy={ busy }
				disabled={ busy }
				onClick={ () =>
					run( () =>
						deleteOrder( order.number )
							.then( onDeleted )
							.catch( onError )
					)
				}
			>
				{ __( 'Delete', 'orillacart' ) }
			</Button>
			<Button variant="tertiary" onClick={ () => setConfirming( false ) }>
				{ __( 'Keep it', 'orillacart' ) }
			</Button>
		</Notice>
	);
}

function Summary( { order }: { order: Order } ) {
	return (
		<Card className="orillacart-card">
			<CardBody>
				<table className="widefat striped">
					<thead>
						<tr>
							<th>{ __( 'Item', 'orillacart' ) }</th>
							<th>{ __( 'Quantity', 'orillacart' ) }</th>
							<th>{ __( 'Price', 'orillacart' ) }</th>
							<th>{ __( 'Total', 'orillacart' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ order.lines.map( ( line, index ) => (
							<tr key={ index }>
								<td>
									{ line.name }
									{ line.options.map( ( option ) => (
										<div key={ option.attribute }>
											<small>
												{ option.attribute }:{ ' ' }
												{ option.option }
											</small>
										</div>
									) ) }
									{ ( line.personalisation ?? [] )
										.filter(
											( answer ) => answer.answer !== ''
										)
										.map( ( answer ) => (
											<div key={ answer.id }>
												<small>
													{ answer.label }:{ ' ' }
													{ answer.upload_id !==
													null ? (
														<a
															href={ orderUploadUrl(
																order.number,
																answer.upload_id
															) }
															download
														>
															{ answer.answer }
														</a>
													) : (
														answer.answer
													) }
												</small>
											</div>
										) ) }
									{ line.sku !== null && (
										<div>
											<small>SKU { line.sku }</small>
										</div>
									) }
									{ ( line.adjustments ?? [] )
										.filter(
											( adjustment ) =>
												adjustment.kind === 'manual' ||
												adjustment.kind === 'add_on' ||
												adjustment.kind === 'adjuster'
										)
										.map( ( adjustment, place ) => (
											<div key={ place }>
												<small>
													{ adjustment.label }:{ ' ' }
													{ money(
														adjustment.amount,
														order.currency
													) }
													{ adjustment.reason !==
														null &&
														` (${ adjustment.reason })` }
												</small>
											</div>
										) ) }
									{ ( line.contents ?? [] ).length > 0 && (
										<div>
											<small>
												{ __( 'Holds', 'orillacart' ) }:{ ' ' }
												{ ( line.contents ?? [] )
													.map(
														( item ) =>
															`${ item.quantity } × ${ item.name }`
													)
													.join( ', ' ) }
											</small>
										</div>
									) }
									{ line.backordered > 0 && (
										<div className="orillacart-backorder">
											<small>
												{ sprintf(
													/* translators: %d: how many of the item */
													__(
														'%d on backorder',
														'orillacart'
													),
													line.backordered
												) }
											</small>
										</div>
									) }
								</td>
								<td>{ line.quantity }</td>
								<td>
									{ money( line.unit_price, order.currency ) }
								</td>
								<td>{ money( line.total, order.currency ) }</td>
							</tr>
						) ) }
					</tbody>
				</table>
				<dl className="orillacart-totals">
					<dt>{ __( 'Subtotal', 'orillacart' ) }</dt>
					<dd>{ money( order.subtotal, order.currency ) }</dd>
					{ order.discounts.map( ( discount ) => (
						<FragmentRow
							key={ discount.code }
							label={
								discount.automatic
									? discount.description
									: sprintf(
											/* translators: 1: coupon code, 2: its description */
											__(
												'Coupon %1$s %2$s',
												'orillacart'
											),
											discount.code,
											discount.description
										).trim()
							}
							value={
								discount.free_shipping
									? __( 'Free shipping', 'orillacart' )
									: `−${ money( discount.amount, order.currency ) }`
							}
						/>
					) ) }
					{ order.shipping !== null && (
						<>
							<dt>
								{ order.shipping }
								{ order.pickup_point !== null && (
									<>
										<br />
										<small>{ order.pickup_point }</small>
									</>
								) }
							</dt>
							<dd>
								{ money(
									order.shipping_total,
									order.currency
								) }
							</dd>
						</>
					) }
					{ order.fees.map( ( fee ) => (
						<FragmentRow
							key={ fee.label }
							label={ fee.label }
							value={ money( fee.amount, order.currency ) }
						/>
					) ) }
					{ ! order.prices_include_tax &&
						order.taxes.map( ( tax ) => (
							<FragmentRow
								key={ tax.name + tax.percent }
								label={ `${ tax.name } (${ tax.percent } %)` }
								value={ money( tax.amount, order.currency ) }
							/>
						) ) }
					<dt>
						<strong>{ __( 'Total', 'orillacart' ) }</strong>
					</dt>
					<dd>
						<strong>
							{ money( order.total, order.currency ) }
						</strong>
					</dd>
					{ order.prices_include_tax &&
						order.taxes.map( ( tax ) => (
							<FragmentRow
								key={ tax.name + tax.percent }
								label={ sprintf(
									/* translators: 1: tax name, 2: its rate */
									__(
										'Includes %1$s (%2$s %%)',
										'orillacart'
									),
									tax.name,
									tax.percent
								) }
								value={ money( tax.amount, order.currency ) }
							/>
						) ) }
					{ ( order.gift_cards ?? [] ).map( ( card, index ) => (
						<FragmentRow
							key={ `${ card.label }-${ index }` }
							label={ card.label }
							value={ `−${ money( card.amount, order.currency ) }` }
						/>
					) ) }
					{ ( order.gift_cards ?? [] ).length > 0 && (
						<FragmentRow
							label={ __( 'Paid the way chosen', 'orillacart' ) }
							value={ money(
								order.amount_due ?? order.total,
								order.currency
							) }
						/>
					) }
				</dl>
				{ order.tax_exemption && (
					<p>
						{ sprintf(
							/* translators: 1: why the customer buys without tax, 2: their certificate's number */
							__(
								'Sold without tax: %1$s, certificate %2$s.',
								'orillacart'
							),
							order.tax_exemption.reason,
							order.tax_exemption.certificate
						) }
					</p>
				) }
				<TaxByJurisdiction order={ order } />
				<p>
					{ sprintf(
						/* translators: 1: date, 2: payment method */
						__( 'Placed %1$s, paying by %2$s.', 'orillacart' ),
						when( order.placed_at ),
						order.payment_name === ''
							? __( 'nothing (free)', 'orillacart' )
							: order.payment_name
					) }
					{ order.transaction_id !== null &&
						` ${ __( 'Transaction', 'orillacart' ) } ${ order.transaction_id }.` }{ ' ' }
					{ order.paid_at === null
						? __( 'Not paid yet.', 'orillacart' )
						: sprintf(
								/* translators: %s: date */
								__( 'Paid %s.', 'orillacart' ),
								when( order.paid_at )
							) }
				</p>
				<OrderLanguage locale={ order.locale ?? null } />
				{ order.delivery_date !== null && (
					<p>
						{ sprintf(
							/* translators: 1: the day, 2: the time of day, or nothing */
							__( 'Delivery on %1$s%2$s.', 'orillacart' ),
							new Date(
								`${ order.delivery_date }T12:00:00Z`
							).toLocaleDateString( undefined, {
								weekday: 'long',
								year: 'numeric',
								month: 'long',
								day: 'numeric',
								timeZone: 'UTC',
							} ),
							order.delivery_slot === null
								? ''
								: `, ${ order.delivery_slot }`
						) }
					</p>
				) }
				{ order.vat_number !== null && (
					<p>
						{ sprintf(
							/* translators: %s: VAT number */
							__( 'VAT number %s.', 'orillacart' ),
							order.vat_number
						) }{ ' ' }
						{ order.reverse_charge &&
							__(
								'Reverse charge: sold without VAT.',
								'orillacart'
							) }
					</p>
				) }
				{ order.anonymised && (
					<p>
						{ __(
							'The customer’s details were erased, as a privacy request asked.',
							'orillacart'
						) }
					</p>
				) }
				{ order.note !== '' && (
					<p>
						<strong>
							{ __( 'Customer’s note:', 'orillacart' ) }
						</strong>{ ' ' }
						{ order.note }
					</p>
				) }
			</CardBody>
		</Card>
	);
}

/**
 * The order's invoice and credit notes, each opening its printable page.
 * @param props
 * @param props.order
 */
function InvoicesList( { order }: { order: Order } ) {
	const [ invoices, setInvoices ] = useState< Invoice[] >( [] );

	useEffect( () => {
		let current = true;
		fetchInvoices( order.number )
			.then(
				( loaded ) =>
					current &&
					setInvoices( Array.isArray( loaded ) ? loaded : [] )
			)
			.catch( () => current && setInvoices( [] ) );
		return () => {
			current = false;
		};
	}, [ order.number, order.paid_at, order.refunded_total ] );

	if ( invoices.length === 0 ) {
		return null;
	}

	return (
		<p className="orillacart-orders__invoices">
			{ invoices.map( ( invoice, index ) => (
				<span key={ invoice.number }>
					{ index > 0 && ' · ' }
					<a href={ invoice.url } target="_blank" rel="noreferrer">
						{ invoice.kind === 'credit_note'
							? sprintf(
									/* translators: %s: the invoice or credit note number */
									__( 'Credit note %s', 'orillacart' ),
									invoice.number
								)
							: sprintf(
									/* translators: %s: the invoice or credit note number */
									__( 'Invoice %s', 'orillacart' ),
									invoice.number
								) }
					</a>
					{ invoice.xml_url !== null && (
						<>
							{ ' ' }
							<a href={ invoice.xml_url }>
								{ __( '(XML)', 'orillacart' ) }
							</a>
						</>
					) }
				</span>
			) ) }
		</p>
	);
}

/**
 * The order's tax by who it is owed to, as returns are filed: each jurisdiction's taxable amount, tax and what
 * refunds gave back, over the items, the shipping and the fees.
 * @param props
 * @param props.order
 */
function TaxByJurisdiction( { order }: { order: Order } ) {
	const rows = new Map<
		string,
		{
			name: string;
			level: string;
			taxable: number;
			amount: number;
			refunded: number;
		}
	>();
	for ( const line of [ ...order.lines, ...( order.charges ?? [] ) ] ) {
		for ( const tax of line.taxes ?? [] ) {
			const key = `${ tax.code }|${ tax.percent }`;
			const row = rows.get( key ) ?? {
				name: `${ tax.jurisdiction } (${ tax.percent } %)`,
				level: tax.level,
				taxable: 0,
				amount: 0,
				refunded: 0,
			};
			row.taxable += Number( tax.taxable );
			row.amount += Number( tax.amount );
			row.refunded += Number( tax.refunded );
			rows.set( key, row );
		}
	}
	if ( rows.size === 0 ) {
		return null;
	}
	const LEVELS: Record< string, string > = {
		country: __( 'Country', 'orillacart' ),
		region: __( 'State', 'orillacart' ),
		local: __( 'Local', 'orillacart' ),
	};
	const amount = ( value: number ) =>
		money( value.toFixed( 4 ), order.currency );
	return (
		<details className="orillacart-tax-jurisdictions">
			<summary>{ __( 'Tax by jurisdiction', 'orillacart' ) }</summary>
			<table className="widefat striped">
				<thead>
					<tr>
						<th>{ __( 'Jurisdiction', 'orillacart' ) }</th>
						<th>{ __( 'Code', 'orillacart' ) }</th>
						<th>{ __( 'Taxable', 'orillacart' ) }</th>
						<th>{ __( 'Tax', 'orillacart' ) }</th>
						<th>{ __( 'Refunded', 'orillacart' ) }</th>
					</tr>
				</thead>
				<tbody>
					{ [ ...rows.entries() ].map( ( [ key, row ] ) => (
						<tr key={ key }>
							<td>
								{ row.name }
								<br />
								<small>
									{ LEVELS[ row.level ] ?? row.level }
								</small>
							</td>
							<td>{ key.split( '|' )[ 0 ] }</td>
							<td>{ amount( row.taxable ) }</td>
							<td>{ amount( row.amount ) }</td>
							<td>{ amount( row.refunded ) }</td>
						</tr>
					) ) }
				</tbody>
			</table>
		</details>
	);
}

function FragmentRow( { label, value }: { label: string; value: string } ) {
	return (
		<>
			<dt>{ label }</dt>
			<dd>{ value }</dd>
		</>
	);
}

interface CardProps {
	order: Order;
	onSaved: ( order: Order ) => void;
	onError: ( error: unknown ) => void;
}

function StatusCard( { order, onSaved, onError }: CardProps ) {
	const [ status, setStatus ] = useState< Status >(
		order.next_statuses[ 0 ] ?? order.status
	);
	const [ note, setNote ] = useState( '' );
	const [ busy, run ] = useBusy();

	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>{ __( 'Status', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				<SelectControl
					__next40pxDefaultSize
					label={ __( 'Move the order to', 'orillacart' ) }
					value={ status }
					options={ order.next_statuses.map( ( next ) => ( {
						value: next,
						label: STATUS_LABELS[ next ],
					} ) ) }
					onChange={ ( value ) => setStatus( value as Status ) }
				/>
				{ status === 'cancelled' && (
					<p>
						{ __(
							'Cancelling puts the items back in stock.',
							'orillacart'
						) }
					</p>
				) }
				{ status === 'completed' && order.paid_at === null && (
					<p>
						{ __(
							'Completing it records the payment, as when the courier hands over the cash.',
							'orillacart'
						) }
					</p>
				) }
				<TextControl
					__next40pxDefaultSize
					label={ __(
						'Note for the history (optional)',
						'orillacart'
					) }
					value={ note }
					onChange={ setNote }
				/>
				<Button
					variant="primary"
					isBusy={ busy }
					disabled={ busy }
					onClick={ () =>
						run( () =>
							changeStatus( order.number, status, note )
								.then( ( saved ) => {
									setNote( '' );
									setStatus(
										saved.next_statuses[ 0 ] ?? saved.status
									);
									onSaved( saved );
								} )
								.catch( onError )
						)
					}
				>
					{ __( 'Change status', 'orillacart' ) }
				</Button>
			</CardBody>
		</Card>
	);
}

/**
 * The shop's and add-ons' changes to the price of an order on hold, and a form to add one, for the whole order
 * (spread over its items) or one item: taxes are worked out again.
 * @param props
 * @param props.order
 * @param props.onSaved
 * @param props.onError
 */
function PriceChangesCard( { order, onSaved, onError }: CardProps ) {
	const [ busy, run ] = useBusy();
	const [ confirmation, confirm ] = useConfirm();
	const [ amount, setAmount ] = useState( '' );
	const [ label, setLabel ] = useState( '' );
	const [ line, setLine ] = useState( '' );
	const [ reason, setReason ] = useState( '' );
	const valid =
		/^-?\d{1,11}(\.\d{1,4})?$/.test( amount.trim() ) &&
		Number( amount ) !== 0 &&
		label.trim() !== '';

	return (
		<Card className="orillacart-card">
			{ confirmation }
			<CardHeader>
				<h2>{ __( 'Price changes', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				{ ( order.price_changes ?? [] ).map( ( change ) => (
					<Flex key={ change.change } justify="space-between">
						<FlexItem>
							{ change.label }:{ ' ' }
							{ money( change.amount, order.currency ) }{ ' ' }
							<small>
								{ change.line === null
									? __( 'whole order', 'orillacart' )
									: order.lines[ change.line ]?.name }
								{ change.kind !== 'manual' &&
									` · ${ change.source }` }
								{ change.reason !== null &&
									change.reason !== '' &&
									` · ${ change.reason }` }
							</small>
						</FlexItem>
						<FlexItem>
							{ change.removable ? (
								<Button
									variant="tertiary"
									isDestructive
									disabled={ busy }
									onClick={ () =>
										confirm(
											sprintf(
												/* translators: %s: the change's label */
												__(
													'Take "%s" off the price? The taxes are worked out again.',
													'orillacart'
												),
												change.label
											),
											() =>
												run( () =>
													removeAdjustment(
														order.number,
														change.change
													)
														.then( onSaved )
														.catch( onError )
												),
											__( 'Take off', 'orillacart' )
										)
									}
								>
									{ __( 'Take off', 'orillacart' ) }
								</Button>
							) : (
								<small>
									{ __(
										'Worked out by the add-on',
										'orillacart'
									) }
								</small>
							) }
						</FlexItem>
					</Flex>
				) ) }
				<p>
					{ __(
						'Lower or raise the price before the order is paid: below zero lowers it. The taxes follow.',
						'orillacart'
					) }
				</p>
				<TextControl
					__next40pxDefaultSize
					label={ __( 'Amount', 'orillacart' ) }
					help={ __( 'Such as -5.00 or 2.50.', 'orillacart' ) }
					value={ amount }
					onChange={ setAmount }
				/>
				<TextControl
					__next40pxDefaultSize
					label={ __( 'What the customer sees', 'orillacart' ) }
					value={ label }
					maxLength={ 100 }
					onChange={ setLabel }
				/>
				<SelectControl
					__next40pxDefaultSize
					label={ __( 'On', 'orillacart' ) }
					value={ line }
					options={ [
						{
							label: __(
								'The whole order, spread over its items',
								'orillacart'
							),
							value: '',
						},
						...order.lines.map( ( orderLine, index ) => ( {
							label: `${ orderLine.quantity } × ${ orderLine.name }`,
							value: String( index ),
						} ) ),
					] }
					onChange={ setLine }
				/>
				<TextControl
					__next40pxDefaultSize
					label={ __( 'Why (for the shop’s records)', 'orillacart' ) }
					value={ reason }
					maxLength={ 200 }
					onChange={ setReason }
				/>
				<Button
					variant="secondary"
					isBusy={ busy }
					disabled={ busy || ! valid }
					onClick={ () =>
						run( () =>
							adjustPrice( order.number, {
								amount: amount.trim(),
								label: label.trim(),
								line: line === '' ? null : Number( line ),
								reason: reason.trim(),
							} )
								.then( ( saved ) => {
									setAmount( '' );
									setLabel( '' );
									setReason( '' );
									setLine( '' );
									onSaved( saved );
								} )
								.catch( onError )
						)
					}
				>
					{ __( 'Change the price', 'orillacart' ) }
				</Button>
			</CardBody>
		</Card>
	);
}

/**
 * The shop says the money came, apart from moving the order along: a bank transfer that arrived, or the cash of
 * an order paid on delivery.
 * @param props
 * @param props.order
 * @param props.onSaved
 * @param props.onError
 */
function PaymentCard( { order, onSaved, onError }: CardProps ) {
	const [ note, setNote ] = useState( '' );
	const [ busy, setBusy ] = useState( false );

	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>{ __( 'Payment', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ [ 'pending_payment', 'on_hold' ].includes( order.status )
						? __(
								'The order waits for its payment. Once the money came, mark it as paid: it is then fulfilled.',
								'orillacart'
							)
						: __(
								'The order is being fulfilled before its payment, as for cash on delivery.',
								'orillacart'
							) }
				</p>
				<TextControl
					__next40pxDefaultSize
					label={ __(
						'How the money came (optional)',
						'orillacart'
					) }
					value={ note }
					onChange={ setNote }
				/>
				<Button
					variant="secondary"
					isBusy={ busy }
					disabled={ busy }
					onClick={ () => {
						setBusy( true );
						markPaid( order.number, note )
							.then( ( saved ) => {
								setNote( '' );
								onSaved( saved );
							} )
							.catch( onError )
							.finally( () => setBusy( false ) );
					} }
				>
					{ __( 'Mark as paid', 'orillacart' ) }
				</Button>
			</CardBody>
		</Card>
	);
}

/**
 * Items of an order refunded by amount, which kept their stock: once they come back, they go back in stock.
 * @param props
 * @param props.order
 * @param props.onSaved
 * @param props.onError
 */
function RestockCard( { order, onSaved, onError }: CardProps ) {
	const [ busy, run ] = useBusy();
	const [ quantities, setQuantities ] = useState< Record< number, string > >(
		{}
	);
	const lines = order.lines
		.map( ( line, index ) => ( { line, index } ) )
		.filter( ( { line } ) => line.restockable > 0 );
	const chosen = lines
		.map( ( { index } ) => ( {
			line: index,
			quantity: Number( quantities[ index ] ?? '0' ),
		} ) )
		.filter(
			( item ) => Number.isInteger( item.quantity ) && item.quantity > 0
		);

	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>{ __( 'Items that came back', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'The money went back without these items. Once they come back, put them back in stock.',
						'orillacart'
					) }
				</p>
				{ lines.map( ( { line, index } ) => (
					<TextControl
						key={ index }
						__next40pxDefaultSize
						type="number"
						min={ 0 }
						max={ line.restockable }
						label={ sprintf(
							/* translators: 1: item name, 2: how many can go back */
							__( '%1$s (up to %2$d)', 'orillacart' ),
							line.name,
							line.restockable
						) }
						value={ quantities[ index ] ?? '' }
						onChange={ ( value ) =>
							setQuantities( ( current ) => ( {
								...current,
								[ index ]: value,
							} ) )
						}
					/>
				) ) }
				<Button
					variant="secondary"
					isBusy={ busy }
					disabled={ busy || chosen.length === 0 }
					onClick={ () =>
						run( () =>
							restockOrder( order.number, chosen )
								.then( ( saved ) => {
									setQuantities( {} );
									onSaved( saved );
								} )
								.catch( onError )
						)
					}
				>
					{ __( 'Put back in stock', 'orillacart' ) }
				</Button>
			</CardBody>
		</Card>
	);
}

function NotesCard( { order, onSaved, onError }: CardProps ) {
	const [ busy, run ] = useBusy();
	const [ text, setText ] = useState( '' );
	const [ forCustomer, setForCustomer ] = useState( false );
	useUnsavedChanges( text.trim() !== '' );

	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>{ __( 'History', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				<ul>
					{ order.notes.map( ( note, index ) => (
						<li key={ index }>
							<small>{ when( note.created_at ) }</small>
							{ note.for_customer && (
								<small>
									{ ' ' }
									· { __( 'to the customer', 'orillacart' ) }
								</small>
							) }
							<div>{ note.text }</div>
						</li>
					) ) }
				</ul>
				<TextareaControl
					label={ __( 'Add a note', 'orillacart' ) }
					value={ text }
					onChange={ setText }
				/>
				<CheckboxControl
					label={ __( 'Email it to the customer', 'orillacart' ) }
					checked={ forCustomer }
					onChange={ setForCustomer }
				/>
				<Button
					variant="secondary"
					isBusy={ busy }
					disabled={ busy || text.trim() === '' }
					onClick={ () =>
						run( () =>
							addNote( order.number, text, forCustomer )
								.then( ( saved ) => {
									setText( '' );
									setForCustomer( false );
									onSaved( saved );
								} )
								.catch( onError )
						)
					}
				>
					{ __( 'Add note', 'orillacart' ) }
				</Button>
			</CardBody>
		</Card>
	);
}

function ItemsCard( { order, onSaved, onError }: CardProps ) {
	const [ busy, run ] = useBusy();
	const [ rows, setRows ] = useState< ItemRow[] >(
		order.lines.map( ( line, index ) => ( {
			item: {
				product_id: line.product_id,
				option_ids: line.option_ids,
				bundle_choices: line.bundle_choices ?? [],
				quantity: line.quantity,
				unit_price: line.unit_price,
				line: index,
			},
			name: line.name,
		} ) )
	);
	const items = rows.map( ( row ) => row.item );
	const [ shippingOption, setShippingOption ] = useState(
		order.shipping_option
	);
	const [ rates, setRates ] = useState< ShippingOption[] >( [] );
	const [ points, setPoints ] = useState< PickupPoint[] >( [] );
	const [ pickupPoint, setPickupPoint ] = useState(
		order.pickup_point_id ?? ''
	);
	// The card opens anew with each saved change (its key), so what it opened with is what is saved.
	const [ opened ] = useState( () => [ rows, shippingOption, pickupPoint ] );
	useUnsavedChanges(
		differs( [ rows, shippingOption, pickupPoint ], opened )
	);
	const toPickupPoint =
		rates.find( ( rate ) => rate.id === shippingOption )
			?.to_pickup_point === true;
	useEffect( () => {
		if ( toPickupPoint && shippingOption !== null ) {
			fetchPickupPoints( order.number, shippingOption )
				.then( setPoints )
				.catch( () => setPoints( [] ) );
		} else {
			setPoints( [] );
		}
	}, [ order.number, shippingOption, toPickupPoint ] );
	useEffect( () => {
		if ( order.shipping_option !== null ) {
			fetchShippingOptions( order.number )
				.then( setRates )
				.catch( () => setRates( [] ) );
		}
	}, [ order.number, order.shipping_option ] );

	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>{ __( 'Items', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'The order waits for its payment, so its items can still change. Taxes, shipping and stock follow; the customer is not emailed — send the "order received" email again if they should know.',
						'orillacart'
					) }
				</p>
				<ItemRows rows={ rows } onChange={ setRows } />
				{ rates.length > 0 && (
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Ships by', 'orillacart' ) }
						value={ shippingOption ?? '' }
						options={ rates.map( ( rate ) => ( {
							label: `${ rate.carrier }: ${ rate.name } (${ money( rate.total, order.currency ) })`,
							value: rate.id,
						} ) ) }
						onChange={ setShippingOption }
					/>
				) }
				{ points.length > 0 && (
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'Pickup point', 'orillacart' ) }
						value={ pickupPoint }
						options={ [
							{
								label: __( '— Choose —', 'orillacart' ),
								value: '',
							},
							...points.map( ( point ) => ( {
								label: `${ point.name }, ${ point.address }`,
								value: point.id,
							} ) ),
						] }
						onChange={ setPickupPoint }
					/>
				) }
				<Button
					variant="primary"
					isBusy={ busy }
					disabled={ busy || items.length === 0 }
					onClick={ () =>
						run( () =>
							editItems(
								order.number,
								items,
								shippingOption,
								toPickupPoint && pickupPoint !== ''
									? pickupPoint
									: null
							)
								.then( onSaved )
								.catch( onError )
						)
					}
				>
					{ __( 'Save the items', 'orillacart' ) }
				</Button>
			</CardBody>
		</Card>
	);
}

function ContactCard( { order, onSaved, onError }: CardProps ) {
	const [ busy, run ] = useBusy();
	const [ email, setEmail ] = useState( order.email );
	const [ billing, setBilling ] = useState< Address >( order.billing );
	const [ shipping, setShipping ] = useState< Address | null >(
		order.shipping_address
	);
	const [ answers, setAnswers ] = useState< Record< string, string > >(
		Object.fromEntries(
			order.fields.map( ( field ) => [ field.id, field.answer ] )
		)
	);
	const [ opened ] = useState( () => [ email, billing, shipping, answers ] );
	useUnsavedChanges(
		differs( [ email, billing, shipping, answers ], opened )
	);

	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>{ __( 'Customer', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				<TextControl
					__next40pxDefaultSize
					type="email"
					label={ __( 'Email', 'orillacart' ) }
					value={ email }
					onChange={ setEmail }
				/>
				<h3>{ __( 'Billing address', 'orillacart' ) }</h3>
				<AddressFields address={ billing } onChange={ setBilling } />
				{ shipping !== null && (
					<>
						<h3>{ __( 'Shipping address', 'orillacart' ) }</h3>
						<AddressFields
							address={ shipping }
							onChange={ setShipping }
						/>
					</>
				) }
				{ order.fields.length > 0 && (
					<>
						<h3>{ __( 'Other details', 'orillacart' ) }</h3>
						{ order.fields.map( ( field ) => (
							<TextControl
								key={ field.id }
								__next40pxDefaultSize
								label={ field.label }
								value={ answers[ field.id ] ?? '' }
								onChange={ ( value ) =>
									setAnswers( ( current ) => ( {
										...current,
										[ field.id ]: value,
									} ) )
								}
							/>
						) ) }
					</>
				) }
				<Button
					variant="secondary"
					isBusy={ busy }
					disabled={ busy }
					onClick={ () =>
						run( () =>
							changeContact(
								order.number,
								email,
								billing,
								shipping,
								answers
							)
								.then( onSaved )
								.catch( onError )
						)
					}
				>
					{ __( 'Save the customer’s details', 'orillacart' ) }
				</Button>
			</CardBody>
		</Card>
	);
}

/**
 * The buttons add-ons put on the order screen, such as sending it to an accounting system. Hidden when no add-on
 * offers one for this order.
 *
 * @param props         The order and what to do with an action's result.
 * @param props.order   The order.
 * @param props.onRan   Receives the action's answer and the order with it in its history.
 * @param props.onError Receives a failed call.
 */
function ActionsCard( {
	order,
	onRan,
	onError,
}: {
	order: Order;
	onRan: ( result: { message: string; order: Order }, label: string ) => void;
	onError: ( error: unknown ) => void;
} ) {
	const [ actions, setActions ] = useState< OrderAction[] >( [] );
	const [ running, setRunning ] = useState< string | null >( null );

	useEffect( () => {
		fetchOrderActions( order.number )
			.then( setActions )
			.catch( () => setActions( [] ) );
	}, [ order.number, order.status ] );

	if ( actions.length === 0 ) {
		return null;
	}

	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>{ __( 'Actions', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				<Flex justify="flex-start" wrap>
					{ actions.map( ( action ) => (
						<FlexItem key={ action.id }>
							<Button
								variant="secondary"
								isBusy={ running === action.id }
								disabled={ running !== null }
								onClick={ () => {
									setRunning( action.id );
									runOrderAction( order.number, action.id )
										.then( ( result ) =>
											onRan( result, action.label )
										)
										.catch( onError )
										.finally( () => setRunning( null ) );
								} }
							>
								{ action.label }
							</Button>
						</FlexItem>
					) ) }
				</Flex>
			</CardBody>
		</Card>
	);
}

function EmailCard( {
	order,
	onSent,
	onError,
}: {
	order: Order;
	onSent: ( sent: boolean ) => void;
	onError: ( error: unknown ) => void;
} ) {
	const [ kind, setKind ] = useState< ( typeof EMAILS )[ number ] >(
		EMAILS[ 0 ]
	);
	const [ busy, run ] = useBusy();

	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>{ __( 'Emails', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				<Flex align="flex-end">
					<FlexItem isBlock>
						<SelectControl
							__next40pxDefaultSize
							label={ __( 'Send again', 'orillacart' ) }
							value={ kind }
							options={ EMAILS.map( ( value ) => ( {
								value,
								label: EMAIL_LABELS[ value ] ?? value,
							} ) ) }
							onChange={ setKind }
						/>
					</FlexItem>
					<FlexItem>
						<Button
							variant="secondary"
							isBusy={ busy }
							disabled={ busy }
							onClick={ () =>
								run( () =>
									sendEmail( order.number, kind )
										.then( ( result ) =>
											onSent( result.sent )
										)
										.catch( onError )
								)
							}
						>
							{ __( 'Send', 'orillacart' ) }
						</Button>
					</FlexItem>
				</Flex>
			</CardBody>
		</Card>
	);
}

/**
 * Which of the site's other languages the customer ordered in: the shop reads the order in its own words, and the
 * customer's emails and invoices are in theirs. Nothing for an order in the site's language.
 *
 * @param props        The line's properties.
 * @param props.locale The order's language.
 */
function OrderLanguage( { locale }: { locale: string | null } ) {
	const language = useOtherLanguages().find(
		( other ) => other.locale === locale
	);
	if ( language === undefined ) {
		return null;
	}
	return (
		<p>
			{ sprintf(
				/* translators: %s: a language, such as "Deutsch" */
				__(
					'Ordered in %s: the customer’s emails and invoices are in it, with the names they read.',
					'orillacart'
				),
				language.name
			) }
		</p>
	);
}
