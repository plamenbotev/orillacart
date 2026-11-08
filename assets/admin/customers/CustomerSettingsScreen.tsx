import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	RadioControl,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { errorMessage } from '../shared/api';
import { useConfirm } from '../shared/useConfirm';
import {
	type CustomerGroup,
	type CustomerSettings,
	deleteGroup,
	fetchCustomerSettings,
	fetchGroups,
	saveCustomerSettings,
	saveGroup,
} from './api';
import { NewsletterCard } from './NewsletterCard';
import { Page, notify } from '../shared/ui';

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
 * The Shop → Customers → Accounts and groups screen: whether customers have accounts, the newsletter, and the
 * customer groups price rules can price for.
 */
export function CustomerSettingsScreen() {
	const [ settings, setSettings ] = useState< CustomerSettings | null >(
		null
	);
	const [ groups, setGroups ] = useState< CustomerGroup[] >( [] );
	const [ error, setError ] = useState< string | null >( null );

	const fail = ( failure: unknown ) => setError( errorMessage( failure ) );
	const saveSettings = ( changed: CustomerSettings ) =>
		saveCustomerSettings( changed )
			.then( ( saved ) => {
				setSettings( saved );
				notify( __( 'Saved.', 'orillacart' ) );
			} )
			.catch( fail );

	useEffect( () => {
		fetchCustomerSettings().then( setSettings ).catch( fail );
		fetchGroups().then( setGroups ).catch( fail );
	}, [] );
	const reloadGroups = () => fetchGroups().then( setGroups ).catch( fail );

	return (
		<Page
			title={ __( 'Accounts and groups', 'orillacart' ) }
			error={ error }
			onDismissError={ () => setError( null ) }
		>
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
				onSaved={ () => notify( __( 'Saved.', 'orillacart' ) ) }
				onError={ fail }
			/>
			<GroupsCard
				groups={ groups }
				onChanged={ reloadGroups }
				onError={ fail }
			/>
		</Page>
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
