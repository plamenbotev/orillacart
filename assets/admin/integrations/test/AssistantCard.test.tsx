import { render } from '@testing-library/react';

import { AssistantCard } from '../AssistantCard';
import type { AssistantCatalogue } from '../api';

const catalogue = ( oauth: boolean ): AssistantCatalogue => ( {
	endpoint: 'https://shop.example/wp-json/orillacart/v1/mcp',
	oauth,
	areas: [],
} );

describe( 'AssistantCard', () => {
	it( 'leads with logging in, the address alone, when the site offers it', () => {
		const { container } = render(
			<AssistantCard catalogue={ catalogue( true ) } error={ null } />
		);
		const text = container.textContent ?? '';

		expect( text ).toContain( 'Claude (claude.ai, Claude Desktop)' );
		expect( text ).toContain( 'ChatGPT' );
		expect( text ).toContain(
			'claude mcp add --transport http my-shop https://shop.example/wp-json/orillacart/v1/mcp'
		);
		expect( text ).not.toContain( 'Authorization' );
		expect( text ).not.toContain( 'mcp-remote' );
	} );

	it( 'says claude.ai and ChatGPT must wait, and shows the key lines, when it cannot', () => {
		const { container } = render(
			<AssistantCard catalogue={ catalogue( false ) } error={ null } />
		);
		const text = container.textContent ?? '';

		expect( text ).toContain(
			'Claude.ai and ChatGPT connect only by logging in'
		);
		expect( text ).not.toContain( 'Claude (claude.ai, Claude Desktop)' );
		expect( text ).toContain( 'Authorization: Bearer oc_…' );
		expect( text ).toContain( 'mcp-remote' );
	} );
} );
