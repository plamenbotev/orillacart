import { renderHook } from '@testing-library/react';

import { useUnsavedChanges } from '../useUnsavedChanges';

describe( 'useUnsavedChanges', () => {
	it( 'asks before leaving only while there are changes', () => {
		const leave = () => {
			const event = new Event( 'beforeunload', { cancelable: true } );
			window.dispatchEvent( event );
			return event.defaultPrevented;
		};
		const { rerender, unmount } = renderHook(
			( { dirty }: { dirty: boolean } ) => useUnsavedChanges( dirty ),
			{ initialProps: { dirty: false } }
		);
		expect( leave() ).toBe( false );

		rerender( { dirty: true } );
		expect( leave() ).toBe( true );

		rerender( { dirty: false } );
		expect( leave() ).toBe( false );
		unmount();
	} );
} );
