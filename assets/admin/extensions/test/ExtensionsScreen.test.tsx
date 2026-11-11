import '@testing-library/jest-dom';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { ExtensionsScreen } from '../ExtensionsScreen';
import type { Extension } from '../api';
import recorded from '../editor/test/recording.json';

jest.mock( '@wordpress/api-fetch' );
// CodeMirror needs a layout engine jsdom has not; the screen's tests see a plain textarea in its place.
jest.mock( '../editor/CodeEditor', () => ( {
	CodeEditor: ( {
		label,
		value,
		onChange,
		disabled,
	}: {
		label: string;
		value: string;
		onChange: ( value: string ) => void;
		disabled?: boolean;
	} ) => (
		<textarea
			aria-label={ label }
			value={ value }
			onChange={ ( event ) => onChange( event.target.value ) }
			disabled={ disabled }
		/>
	),
} ) );
// The debugger's source view is CodeMirror too: its tests are the debugger's own.
jest.mock( '../editor/SourceView', () => ( {
	SourceView: ( { label, value }: { label: string; value: string } ) => (
		<pre aria-label={ label }>{ value }</pre>
	),
} ) );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const TOAST = { selector: '.components-snackbar__content' };
const NOTICE = { selector: '.components-notice__content' };

const greeter: Extension = {
	id: 'greeter',
	name: 'Greeter',
	version: '1.0.0',
	state: 'draft',
	stale: false,
	granted: [],
	needs: [ 'orders:read' ],
	failures: 0,
	last_problem: null,
	created_at: '2026-11-07T10:00:00+00:00',
	updated_at: '2026-11-07T10:00:00+00:00',
	files: [
		{
			path: 'main.ocs',
			content: 'fn greet(n) { return n }',
			updated_at: '2026-11-07T10:00:00+00:00',
		},
	],
	settings: [
		{
			key: 'token',
			label: 'Token',
			type: 'secret',
			required: false,
			help: '',
			choices: {},
			value: '',
			set: true,
		},
	],
	findings: [],
};

/**
 * Answers the screen's requests; a write answers with the extension as given.
 *
 * @param override Answers a request first, or returns undefined to let the default answer.
 */
function serve(
	override?: ( request: {
		path: string;
		method?: string;
		data?: unknown;
	} ) => Promise< unknown > | undefined
) {
	mockedFetch.mockImplementation( ( options ) => {
		const request = options as {
			path: string;
			method?: string;
			data?: unknown;
		};
		const answer = override?.( request );
		if ( answer ) {
			return answer;
		}
		if (
			request.path === '/orillacart/v1/extensions' &&
			request.method === undefined
		) {
			return Promise.resolve( {
				extensions: [
					{ ...greeter, files: null, settings: null, findings: null },
				],
			} );
		}
		if ( request.path === '/orillacart/v1/script-reference' ) {
			return Promise.resolve( {
				language: '',
				declarations: {},
				functions: [],
				filters: [],
				tests: [],
				keywords: [],
				slots: [],
			} );
		}
		if ( request.path === '/orillacart/v1/script/check' ) {
			return Promise.resolve( { findings: [] } );
		}
		if ( request.path === '/orillacart/v1/extensions/greeter/versions' ) {
			return Promise.resolve( {
				versions: [
					{
						number: 2,
						note: 'Saved main.ocs',
						created_at: '2026-11-08T10:00:00+00:00',
						checksum: 'b',
						files: [ 'main.ocs' ],
						current: true,
					},
					{
						number: 1,
						note: 'Installed',
						created_at: '2026-11-08T09:00:00+00:00',
						checksum: 'a',
						files: [ 'main.ocs' ],
						current: false,
					},
				],
			} );
		}
		if ( request.path === '/orillacart/v1/extensions/greeter/runs' ) {
			return Promise.resolve( {
				runs: [
					{
						at: '2026-11-08T10:01:00+00:00',
						what: 'console: greet',
						outcome: 'error',
						message: 'greet expects 1 argument, 0 given',
						used: { steps: 1, calls: 0, seconds: 0.001 },
					},
				],
			} );
		}
		if (
			request.path === '/orillacart/v1/extensions/greeter/recordings' &&
			request.method === undefined
		) {
			return Promise.resolve( { recordings: [], capture: null } );
		}
		if (
			request.path === '/orillacart/v1/extensions/greeter/translations'
		) {
			return Promise.resolve( { languages: [], words: [] } );
		}
		if ( request.path === '/orillacart/v1/extensions/greeter' ) {
			return Promise.resolve( greeter );
		}
		if ( request.path.startsWith( '/orillacart/v1/extensions/greeter/' ) ) {
			return Promise.resolve( greeter );
		}
		return Promise.reject( new Error( 'unexpected ' + request.path ) );
	} );
}

jest.setTimeout( 15000 );

describe( 'ExtensionsScreen', () => {
	afterEach( () => mockedFetch.mockReset() );

	it( 'lists the extensions with their state and opens one into the editor', async () => {
		serve();
		const user = userEvent.setup();
		render( <ExtensionsScreen /> );

		expect( await screen.findByText( 'Draft' ) ).toBeVisible();
		await user.click( screen.getByRole( 'button', { name: 'Greeter' } ) );

		expect(
			await screen.findByRole( 'heading', { name: 'Greeter', level: 1 } )
		).toBeVisible();
		expect( screen.getByLabelText( 'main.ocs' ) ).toHaveValue(
			'fn greet(n) { return n }'
		);
		expect(
			screen.getByText( 'The scripts compile.', NOTICE )
		).toBeVisible();
		expect( screen.getByLabelText( 'Token' ) ).toHaveAttribute(
			'placeholder',
			'••••••••'
		);
	} );

	it( 'saves a file and shows the findings the check returns', async () => {
		serve( ( { path, method, data } ) =>
			method === 'PUT' &&
			path === '/orillacart/v1/extensions/greeter/files/main.ocs'
				? Promise.resolve( {
						...greeter,
						files: [
							{
								path: 'main.ocs',
								content: ( data as { content: string } )
									.content,
								updated_at: '',
							},
						],
						findings: [
							'main.ocs: Unknown name "x" (line 1, column 24)',
						],
					} )
				: undefined
		);
		const user = userEvent.setup();
		render( <ExtensionsScreen /> );
		await user.click(
			await screen.findByRole( 'button', { name: 'Greeter' } )
		);

		const editor = await screen.findByLabelText( 'main.ocs' );
		await user.click( editor );
		await user.keyboard( ' x' );
		await user.click( screen.getByRole( 'button', { name: 'Save file' } ) );

		expect( await screen.findByText( 'File saved.', TOAST ) ).toBeVisible();
		expect(
			screen.getByText(
				'main.ocs: Unknown name "x" (line 1, column 24)',
				{ selector: 'li' }
			)
		).toBeVisible();
		expect(
			screen.getByRole( 'button', { name: 'Enable' } )
		).toBeDisabled();
	} );

	it( 'runs the tests over the files as edited and lists each outcome', async () => {
		let sent: unknown = null;
		serve( ( { path, method, data } ) => {
			if (
				method === 'POST' &&
				path === '/orillacart/v1/extensions/greeter/test'
			) {
				sent = data;
				return Promise.resolve( {
					tests: [
						{
							name: 'greets',
							ok: true,
							message: null,
							file: null,
							line: null,
							http: [],
							jobs: [],
							used: {},
						},
						{
							name: 'quotes',
							ok: false,
							message: 'the quote is 6',
							file: 'main.ocs',
							line: 9,
							http: [
								{
									method: 'POST',
									url: 'https://api.example/quote',
									status: 200,
								},
							],
							jobs: [],
							used: {},
						},
					],
				} );
			}
			return undefined;
		} );
		const user = userEvent.setup();
		render( <ExtensionsScreen /> );
		await user.click(
			await screen.findByRole( 'button', { name: 'Greeter' } )
		);
		const editor = await screen.findByLabelText( 'main.ocs' );
		await user.click( editor );
		await user.keyboard( ' y' );
		await user.click( screen.getByRole( 'button', { name: 'Run tests' } ) );

		expect(
			await screen.findByText( '1 of 2 tests failed.', { selector: 'p' } )
		).toBeVisible();
		expect(
			screen.getByText( ': the quote is 6', {
				exact: false,
				selector: 'span',
			} )
		).toBeVisible();
		expect(
			screen.getByText( 'main.ocs line 9', {
				exact: false,
				selector: 'span',
			} )
		).toBeVisible();
		const files = ( sent as { files: Record< string, string > } ).files;
		expect( files[ 'main.ocs' ] ).toMatch( / y$/ );
	} );

	it( 'debugs one test over the files as edited and opens its recording', async () => {
		let sent: unknown = null;
		serve( ( { path, method, data } ) => {
			if (
				method === 'POST' &&
				path === '/orillacart/v1/extensions/greeter/test'
			) {
				sent = data;
				const debugged =
					( data as { debug?: string } ).debug === 'quotes';
				return Promise.resolve( {
					tests: [
						{
							name: 'quotes',
							ok: false,
							message: 'refused: 503',
							file: 'price.ocs',
							line: 6,
							http: [],
							jobs: [],
							used: {},
							stack: [],
							recording: debugged ? { ...recorded, id: 4 } : null,
						},
					],
				} );
			}
			return undefined;
		} );
		const user = userEvent.setup();
		render( <ExtensionsScreen /> );
		await user.click(
			await screen.findByRole( 'button', { name: 'Greeter' } )
		);
		await user.click( await screen.findByLabelText( 'main.ocs' ) );
		await user.keyboard( ' y' );
		await user.click( screen.getByRole( 'button', { name: 'Run tests' } ) );
		await user.click(
			await screen.findByRole( 'button', {
				name: 'Debug the test quotes: run it again recorded',
			} )
		);

		expect(
			await screen.findByRole( 'group', { name: 'Debugger' } )
		).toBeVisible();
		expect(
			screen.getByText( 'fn total', { selector: 'code' } )
		).toBeVisible();
		expect( screen.getByLabelText( 'main.ocs as it ran' ) ).toBeVisible();
		const request = sent as {
			files: Record< string, string >;
			debug: string;
		};
		expect( request.debug ).toBe( 'quotes' );
		expect( request.files[ 'main.ocs' ] ).toMatch( / y$/ );

		await user.click(
			screen.getByRole( 'button', { name: 'Close the debugger' } )
		);
		expect(
			screen.queryByRole( 'group', { name: 'Debugger' } )
		).not.toBeInTheDocument();
	} );

	it( 'debugs a console run', async () => {
		let sent: unknown = null;
		serve( ( { path, method, data } ) => {
			if (
				method === 'POST' &&
				path === '/orillacart/v1/extensions/greeter/run'
			) {
				sent = data;
				return Promise.resolve( {
					ok: false,
					result: null,
					failure: {
						kind: 'error',
						message: 'refused: 503',
						data: null,
					},
					stopped: null,
					used: { steps: 14 },
					stack: [],
					recording: { ...recorded, id: 5 },
				} );
			}
			return undefined;
		} );
		const user = userEvent.setup();
		render( <ExtensionsScreen /> );
		await user.click(
			await screen.findByRole( 'button', { name: 'Greeter' } )
		);
		await user.type( await screen.findByLabelText( 'Function' ), 'total' );
		await user.click(
			screen.getByRole( 'button', {
				name: 'Run the function recorded, and open the recording in the debugger',
			} )
		);

		expect(
			await screen.findByRole( 'group', { name: 'Debugger' } )
		).toBeVisible();
		expect( sent ).toEqual( {
			function: 'total',
			arguments: [],
			debug: true,
		} );
	} );

	it( 'lists the recordings to open or delete, and records the next runs live', async () => {
		const asked: string[] = [];
		let capture: unknown = null;
		serve( ( { path, method, data } ) => {
			const base = '/orillacart/v1/extensions/greeter';
			if ( path === `${ base }/recordings` && method === undefined ) {
				return Promise.resolve( {
					recordings: [
						{
							id: 7,
							source: 'live',
							what: 'on order_paid',
							outcome: 'error',
							message: 'refused: 503',
							steps: 14,
							recorded_at: '2026-11-11T10:00:00+00:00',
						},
					],
					capture: null,
				} );
			}
			if ( path === `${ base }/recordings/7` ) {
				asked.push( `${ method ?? 'GET' } ${ path }` );
				return Promise.resolve(
					method === 'DELETE'
						? { deleted: true }
						: {
								...recorded,
								id: 7,
								source: 'live',
								recorded_at: '2026-11-11T10:00:00+00:00',
							}
				);
			}
			if ( path === `${ base }/capture` ) {
				capture = data ?? null;
				return Promise.resolve( {
					capture:
						method === 'POST'
							? {
									what: 'on order_paid',
									left: 3,
									until: '2026-11-11T11:00:00+00:00',
								}
							: null,
				} );
			}
			return undefined;
		} );
		const user = userEvent.setup();
		render( <ExtensionsScreen /> );
		await user.click(
			await screen.findByRole( 'button', { name: 'Greeter' } )
		);

		await user.click(
			await screen.findByRole( 'button', {
				name: 'Open the recording of on order_paid in the debugger',
			} )
		);
		expect(
			await screen.findByRole( 'group', { name: 'Debugger' } )
		).toBeVisible();
		await user.click(
			screen.getByRole( 'button', {
				name: 'Delete the recording of on order_paid',
			} )
		);
		expect(
			await screen.findByText( 'Recording deleted.', TOAST )
		).toBeVisible();
		expect( asked ).toEqual( [
			'GET /orillacart/v1/extensions/greeter/recordings/7',
			'DELETE /orillacart/v1/extensions/greeter/recordings/7',
		] );

		expect(
			screen.getByText( /customers’ data among it/, NOTICE )
		).toBeVisible();
		await user.type( screen.getByLabelText( 'Handlers' ), 'on order_paid' );
		await user.clear( screen.getByLabelText( 'Runs' ) );
		await user.type( screen.getByLabelText( 'Runs' ), '3' );
		await user.click(
			screen.getByRole( 'button', { name: 'Start recording' } )
		);
		expect(
			await screen.findByText( /Recording 3 more runs of on order_paid/ )
		).toBeVisible();
		expect( capture ).toEqual( {
			what: 'on order_paid',
			runs: 3,
			minutes: 15,
		} );
		await user.click(
			screen.getByRole( 'button', { name: 'Stop recording' } )
		);
		expect(
			await screen.findByRole( 'button', { name: 'Start recording' } )
		).toBeVisible();
	} );

	it( 'lists the versions with a restore and the recent runs', async () => {
		let restored: string | null = null;
		serve( ( { path, method } ) => {
			if (
				method === 'POST' &&
				path === '/orillacart/v1/extensions/greeter/versions/1/restore'
			) {
				restored = path;
				return Promise.resolve( greeter );
			}
			return undefined;
		} );
		const user = userEvent.setup();
		render( <ExtensionsScreen /> );
		await user.click(
			await screen.findByRole( 'button', { name: 'Greeter' } )
		);

		expect( await screen.findByText( 'Installed' ) ).toBeVisible();
		expect( screen.getByText( 'current' ) ).toBeVisible();
		expect(
			screen.getAllByRole( 'button', { name: 'Restore' } )
		).toHaveLength( 1 );
		await user.click( screen.getByRole( 'button', { name: 'Restore' } ) );
		expect(
			await screen.findByText( 'Version 1 restored.', TOAST )
		).toBeVisible();
		expect( restored ).toBe(
			'/orillacart/v1/extensions/greeter/versions/1/restore'
		);

		expect( screen.getByText( 'console: greet' ) ).toBeVisible();
		expect(
			screen.getByText( 'greet expects 1 argument, 0 given', {
				exact: false,
			} )
		).toBeVisible();
	} );

	it( 'translates the words with a field per language and a plural per form', async () => {
		let saved: unknown = null;
		const words = {
			languages: [
				{ locale: 'de_DE', name: 'Deutsch', forms: 2 },
				{ locale: 'pl_PL', name: 'Polski', forms: 3 },
			],
			words: [
				{
					key: 'aaaaaaaaaaaaaaaa',
					text: 'Hello',
					plural: null,
					context: '',
					where: [ 'main.ocs, line 3' ],
					translations: { de_DE: [ 'Hallo' ], pl_PL: [ '' ] },
				},
				{
					key: 'bbbbbbbbbbbbbbbb',
					text: '%d item',
					plural: '%d items',
					context: '',
					where: [ 'main.ocs, line 4' ],
					translations: {
						de_DE: [ '', '' ],
						pl_PL: [ '', '', '' ],
					},
				},
			],
		};
		serve( ( { path, method, data } ) => {
			if ( path === '/orillacart/v1/extensions/greeter/translations' ) {
				if ( method === 'PUT' ) {
					saved = data;
				}
				return Promise.resolve( words );
			}
			return undefined;
		} );
		const user = userEvent.setup();
		render( <ExtensionsScreen /> );
		await user.click(
			await screen.findByRole( 'button', { name: 'Greeter' } )
		);

		expect(
			await screen.findByLabelText( 'Hello in Deutsch' )
		).toHaveValue( 'Hallo' );
		expect( screen.getByText( 'main.ocs, line 3' ) ).toBeVisible();
		expect(
			screen.getByLabelText( '%d items, form 3 in Polski' )
		).toBeVisible();
		expect(
			screen.queryByLabelText( '%d items, form 3 in Deutsch' )
		).toBeNull();

		await user.type( screen.getByLabelText( 'Hello in Polski' ), 'Cześć' );
		await user.type(
			screen.getByLabelText( '%d items, form 3 in Polski' ),
			'%d produktów'
		);
		await user.click(
			screen.getByRole( 'button', { name: 'Save translations' } )
		);
		expect(
			await screen.findByText( 'Translations saved.', TOAST )
		).toBeVisible();
		expect( saved ).toEqual( {
			translations: {
				aaaaaaaaaaaaaaaa: { de_DE: [ 'Hallo' ], pl_PL: [ 'Cześć' ] },
				bbbbbbbbbbbbbbbb: {
					de_DE: [ '', '' ],
					pl_PL: [ '', '', '%d produktów' ],
				},
			},
		} );
	} );

	it( 'imports a zip and says which of its files were left out', async () => {
		let sent: unknown = null;
		serve( ( { path, method, ...rest } ) => {
			if (
				path === '/orillacart/v1/extensions/upload' &&
				method === 'POST'
			) {
				sent = ( rest as { body?: FormData } ).body?.get( 'file' );
				return Promise.resolve( {
					extension: greeter,
					skipped: [ 'greeter/LICENSE' ],
				} );
			}
			return undefined;
		} );
		const user = userEvent.setup();
		render( <ExtensionsScreen /> );
		await screen.findByRole( 'button', { name: 'Greeter' } );

		const zip = new File( [ 'PK' ], 'greeter.zip', {
			type: 'application/zip',
		} );
		await user.upload(
			screen.getByLabelText( 'Import an extension file' ),
			zip
		);
		expect(
			(
				await screen.findAllByText(
					'Imported Greeter. Left out what is not an extension’s file: greeter/LICENSE.'
				)
			).length
		).toBeGreaterThan( 0 );
		expect( sent ).toBe( zip );
	} );

	it( 'asks the manager to grant what the extension needs before enabling it', async () => {
		let granted: unknown = null;
		serve( ( { path, method, data } ) => {
			if (
				method === 'POST' &&
				path === '/orillacart/v1/extensions/greeter/enable'
			) {
				granted = ( data as { grant: string[] } ).grant;
				return Promise.resolve( {
					...greeter,
					state: 'enabled',
					granted: [ 'orders:read' ],
				} );
			}
			return undefined;
		} );
		const user = userEvent.setup();
		render( <ExtensionsScreen /> );
		await user.click(
			await screen.findByRole( 'button', { name: 'Greeter' } )
		);

		await user.click(
			await screen.findByRole( 'button', { name: 'Enable' } )
		);
		const dialog = await screen.findByRole( 'dialog' );
		expect( dialog ).toHaveTextContent( 'orders:read' );
		await user.click(
			screen.getByLabelText( 'I grant these permissions' )
		);
		await user.click(
			screen.getAllByRole( 'button', { name: 'Enable' } ).at( -1 )!
		);

		expect(
			await screen.findByText( 'Extension enabled.', TOAST )
		).toBeVisible();
		await waitFor( () => expect( granted ).toEqual( [ 'orders:read' ] ) );
		expect(
			screen.getByRole( 'button', { name: 'Disable' } )
		).toBeVisible();
	} );
} );
