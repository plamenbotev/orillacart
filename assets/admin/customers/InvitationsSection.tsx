import { Button } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { shopDate } from '../shared/dates';
import { Badge, DataTable, Section, notify } from '../shared/ui';
import {
	fetchInvitations,
	resendInvitation,
	withdrawInvitation,
	type Invitation,
} from './api';

/**
 * The customers the shop invited to make an account who have not yet: on a network the shop invites rather than
 * making accounts. Shows nothing when there are none.
 *
 * @param props
 * @param props.onError What went wrong.
 */
export function InvitationsSection( {
	onError,
}: {
	onError: ( failure: unknown ) => void;
} ) {
	const [ invitations, setInvitations ] = useState< Invitation[] | null >(
		null
	);
	const [ busy, setBusy ] = useState< number | null >( null );

	useEffect( () => {
		fetchInvitations().then( setInvitations ).catch( onError );
	}, [ onError ] );

	if ( invitations === null || invitations.length === 0 ) {
		return null;
	}

	const resend = ( invitation: Invitation ) => {
		setBusy( invitation.id );
		resendInvitation( invitation.id )
			.then( ( sent ) => {
				setInvitations(
					( current ) =>
						current?.map( ( other ) =>
							other.id === sent.id ? sent : other
						) ?? null
				);
				notify( __( 'The invitation went again.', 'orillacart' ) );
			} )
			.catch( onError )
			.finally( () => setBusy( null ) );
	};
	const withdraw = ( invitation: Invitation ) => {
		setBusy( invitation.id );
		withdrawInvitation( invitation.id )
			.then( () => {
				setInvitations(
					( current ) =>
						current?.filter(
							( other ) => other.id !== invitation.id
						) ?? null
				);
				notify( __( 'Invitation withdrawn.', 'orillacart' ) );
			} )
			.catch( onError )
			.finally( () => setBusy( null ) );
	};

	return (
		<Section
			title={ __( 'Invited', 'orillacart' ) }
			description={ __(
				'Customers the shop invited to make an account. They join their groups once they follow the link.',
				'orillacart'
			) }
		>
			<DataTable< Invitation >
				caption={ __( 'Invitations', 'orillacart' ) }
				rows={ invitations }
				rowKey={ ( invitation ) => String( invitation.id ) }
				columns={ [
					{
						id: 'email',
						label: __( 'Email', 'orillacart' ),
						render: ( invitation ) => invitation.email,
					},
					{
						id: 'name',
						label: __( 'Name', 'orillacart' ),
						render: ( invitation ) =>
							`${ invitation.first_name } ${ invitation.last_name }`.trim(),
					},
					{
						id: 'state',
						label: __( 'Link', 'orillacart' ),
						nowrap: true,
						render: ( invitation ) =>
							invitation.expired ? (
								<Badge tone="warning">
									{ __( 'Stopped working', 'orillacart' ) }
								</Badge>
							) : (
								<Badge tone="info">
									{ sprintf(
										/* translators: %s: date */
										__( 'Waiting, until %s', 'orillacart' ),
										shopDate( invitation.expires_at )
									) }
								</Badge>
							),
					},
					{
						id: 'actions',
						label: __( 'Actions', 'orillacart' ),
						align: 'end',
						nowrap: true,
						render: ( invitation ) => (
							<>
								<Button
									variant="secondary"
									size="compact"
									disabled={
										! invitation.may_resend ||
										busy === invitation.id
									}
									title={
										invitation.may_resend
											? undefined
											: __(
													'An invitation goes again once a day at most.',
													'orillacart'
												)
									}
									onClick={ () => resend( invitation ) }
								>
									{ __( 'Send again', 'orillacart' ) }
								</Button>{ ' ' }
								<Button
									variant="tertiary"
									size="compact"
									isDestructive
									disabled={ busy === invitation.id }
									onClick={ () => withdraw( invitation ) }
								>
									{ __( 'Withdraw', 'orillacart' ) }
								</Button>
							</>
						),
					},
				] }
			/>
		</Section>
	);
}
