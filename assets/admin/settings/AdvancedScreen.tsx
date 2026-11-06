import { __ } from '@wordpress/i18n';

import { DownloadDeliverySection } from './DownloadDeliverySection';
import { Page } from '../shared/ui';

/**
 * What few shops change: how bought files are sent. Each section here acts on its own button, as its change is
 * tested on the server rather than saved.
 */
export function AdvancedScreen() {
	return (
		<Page title={ __( 'Advanced', 'orillacart' ) }>
			<DownloadDeliverySection />
		</Page>
	);
}
