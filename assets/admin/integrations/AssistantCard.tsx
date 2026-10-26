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
 * The AI assistant: what it is, where it connects, and how many tools it has. Connecting by logging in comes
 * first, as claude.ai and ChatGPT can do nothing else; a key is for clients that cannot log in, or a site that
 * cannot offer it. What each connection may do is chosen below, beside its key.
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
						'An AI assistant such as Claude or ChatGPT can run the shop with you: set it up, answer questions about orders and stock, change prices and settings. It does what the shop manager who connected it can, nothing more. Every change it makes is in the change log.',
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
								'tools. Each app you connect shows below as a key; Choose… beside it sets what its assistant may use and what it asks you first.',
								'orillacart'
							) }
						</p>
						{ catalogue.oauth ? (
							<>
								<p>
									{ __(
										'Give your app the address: it sends you here to log in and choose whether it may only read or also change things.',
										'orillacart'
									) }
								</p>
								<ConnectAssistant
									endpoint={ catalogue.endpoint }
									apiKey={ null }
								/>
								<p>
									{ __(
										'A client or script that cannot log in connects with a key: make one below, and the lines to paste are shown with it.',
										'orillacart'
									) }
								</p>
							</>
						) : (
							<>
								<Notice
									status="warning"
									isDismissible={ false }
								>
									{ __(
										'Claude.ai and ChatGPT connect only by logging in, which needs the site on https with pretty permalinks (Settings → Permalinks). Until then, connect a client that takes a key: make one below.',
										'orillacart'
									) }
								</Notice>
								<ConnectAssistant
									endpoint={ catalogue.endpoint }
									apiKey="oc_…"
								/>
							</>
						) }
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
