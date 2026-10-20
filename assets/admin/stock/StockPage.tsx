import { TabPanel } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { HistoryTab } from './HistoryTab';
import { StockScreen } from './StockScreen';

/**
 * Shop → Stock: the levels every stockroom holds, to count and correct, and the history of how they moved.
 */
export function StockPage() {
	return (
		<TabPanel
			tabs={ [
				{ name: 'levels', title: __( 'Levels', 'orillacart' ) },
				{ name: 'history', title: __( 'History', 'orillacart' ) },
			] }
		>
			{ ( tab ) =>
				tab.name === 'history' ? (
					<>
						<h1>{ __( 'Stock history', 'orillacart' ) }</h1>
						<HistoryTab />
					</>
				) : (
					<StockScreen />
				)
			}
		</TabPanel>
	);
}
