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

import { errorMessage } from '../shared/api';
import { useConfirm } from '../shared/useConfirm';
import { useLeaveGuard } from '../shared/useLeaveGuard';
import {
	type Customer,
	type CustomerGroup,
	type CustomerPage,
	type CustomerSettings,
	deleteGroup,
	fetchCustomers,
	fetchCustomerSettings,
	fetchGroups,
	saveCustomerSettings,
	saveGroup,
} from './api';
import { CustomerCard } from './CustomerCard';
import { NewsletterCard } from './NewsletterCard';
import { money } from '../orders/labels';
import { useLatest } from '../shared/useLatest';
import { shopDate } from '../shared/dates';

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
		id: 'spent',
		label: __( 'Spent', 'orillacart' ),
		enableSorting: false,
		render: ( { item } ) =>
			item.lifetime_value
				? money(
						item.lifetime_value.spent,
						item.lifetime_value.currency
					)
				: '',
	},
	{
		id: 'registered_at',
		label: __( 'Since', 'orillacart' ),
		enableSorting: false,
		render: ( { item } ) => shopDate( item.registered_at ),
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
	const [ selected, setSelectedNow ] = useState< Customer | null >( null );
	const [ leaving, guard ] = useLeaveGuard();
	// Another row, or none, only once unsaved changes are let go.
	const setSelected = ( next: Parameters< typeof setSelectedNow >[ 0 ] ) =>
		guard( () => setSelectedNow( next ) );
	const [ groups, setGroups ] = useState< CustomerGroup[] >( [] );
	const [ message, setMessage ] = useState< Message >( null );

	const fail = ( error: unknown ) =>
		setMessage( { status: 'error', text: errorMessage( error ) } );
	const saveSettings = ( changed: CustomerSettings ) =>
		saveCustomerSettings( changed )
			.then( ( saved ) => {
				setSettings( saved );
				setMessage( {
					status: 'success',
					text: __( 'Saved.', 'orillacart' ),
				} );
			} )
			.catch( fail );

	useEffect( () => {
		fetchCustomerSettings().then( setSettings ).catch( fail );
		fetchGroups().then( setGroups ).catch( fail );
	}, [] );
	const reloadGroups = () => fetchGroups().then( setGroups ).catch( fail );
	const latest = useLatest();
	const load = useCallback( () => {
		latest(
			fetchCustomers( {
				search: view.search === '' ? undefined : view.search,
				page: view.page ?? 1,
				per_page: view.perPage ?? 20,
			} )
		)
			.then( setPage )
			.catch( fail );
	}, [ view, latest ] );
	useEffect( load, [ load ] );

	return (
		<>
			{ leaving }
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
								saveSettings( {
									...settings,
									accounts:
										value as CustomerSettings[ 'accounts' ],
								} )
							}
						/>
						{ settings.accounts !== 'disabled' && (
							<CheckboxControl
								label={ __(
									'A new account takes the orders placed with its email before',
									'orillacart'
								) }
								help={ __(
									'Its owner proved the address by following the link emailed to it. Off, an account takes only the order placed while asking for it.',
									'orillacart'
								) }
								checked={ settings.guest_orders }
								onChange={ ( guestOrders ) =>
									saveSettings( {
										...settings,
										guest_orders: guestOrders,
									} )
								}
							/>
						) }
					</CardBody>
				</Card>
			) }
			<NewsletterCard
				onSaved={ () =>
					setMessage( {
						status: 'success',
						text: __( 'Saved.', 'orillacart' ),
					} )
				}
				onError={ fail }
			/>
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
						setSelectedNow( saved );
						load();
						setMessage( {
							status: 'success',
							text: __( 'Saved.', 'orillacart' ),
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
	const [ renaming, setRenaming ] = useState< {
		id: number;
		name: string;
	} | null >( null );
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
								{ renaming?.id === group.id ? (
									<form
										className="orillacart-groups__rename"
										onSubmit={ ( event ) => {
											event.preventDefault();
											if ( renaming.name.trim() === '' ) {
												return;
											}
											saveGroup( group.id, renaming.name )
												.then( () => {
													setRenaming( null );
													onChanged();
												} )
												.catch( onError );
										} }
									>
										<TextControl
											__next40pxDefaultSize
											label={ __(
												'New name',
												'orillacart'
											) }
											value={ renaming.name }
											onChange={ ( value ) =>
												setRenaming( {
													id: group.id,
													name: value,
												} )
											}
										/>
										<Button
											variant="secondary"
											type="submit"
										>
											{ __( 'Rename', 'orillacart' ) }
										</Button>{ ' ' }
										<Button
											variant="tertiary"
											onClick={ () =>
												setRenaming( null )
											}
										>
											{ __( 'Cancel', 'orillacart' ) }
										</Button>
									</form>
								) : (
									<Button
										variant="link"
										onClick={ () =>
											setRenaming( {
												id: group.id,
												name: group.name,
											} )
										}
									>
										{ __( 'Rename', 'orillacart' ) }
									</Button>
								) }{ ' ' }
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
