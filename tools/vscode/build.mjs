import * as esbuild from 'esbuild';

const watch = process.argv.includes( '--watch' );
const common = {
	bundle: true,
	platform: 'node',
	target: 'node18',
	format: 'cjs',
	sourcemap: true,
	external: [ 'vscode' ],
	logLevel: 'info',
};
const builds = [
	{
		...common,
		entryPoints: [ 'src/extension.ts' ],
		outfile: 'dist/extension.js',
	},
	{ ...common, entryPoints: [ 'src/server.ts' ], outfile: 'dist/server.js' },
];
if ( watch ) {
	for ( const options of builds ) {
		( await esbuild.context( options ) ).watch();
	}
} else {
	await Promise.all( builds.map( ( options ) => esbuild.build( options ) ) );
}
