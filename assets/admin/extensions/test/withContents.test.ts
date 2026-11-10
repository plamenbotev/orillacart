import { type Extension, withContents } from '../api';

const extension = ( files: Extension[ 'files' ] ): Extension =>
	( { id: 'loyalty', files } ) as unknown as Extension;

describe( 'withContents', () => {
	it( 'keeps what the screen read for the files a save leaves out', () => {
		const before = extension( [
			{ path: 'main.ocs', content: 'old', updated_at: '1' },
			{
				path: 'templates/big.html.twig',
				content: 'big',
				updated_at: '1',
			},
		] );
		const answered = extension( [
			{ path: 'main.ocs', content: 'new', updated_at: '2' },
			{ path: 'templates/big.html.twig', content: null, updated_at: '1' },
			{ path: 'assets/added.css', content: null, updated_at: '2' },
		] );

		expect(
			withContents( answered, before ).files?.map( ( f ) => f.content )
		).toEqual( [ 'new', 'big', null ] );
		expect(
			withContents( answered, { ...before, id: 'other' } as Extension )
				.files?.[ 1 ]?.content
		).toBeNull();
	} );
} );
