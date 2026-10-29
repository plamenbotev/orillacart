/**
 * The shop's MCP server over HTTP as clients speak it: a client of the earlier revisions (initialize, then
 * requests) and one of 2026-07-28 (stateless, the version and method in the headers), and the admin's AI
 * assistant card that connects one.
 */
import { expect, test } from '@wordpress/e2e-test-utils-playwright';
import type { APIRequestContext } from '@playwright/test';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { StreamableHTTPClientTransport } from '@modelcontextprotocol/sdk/client/streamableHttp.js';

import { remove } from './shop';

const ENDPOINT = '/wp-json/orillacart/v1/mcp';
const MODERN = '2026-07-28';

let key: { id: number; secret: string };

type Answer = {
	status: number;
	body: {
		result?: Record< string, unknown >;
		error?: { code: number; message: string };
	};
};

/**
 * One JSON-RPC request, as a Streamable HTTP client sends it: POST, JSON and SSE accepted, the key as a bearer
 * token, and for 2026-07-28 the version in _meta and the method and name in the headers.
 *
 * @param request The browser-less HTTP client.
 * @param method  The JSON-RPC method.
 * @param params  Its params.
 * @param version The revision the request speaks; a legacy one sends only its header.
 */
async function rpc(
	request: APIRequestContext,
	method: string,
	params: Record< string, unknown > = {},
	version = MODERN
): Promise< Answer > {
	const headers: Record< string, string > = {
		Authorization: `Bearer ${ key.secret }`,
		Accept: 'application/json, text/event-stream',
		'MCP-Protocol-Version': version,
	};
	let body = params;
	if ( version === MODERN ) {
		headers[ 'Mcp-Method' ] = method;
		const name = params.name ?? params.uri;
		if ( typeof name === 'string' ) {
			headers[ 'Mcp-Name' ] = name;
		}
		body = {
			...params,
			_meta: {
				'io.modelcontextprotocol/protocolVersion': MODERN,
				'io.modelcontextprotocol/clientCapabilities': {},
				'io.modelcontextprotocol/clientInfo': {
					name: 'e2e',
					version: '1',
				},
			},
		};
	}
	const response = await request.post( ENDPOINT, {
		headers,
		data: { jsonrpc: '2.0', id: Date.now(), method, params: body },
	} );

	return { status: response.status(), body: await response.json() };
}

/**
 * The names of the tools the key's client is offered. @param request The HTTP client.
 * @param request
 */
async function toolNames( request: APIRequestContext ): Promise< string[] > {
	const listed = await rpc( request, 'tools/list' );

	return ( listed.body.result?.tools as { name: string }[] ).map(
		( tool ) => tool.name
	);
}

test.beforeAll( async ( { requestUtils } ) => {
	const made = await requestUtils.rest< {
		key: string;
		api_key: { id: number };
	} >( {
		method: 'POST',
		path: '/orillacart/v1/api-keys',
		data: { name: 'E2E assistant', scope: 'read_write' },
	} );
	key = { id: made.api_key.id, secret: made.key };
} );

test.afterAll( async ( { requestUtils } ) => {
	await remove( requestUtils, `/orillacart/v1/api-keys/${ key.id }` );
} );

test.describe( 'The AI assistant', () => {
	test( 'a client of the earlier revisions starts with initialize and runs a tool', async ( {
		request,
	} ) => {
		const init = await rpc(
			request,
			'initialize',
			{
				protocolVersion: '2025-06-18',
				capabilities: {},
				clientInfo: { name: 'e2e', version: '1' },
			},
			'2025-06-18'
		);
		expect( init.body.result?.protocolVersion ).toBe( '2025-06-18' );
		expect(
			( init.body.result?.serverInfo as { name: string } ).name
		).toBe( 'orillacart' );

		const settings = await rpc(
			request,
			'tools/call',
			{ name: 'show_settings', arguments: {} },
			'2025-06-18'
		);
		expect( settings.body.result?.isError ).toBe( false );
		expect( settings.body.result?.structuredContent ).toHaveProperty(
			'currency'
		);
	} );

	test( 'the official MCP client connects, lists the tools, runs one, reads the shop and is told what waits for the owner', async ( {
		baseURL,
	} ) => {
		// The SDK's own client and Streamable HTTP transport, as Claude Desktop, Cursor and others use them: it
		// speaks its latest revision (2025-11-25) and the earlier ones; 2026-07-28 is the test below.
		const client = new Client( { name: 'e2e-sdk', version: '1' } );
		const transport = new StreamableHTTPClientTransport(
			new URL( ENDPOINT, baseURL ),
			{
				requestInit: {
					headers: { Authorization: `Bearer ${ key.secret }` },
				},
			}
		);
		// The SDK's own types disagree under exactOptionalPropertyTypes (sessionId may be undefined).
		await client.connect(
			transport as unknown as Parameters< Client[ 'connect' ] >[ 0 ]
		);
		try {
			expect( client.getServerVersion()?.name ).toBe( 'orillacart' );
			expect( transport.protocolVersion ).toBe( '2025-11-25' );

			const names: string[] = [];
			let cursor: string | undefined;
			do {
				const page = await client.listTools(
					cursor === undefined ? {} : { cursor }
				);
				names.push( ...page.tools.map( ( tool ) => tool.name ) );
				cursor = page.nextCursor;
			} while ( cursor !== undefined );
			expect( names.length ).toBeGreaterThan( 200 );
			expect( names ).toEqual(
				expect.arrayContaining( [ 'list_orders', 'e2e_echo' ] )
			);

			// The client checks each answer against the tool's output schema, where one is given.
			const settings = await client.callTool( {
				name: 'show_settings',
				arguments: {},
			} );
			expect( settings.isError ).toBe( false );
			expect( settings.structuredContent ).toHaveProperty( 'currency' );

			const refused = await client.callTool( {
				name: 'create_tax_group',
				arguments: { name: '' },
			} );
			expect( refused.isError ).toBe( true );

			// A refund waits for the owner's yes, which this revision's clients cannot ask: the model is told so.
			const waits = await client.callTool( {
				name: 'refund_order',
				arguments: { id: 1, amount: '1' },
			} );
			expect( waits.isError ).toBe( true );
			expect( JSON.stringify( waits.content ) ).toContain(
				'waits for the owner'
			);

			const shop = await client.readResource( {
				uri: 'orillacart://shop',
			} );
			expect( shop.contents[ 0 ]?.uri ).toBe( 'orillacart://shop' );
			const prompts = await client.listPrompts();
			expect(
				prompts.prompts.map( ( prompt ) => prompt.name )
			).toContain( 'set_up_shop' );
			const prompt = await client.getPrompt( {
				name: 'set_up_shop',
			} );
			expect( prompt.messages.length ).toBeGreaterThan( 0 );
		} finally {
			await client.close();
		}
	} );

	test( 'an app that cannot ask gives the owner a link, where they see what it would do and say yes', async ( {
		baseURL,
		page,
		requestUtils,
	} ) => {
		const group = await requestUtils.rest< { id: number } >( {
			method: 'POST',
			path: '/orillacart/v1/tax/groups',
			data: { name: 'E2E Approve me' },
		} );
		const client = new Client( { name: 'e2e-sdk', version: '1' } );
		await client.connect(
			new StreamableHTTPClientTransport( new URL( ENDPOINT, baseURL ), {
				requestInit: {
					headers: { Authorization: `Bearer ${ key.secret }` },
				},
			} ) as unknown as Parameters< Client[ 'connect' ] >[ 0 ]
		);
		try {
			const call = {
				name: 'delete_tax_group',
				arguments: { id: group.id },
			};
			const waits = await client.callTool( call );
			expect( waits.isError ).toBe( true );
			const text =
				( waits.content as { text: string }[] )[ 0 ]?.text ?? '';
			const link = text.match(
				/https?:\/\/\S+orillacart-approve\S+/
			)?.[ 0 ];
			expect( link ).toBeDefined();

			// The owner, logged in, reads what it would do and says yes.
			await page.goto( link ?? '' );
			await expect(
				page.getByText(
					'Deletes the tax group "E2E Approve me" for good.'
				)
			).toBeVisible();
			await page.getByRole( 'button', { name: 'Yes, do it' } ).click();
			await expect( page.getByText( 'You said yes.' ) ).toBeVisible();

			const ran = await client.callTool( call );
			expect( ran.isError ).toBe( false );
			const groups = await requestUtils.rest< { id: number }[] >( {
				path: '/orillacart/v1/tax/groups',
			} );
			expect( groups.map( ( { id } ) => id ) ).not.toContain( group.id );
		} finally {
			await client.close();
		}
	} );

	test( 'a 2026-07-28 client discovers the server, lists the tools and reads the shop', async ( {
		request,
	} ) => {
		const discovered = await rpc( request, 'server/discover' );
		expect( discovered.body.result?.supportedVersions ).toContain( MODERN );

		const names = await toolNames( request );
		expect( names.length ).toBeGreaterThan( 200 );
		expect( names ).toEqual(
			expect.arrayContaining( [
				'list_orders',
				'create_product',
				'setup_answer_business',
				'e2e_echo',
			] )
		);
		expect( names ).not.toContain( 'create_api_key' );

		const refused = await rpc( request, 'tools/call', {
			name: 'create_tax_group',
			arguments: { name: '' },
		} );
		expect( refused.body.result?.isError ).toBe( true );

		const mismatch = await request.post( ENDPOINT, {
			headers: {
				Authorization: `Bearer ${ key.secret }`,
				'MCP-Protocol-Version': MODERN,
				'Mcp-Method': 'tools/call',
			},
			data: {
				jsonrpc: '2.0',
				id: 1,
				method: 'tools/list',
				params: {
					_meta: {
						'io.modelcontextprotocol/protocolVersion': MODERN,
					},
				},
			},
		} );
		expect( mismatch.status() ).toBe( 400 );

		const shop = await rpc( request, 'resources/read', {
			uri: 'orillacart://shop',
		} );
		const contents = shop.body.result?.contents as { text: string }[];
		expect( JSON.parse( contents[ 0 ]?.text ?? '{}' ) ).toHaveProperty(
			'readiness'
		);
		const prompts = await rpc( request, 'prompts/list' );
		expect(
			( prompts.body.result?.prompts as { name: string }[] ).map(
				( prompt ) => prompt.name
			)
		).toContain( 'set_up_shop' );
	} );

	test( 'without a key the endpoint asks for one', async ( { request } ) => {
		const response = await request.post( ENDPOINT, {
			data: { jsonrpc: '2.0', id: 1, method: 'tools/list' },
		} );
		expect( response.status() ).toBe( 401 );
		expect( response.headers()[ 'www-authenticate' ] ).toContain(
			'Bearer'
		);
	} );

	test( "the owner chooses what the key's assistant may use", async ( {
		admin,
		page,
		request,
	} ) => {
		await admin.visitAdminPage(
			'admin.php',
			'page=orillacart-integrations'
		);
		await expect(
			page.getByRole( 'heading', { name: 'AI assistant (MCP)' } )
		).toBeVisible();

		await page
			.getByRole( 'button', { name: 'Check the connection' } )
			.click();
		await expect(
			page
				.locator( '.components-notice' )
				.getByText( 'a key sent with it reaches the shop' )
		).toBeVisible();

		await page
			.getByRole( 'row', { name: /E2E assistant/ } )
			.getByRole( 'button', { name: 'Choose…' } )
			.click();
		const dialog = page.getByRole( 'dialog' );
		await dialog.getByLabel( /^Tax \(\d+ tools\)$/ ).uncheck();
		await dialog.getByRole( 'button', { name: 'Save' } ).click();
		await expect( dialog ).toBeHidden();

		const names = await toolNames( request );
		expect( names ).not.toContain( 'show_tax_settings' );
		expect( names ).toContain( 'list_orders' );
	} );
} );
