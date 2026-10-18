/**
 * An ISO 8601 moment as a datetime-local field shows it, in the browser's time zone.
 *
 * @param iso The moment, or null for none.
 */
export const toLocal = ( iso: string | null ): string => {
	if ( iso === null ) {
		return '';
	}
	const date = new Date( iso );
	const pad = ( value: number ) => String( value ).padStart( 2, '0' );
	return `${ date.getFullYear() }-${ pad( date.getMonth() + 1 ) }-${ pad(
		date.getDate()
	) }T${ pad( date.getHours() ) }:${ pad( date.getMinutes() ) }`;
};

/**
 * A datetime-local value as ISO 8601 in UTC, as the API takes it.
 *
 * @param local The field's value; empty for none.
 */
export const fromLocal = ( local: string ): string | null =>
	local === ''
		? null
		: new Date( local ).toISOString().replace( /\.\d{3}Z$/, '+00:00' );
