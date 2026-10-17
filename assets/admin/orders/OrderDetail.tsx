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
import { toApiError } from '../shared/api';
import {
	addNote,
	changeContact,
	changeStatus,
	deleteOrder,
	editItems,
	type EditedItem,
	EMAILS,
	fetchOrder,
	type Order,
	sendEmail,
	type Status,
} from './api';
import { EMAIL_LABELS, STATUS_LABELS, money, when } from './labels';
import { ProductPicker } from './ProductPicker';

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
export function OrderDetail( { id, onBack }: OrderDetailProps ) {
	const [ order, setOrder ] = useState< Order | null >( null );
	const [ message, setMessage ] = useState< Message >( null );

	const fail = ( error: unknown ) =>
		setMessage( { status: 'error', text: toApiError( error ).message } );
	const done = ( text: string ) => ( saved: Order ) => {
		setOrder( saved );
		setMessage( { status: 'success', text } );
	};

	useEffect( () => {
		fetchOrder( id ).then( setOrder ).catch( fail );
	}, [ id ] );

	const back = (
		<Button variant="link" onClick={ onBack }>
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
			<Summary order={ order } />
			{ order.status === 'on_hold' && (
				<ItemsCard
					order={ order }
					onSaved={ done(
						__( 'The items were changed.', 'orillacart' )
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
			<NotesCard
				order={ order }
				onSaved={ done( __( 'The note was added.', 'orillacart' ) ) }
				onError={ fail }
			/>
			<ContactCard
				order={ order }
				onSaved={ done(
					__( 'The customer’s details were saved.', 'orillacart' )
				) }
				onError={ fail }
			/>
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
			{ [ 'cancelled', 'failed', 'refunded' ].includes(
				order.status
			) && (
				<Button
					variant="secondary"
					isDestructive
					onClick={ () =>
						deleteOrder( order.number ).then( onBack ).catch( fail )
					}
				>
					{ __( 'Delete this order for good', 'orillacart' ) }
				</Button>
			) }
		</>
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
									{ line.sku !== null && (
										<div>
											<small>SKU { line.sku }</small>
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
					{ order.shipping !== null && (
						<>
							<dt>{ order.shipping }</dt>
							<dd>
								{ money(
									order.shipping_total,
									order.currency
								) }
							</dd>
						</>
					) }
					{ order.taxes.map( ( tax ) => (
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
				</dl>
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
						` ${ __( 'Transaction', 'orillacart' ) } ${ order.transaction_id }.` }
				</p>
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
	const [ restock, setRestock ] = useState( false );
	const [ note, setNote ] = useState( '' );

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
				{ status === 'refunded' && (
					<CheckboxControl
						label={ __(
							'Put the items back in stock',
							'orillacart'
						) }
						checked={ restock }
						onChange={ setRestock }
					/>
				) }
				{ status === 'cancelled' && (
					<p>
						{ __(
							'Cancelling puts the items back in stock.',
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
					onClick={ () =>
						changeStatus( order.number, status, restock, note )
							.then( ( saved ) => {
								setNote( '' );
								setStatus(
									saved.next_statuses[ 0 ] ?? saved.status
								);
								onSaved( saved );
							} )
							.catch( onError )
					}
				>
					{ __( 'Change status', 'orillacart' ) }
				</Button>
			</CardBody>
		</Card>
	);
}

function NotesCard( { order, onSaved, onError }: CardProps ) {
	const [ text, setText ] = useState( '' );
	const [ forCustomer, setForCustomer ] = useState( false );

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
					disabled={ text.trim() === '' }
					onClick={ () =>
						addNote( order.number, text, forCustomer )
							.then( ( saved ) => {
								setText( '' );
								setForCustomer( false );
								onSaved( saved );
							} )
							.catch( onError )
					}
				>
					{ __( 'Add note', 'orillacart' ) }
				</Button>
			</CardBody>
		</Card>
	);
}

function ItemsCard( { order, onSaved, onError }: CardProps ) {
	// Each item with the name to show: the order's lines have one, picked products bring theirs.
	const [ rows, setRows ] = useState< { item: EditedItem; name: string }[] >(
		order.lines.map( ( line ) => ( {
			item: {
				product_id: line.product_id,
				option_ids: line.option_ids,
				quantity: line.quantity,
				unit_price: line.unit_price,
			},
			name: line.name,
		} ) )
	);
	const items = rows.map( ( row ) => row.item );
	const change = ( index: number, item: EditedItem ) =>
		setRows(
			rows.map( ( row, i ) => ( i === index ? { ...row, item } : row ) )
		);

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
				{ items.map( ( item, index ) => (
					<Flex key={ index } align="flex-end">
						<FlexItem isBlock>
							{ rows[ index ]?.name ??
								sprintf(
									/* translators: %d: product id */
									__( 'Product %d', 'orillacart' ),
									item.product_id
								) }
						</FlexItem>
						<FlexItem>
							<TextControl
								__next40pxDefaultSize
								type="number"
								min={ 1 }
								label={ __( 'Quantity', 'orillacart' ) }
								value={ String( item.quantity ) }
								onChange={ ( value ) =>
									change( index, {
										...item,
										quantity: Math.max(
											1,
											Number.parseInt( value, 10 ) || 1
										),
									} )
								}
							/>
						</FlexItem>
						<FlexItem>
							<TextControl
								__next40pxDefaultSize
								label={ __( 'Unit price', 'orillacart' ) }
								help={ __(
									'Empty for the catalog price',
									'orillacart'
								) }
								value={ item.unit_price ?? '' }
								onChange={ ( value ) =>
									change( index, {
										...item,
										unit_price: value === '' ? null : value,
									} )
								}
							/>
						</FlexItem>
						<FlexItem>
							<Button
								variant="tertiary"
								isDestructive
								onClick={ () =>
									setRows(
										rows.filter( ( _, i ) => i !== index )
									)
								}
							>
								{ __( 'Remove', 'orillacart' ) }
							</Button>
						</FlexItem>
					</Flex>
				) ) }
				<ProductPicker
					onPick={ ( picked ) =>
						setRows( [
							...rows,
							{
								item: {
									product_id: picked.product_id,
									option_ids: picked.option_ids,
									quantity: 1,
									unit_price: null,
								},
								name: picked.name,
							},
						] )
					}
				/>
				<Button
					variant="primary"
					disabled={ items.length === 0 }
					onClick={ () =>
						editItems( order.number, items, order.shipping_rate_id )
							.then( onSaved )
							.catch( onError )
					}
				>
					{ __( 'Save the items', 'orillacart' ) }
				</Button>
			</CardBody>
		</Card>
	);
}

function ContactCard( { order, onSaved, onError }: CardProps ) {
	const [ email, setEmail ] = useState( order.email );
	const [ billing, setBilling ] = useState< Address >( order.billing );
	const [ shipping, setShipping ] = useState< Address | null >(
		order.shipping_address
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
				<Button
					variant="secondary"
					onClick={ () =>
						changeContact( order.number, email, billing, shipping )
							.then( onSaved )
							.catch( onError )
					}
				>
					{ __( 'Save the customer’s details', 'orillacart' ) }
				</Button>
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
							onClick={ () =>
								sendEmail( order.number, kind )
									.then( ( result ) => onSent( result.sent ) )
									.catch( onError )
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
