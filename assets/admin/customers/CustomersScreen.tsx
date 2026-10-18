import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Notice,
	RadioControl,
	TextControl,
} from '@wordpress/components';
import { DataViews, type Field, type View } from '@wordpress/dataviews';
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { type Address, EMPTY_ADDRESS } from '../shared/address';
import { AddressFields } from '../shared/AddressFields';
import { toApiError } from '../shared/api';
import { useConfirm } from '../shared/useConfirm';
import {
	type Customer,
	type CustomerGroup,
	type CustomerPage,
	type CustomerSettings,
	deleteGroup,
	fetchCustomers,
	fetchCustomerSettings,
	fetchGroups,
	saveAddresses,
	saveCustomerSettings,
	saveGroup,
	setCustomerGroups,
} from './api';

type Message = { status: 'success' | 'error'; text: string } | null;

const FIELDS: Field< Customer >[] = [
	{
		id: 'name',
		label: __( 'Customer', 'orillacart' ),
		enableHiding: false,
		enableSorting: false,
		getValue: ( { item } ) => `${ item.first_name } ${ item.last_name }`,
	},
	{ id: 'email', label: __( 'Email', 'orillacart' ), enableSorting: false },
	{ id: 'orders', label: __( 'Orders', 'orillacart' ), enableSorting: false },
	{
		id: 'registered_at',
		label: __( 'Since', 'orillacart' ),
		enableSorting: false,
		render: ( { item } ) =>
			new Date( item.registered_at ).toLocaleDateString(),
	},
];

const MODES = [
	{
		value: 'optional',
		label: __(
			'Customers may make an account when they order',
			'orillacart'
		),
	},
	{
		value: 'required',
		label: __(
			'Customers must log in or make an account to order',
			'orillacart'
		),
	},
	{
		value: 'disabled',
		label: __( 'Everyone orders as a guest', 'orillacart' ),
	},
];

/**
 * The Shop → Customers screen: whether customers have accounts, and the customers who made one.
 */
export function CustomersScreen() {
	const [ settings, setSettings ] = useState< CustomerSettings | null >(
		null
	);
	const [ view, setView ] = useState< View >( {
		type: 'table',
		page: 1,
		perPage: 20,
		search: '',
		fields: [ 'email', 'orders', 'registered_at' ],
		titleField: 'name',
		layout: {},
	} );
	const [ page, setPage ] = useState< CustomerPage | null >( null );
	const [ selected, setSelected ] = useState< Customer | null >( null );
	const [ groups, setGroups ] = useState< CustomerGroup[] >( [] );
	const [ message, setMessage ] = useState< Message >( null );

	const fail = ( error: unknown ) =>
		setMessage( { status: 'error', text: toApiError( error ).message } );

	useEffect( () => {
		fetchCustomerSettings().then( setSettings ).catch( fail );
		fetchGroups().then( setGroups ).catch( fail );
	}, [] );
	const reloadGroups = () => fetchGroups().then( setGroups ).catch( fail );
	const load = useCallback( () => {
		fetchCustomers( {
			search: view.search === '' ? undefined : view.search,
			page: view.page ?? 1,
			per_page: view.perPage ?? 20,
		} )
			.then( setPage )
			.catch( fail );
	}, [ view ] );
	useEffect( load, [ load ] );

	return (
		<>
			<h1>{ __( 'Customers', 'orillacart' ) }</h1>
			{ message !== null && (
				<Notice
					status={ message.status }
					onRemove={ () => setMessage( null ) }
				>
					{ message.text }
				</Notice>
			) }
			{ settings !== null && (
				<Card className="orillacart-card">
					<CardHeader>
						<h2>{ __( 'Accounts', 'orillacart' ) }</h2>
					</CardHeader>
					<CardBody>
						<RadioControl
							label={ __( 'Customer accounts', 'orillacart' ) }
							hideLabelFromVision
							selected={ settings.accounts }
							options={ MODES }
							onChange={ ( value ) =>
								saveCustomerSettings(
									value as CustomerSettings[ 'accounts' ]
								)
									.then( ( saved ) => {
										setSettings( saved );
										setMessage( {
											status: 'success',
											text: __( 'Saved.', 'orillacart' ),
										} );
									} )
									.catch( fail )
							}
						/>
					</CardBody>
				</Card>
			) }
			<GroupsCard
				groups={ groups }
				onChanged={ () => {
					reloadGroups();
					load();
				} }
				onError={ fail }
			/>
			{ selected !== null && (
				<CustomerCard
					key={ selected.id }
					customer={ selected }
					groups={ groups }
					onClose={ () => setSelected( null ) }
					onSaved={ ( saved ) => {
						setSelected( saved );
						load();
						setMessage( {
							status: 'success',
							text: __(
								'The addresses were saved.',
								'orillacart'
							),
						} );
					} }
					onError={ fail }
				/>
			) }
			<DataViews< Customer >
				view={ view }
				onChangeView={ setView }
				fields={ FIELDS }
				data={ page?.customers ?? [] }
				getItemId={ ( item ) => String( item.id ) }
				isLoading={ page === null }
				paginationInfo={ {
					totalItems: page?.total ?? 0,
					totalPages: page?.pages ?? 1,
				} }
				defaultLayouts={ { table: {} } }
				searchLabel={ __( 'Search by email or name', 'orillacart' ) }
				onClickItem={ setSelected }
				isItemClickable={ () => true }
				actions={ [
					{
						id: 'edit',
						label: __( 'Edit addresses', 'orillacart' ),
						isPrimary: true,
						callback: ( [ item ] ) => setSelected( item ?? null ),
					},
				] }
				empty={
					<p>
						{ __(
							'No customers have made an account yet.',
							'orillacart'
						) }
					</p>
				}
			/>
		</>
	);
}

function GroupsCard( {
	groups,
	onChanged,
	onError,
}: {
	groups: CustomerGroup[];
	onChanged: () => void;
	onError: ( error: unknown ) => void;
} ) {
	const [ name, setName ] = useState( '' );
	const [ confirmation, confirm ] = useConfirm();
	return (
		<Card className="orillacart-card">
			{ confirmation }
			<CardHeader>
				<h2>{ __( 'Customer groups', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'Groups such as Wholesale or VIP; price rules give them their own prices. Put customers in groups from their details below.',
						'orillacart'
					) }
				</p>
				{ groups.length > 0 && (
					<ul className="orillacart-groups">
						{ groups.map( ( group ) => (
							<li key={ group.id }>
								{ sprintf(
									/* translators: 1: group name, 2: number of customers */
									__( '%1$s (%2$d)', 'orillacart' ),
									group.name,
									group.members
								) }{ ' ' }
								<Button
									variant="link"
									onClick={ () => {
										const renamed = window.prompt(
											__( 'New name', 'orillacart' ),
											group.name
										);
										if ( renamed ) {
											saveGroup( group.id, renamed )
												.then( onChanged )
												.catch( onError );
										}
									} }
								>
									{ __( 'Rename', 'orillacart' ) }
								</Button>{ ' ' }
								<Button
									variant="link"
									isDestructive
									onClick={ () =>
										confirm(
											sprintf(
												/* translators: %s: group name */
												__(
													'Delete the group %s? Its customers leave it, and its price rules apply to no one.',
													'orillacart'
												),
												group.name
											),
											() =>
												deleteGroup( group.id )
													.then( onChanged )
													.catch( onError )
										)
									}
								>
									{ __( 'Delete', 'orillacart' ) }
								</Button>
							</li>
						) ) }
					</ul>
				) }
				<form
					onSubmit={ ( event ) => {
						event.preventDefault();
						saveGroup( null, name )
							.then( () => {
								setName( '' );
								onChanged();
							} )
							.catch( onError );
					} }
				>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'New group', 'orillacart' ) }
						value={ name }
						onChange={ setName }
					/>
					<Button variant="secondary" type="submit">
						{ __( 'Add the group', 'orillacart' ) }
					</Button>
				</form>
			</CardBody>
		</Card>
	);
}

interface CustomerCardProps {
	groups: CustomerGroup[];
	customer: Customer;
	onClose: () => void;
	onSaved: ( customer: Customer ) => void;
	onError: ( error: unknown ) => void;
}

function CustomerCard( {
	groups,
	customer,
	onClose,
	onSaved,
	onError,
}: CustomerCardProps ) {
	const [ billing, setBilling ] = useState< Address >(
		customer.billing ?? EMPTY_ADDRESS
	);
	const [ shipping, setShipping ] = useState< Address >(
		customer.shipping_address ?? EMPTY_ADDRESS
	);
	const [ member, setMember ] = useState< number[] >(
		customer.group_ids ?? []
	);
	const filled = ( address: Address ) =>
		address.street.trim() === '' ? null : address;

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
				<p>
					{ __(
						'Checkout fills these in for the customer. Leave the street empty to forget an address.',
						'orillacart'
					) }
				</p>
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
				<h3>{ __( 'Billing address', 'orillacart' ) }</h3>
				<AddressFields address={ billing } onChange={ setBilling } />
				<h3>{ __( 'Shipping address', 'orillacart' ) }</h3>
				<AddressFields address={ shipping } onChange={ setShipping } />
				<Button
					variant="primary"
					onClick={ () =>
						saveAddresses(
							customer.id,
							filled( billing ),
							filled( shipping )
						)
							.then( onSaved )
							.catch( onError )
					}
				>
					{ __( 'Save addresses', 'orillacart' ) }
				</Button>
			</CardBody>
		</Card>
	);
}
