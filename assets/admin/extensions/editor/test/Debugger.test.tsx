import '@testing-library/jest-dom';
import { fireEvent, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import recorded from './recording.json';
import { Debugger } from '../Debugger';
import type { Recording } from '../replay';

// CodeMirror needs a layout engine jsdom has not: the source is a list of its lines, each a button that toggles
// a breakpoint, the current one marked.
jest.mock( '../SourceView', () => ( {
	SourceView: ( {
		value,
		label,
		line,
		breakpoints,
		onToggle,
	}: {
		value: string;
		label: string;
		line: number | null;
		breakpoints: number[];
		onToggle: ( line: number ) => void;
	} ) => (
		<ol aria-label={ label }>
			{ value.split( '\n' ).map( ( text, index ) => (
				<li
					key={ index }
					aria-current={ line === index + 1 ? 'step' : undefined }
				>
					<button onClick={ () => onToggle( index + 1 ) }>
						{ `${ index + 1 }${
							breakpoints.includes( index + 1 ) ? ' ●' : ''
						}` }
					</button>
					{ text }
				</li>
			) ) }
		</ol>
	),
} ) );

const recording = recorded as unknown as Recording;
const NOTICE = { selector: '.components-notice__content' };
const current = () =>
	document.querySelector( '[aria-current="step"]' )?.textContent;

describe( 'Debugger', () => {
	it( 'steps through the run with the toolbar and shows the frames and variables', async () => {
		const user = userEvent.setup();
		render( <Debugger recording={ recording } onClose={ () => {} } /> );

		expect(
			screen.getByText( /^Step 1 of 14/, { selector: 'span' } )
		).toBeVisible();
		expect( current() ).toContain( 'let sum = 0' );
		expect(
			screen.getByRole( 'button', { name: 'Step back' } )
		).toBeDisabled();

		await user.click( screen.getByRole( 'button', { name: 'Step in' } ) );
		await user.click( screen.getByRole( 'button', { name: 'Step in' } ) );
		await user.click( screen.getByRole( 'button', { name: 'Step in' } ) );
		expect(
			screen.getByRole( 'tab', { name: 'price.ocs' } )
		).toHaveAttribute( 'aria-selected', 'true' );
		expect( current() ).toContain( 'let unit = item.unit' );
		expect(
			screen.getByText( 'price.ocs:2', { selector: 'code' } )
		).toBeVisible();

		// The variables of the frame chosen open into their keys.
		await user.click( screen.getByRole( 'button', { name: /item/ } ) );
		expect( screen.getByText( 'qty' ) ).toBeVisible();

		await user.click( screen.getByRole( 'button', { name: /total/ } ) );
		expect(
			screen.getByRole( 'tab', { name: 'main.ocs' } )
		).toHaveAttribute( 'aria-selected', 'true' );
		expect( screen.getByText( 'sum' ) ).toBeVisible();
	} );

	it( 'runs to a breakpoint set in the gutter, by key, and to the error', () => {
		render( <Debugger recording={ recording } onClose={ () => {} } /> );
		fireEvent.click( screen.getByRole( 'tab', { name: 'price.ocs' } ) );
		fireEvent.click( screen.getByRole( 'button', { name: '5' } ) );
		expect(
			screen.getByText( 'price.ocs:5', { selector: 'code' } )
		).toBeVisible();

		const panel = screen.getByRole( 'group', { name: 'Debugger' } );
		fireEvent.keyDown( panel, { key: 'F5' } );
		expect( current() ).toContain( 'http.get' );
		expect(
			screen.getByText( /At price.ocs:5, step/ )
		).toBeInTheDocument();

		fireEvent.keyDown( panel, { key: 'F5' } );
		expect(
			screen.getByText( 'The run ended here: refused: 503', NOTICE )
		).toBeVisible();
		expect(
			screen.getByText( 'http.get("https://api.example/check")' )
		).toBeVisible();
		expect(
			screen.getByRole( 'button', { name: 'Continue' } )
		).toBeDisabled();

		fireEvent.keyDown( panel, { key: 'F5', shiftKey: true } );
		expect( current() ).toContain( 'http.get' );
	} );

	it( 'says when the recording is cut short and closes', async () => {
		const onClose = jest.fn();
		const user = userEvent.setup();
		render(
			<Debugger
				recording={ { ...recording, cut: true } }
				onClose={ onClose }
			/>
		);
		expect(
			screen.getByText( /its end is missing/, NOTICE )
		).toBeVisible();
		await user.click(
			screen.getByRole( 'button', { name: 'Close the debugger' } )
		);
		expect( onClose ).toHaveBeenCalled();
	} );
} );
