import { dispatch, select } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/editor';
import { createRoot } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';
import type { ReactNode } from 'react';

import { GalleryFields } from './GalleryPanel';
import {
	blockEditorHost,
	classicEditorHost,
	EditorHostProvider,
	type EditorHost,
} from './host';
import { IdentifiersFields, LinkedProductsFields } from './LinkedProductsPanel';
import { ProductPanels } from './ProductPanels';

// Loaded on a product's edit screen, the block editor's or the classic one (EditorPanelRegistrar). The product's
// data is a wide box below the post in both (ProductDataBoxes); the small panels sit in the block editor's sidebar,
// or in boxes beside the classic screen's form.
const SMALL: [ string, string, () => ReactNode ][] = [
	[ 'orillacart-gallery', __( 'Gallery', 'orillacart' ), GalleryFields ],
	[
		'orillacart-linked-products',
		__( 'Linked products', 'orillacart' ),
		LinkedProductsFields,
	],
	[
		'orillacart-identifiers',
		__( 'Barcode and part number', 'orillacart' ),
		IdentifiersFields,
	],
];

/**
 * Renders into a box of the edit screen, once it is there.
 *
 * @param id      The element's id.
 * @param host    The editor the box is in.
 * @param content What it shows.
 */
function mount( id: string, host: EditorHost, content: ReactNode ) {
	const element = document.getElementById( id );
	if ( element !== null && element.dataset.mounted !== '1' ) {
		element.dataset.mounted = '1';
		createRoot( element ).render(
			<EditorHostProvider value={ host }>{ content }</EditorHostProvider>
		);
	}
}

const blockEditor = document.body.classList.contains( 'block-editor-page' );
if ( blockEditor ) {
	for ( const [ name, title, Fields ] of SMALL ) {
		registerPlugin( name, {
			render: () => (
				<PluginDocumentSettingPanel name={ name } title={ title }>
					<Fields />
				</PluginDocumentSettingPanel>
			),
		} );
	}
}

/**
 * The block editor keeps its boxes in a panel below the post, closed unless the user opened it: the product's data
 * is there, so the product's screen opens it for someone who never chose. A user who closed it keeps it closed.
 */
function openBoxesPanel() {
	const preferences = select( 'core/preferences' ) as unknown as {
		get: ( scope: string, name: string ) => unknown;
	};
	if (
		preferences.get( 'core/edit-post', 'metaBoxesMainIsOpen' ) === undefined
	) {
		(
			dispatch( 'core/preferences' ) as unknown as {
				set: ( scope: string, name: string, value: unknown ) => void;
			}
		 ).set( 'core/edit-post', 'metaBoxesMainIsOpen', true );
	}
}

/** The boxes, once the page has them. */
function mountBoxes() {
	if ( blockEditor ) {
		openBoxesPanel();
	}
	const host = blockEditor ? blockEditorHost : classicEditorHost;
	mount( 'orillacart-product-data-root', host, <ProductPanels /> );
	if ( ! blockEditor ) {
		for ( const [ name, , Fields ] of SMALL ) {
			mount( `${ name }-root`, host, <Fields /> );
		}
	}
}

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mountBoxes );
} else {
	mountBoxes();
}
