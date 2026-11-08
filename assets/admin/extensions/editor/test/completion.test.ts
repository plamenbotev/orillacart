import { CompletionContext } from '@codemirror/autocomplete';
import { EditorState } from '@codemirror/state';

import type { Reference } from '../../api';
import { localNames, ocsCompletion } from '../completion';
import { toDiagnostics } from '../diagnostics';

const reference: Reference = {
	language: '',
	declarations: {},
	functions: [
		{
			name: 'money',
			summary: 'a money value',
			arguments: [ 1, 2 ],
			needs: null,
		},
		{
			name: 'shop.orders.find',
			summary: 'the order',
			arguments: [ 1, 1 ],
			needs: 'orders:read',
		},
		{
			name: 'shop.orders.mark',
			summary: 'marks',
			arguments: [ 2, 2 ],
			needs: 'orders:write',
		},
		{
			name: 'http.get',
			summary: 'a GET',
			arguments: [ 1, 2 ],
			needs: 'http',
		},
		{
			name: 'objects.*.query',
			summary: 'records',
			arguments: [ 1, 1 ],
			needs: null,
		},
	],
	filters: [ 'upper', 'lower' ],
	tests: [ 'defined', 'empty' ],
	keywords: [ 'let', 'fn', 'return' ],
	slots: [],
};

function complete( text: string, explicit = false ) {
	const state = EditorState.create( { doc: text } );
	const context = new CompletionContext( state, text.length, explicit );
	const result = ocsCompletion( reference )( context );
	return result ? result.options.map( ( option ) => option.label ) : null;
}

describe( 'ocsCompletion', () => {
	it( 'offers filters after a pipe and tests after is', () => {
		expect( complete( 'let x = name|' ) ).toEqual( [ 'upper', 'lower' ] );
		expect( complete( 'let x = name|up' ) ).toEqual( [ 'upper', 'lower' ] );
		expect( complete( 'if x is not ' ) ).toEqual( [ 'defined', 'empty' ] );
	} );

	it( 'walks the namespaces of the host functions', () => {
		expect( complete( 'let o = shop.' ) ).toEqual( [ 'shop.orders' ] );
		expect( complete( 'let o = shop.orders.f' ) ).toEqual( [
			'shop.orders.find',
			'shop.orders.mark',
		] );
	} );

	it( 'completes the object kinds the buffer declares', () => {
		expect(
			complete(
				'object "ticket" { fields: { a: text } }\nfn f() { return objects.'
			)
		).toEqual( [ 'objects.ticket' ] );
		expect(
			complete(
				'object "ticket" { fields: { a: text } }\nfn f() { return objects.ticket.'
			)
		).toEqual( [ 'objects.ticket.query' ] );
	} );

	it( 'offers keywords, namespaces, builtins and the local names for a bare word', () => {
		const options =
			complete(
				'fn total(items, rate) {\n  let sum = 0\n  for item in items { sum = s'
			) ?? [];
		expect( options ).toEqual(
			expect.arrayContaining( [
				'let',
				'money',
				'shop',
				'http',
				'objects',
				'items',
				'rate',
				'sum',
				'item',
				'total',
			] )
		);
		expect( options ).not.toContain( 'extension' );
		expect( complete( '' ) ).toBeNull();
		expect( complete( '', true ) ).toEqual(
			expect.arrayContaining( [ 'extension', 'settings', 'fn' ] )
		);
	} );
} );

describe( 'localNames', () => {
	it( 'finds variables, parameters, functions and object kinds', () => {
		expect(
			localNames(
				'object "a" {}\nfn f(x, y) {\n  let z = 1\n  for k, v in x {}\n  try {} catch e {}\n}\nlet w = fn(q) { return q }'
			)
		).toEqual( {
			variables: [ 'z', 'w', 'k', 'v', 'x', 'y', 'q', 'e' ],
			functions: [ 'f' ],
			objects: [ 'a' ],
		} );
	} );
} );

describe( 'toDiagnostics', () => {
	it( 'places a finding on its line from its column to the end of the name there', () => {
		const state = EditorState.create( {
			doc: 'fn f() {\n  return nothing()\n}\n',
		} );
		expect(
			toDiagnostics( state, [
				{
					file: 'main.ocs',
					line: 2,
					column: 10,
					message: 'Unknown name "nothing"',
				},
			] )
		).toEqual( [
			{
				from: 18,
				to: 25,
				severity: 'error',
				message: 'Unknown name "nothing"',
			},
		] );
		expect(
			toDiagnostics( state, [
				{
					file: 'main.ocs',
					line: 9,
					column: 1,
					message: 'past the end',
				},
			] )
		).toEqual( [] );
	} );
} );
