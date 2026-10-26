import { __ } from '@wordpress/i18n';

interface Props {
	/** The shop's MCP endpoint. */
	endpoint: string;
	/** The key to connect with, the placeholder "oc_…" to show where one goes, or null to connect by logging in. */
	apiKey: string | null;
}

/**
 * What to give an AI client to connect it to the shop, for the clients most owners use. Without a key it is the
 * address alone: the client sends the owner to the shop's admin to log in and say yes.
 *
 * @param props          The endpoint and the key.
 * @param props.endpoint The shop's MCP endpoint.
 * @param props.apiKey   The key, or null to log in.
 */
export function ConnectAssistant( { endpoint, apiKey }: Props ) {
	const json = ( servers: Record< string, unknown > ) =>
		JSON.stringify( servers, null, 2 );
	const headers =
		apiKey === null
			? {}
			: { headers: { Authorization: `Bearer ${ apiKey }` } };
	const clients = [
		...( apiKey === null
			? [
					{
						name: 'Claude (claude.ai, Claude Desktop)',
						where: __(
							'In Settings → Connectors, add a custom connector with this address, then connect it:',
							'orillacart'
						),
						text: endpoint,
					},
					{
						name: 'ChatGPT',
						where: __(
							'In its settings, add an app (a connector) with this address and OAuth, then connect it:',
							'orillacart'
						),
						text: endpoint,
					},
				]
			: [] ),
		{
			name: 'Claude Code',
			where:
				apiKey === null
					? __(
							'Run in a terminal, then /mcp in Claude Code to log in:',
							'orillacart'
						)
					: __( 'Run in a terminal:', 'orillacart' ),
			text:
				`claude mcp add --transport http my-shop ${ endpoint }` +
				( apiKey === null
					? ''
					: ` \\\n  --header "Authorization: Bearer ${ apiKey }"` ),
		},
		{
			name: 'Cursor',
			where: __( 'In ~/.cursor/mcp.json:', 'orillacart' ),
			text: json( {
				mcpServers: { 'my-shop': { url: endpoint, ...headers } },
			} ),
		},
		{
			name: 'VS Code',
			where: __( 'In .vscode/mcp.json:', 'orillacart' ),
			text: json( {
				servers: {
					'my-shop': { type: 'http', url: endpoint, ...headers },
				},
			} ),
		},
		...( apiKey === null
			? []
			: [
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
										`Authorization:Bearer ${ apiKey }`,
									],
								},
							},
						} ),
					},
				] ),
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
