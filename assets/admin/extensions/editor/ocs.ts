import { StreamLanguage, type StringStream } from '@codemirror/language';

/**
 * Highlighting for the shop's script language (`.ocs`): a stream tokenizer, since the grammar is line-based and
 * the compiler on the server is the only parser. Keywords and declaration kinds mirror the parser's; the
 * reference keeps them current for completion, this list is for colour only.
 */
export const KEYWORDS = [
	'let',
	'fn',
	'if',
	'elif',
	'else',
	'for',
	'in',
	'while',
	'return',
	'break',
	'continue',
	'try',
	'catch',
	'fail',
	'and',
	'or',
	'not',
	'is',
	'starts',
	'ends',
	'with',
];

export const DECLARATIONS = [
	'extension',
	'settings',
	'payment_gateway',
	'shipping_method',
	'cart_rule',
	'order_fee',
	'price_adjuster',
	'order_adjuster',
	'checkout_field',
	'order_action',
	'order_column',
	'product_column',
	'order_filter',
	'product_filter',
	'home',
	'assistant_tool',
	'product_block',
	'mailing_list',
	'route',
	'page',
	'slot',
	'template_data',
	'object',
	'fields',
	'on',
	'job',
	'every',
];

const FAILURES = [ 'unavailable', 'refused', 'error' ];
const ATOMS = [ 'true', 'false', 'null' ];

interface State {
	/** The quote of the string being read across a line break, or null. */
	string: string | null;
}

function readString( stream: StringStream, quote: string ): boolean {
	while ( ! stream.eol() ) {
		const ch = stream.next();
		if ( ch === '\\' && quote === '"' ) {
			stream.next();
			continue;
		}
		if ( ch === quote ) {
			return true;
		}
	}
	return false;
}

export const ocs = StreamLanguage.define< State >( {
	name: 'ocs',
	startState: () => ( { string: null } ),
	token( stream, state ) {
		if ( state.string !== null ) {
			if ( readString( stream, state.string ) ) {
				state.string = null;
			}
			return 'string';
		}
		if ( stream.eatSpace() ) {
			return null;
		}
		if ( stream.match( '#' ) ) {
			stream.skipToEnd();
			return 'comment';
		}
		if ( stream.match( /^"|^'/ ) ) {
			const quote = stream.current();
			if ( ! readString( stream, quote ) ) {
				state.string = quote;
			}
			return 'string';
		}
		if ( stream.match( /^[0-9]+(\.[0-9]+)?/ ) ) {
			return 'number';
		}
		if ( stream.match( /^[A-Za-z_][A-Za-z0-9_]*/ ) ) {
			const word = stream.current();
			if ( ATOMS.includes( word ) ) {
				return 'atom';
			}
			if ( KEYWORDS.includes( word ) ) {
				return 'keyword';
			}
			if (
				stream.sol() ||
				stream.string.slice( 0, stream.start ).trim() === ''
			) {
				if ( DECLARATIONS.includes( word ) ) {
					return 'def';
				}
			}
			if (
				FAILURES.includes( word ) &&
				stream.match( /^\s*\(/, false )
			) {
				return 'builtin';
			}
			if ( stream.match( /^\s*\./, false ) ) {
				return 'variable';
			}
			if ( stream.match( /^\s*\(/, false ) ) {
				return 'builtin';
			}
			if ( stream.match( /^\s*:/, false ) ) {
				return 'property';
			}
			return 'variable';
		}
		if ( stream.match( /^\|\s*[A-Za-z_][A-Za-z0-9_]*/ ) ) {
			return 'meta';
		}
		if (
			stream.match(
				/^(\.\.\.|==|!=|<=|>=|\?\?|\.\.|\/\/|[-+*/%~<>=?:|.,;])/
			)
		) {
			return 'operator';
		}
		if ( stream.match( /^[()[\]{}]/ ) ) {
			return 'punctuation';
		}
		stream.next();
		return null;
	},
	languageData: {
		commentTokens: { line: '#' },
		closeBrackets: { brackets: [ '(', '[', '{', '"', "'" ] },
	},
} );
