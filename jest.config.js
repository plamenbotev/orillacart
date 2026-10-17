const defaultConfig = require( '@wordpress/scripts/config/jest-unit.config' );

const babelTransform = require.resolve(
	'@wordpress/scripts/config/babel-transform'
);

// A few packages under @wordpress/components ship only ES modules (.js and .mjs), which Jest's
// CommonJS runtime cannot load; Babel compiles exactly those, the rest of node_modules stays as is.
const esmOnly = [ 'uuid', '@wordpress/theme' ];

module.exports = {
	...defaultConfig,
	transform: { '\\.m?[jt]sx?$': babelTransform },
	transformIgnorePatterns: [
		`/node_modules/(?!(?:.+/)?(?:${ esmOnly.join( '|' ) })/)`,
	],
};
