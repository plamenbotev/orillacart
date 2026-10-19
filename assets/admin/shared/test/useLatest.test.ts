import { renderHook } from '@testing-library/react';

import { useLatest } from '../useLatest';

describe( 'useLatest', () => {
	it( 'gives only the answer to the latest request, whichever comes back first', async () => {
		const { result } = renderHook( () => useLatest() );
		let answerFirst: ( value: string ) => void = () => undefined;
		const first = new Promise< string >( ( resolve ) => {
			answerFirst = resolve;
		} );
		const seen: string[] = [];

		void result.current( first ).then( ( value ) => seen.push( value ) );
		await result
			.current( Promise.resolve( 'second' ) )
			.then( ( value ) => seen.push( value ) );
		answerFirst( 'first, late' );
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

		expect( seen ).toEqual( [ 'second' ] );
	} );

	it( 'gives nothing once the screen is gone', async () => {
		const { result, unmount } = renderHook( () => useLatest() );
		const seen: string[] = [];
		const request = result.current( Promise.resolve( 'too late' ) );
		unmount();
		void request.then( ( value ) => seen.push( value ) );
		await new Promise( ( resolve ) => setTimeout( resolve, 0 ) );

		expect( seen ).toEqual( [] );
	} );
} );
