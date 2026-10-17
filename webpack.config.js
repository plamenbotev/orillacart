/**
 * The @wordpress/scripts build, with DataViews shared between admin screens.
 *
 * WordPress does not ship DataViews as a script, so each screen that used it carried its own copy (about
 * 800 KB). The "dataviews" entry now bundles it once as window.orillacartDataViews (script handle
 * orillacart-dataviews); other entries import @wordpress/dataviews as usual and get that copy.
 */
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const DependencyExtractionWebpackPlugin = require( '@wordpress/dependency-extraction-webpack-plugin' );

const SHARED = '@wordpress/dataviews';

module.exports = {
	...defaultConfig,
	resolve: {
		...defaultConfig.resolve,
		alias: {
			...defaultConfig.resolve?.alias,
			// The one place that bundles the package: assets/admin/dataviews/index.ts.
			'orillacart-bundled-dataviews$': SHARED,
		},
	},
	plugins: [
		...defaultConfig.plugins.filter(
			( plugin ) =>
				plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'
		),
		new DependencyExtractionWebpackPlugin( {
			requestToExternal: ( request ) =>
				request === SHARED ? 'orillacartDataViews' : undefined,
			requestToHandle: ( request ) =>
				request === SHARED ? 'orillacart-dataviews' : undefined,
		} ),
	],
};
