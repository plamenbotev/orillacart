import {
	snippetCompletion,
	type Completion,
	type CompletionContext,
	type CompletionResult,
} from '@codemirror/autocomplete';

import type { ExtensionRoute } from '../api';

/**
 * What an extension's script for the browser reaches (design 59): the shop's client on the storefront, WordPress's
 * on an admin screen, and the events the storefront sends. Offered as snippets beside the language's own
 * completions when a word is being typed.
 */
const SNIPPETS: Completion[] = [
	snippetCompletion(
		"window.orillacart.api('${POST}', '/x/${extension}/${path}', ${body})",
		{
			label: 'orillacart.api',
			detail: "the shop's client: a route of the extension, nonce carried",
			type: 'function',
		}
	),
	snippetCompletion(
		"wp.apiFetch({ path: '/orillacart/v1/x/${extension}/${path}', method: '${POST}', data: ${body} })",
		{
			label: 'wp.apiFetch',
			detail: "WordPress's client, on an admin screen (admin_page)",
			type: 'function',
		}
	),
	snippetCompletion(
		"document.addEventListener('orillacart:cart-changed', () => {\n\t${}\n});",
		{
			label: 'orillacart:cart-changed',
			detail: 'the storefront says the cart changed',
			type: 'keyword',
		}
	),
	snippetCompletion( "document.querySelector('${selector}')", {
		label: 'querySelector',
		type: 'function',
	} ),
];

/**
 * The snippets, for a word being typed (or on demand), as a completion source of the JavaScript language: the
 * general ones, and one call per route the extension declares, with its method and path filled in.
 * @param routes The extension's routes, as the shop read them from its scripts.
 */
export function browserCompletion(
	routes: ExtensionRoute[]
): ( context: CompletionContext ) => CompletionResult | null {
	const options = [
		...routes.map( ( route ) =>
			snippetCompletion(
				route.who === 'manager'
					? `wp.apiFetch({ path: '/orillacart/v1/x/${ '${extension}' }${ route.path }', method: '${ route.method }'${
							route.method === 'GET' ? '' : ', data: ${body}'
						} })`
					: `window.orillacart.api('${ route.method }', '/x/${ '${extension}' }${ route.path }'${
							route.method === 'GET' ? '' : ', ${body}'
						})`,
				{
					label: `${ route.method } ${ route.path }`,
					detail:
						route.who === 'manager'
							? 'a manager route: from an admin screen'
							: `a ${ route.who } route: from the storefront`,
					type: 'function',
				}
			)
		),
		...SNIPPETS,
	];
	return ( context ) => {
		const word = context.matchBefore( /[\w.:/-]*/ );
		if ( ! word || ( word.from === word.to && ! context.explicit ) ) {
			return null;
		}
		return { from: word.from, options, validFor: /^[\w.:/-]*$/ };
	};
}
