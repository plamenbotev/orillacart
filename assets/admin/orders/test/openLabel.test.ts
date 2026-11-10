import apiFetch from '@wordpress/api-fetch';

import { fetchLabel, openLabel } from '../api';

jest.mock( '@wordpress/api-fetch' );

describe( 'shipping labels', () => {
	const open = jest.fn();
	const click = jest.fn();

	beforeEach( () => {
		open.mockReset();
		click.mockReset();
		window.open = open;
		URL.createObjectURL = jest.fn( () => 'blob:label' );
		URL.revokeObjectURL = jest.fn();
		jest.spyOn( HTMLAnchorElement.prototype, 'click' ).mockImplementation(
			click
		);
	} );

	it( 'opens a PDF or an image to print', () => {
		for ( const type of [ 'application/pdf', 'image/png', 'image/jpeg' ] ) {
			openLabel( new File( [ 'x' ], 'label-1.pdf', { type } ) );
		}
		expect( open ).toHaveBeenCalledTimes( 3 );
		expect( click ).not.toHaveBeenCalled();
	} );

	it( 'downloads any other file and never opens it', () => {
		openLabel(
			new File( [ '<script>1</script>' ], 'label-1.bin', {
				type: 'application/octet-stream',
			} )
		);
		expect( open ).not.toHaveBeenCalled();
		expect( click ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'takes a type it would run, such as HTML or SVG, as a file', async () => {
		for ( const type of [ 'text/html', 'image/svg+xml' ] ) {
			( apiFetch as unknown as jest.Mock ).mockResolvedValueOnce( {
				filename: 'label-1.bin',
				mime_type: type,
				content: window.btoa( '<script>1</script>' ),
			} );
			const file = await fetchLabel( 1 );
			expect( file.type ).toBe( 'application/octet-stream' );
			openLabel( file );
		}
		expect( open ).not.toHaveBeenCalled();
	} );
} );
