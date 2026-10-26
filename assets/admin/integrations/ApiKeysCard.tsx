import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { shopDateTime } from '../shared/dates';
import { errorMessage } from '../shared/api';
import { useConfirm } from '../shared/useConfirm';
import { AssistantModal } from './AssistantModal';
import { ConnectAssistant } from './ConnectAssistant';
import {
	type ApiKey,
	type AssistantCatalogue,
	createApiKey,
	fetchApiKeys,
	revokeApiKey,
} from './api';

const SCOPES = [
	{ value: 'read', label: __( 'Read only', 'orillacart' ) },
	{ value: 'read_write', label: __( 'Read and change', 'orillacart' ) },
];

interface Props {
	/** The assistant's endpoint and tools, to choose each key's from; null until read. */
	catalogue: AssistantCatalogue | null;
}

/**
 * Keys integrations and AI assistants call the shop's API with, as the shop manager who made them. A new key
 * shows once.
 *
 * @param props           The assistant's tools.
 * @param props.catalogue The endpoint and the tools by area.
 */
export function ApiKeysCard( { catalogue }: Props ) {
	const [ choosing, setChoosing ] = useState< ApiKey | null >( null );
	const [ keys, setKeys ] = useState< ApiKey[] | null >( null );
	const [ name, setName ] = useState( '' );
	const [ scope, setScope ] = useState< ApiKey[ 'scope' ] >( 'read' );
	const [ made, setMade ] = useState< string | null >( null );
	const [ error, setError ] = useState< string | null >( null );
	const [ confirmation, confirm ] = useConfirm();
	const fail = ( failure: unknown ) => setError( errorMessage( failure ) );
	const load = () => fetchApiKeys().then( setKeys ).catch( fail );

	useEffect( () => {
		fetchApiKeys().then( setKeys ).catch( fail );
	}, [] );

	return (
		<Card className="orillacart-card">
			{ confirmation }
			{ choosing !== null && catalogue !== null && (
				<AssistantModal
					apiKey={ choosing }
					catalogue={ catalogue }
					onClose={ () => setChoosing( null ) }
				/>
			) }
			<CardHeader>
				<h2>{ __( 'API keys', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'An accounting app, a warehouse or a script calls the shop’s API (/wp-json/orillacart/v1) with a key, sent as “Authorization: Bearer <key>”. A key acts as the shop manager who made it.',
						'orillacart'
					) }
				</p>
				{ error !== null && (
					<Notice status="error" onRemove={ () => setError( null ) }>
						{ error }
					</Notice>
				) }
				{ made !== null && (
					<Notice status="success" onRemove={ () => setMade( null ) }>
						<p>
							{ __(
								'Copy the key now: it is not shown again.',
								'orillacart'
							) }
						</p>
						<code className="orillacart-api-key">{ made }</code>
						{ catalogue !== null && (
							<>
								<p>
									{ __(
										'To connect an AI assistant with it:',
										'orillacart'
									) }
								</p>
								<ConnectAssistant
									endpoint={ catalogue.endpoint }
									apiKey={ made }
								/>
							</>
						) }
					</Notice>
				) }
				{ keys !== null && keys.length > 0 && (
					<table className="widefat striped">
						<thead>
							<tr>
								<th>{ __( 'Name', 'orillacart' ) }</th>
								<th>{ __( 'Key', 'orillacart' ) }</th>
								<th>{ __( 'May', 'orillacart' ) }</th>
								<th>{ __( 'As', 'orillacart' ) }</th>
								<th>{ __( 'Last used', 'orillacart' ) }</th>
								<th>{ __( 'AI assistant', 'orillacart' ) }</th>
								<th>
									<span className="screen-reader-text">
										{ __( 'Actions', 'orillacart' ) }
									</span>
								</th>
							</tr>
						</thead>
						<tbody>
							{ keys.map( ( key ) => (
								<tr key={ key.id }>
									<td>{ key.name }</td>
									<td>
										<code>{ key.prefix }…</code>
									</td>
									<td>
										{ SCOPES.find(
											( option ) =>
												option.value === key.scope
										)?.label ?? key.scope }
									</td>
									<td>{ key.user || `#${ key.user_id }` }</td>
									<td>
										{ key.last_used_at
											? shopDateTime( key.last_used_at )
											: __( 'Never', 'orillacart' ) }
									</td>
									<td>
										<Button
											variant="link"
											disabled={ catalogue === null }
											onClick={ () => setChoosing( key ) }
										>
											{ __( 'Choose…', 'orillacart' ) }
										</Button>
									</td>
									<td>
										<Button
											variant="link"
											isDestructive
											onClick={ () =>
												confirm(
													__(
														'Revoke this key? What uses it stops working at once.',
														'orillacart'
													),
													() =>
														void revokeApiKey(
															key.id
														)
															.then( load )
															.catch( fail ),
													__( 'Revoke', 'orillacart' )
												)
											}
										>
											{ __( 'Revoke', 'orillacart' ) }
										</Button>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				) }
				<form
					className="orillacart-api-keys__new"
					onSubmit={ ( event ) => {
						event.preventDefault();
						createApiKey( name, scope )
							.then( ( created ) => {
								setMade( created.key );
								setName( '' );
								return load();
							} )
							.catch( fail );
					} }
				>
					<TextControl
						__next40pxDefaultSize
						label={ __( 'New key for', 'orillacart' ) }
						help={ __(
							'What uses it, such as “Accounting”.',
							'orillacart'
						) }
						value={ name }
						onChange={ setName }
					/>
					<SelectControl
						__next40pxDefaultSize
						label={ __( 'It may', 'orillacart' ) }
						value={ scope }
						options={ SCOPES }
						onChange={ ( value ) =>
							setScope( value as ApiKey[ 'scope' ] )
						}
					/>
					<Button
						variant="secondary"
						type="submit"
						disabled={ name.trim() === '' }
					>
						{ __( 'Make the key', 'orillacart' ) }
					</Button>
				</form>
			</CardBody>
		</Card>
	);
}
