import { __ } from '@wordpress/i18n';

import { FiltersSection } from './FiltersSection';
import { SearchEngineSection } from './SearchEngineSection';
import { Page } from '../shared/ui';

/**
 * How shoppers find products: the filters on the shop page, and the engine behind its search box. A tab of
 * Catalog.
 */
export function FiltersScreen() {
	return (
		<Page title={ __( 'Filters and search', 'orillacart' ) }>
			<FiltersSection />
			<SearchEngineSection />
		</Page>
	);
}
