import { createRoot } from '@wordpress/element';

import './shared/screen.css';
import type { ComponentType } from 'react';
import type { Root } from 'react-dom/client';

/**
 * Renders an admin screen into the element its AdminPage printed. Each React admin
 * screen is one entry point that calls this with its root element id.
 *
 * @param elementId Id of the element the AdminPage printed.
 * @param App       The screen's root component.
 * @param doc       Document to render into; tests pass their own.
 * @return The React root, or null when the page has no such element.
 */
export function mountAdminApp(
	elementId: string,
	App: ComponentType,
	doc: Document = document
): Root | null {
	const container = doc.getElementById( elementId );
	if ( container === null ) {
		return null;
	}

	const root = createRoot( container );
	root.render( <App /> );

	return root;
}
