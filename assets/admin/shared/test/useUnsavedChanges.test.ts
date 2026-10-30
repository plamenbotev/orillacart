import { renderHook } from '@testing-library/react';

import { differs, useUnsavedChanges } from '../useUnsavedChanges';

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

	it( 'compares the decimals named as numbers, at any depth, and keeps empty apart from 0', () => {
		const saved = { price: '2.50', sale: { price: '1.00' }, sku: '007' };
		const decimals = [ 'price' ];

		expect(
			differs(
				{ price: '2.5', sale: { price: '1' }, sku: '007' },
				saved,
				decimals
			)
		).toBe( false );
		expect(
			differs( { ...saved, sale: { price: '' } }, saved, decimals )
		).toBe( true );
		expect(
			differs(
				{ ...saved, price: '' },
				{ ...saved, price: '0' },
				decimals
			)
		).toBe( true );
		// A field not named compares as text.
		expect( differs( { ...saved, sku: '7' }, saved, decimals ) ).toBe(
			true
		);
		expect( differs( { price: '2.5' }, { price: '2.50' } ) ).toBe( true );
	} );
} );
