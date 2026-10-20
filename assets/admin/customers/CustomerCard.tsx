import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Flex,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { type Address, EMPTY_ADDRESS } from '../shared/address';
import { AddressFields } from '../shared/AddressFields';
import { useConfirm } from '../shared/useConfirm';
import {
	type Customer,
	type CustomerGroup,
	removeAddress,
	type SavedAddress,
	saveAddress,
	setCustomerGroups,
} from './api';

interface CustomerCardProps {
	groups: CustomerGroup[];
	customer: Customer;
	onClose: () => void;
	onSaved: ( customer: Customer ) => void;
	onError: ( error: unknown ) => void;
}

/**
 * One customer: their groups and their address book.
 * @param props
 * @param props.groups
 * @param props.customer
 * @param props.onClose
 * @param props.onSaved
 * @param props.onError
 */
export function CustomerCard( {
	groups,
	customer,
	onClose,
	onSaved,
	onError,
}: CustomerCardProps ) {
	const [ member, setMember ] = useState< number[] >(
		customer.group_ids ?? []
	);

	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>
					{ sprintf(
						/* translators: 1: name, 2: email */
						__( '%1$s (%2$s)', 'orillacart' ),
						`${ customer.first_name } ${ customer.last_name }`,
						customer.email
					) }
				</h2>
				<Button variant="tertiary" onClick={ onClose }>
					{ __( 'Close', 'orillacart' ) }
				</Button>
			</CardHeader>
			<CardBody>
				{ groups.length > 0 && (
					<>
						<h3>{ __( 'Customer groups', 'orillacart' ) }</h3>
						{ groups.map( ( group ) => (
							<CheckboxControl
								key={ group.id }
								label={ group.name }
								checked={ member.includes( group.id ) }
								onChange={ ( on ) => {
									const next = on
										? [ ...member, group.id ]
										: member.filter(
												( id ) => id !== group.id
											);
									setCustomerGroups( customer.id, next )
										.then( ( saved ) =>
											setMember( saved.group_ids )
										)
										.catch( onError );
								} }
							/>
						) ) }
					</>
				) }
				<AddressBook
					customer={ customer }
					onSaved={ onSaved }
					onError={ onError }
				/>
			</CardBody>
		</Card>
	);
}

/**
 * The customer's saved addresses: checkout fills one in for billing and one for shipping, and offers the others.
 * @param props
 * @param props.customer
 * @param props.onSaved
 * @param props.onError
 */
function AddressBook( {
	customer,
	onSaved,
	onError,
}: Pick< CustomerCardProps, 'customer' | 'onSaved' | 'onError' > ) {
	// The id of the address being edited; '' for a new one.
	const [ editing, setEditing ] = useState< string | null >( null );
	const [ confirmation, confirm ] = useConfirm();
	const saved = customer.addresses ?? [];

	return (
		<>
			<h3>{ __( 'Addresses', 'orillacart' ) }</h3>
			<p>
				{ __(
					'Checkout fills these in for the customer and saves the addresses they order with.',
					'orillacart'
				) }
			</p>
			{ confirmation }
			{ saved.length === 0 && (
				<p>{ __( 'No saved addresses.', 'orillacart' ) }</p>
			) }
			{ saved.map( ( entry ) =>
				editing === entry.id ? (
					<AddressForm
						key={ entry.id }
						saved={ entry }
						onSave={ ( address, uses ) =>
							saveAddress( customer.id, entry.id, address, uses )
								.then( ( next ) => {
									setEditing( null );
									onSaved( next );
								} )
								.catch( onError )
						}
						onCancel={ () => setEditing( null ) }
					/>
				) : (
					<Flex
						key={ entry.id }
						className="orillacart-saved-address"
						justify="space-between"
					>
						<span>
							{ oneLine( entry.address ) }
							{ entry.billing &&
								` · ${ __( 'Billing', 'orillacart' ) }` }
							{ entry.shipping &&
								` · ${ __( 'Shipping', 'orillacart' ) }` }
						</span>
						<span>
							<Button
								variant="link"
								onClick={ () => setEditing( entry.id ) }
							>
								{ __( 'Edit', 'orillacart' ) }
							</Button>{ ' ' }
							<Button
								variant="link"
								isDestructive
								onClick={ () =>
									confirm(
										__(
											'Remove this address from the customer’s address book?',
											'orillacart'
										),
										() =>
											void removeAddress(
												customer.id,
												entry.id
											)
												.then( onSaved )
												.catch( onError ),
										__( 'Remove', 'orillacart' )
									)
								}
							>
								{ __( 'Remove', 'orillacart' ) }
							</Button>
						</span>
					</Flex>
				)
			) }
			{ editing === '' ? (
				<AddressForm
					saved={ null }
					onSave={ ( address, uses ) =>
						saveAddress( customer.id, null, address, uses )
							.then( ( next ) => {
								setEditing( null );
								onSaved( next );
							} )
							.catch( onError )
					}
					onCancel={ () => setEditing( null ) }
				/>
			) : (
				<Button variant="secondary" onClick={ () => setEditing( '' ) }>
					{ __( 'Add an address', 'orillacart' ) }
				</Button>
			) }
		</>
	);
}

function AddressForm( {
	saved,
	onSave,
	onCancel,
}: {
	saved: SavedAddress | null;
	onSave: (
		address: Address,
		uses: { billing: boolean; shipping: boolean }
	) => Promise< void >;
	onCancel: () => void;
} ) {
	const [ address, setAddress ] = useState< Address >(
		saved?.address ?? EMPTY_ADDRESS
	);
	const [ billing, setBilling ] = useState( saved?.billing ?? false );
	const [ shipping, setShipping ] = useState( saved?.shipping ?? false );
	const [ busy, setBusy ] = useState( false );

	return (
		<div className="orillacart-saved-address">
			<AddressFields address={ address } onChange={ setAddress } />
			<CheckboxControl
				label={ __( 'Checkout uses it for billing', 'orillacart' ) }
				checked={ billing }
				disabled={ saved?.billing ?? false }
				onChange={ setBilling }
			/>
			<CheckboxControl
				label={ __( 'Checkout uses it for shipping', 'orillacart' ) }
				checked={ shipping }
				disabled={ saved?.shipping ?? false }
				onChange={ setShipping }
			/>
			<Button
				variant="primary"
				isBusy={ busy }
				disabled={ busy }
				onClick={ () => {
					setBusy( true );
					void onSave( address, { billing, shipping } ).finally( () =>
						setBusy( false )
					);
				} }
			>
				{ __( 'Save address', 'orillacart' ) }
			</Button>{ ' ' }
			<Button variant="tertiary" onClick={ onCancel }>
				{ __( 'Cancel', 'orillacart' ) }
			</Button>
		</div>
	);
}

function oneLine( address: Address ): string {
	return [
		`${ address.first_name } ${ address.last_name }`,
		address.street,
		`${ address.postcode } ${ address.city }`,
		address.country,
	]
		.filter( ( part ) => part.trim() !== '' )
		.join( ', ' );
}
