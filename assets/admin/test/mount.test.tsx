import { act } from 'react';
import { mountAdminApp } from '../mount';

// Tells React that updates are wrapped in act(), as in a test renderer.
(
	globalThis as { IS_REACT_ACT_ENVIRONMENT?: boolean }
 ).IS_REACT_ACT_ENVIRONMENT = true;

function Hello() {
	return <p>OrillaCart admin</p>;
}

describe( 'mountAdminApp', () => {
	afterEach( () => {
		document.body.innerHTML = '';
	} );

	it( 'renders the screen into its root element', () => {
		document.body.innerHTML = '<div id="orillacart-test"></div>';

		const root = act( () => mountAdminApp( 'orillacart-test', Hello ) );

		expect( root ).not.toBeNull();
		expect(
			document.getElementById( 'orillacart-test' )?.textContent
		).toBe( 'OrillaCart admin' );
	} );

	it( 'does nothing when the page has no root element', () => {
		expect( mountAdminApp( 'missing', Hello ) ).toBeNull();
	} );
} );
