import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';

import { exportUrl } from '../shared/exports';
import { useSavedCopy } from '../shared/useUnsavedChanges';
import {
	fetchNewsletterSettings,
	type NewsletterSettings,
	saveNewsletterSettings,
} from './api';

/**
 * The newsletter: whether checkout and the account offer it, in which words, which add-ons' mailing lists get
 * the sign-ups, and the sign-ups to download.
 * @param props
 * @param props.onSaved
 * @param props.onError
 */
export function NewsletterCard( {
	onSaved,
	onError,
}: {
	onSaved: () => void;
	onError: ( error: unknown ) => void;
} ) {
	const [ settings, setSettings ] = useState< NewsletterSettings | null >(
		null
	);
	const [ busy, setBusy ] = useState( false );
	const loaded = useSavedCopy( settings );
	const failed = useRef( onError );
	failed.current = onError;
	useEffect( () => {
		fetchNewsletterSettings()
			.then( ( found ) => {
				setSettings( found );
				loaded( found );
			} )
			.catch( ( error: unknown ) => failed.current( error ) );
	}, [ loaded ] );
	if ( settings === null ) {
		return null;
	}

	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>{ __( 'Newsletter', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Offer the newsletter at checkout and in the account',
						'orillacart'
					) }
					checked={ settings.offered }
					onChange={ ( offered ) =>
						setSettings( { ...settings, offered } )
					}
				/>
				<ToggleControl
					__nextHasNoMarginBottom
					label={ __(
						'Confirm new subscribers by email',
						'orillacart'
					) }
					help={ __(
						'Each new sign-up gets a link to confirm it, and counts (and goes to the mailing lists) only once followed: nobody signs up an address that is not theirs, as the GDPR expects you to be able to show.',
						'orillacart'
					) }
					checked={ settings.confirm_new }
					onChange={ ( confirmNew ) =>
						setSettings( { ...settings, confirm_new: confirmNew } )
					}
				/>
				<TextareaControl
					__nextHasNoMarginBottom
					label={ __( 'What customers agree to', 'orillacart' ) }
					help={ __(
						'The box they tick says this, unticked to start with; each sign-up keeps the words it agreed to.',
						'orillacart'
					) }
					value={ settings.consent }
					onChange={ ( consent ) =>
						setSettings( { ...settings, consent } )
					}
				/>
				<h3>{ __( 'Mailing lists', 'orillacart' ) }</h3>
				{ settings.lists.length === 0 ? (
					<p>
						{ __(
							'No add-on brings a mailing list: the shop keeps the sign-ups, for you to download.',
							'orillacart'
						) }
					</p>
				) : (
					settings.lists.map( ( list ) => (
						<CheckboxControl
							key={ list.id }
							label={ list.name }
							help={ __(
								'Gets each sign-up and each unsubscribe; turned on, it gets everyone subscribed so far.',
								'orillacart'
							) }
							checked={ list.on }
							onChange={ ( on ) =>
								setSettings( {
									...settings,
									lists: settings.lists.map( ( other ) =>
										other.id === list.id
											? { ...other, on }
											: other
									),
								} )
							}
						/>
					) )
				) }
				<p>
					{ sprintf(
						/* translators: %d: how many are subscribed */
						_n(
							'%d subscriber.',
							'%d subscribers.',
							settings.subscribers,
							'orillacart'
						),
						settings.subscribers
					) }{ ' ' }
					<a href={ exportUrl( 'subscribers', 'csv', {} ) }>
						{ __( 'Download them (CSV)', 'orillacart' ) }
					</a>
				</p>
				<Button
					variant="primary"
					isBusy={ busy }
					disabled={ busy }
					onClick={ () => {
						setBusy( true );
						saveNewsletterSettings( {
							offered: settings.offered,
							consent: settings.consent,
							confirm_new: settings.confirm_new,
							lists: settings.lists
								.filter( ( list ) => list.on )
								.map( ( list ) => list.id ),
						} )
							.then( ( saved ) => {
								setSettings( saved );
								loaded( saved );
								onSaved();
							} )
							.catch( onError )
							.finally( () => setBusy( false ) );
					} }
				>
					{ __( 'Save', 'orillacart' ) }
				</Button>
			</CardBody>
		</Card>
	);
}
