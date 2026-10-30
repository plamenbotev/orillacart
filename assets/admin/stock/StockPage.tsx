import { TabPanel } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { HistoryTab } from './HistoryTab';
import { StockScreen } from './StockScreen';

/**
 * Shop → Stock: the levels every stockroom holds, to count and correct, and the history of how they moved. The
 * levels stay on the page while the history shows, hidden: amounts typed and not yet saved are there on the way
 * back, rather than dropped by a look at the history.
 */
export function StockPage() {
	return (
		<TabPanel
			tabs={ [
				{ name: 'levels', title: __( 'Levels', 'orillacart' ) },
				{ name: 'history', title: __( 'History', 'orillacart' ) },
			] }
		>
			{ ( tab ) => (
				<>
					<div hidden={ tab.name !== 'levels' }>
						<StockScreen />
					</div>
					{ tab.name === 'history' && (
						<>
							<h1>{ __( 'Stock history', 'orillacart' ) }</h1>
							<HistoryTab />
						</>
					) }
				</>
			) }
		</TabPanel>
	);
}
