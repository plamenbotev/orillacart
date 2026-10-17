/**
 * End-to-end tests (tests/e2e) in a browser against the wp-env test site, http://localhost:8889:
 * `npm run test:e2e`. The WordPress defaults, with this repository's folders and wp-env command.
 */
const path = require( 'path' );
const base = require( '@wordpress/scripts/config/playwright.config.js' );

module.exports = {
	...base,
	testDir: './tests/e2e',
	outputDir: path.join( process.env.WP_ARTIFACTS_PATH, 'test-results' ),
	webServer: { ...base.webServer, command: 'npm run env:start' },
};
