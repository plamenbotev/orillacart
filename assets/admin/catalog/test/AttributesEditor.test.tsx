import '@testing-library/jest-dom';
import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import apiFetch from '@wordpress/api-fetch';
import { useState } from '@wordpress/element';

import { forgetContentLanguages } from '../../shared/Translations';
import type { AttributeInput } from '../api';
import { AttributesEditor } from '../AttributesEditor';

jest.mock( '@wordpress/api-fetch' );
const mockedFetch = apiFetch as jest.MockedFunction< typeof apiFetch >;

const colour: AttributeInput = {
	id: 3,
	name: 'Colour',
	required: false,
	price_hidden: false,
	options: [
		{
			id: 4,
			name: 'Red',
			adjustment: '0',
			adjustment_kind: 'amount',
			translations: { de_DE: 'Rot' },
		},
	],
	translations: {},
};

/**
 * The editor with its list kept, as the screens keep it.
 *
 * @param props          The test's properties.
 * @param props.onChange Receives each changed list.
 */
function Editor( {
	onChange,
}: {
	onChange: ( attributes: AttributeInput[] ) => void;
} ) {
	const [ attributes, setAttributes ] = useState< AttributeInput[] >( [
		colour,
	] );
	return (
		<AttributesEditor
			attributes={ attributes }
			onChange={ ( changed ) => {
				setAttributes( changed );
				onChange( changed );
			} }
		/>
	);
}

/** Lets the editor take the languages it asked the server for. */
async function languagesRead() {
	await act( async () => {
		await Promise.resolve();
	} );
}

describe( 'AttributesEditor', () => {
	afterEach( () => {
		mockedFetch.mockReset();
		forgetContentLanguages();
	} );

	it( 'edits the names in the site’s other languages with the attribute', async () => {
		mockedFetch.mockResolvedValue( [
			{ code: 'en', locale: 'en_US', name: 'English', default: true },
			{ code: 'de', locale: 'de_DE', name: 'Deutsch', default: false },
		] );
		const changes: AttributeInput[][] = [];
		const user = userEvent.setup();
		render( <Editor onChange={ ( list ) => changes.push( list ) } /> );
		await languagesRead();
		await user.click(
			screen.getByRole( 'button', { name: /Colour/, expanded: false } )
		);

		await user.type(
			await screen.findByLabelText( 'Attribute in Deutsch' ),
			'Farbe'
		);
		await user.click(
			screen.getByRole( 'button', { name: /Red/, expanded: false } )
		);

		expect( screen.getByLabelText( 'Option in Deutsch' ) ).toHaveValue(
			'Rot'
		);
		expect(
			screen.queryByLabelText( /in English/ )
		).not.toBeInTheDocument();
		expect( changes.at( -1 )?.[ 0 ]?.translations ).toEqual( {
			de_DE: 'Farbe',
		} );
	} );

	it( 'shows no translations on a site in one language', async () => {
		mockedFetch.mockResolvedValue( [] );
		const user = userEvent.setup();
		render( <Editor onChange={ () => undefined } /> );
		await languagesRead();
		await user.click(
			screen.getByRole( 'button', { name: /Colour/, expanded: false } )
		);

		expect( await screen.findByLabelText( 'Attribute' ) ).toHaveValue(
			'Colour'
		);
		expect(
			screen.queryByLabelText( /in Deutsch/ )
		).not.toBeInTheDocument();
	} );
} );
