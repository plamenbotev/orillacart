// Stylesheets imported for their side effect; the build extracts them next to the script.
declare module '*.css';

// The DataViews package itself, bundled only by assets/admin/dataviews (see webpack.config.js).
declare module 'orillacart-bundled-dataviews' {
	export * from '@wordpress/dataviews';
}
