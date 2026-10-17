import { registerPlugin } from '@wordpress/plugins';

import { ProductPanels } from './ProductPanels';

// Loaded by the EditorPanelRegistrar on the product editor only.
registerPlugin( 'orillacart-product', { render: ProductPanels } );
