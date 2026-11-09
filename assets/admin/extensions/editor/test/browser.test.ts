import { CompletionContext } from '@codemirror/autocomplete';
import { EditorState } from '@codemirror/state';

import { browserCompletion } from '../browser';

const ROUTES = [
	{ method: 'POST', path: '/points/use', who: 'account' },
	{ method: 'GET', path: '/overdue', who: 'manager' },
];

function complete( text: string, explicit = false ) {
	const state = EditorState.create( { doc: text } );
	return browserCompletion( ROUTES )(
		new CompletionContext( state, text.length, explicit )
	);
}

describe( 'browserCompletion', () => {
	it( "offers the shop's client and the storefront's event for a word being typed", () => {
		const result = complete( 'const r = await orill' );
		expect( result ).not.toBeNull();
		expect( result?.from ).toBe( 'const r = await '.length );
		expect( result?.options.map( ( option ) => option.label ) ).toEqual( [
			'POST /points/use',
			'GET /overdue',
			'orillacart.api',
			'wp.apiFetch',
			'orillacart:cart-changed',
			'querySelector',
		] );
	} );

	it( 'offers nothing between words unless asked', () => {
		expect( complete( 'const r = ' ) ).toBeNull();
		expect( complete( 'const r = ', true ) ).not.toBeNull();
	} );
} );
