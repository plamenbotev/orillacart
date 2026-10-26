import { __ } from '@wordpress/i18n';

interface Props {
	/** The shop's MCP endpoint. */
	endpoint: string;
	/** The key just made, or null to show where it goes. */
	apiKey: string | null;
}

/**
 * What to paste into an AI client to connect it to the shop, for the clients most owners use.
 *
 * @param props          The endpoint and the key.
 * @param props.endpoint The shop's MCP endpoint.
 * @param props.apiKey   The key just made, or null.
 */
export function ConnectAssistant( { endpoint, apiKey }: Props ) {
	const key = apiKey ?? 'oc_…';
	const json = ( servers: Record< string, unknown > ) =>
		JSON.stringify( servers, null, 2 );
	const clients = [
		{
			name: 'Claude Code',
			where: __( 'Run in a terminal:', 'orillacart' ),
			text: `claude mcp add --transport http my-shop ${ endpoint } \\\n  --header "Authorization: Bearer ${ key }"`,
		},
		{
			name: 'Cursor',
			where: __( 'In ~/.cursor/mcp.json:', 'orillacart' ),
			text: json( {
				mcpServers: {
					'my-shop': {
						url: endpoint,
						headers: { Authorization: `Bearer ${ key }` },
					},
				},
			} ),
		},
		{
			name: 'VS Code',
			where: __( 'In .vscode/mcp.json:', 'orillacart' ),
			text: json( {
				servers: {
					'my-shop': {
						type: 'http',
						url: endpoint,
						headers: { Authorization: `Bearer ${ key }` },
					},
				},
			} ),
		},
		{
			name: 'Claude Desktop',
			where: __(
				'In claude_desktop_config.json (it needs Node.js):',
				'orillacart'
			),
			text: json( {
				mcpServers: {
					'my-shop': {
						command: 'npx',
						args: [
							'mcp-remote',
							endpoint,
							'--header',
							`Authorization:Bearer ${ key }`,
						],
					},
				},
			} ),
		},
	];

	return (
		<div className="orillacart-connect-assistant">
			{ clients.map( ( client ) => (
				<details key={ client.name }>
					<summary>{ client.name }</summary>
					<p>{ client.where }</p>
					<pre>
						<code>{ client.text }</code>
					</pre>
				</details>
			) ) }
		</div>
	);
}
