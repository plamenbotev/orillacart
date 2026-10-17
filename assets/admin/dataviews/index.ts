/**
 * DataViews, once for every admin screen that lists things (see webpack.config.js), with its styles.
 */
import * as DataViews from 'orillacart-bundled-dataviews';

import '../shared/dataviews.css';

declare global {
	interface Window {
		orillacartDataViews?: typeof DataViews;
	}
}

window.orillacartDataViews = DataViews;
