import {
	snippetCompletion,
	type Completion,
	type CompletionContext,
	type CompletionResult,
} from '@codemirror/autocomplete';

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
 * The snippets, for a word being typed (or on demand), as a completion source of the JavaScript language.
 * @param context Where the cursor is.
 */
export function browserCompletion(
	context: CompletionContext
): CompletionResult | null {
	const word = context.matchBefore( /[\w.:-]*/ );
	if ( ! word || ( word.from === word.to && ! context.explicit ) ) {
		return null;
	}
	return { from: word.from, options: SNIPPETS, validFor: /^[\w.:-]*$/ };
}
