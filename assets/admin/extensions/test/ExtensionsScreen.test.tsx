import '@testing-library/jest-dom';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';

import { ExtensionsScreen } from '../ExtensionsScreen';
import type { Extension } from '../api';

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

/** Answers the screen's requests; a write answers with the extension as given. */
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
