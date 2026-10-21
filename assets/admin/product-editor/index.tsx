import { registerPlugin } from '@wordpress/plugins';

import { GalleryPanel } from './GalleryPanel';
import { IdentifiersPanel, LinkedProductsPanel } from './LinkedProductsPanel';
import { ProductPanels } from './ProductPanels';

// Loaded by the EditorPanelRegistrar on the product editor only.
registerPlugin( 'orillacart-product', { render: ProductPanels } );
registerPlugin( 'orillacart-product-gallery', { render: GalleryPanel } );
registerPlugin( 'orillacart-linked-products', {
	render: LinkedProductsPanel,
} );
registerPlugin( 'orillacart-identifiers', { render: IdentifiersPanel } );
