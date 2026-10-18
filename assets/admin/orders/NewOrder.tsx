import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	ComboboxControl,
	Notice,
	SelectControl,
	TextControl,
	TextareaControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { type Customer, fetchCustomers } from '../customers/api';
import { type Address, EMPTY_ADDRESS } from '../shared/address';
import { AddressFields } from '../shared/AddressFields';
import { toApiError } from '../shared/api';
import { createOrder, type OrderQuote, quoteOrder } from './api';
import { type ItemRow, ItemRows } from './ItemRows';
import { money } from './labels';

interface NewOrderProps {
	onCreated: ( id: number ) => void;
	onBack: () => void;
}

/**
 * The shop takes an order itself, as on the phone: for a customer's account or a guest, priced as checkout
 * prices it while the items and addresses are entered.
 *
 * @param props           What to do when the order is created, or when the shop goes back.
 * @param props.onCreated Receives the new order's number.
 * @param props.onBack    Goes back to the list.
 */
export function NewOrder( { onCreated, onBack }: NewOrderProps ) {
	const [ customers, setCustomers ] = useState< Customer[] >( [] );
	const [ search, setSearch ] = useState( '' );
	const [ customerId, setCustomerId ] = useState< number | null >( null );
	const [ email, setEmail ] = useState( '' );
	const [ billing, setBilling ] = useState< Address >( EMPTY_ADDRESS );
	const [ shipping, setShipping ] = useState< Address | null >( null );
	const [ rows, setRows ] = useState< ItemRow[] >( [] );
	const [ shippingOption, setShippingOption ] = useState< string | null >(
		null
	);
	const [ payment, setPayment ] = useState( '' );
	const [ paid, setPaid ] = useState( false );
	const [ emailCustomer, setEmailCustomer ] = useState( false );
	const [ note, setNote ] = useState( '' );
	const [ quote, setQuote ] = useState< OrderQuote | null >( null );
	const [ error, setError ] = useState< string | null >( null );
	const [ saving, setSaving ] = useState( false );

	useEffect( () => {
		const timer = window.setTimeout( () => {
			fetchCustomers( {
				search: search || undefined,
				page: 1,
				per_page: 20,
			} )
				.then( ( page ) => setCustomers( page.customers ) )
				.catch( () => setCustomers( [] ) );
		}, 250 );
		return () => window.clearTimeout( timer );
	}, [ search ] );

	const items = rows.map( ( row ) => row.item );
	const shipsTo = shipping ?? billing;
	const quoteKey = JSON.stringify( [
		items,
		billing,
		shipping,
		shippingOption,
	] );
	useEffect( () => {
		if ( items.length === 0 ) {
			setQuote( null );
			return undefined;
		}
		const timer = window.setTimeout( () => {
			quoteOrder( {
				items,
				billing_country: billing.country || null,
				billing_state: billing.state,
				shipping_country: shipsTo.country || null,
				shipping_state: shipsTo.state,
				shipping_postcode: shipsTo.postcode || null,
				shipping_option: shippingOption,
			} )
				.then( ( priced ) => {
					setQuote( priced );
					setError( null );
				} )
				.catch( ( reason: unknown ) =>
					setError( toApiError( reason ).message )
				);
		}, 300 );
		return () => window.clearTimeout( timer );
		// The key stands for the inputs, which are new objects on every render.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ quoteKey ] );

	const chooseCustomer = ( id: number | null ) => {
		setCustomerId( id );
		const customer = customers.find( ( found ) => found.id === id );
		if ( customer !== undefined ) {
			setEmail( customer.email );
			setBilling( customer.billing ?? EMPTY_ADDRESS );
			setShipping( customer.shipping_address );
		}
	};

	const create = () => {
		setSaving( true );
		createOrder( {
			email,
			billing,
			shipping_address: shipping,
			items,
			customer_id: customerId,
			shipping_option: quote?.needs_shipping
				? quote.shipping_option
				: null,
			payment: payment === '' ? null : payment,
			paid,
			note,
			email_customer: emailCustomer,
		} )
			.then( ( order ) => onCreated( order.number ) )
			.catch( ( reason: unknown ) => {
				setSaving( false );
				setError( toApiError( reason ).message );
			} );
	};

	return (
		<>
			<Button variant="link" onClick={ onBack }>
				{ __( '← All orders', 'orillacart' ) }
			</Button>
			<h1>{ __( 'New order', 'orillacart' ) }</h1>
			{ error !== null && (
				<Notice status="error" onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }
			<Card className="orillacart-card">
				<CardHeader>
					<h2>{ __( 'Customer', 'orillacart' ) }</h2>
				</CardHeader>
				<CardBody>
					<ComboboxControl
						__next40pxDefaultSize
						label={ __( 'Account', 'orillacart' ) }
						help={ __(
							'Empty for a guest’s order. Choosing an account fills in its email and addresses.',
							'orillacart'
						) }
						value={
							customerId === null ? null : String( customerId )
						}
						options={ customers.map( ( customer ) => ( {
							label: `${ customer.first_name } ${ customer.last_name } <${ customer.email }>`.trim(),
							value: String( customer.id ),
						} ) ) }
						onFilterValueChange={ setSearch }
						onChange={ ( value ) =>
							chooseCustomer( value ? Number( value ) : null )
						}
					/>
					<TextControl
						__next40pxDefaultSize
						type="email"
						label={ __( 'Email', 'orillacart' ) }
						value={ email }
						onChange={ setEmail }
					/>
					<h3>{ __( 'Billing address', 'orillacart' ) }</h3>
					<AddressFields
						address={ billing }
						onChange={ setBilling }
					/>
					<CheckboxControl
						label={ __(
							'Ship to a different address',
							'orillacart'
						) }
						checked={ shipping !== null }
						onChange={ ( checked ) =>
							setShipping( checked ? EMPTY_ADDRESS : null )
						}
					/>
					{ shipping !== null && (
						<>
							<h3>{ __( 'Shipping address', 'orillacart' ) }</h3>
							<AddressFields
								address={ shipping }
								onChange={ setShipping }
							/>
						</>
					) }
				</CardBody>
			</Card>
			<Card className="orillacart-card">
				<CardHeader>
					<h2>{ __( 'Items', 'orillacart' ) }</h2>
				</CardHeader>
				<CardBody>
					<ItemRows rows={ rows } onChange={ setRows } />
				</CardBody>
			</Card>
			{ quote !== null && (
				<Card className="orillacart-card">
					<CardHeader>
						<h2>{ __( 'Shipping and payment', 'orillacart' ) }</h2>
					</CardHeader>
					<CardBody>
						{ quote.needs_shipping &&
							( quote.shipping_options.length === 0 ? (
								<p>
									{ __(
										'No rate ships these items to this address.',
										'orillacart'
									) }
								</p>
							) : (
								<SelectControl
									__next40pxDefaultSize
									label={ __( 'Ships by', 'orillacart' ) }
									value={ quote.shipping_option ?? '' }
									options={ quote.shipping_options.map(
										( rate ) => ( {
											label: `${ rate.carrier }: ${ rate.name } (${ money( rate.total, quote.currency ) })`,
											value: rate.id,
										} )
									) }
									onChange={ setShippingOption }
								/>
							) ) }
						<SelectControl
							__next40pxDefaultSize
							label={ __( 'Payment method', 'orillacart' ) }
							value={ payment }
							options={ [
								{
									label: __( 'None', 'orillacart' ),
									value: '',
								},
								...quote.payment_options.map( ( option ) => ( {
									label: option.name,
									value: option.gateway,
								} ) ),
							] }
							onChange={ setPayment }
						/>
						<CheckboxControl
							label={ __( 'Already paid', 'orillacart' ) }
							help={ __(
								'A paid order starts processing; otherwise it waits on hold for the payment.',
								'orillacart'
							) }
							checked={ paid }
							onChange={ setPaid }
						/>
						<CheckboxControl
							label={ __( 'Email the customer', 'orillacart' ) }
							help={ __(
								'They get the order received or confirmed email, as after checkout. You can also send it later from the order.',
								'orillacart'
							) }
							checked={ emailCustomer }
							onChange={ setEmailCustomer }
						/>
						<TextareaControl
							label={ __(
								'Note from the customer',
								'orillacart'
							) }
							value={ note }
							onChange={ setNote }
						/>
						<p>
							{ sprintf(
								/* translators: 1: items, 2: their tax, 3: shipping with its tax, 4: total */
								__(
									'Items %1$s, their tax %2$s, shipping %3$s with its tax. Total %4$s.',
									'orillacart'
								),
								money( quote.subtotal, quote.currency ),
								money( quote.items_tax, quote.currency ),
								money(
									String(
										Number( quote.shipping ) +
											Number( quote.shipping_tax )
									),
									quote.currency
								),
								money( quote.total, quote.currency )
							) }
						</p>
						<Button
							variant="primary"
							isBusy={ saving }
							disabled={ saving || email === '' }
							onClick={ create }
						>
							{ __( 'Create the order', 'orillacart' ) }
						</Button>
					</CardBody>
				</Card>
			) }
		</>
	);
}
