import {
	Button,
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Notice,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { toApiError } from '../shared/api';
import { useConfirm } from '../shared/useConfirm';
import {
	deleteWebhook,
	fetchDeliveries,
	fetchWebhooks,
	newWebhookSecret,
	pingWebhook,
	saveWebhook,
	type Webhook,
	type WebhookDelivery,
	type WebhookInput,
} from './api';

const EMPTY: WebhookInput = { name: '', url: '', topics: [], active: true };

function outcome( delivery: WebhookDelivery | null ): string {
	if ( delivery === null ) {
		return __( 'Nothing sent yet', 'orillacart' );
	}
	return delivery.succeeded
		? sprintf(
				/* translators: %d: HTTP status */
				__( 'Delivered (%d)', 'orillacart' ),
				delivery.status ?? 0
			)
		: sprintf(
				/* translators: %s: what went wrong */
				__( 'Failed: %s', 'orillacart' ),
				delivery.status !== null
					? String( delivery.status )
					: delivery.error
			);
}

/**
 * Addresses the shop tells of its events, each POSTed as signed JSON by the background jobs and tried again when
 * it fails.
 */
export function WebhooksCard() {
	const [ webhooks, setWebhooks ] = useState< Webhook[] | null >( null );
	const [ topics, setTopics ] = useState< Record< string, string > >( {} );
	const [ editing, setEditing ] = useState< {
		id: number | null;
		webhook: WebhookInput;
	} | null >( null );
	const [ opened, setOpened ] = useState< {
		id: number;
		deliveries: WebhookDelivery[];
	} | null >( null );
	const [ message, setMessage ] = useState< {
		status: 'success' | 'error';
		text: string;
	} | null >( null );
	const [ confirmation, confirm ] = useConfirm();
	const fail = ( failure: unknown ) =>
		setMessage( { status: 'error', text: toApiError( failure ).message } );
	const load = () =>
		fetchWebhooks()
			.then( ( loaded ) => {
				setWebhooks( loaded.webhooks );
				setTopics( loaded.topics );
			} )
			.catch( fail );

	useEffect( () => {
		void load();
		// Once, when the screen opens; changes load again themselves.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const field = < K extends keyof WebhookInput >(
		key: K,
		value: WebhookInput[ K ]
	) =>
		setEditing(
			( current ) =>
				current && {
					...current,
					webhook: { ...current.webhook, [ key ]: value },
				}
		);

	return (
		<Card className="orillacart-card">
			{ confirmation }
			<CardHeader>
				<h2>{ __( 'Webhooks', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'The shop POSTs each event a webhook listens to as JSON, signed with its secret in X-OrillaCart-Signature ("t=<time>,v1=<HMAC-SHA256 of time.body>"). An answer other than 2xx is tried again, up to six times over a day.',
						'orillacart'
					) }
				</p>
				{ message !== null && (
					<Notice
						status={ message.status }
						onRemove={ () => setMessage( null ) }
					>
						{ message.text }
					</Notice>
				) }
				{ webhooks !== null && webhooks.length > 0 && (
					<table className="widefat striped">
						<thead>
							<tr>
								<th>{ __( 'Name', 'orillacart' ) }</th>
								<th>{ __( 'Address', 'orillacart' ) }</th>
								<th>{ __( 'Tells of', 'orillacart' ) }</th>
								<th>{ __( 'Last delivery', 'orillacart' ) }</th>
								<th>
									<span className="screen-reader-text">
										{ __( 'Actions', 'orillacart' ) }
									</span>
								</th>
							</tr>
						</thead>
						<tbody>
							{ webhooks.map( ( webhook ) => (
								<tr key={ webhook.id }>
									<td>
										{ webhook.name }
										{ ! webhook.active &&
											` (${ __( 'off', 'orillacart' ) })` }
									</td>
									<td>
										<code>{ webhook.url }</code>
									</td>
									<td>
										{ webhook.topics
											.map(
												( topic ) =>
													topics[ topic ] ?? topic
											)
											.join( ', ' ) }
									</td>
									<td>
										{ outcome( webhook.last_delivery ) }
									</td>
									<td>
										<Button
											variant="link"
											onClick={ () =>
												setEditing( {
													id: webhook.id,
													webhook: {
														name: webhook.name,
														url: webhook.url,
														topics: webhook.topics,
														active: webhook.active,
													},
												} )
											}
										>
											{ __( 'Edit', 'orillacart' ) }
										</Button>{ ' ' }
										<Button
											variant="link"
											onClick={ () =>
												pingWebhook( webhook.id )
													.then( () =>
														setMessage( {
															status: 'success',
															text: __(
																'A test is on its way: see the deliveries in a moment.',
																'orillacart'
															),
														} )
													)
													.catch( fail )
											}
										>
											{ __(
												'Send a test',
												'orillacart'
											) }
										</Button>{ ' ' }
										<Button
											variant="link"
											onClick={ () =>
												fetchDeliveries( webhook.id )
													.then( ( deliveries ) =>
														setOpened( {
															id: webhook.id,
															deliveries,
														} )
													)
													.catch( fail )
											}
										>
											{ __( 'Deliveries', 'orillacart' ) }
										</Button>{ ' ' }
										<Button
											variant="link"
											isDestructive
											onClick={ () =>
												confirm(
													__(
														'Delete this webhook? The shop stops telling it of anything.',
														'orillacart'
													),
													() =>
														void deleteWebhook(
															webhook.id
														)
															.then( load )
															.catch( fail ),
													__( 'Delete', 'orillacart' )
												)
											}
										>
											{ __( 'Delete', 'orillacart' ) }
										</Button>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				) }
				{ opened !== null && (
					<div className="orillacart-webhook__deliveries">
						<h3>{ __( 'Latest deliveries', 'orillacart' ) }</h3>
						{ opened.deliveries.length === 0 ? (
							<p>{ __( 'Nothing sent yet.', 'orillacart' ) }</p>
						) : (
							<ul>
								{ opened.deliveries.map( ( delivery ) => (
									<li
										key={ `${ delivery.event }-${ delivery.attempt }` }
									>
										{ new Date(
											delivery.attempted_at
										).toLocaleString() }{ ' ' }
										<code>{ delivery.topic }</code>{ ' ' }
										{ outcome( delivery ) }{ ' ' }
										<small>
											{ sprintf(
												/* translators: 1: try number, 2: milliseconds */
												__(
													'try %1$d, %2$d ms',
													'orillacart'
												),
												delivery.attempt,
												delivery.milliseconds
											) }
										</small>
									</li>
								) ) }
							</ul>
						) }
					</div>
				) }
				{ editing === null ? (
					<Button
						variant="secondary"
						onClick={ () =>
							setEditing( { id: null, webhook: EMPTY } )
						}
					>
						{ __( 'Add a webhook', 'orillacart' ) }
					</Button>
				) : (
					<form
						className="orillacart-webhook__form"
						onSubmit={ ( event ) => {
							event.preventDefault();
							saveWebhook( editing.id, editing.webhook )
								.then( () => {
									setEditing( null );
									return load();
								} )
								.catch( fail );
						} }
					>
						<TextControl
							__next40pxDefaultSize
							label={ __( 'Name', 'orillacart' ) }
							value={ editing.webhook.name }
							onChange={ ( value ) => field( 'name', value ) }
						/>
						<TextControl
							__next40pxDefaultSize
							type="url"
							label={ __( 'Address', 'orillacart' ) }
							help={ __(
								'https://…; http:// only for an address on this computer.',
								'orillacart'
							) }
							value={ editing.webhook.url }
							onChange={ ( value ) => field( 'url', value ) }
						/>
						<fieldset>
							<legend>{ __( 'Tells of', 'orillacart' ) }</legend>
							{ Object.entries( topics ).map(
								( [ topic, label ] ) => (
									<CheckboxControl
										key={ topic }
										label={ `${ label } (${ topic })` }
										checked={ editing.webhook.topics.includes(
											topic
										) }
										onChange={ ( on ) =>
											field(
												'topics',
												on
													? [
															...editing.webhook
																.topics,
															topic,
														]
													: editing.webhook.topics.filter(
															( other ) =>
																other !== topic
														)
											)
										}
									/>
								)
							) }
						</fieldset>
						<CheckboxControl
							label={ __( 'On', 'orillacart' ) }
							checked={ editing.webhook.active }
							onChange={ ( on ) => field( 'active', on ) }
						/>
						{ editing.id !== null && (
							<p>
								{ __( 'Secret:', 'orillacart' ) }{ ' ' }
								<code className="orillacart-webhook__secret">
									{ webhooks?.find(
										( webhook ) => webhook.id === editing.id
									)?.secret ?? '' }
								</code>{ ' ' }
								<Button
									variant="link"
									onClick={ () =>
										editing.id !== null &&
										newWebhookSecret( editing.id )
											.then( load )
											.catch( fail )
									}
								>
									{ __( 'New secret', 'orillacart' ) }
								</Button>
							</p>
						) }
						<Button variant="primary" type="submit">
							{ __( 'Save the webhook', 'orillacart' ) }
						</Button>{ ' ' }
						<Button
							variant="tertiary"
							onClick={ () => setEditing( null ) }
						>
							{ __( 'Cancel', 'orillacart' ) }
						</Button>
					</form>
				) }
			</CardBody>
		</Card>
	);
}
