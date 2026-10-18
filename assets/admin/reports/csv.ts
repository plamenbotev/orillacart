/**
 * Rows as CSV, quoted where needed, with a header; Excel opens it as UTF-8 thanks to the byte order mark.
 *
 * @param header The column titles.
 * @param rows   The cells, row by row.
 * @return The file's text.
 */
export function toCsv(
	header: string[],
	rows: ( string | number | null )[][]
): string {
	const cell = ( value: string | number | null ): string => {
		const text = value === null ? '' : String( value );
		// A leading =, +, - or @ would make a spreadsheet run it as a formula.
		const safe =
			/^[=+\-@]/.test( text ) && ! /^-?\d/.test( text )
				? `'${ text }`
				: text;
		return /[",\n\r]/.test( safe )
			? `"${ safe.replace( /"/g, '""' ) }"`
			: safe;
	};
	return (
		'﻿' +
		[ header, ...rows ]
			.map( ( row ) => row.map( cell ).join( ',' ) )
			.join( '\r\n' )
	);
}

/**
 * Gives the browser a CSV file to save.
 *
 * @param name The file name.
 * @param text The file's text.
 */
export function download( name: string, text: string ): void {
	const url = URL.createObjectURL(
		new Blob( [ text ], { type: 'text/csv;charset=utf-8' } )
	);
	const link = document.createElement( 'a' );
	link.href = url;
	link.download = name;
	document.body.appendChild( link );
	link.click();
	link.remove();
	URL.revokeObjectURL( url );
}
