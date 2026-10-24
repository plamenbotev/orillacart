import { act, render, renderHook, screen } from '@testing-library/react';

import { useLeaveGuard } from '../useLeaveGuard';
import { hasUnsavedChanges, useUnsavedChanges } from '../useUnsavedChanges';

function Screen( { dirty, onLeave }: { dirty: boolean; onLeave: () => void } ) {
	useUnsavedChanges( dirty );
	const [ dialog, guard ] = useLeaveGuard();

	return (
		<>
			{ dialog }
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

	it( 'forgets a form once it is gone', () => {
		const { unmount } = renderHook( () => useUnsavedChanges( true ) );
		expect( hasUnsavedChanges() ).toBe( true );

		unmount();
		expect( hasUnsavedChanges() ).toBe( false );
	} );
} );
