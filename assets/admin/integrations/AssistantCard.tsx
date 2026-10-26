import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { ConnectAssistant } from './ConnectAssistant';
import type { AssistantCatalogue } from './api';

interface Props {
	catalogue: AssistantCatalogue | null;
	error: string | null;
}

/**
 * The AI assistant: what it is, where it connects, and how many tools it has. Keys are made below, and each
 * key's assistant is chosen there.
 *
 * @param props           The tools there are, and why they could not be read.
 * @param props.catalogue The endpoint and the tools by area.
 * @param props.error     Why they could not be read.
 */
export function AssistantCard( { catalogue, error }: Props ) {
	const [ check, setCheck ] = useState< {
		status: 'success' | 'warning' | 'error';
		text: string;
	} | null >( null );
	const count =
		catalogue?.areas.reduce(
			( sum, area ) => sum + area.tools.length,
			0
		) ?? 0;

	return (
		<Card className="orillacart-card">
			<CardHeader>
				<h2>{ __( 'AI assistant (MCP)', 'orillacart' ) }</h2>
			</CardHeader>
			<CardBody>
				<p>
					{ __(
						'An AI assistant such as Claude can run the shop with you: set it up, answer questions about orders and stock, change prices and settings. It connects with an API key and does what the key’s shop manager can, nothing more. Every change it makes is in the change log.',
						'orillacart'
					) }
				</p>
				{ error !== null && (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) }
				{ catalogue !== null && (
					<>
						<p>
							{ __( 'Its address:', 'orillacart' ) }{ ' ' }
							<code>{ catalogue.endpoint }</code>
						</p>
						<p>
							{ count }{ ' ' }
							{ __(
								'tools. Make a key below, then choose what its assistant may use and what it asks you first.',
								'orillacart'
							) }
						</p>
						<ConnectAssistant
							endpoint={ catalogue.endpoint }
							apiKey={ null }
						/>
						<p>
							<Button
								variant="secondary"
								onClick={ () =>
									void checkConnection(
										catalogue.endpoint
									).then( setCheck )
								}
							>
								{ __( 'Check the connection', 'orillacart' ) }
							</Button>
						</p>
						{ check !== null && (
							<Notice
								status={ check.status }
								onRemove={ () => setCheck( null ) }
							>
								{ check.text }
							</Notice>
						) }
					</>
				) }
			</CardBody>
		</Card>
	);
}

/**
 * Whether a key sent from outside reaches the shop: a made-up key, without cookies, is refused as a key the shop
 * has not when the Authorization header arrives, and as not logged in when the server drops it.
 *
 * @param endpoint The shop's MCP endpoint.
 */
async function checkConnection( endpoint: string ): Promise< {
	status: 'success' | 'warning' | 'error';
	text: string;
} > {
	try {
		const response = await window.fetch( endpoint, {
			method: 'POST',
			credentials: 'omit',
			headers: {
				'Content-Type': 'application/json',
				Authorization: 'Bearer oc_' + '0'.repeat( 40 ),
			},
			body: JSON.stringify( {
				jsonrpc: '2.0',
				id: 1,
				method: 'ping',
			} ),
		} );
		const body: unknown = await response.json().catch( () => null );
		const code =
			typeof body === 'object' && body !== null && 'code' in body
				? String( body.code )
				: '';
		if ( code === 'orillacart_invalid_api_key' ) {
			return {
				status: 'success',
				text: __(
					'The address answers, and a key sent with it reaches the shop.',
					'orillacart'
				),
			};
		}
		if ( response.status === 401 ) {
			return {
				status: 'warning',
				text: __(
					'The address answers, but the server drops the Authorization header. Send the key as “X-OrillaCart-Key: <key>” instead, or ask your host to pass Authorization to PHP.',
					'orillacart'
				),
			};
		}

		return {
			status: 'error',
			text: __(
				'The address did not answer as expected: a security plugin or the server may block it.',
				'orillacart'
			),
		};
	} catch {
		return {
			status: 'error',
			text: __( 'The address could not be reached.', 'orillacart' ),
		};
	}
}
