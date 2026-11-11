import { readdirSync, readFileSync, statSync } from 'node:fs';
import { basename, dirname, join, relative } from 'node:path';

/**
 * An extension's folder on disk: named after its id, with its `.ocs` files and templates, the files Pull writes
 * and Push and the tests send.
 */

/** The id a folder's name makes, or null when it makes none. */
export function extensionId( folder: string ): string | null {
	const id = basename( folder )
		.toLowerCase()
		.replace( /[^a-z0-9_]+/g, '_' )
		.replace( /^[^a-z]+/, '' );
	return id === '' ? null : id;
}

/** The folder's `.ocs` and template files, path relative to it → content. */
export function extensionFiles( folder: string ): Record< string, string > {
	const files: Record< string, string > = {};
	const walk = ( dir: string ) => {
		for ( const name of readdirSync( dir ) ) {
			const path = join( dir, name );
			if ( statSync( path ).isDirectory() ) {
				if ( name !== 'node_modules' && ! name.startsWith( '.' ) ) {
					walk( path );
				}
			} else if (
				name.endsWith( '.ocs' ) ||
				name.endsWith( '.twig' ) ||
				name.endsWith( '.html' )
			) {
				files[ relative( folder, path ).split( '\\' ).join( '/' ) ] =
					readFileSync( path, 'utf8' );
			}
		}
	};
	walk( folder );
	return files;
}

/**
 * The extension folder a script is in: the nearest folder up from it, no higher than `root`, whose scripts
 * declare `extension "…"`; the script's own folder when none does.
 */
export function extensionFolder( file: string, root?: string ): string {
	const own = dirname( file );
	for ( let dir = own; ; dir = dirname( dir ) ) {
		try {
			const declares = readdirSync( dir ).some(
				( name ) =>
					name.endsWith( '.ocs' ) &&
					/^\s*extension\s+"/m.test(
						readFileSync( join( dir, name ), 'utf8' )
					)
			);
			if ( declares ) {
				return dir;
			}
		} catch {
			return own;
		}
		if (
			dir === dirname( dir ) ||
			( root !== undefined && relative( root, dir ) === '' )
		) {
			return own;
		}
	}
}

/** The names of the `test "…"` declarations in a script. */
export function testNames( source: string ): string[] {
	return [
		...source.matchAll( /^[ \t]*test\s+"((?:[^"\\\n]|\\.)*)"/gm ),
	].map( ( match ) => unquote( match[ 1 ] ?? '' ) );
}

/** The top-level functions a script declares. */
export function functionNames( source: string ): string[] {
	return [
		...source.matchAll( /^fn\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/gm ),
	].map( ( match ) => match[ 1 ] ?? '' );
}

export function unquote( text: string ): string {
	try {
		return JSON.parse( `"${ text }"` ) as string;
	} catch {
		return text;
	}
}
