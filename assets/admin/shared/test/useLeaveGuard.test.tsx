import { act, render, renderHook, screen } from '@testing-library/react';

import { useLeaveGuard } from '../useLeaveGuard';
import { hasUnsavedChanges, useUnsavedChanges } from '../useUnsavedChanges';

function Form( { dirty }: { dirty: boolean } ) {
	useUnsavedChanges( dirty );
	return null;
}

function Screen( {
	dirty,
	elsewhere = false,
	onLeave,
}: {
	dirty: boolean;
	elsewhere?: boolean;
	onLeave: () => void;
} ) {
	const [ dialog, guard, Guarded ] = useLeaveGuard();

	return (
		<>
			{ dialog }
			<Guarded>
				<Form dirty={ dirty } />
			</Guarded>
			<Form dirty={ elsewhere } />
			<button onClick={ () => guard( onLeave ) }>Close</button>
		</>
	);
}

describe( 'useLeaveGuard', () => {
	it( 'goes straight on without changes', () => {
		const leave = jest.fn();
		render( <Screen dirty={ false } onLeave={ leave } /> );

		act( () => screen.getByText( 'Close' ).click() );

		expect( leave ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'asks first while a form has changes, and leaves once the shop agrees', () => {
		const leave = jest.fn();
		render( <Screen dirty onLeave={ leave } /> );

		act( () => screen.getByText( 'Close' ).click() );
		expect( leave ).not.toHaveBeenCalled();
		expect(
			screen.getByText(
				'The changes you made are not saved. Leave them?'
			)
		).toBeTruthy();

		act( () => screen.getByText( 'Leave without saving' ).click() );
		expect( leave ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'does not ask about a form in another part of the page', () => {
		const leave = jest.fn();
		render( <Screen dirty={ false } elsewhere onLeave={ leave } /> );

		act( () => screen.getByText( 'Close' ).click() );

		expect( leave ).toHaveBeenCalledTimes( 1 );
		// Leaving the page still asks about it.
		expect( hasUnsavedChanges() ).toBe( true );
	} );

	it( 'forgets a form once it is gone', () => {
		const { unmount } = renderHook( () => useUnsavedChanges( true ) );
		expect( hasUnsavedChanges() ).toBe( true );

		unmount();
		expect( hasUnsavedChanges() ).toBe( false );
	} );
} );
