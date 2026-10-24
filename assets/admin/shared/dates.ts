/**
 * Dates as the shop enters and reads them: in its own time zone (Settings → General), not the browser's, so
 * a sale set to start at midnight starts at the shop's midnight wherever the manager sits.
 */
declare global {
	interface Window {
		/** WordPress's time zone: a name such as "Europe/Sofia", or an offset such as "+02:00". */
		orillacartTimeZone?: string;
	}
}

const OFFSET = /^([+-])(\d{1,2}):?(\d{2})?$/;

/**
 * How far the shop's clock is ahead of UTC at a moment, in milliseconds.
 *
 * @param at       The moment.
 * @param timeZone The shop's time zone; the browser's when not known.
 */
function offsetAt( at: Date, timeZone: string | undefined ): number {
	if ( timeZone === undefined || timeZone === '' ) {
		return -at.getTimezoneOffset() * 60000;
	}
	const fixed = OFFSET.exec( timeZone );
	if ( fixed ) {
		const minutes = Number( fixed[ 2 ] ) * 60 + Number( fixed[ 3 ] ?? '0' );
		return ( fixed[ 1 ] === '-' ? -minutes : minutes ) * 60000;
	}
	try {
		const parts = Object.fromEntries(
			new Intl.DateTimeFormat( 'en-US', {
				timeZone,
				hourCycle: 'h23',
				year: 'numeric',
				month: '2-digit',
				day: '2-digit',
				hour: '2-digit',
				minute: '2-digit',
				second: '2-digit',
			} )
				.formatToParts( at )
				.map( ( part ) => [ part.type, part.value ] )
		);
		const wall = Date.UTC(
			Number( parts.year ),
			Number( parts.month ) - 1,
			Number( parts.day ),
			Number( parts.hour ),
			Number( parts.minute ),
			Number( parts.second )
		);
		return wall - Math.floor( at.getTime() / 1000 ) * 1000;
	} catch {
		return -at.getTimezoneOffset() * 60000;
	}
}

const shopZone = (): string | undefined =>
	typeof window === 'undefined' ? undefined : window.orillacartTimeZone;

/**
 * An ISO 8601 moment as a datetime-local field shows it, in the shop's time zone.
 *
 * @param iso      The moment, or null for none.
 * @param timeZone The time zone; the shop's when not given.
 */
export const toLocal = (
	iso: string | null,
	timeZone: string | undefined = shopZone()
): string => {
	if ( iso === null ) {
		return '';
	}
	const at = new Date( iso );
	const wall = new Date( at.getTime() + offsetAt( at, timeZone ) );
	return wall.toISOString().slice( 0, 16 );
};

/**
 * A datetime-local value, read in the shop's time zone, as ISO 8601 in UTC, as the API takes it.
 *
 * @param local    The field's value; empty for none.
 * @param timeZone The time zone; the shop's when not given.
 */
export const fromLocal = (
	local: string,
	timeZone: string | undefined = shopZone()
): string | null => {
	if ( local === '' ) {
		return null;
	}
	const wall = new Date( `${ local.slice( 0, 16 ) }:00Z` ).getTime();
	// The offset of the moment meant, found from a first guess; again once, for a change of clocks between.
	let at = wall - offsetAt( new Date( wall ), timeZone );
	at = wall - offsetAt( new Date( at ), timeZone );
	return new Date( at ).toISOString().replace( /\.\d{3}Z$/, '+00:00' );
};

/**
 * Today in the shop's calendar, as a Date at the browser's midnight of that day, for counting days from: a
 * manager abroad, or just after midnight, sees the shop's today.
 *
 * @param now      The moment; now when not given.
 * @param timeZone The time zone; the shop's when not given.
 */
export const shopToday = (
	now: Date = new Date(),
	timeZone: string | undefined = shopZone()
): Date => {
	const [ year, month, date ] = toLocal( now.toISOString(), timeZone )
		.slice( 0, 10 )
		.split( '-' )
		.map( Number );
	return new Date( year ?? 1970, ( month ?? 1 ) - 1, date ?? 1 );
};

/**
 * The time of day of a moment on the shop's clock, as "14:00".
 *
 * @param iso      The moment.
 * @param timeZone The time zone; the shop's when not given.
 */
export const shopClock = (
	iso: string,
	timeZone: string | undefined = shopZone()
): string => toLocal( iso, timeZone ).slice( 11, 16 );

/**
 * A moment on the shop's clock, in the browser's language: "24 Oct 2026, 14:00".
 *
 * @param iso      The moment.
 * @param options  What to show; the date and the time by default.
 * @param timeZone The time zone; the shop's when not given.
 */
export const shopDateTime = (
	iso: string,
	options: Intl.DateTimeFormatOptions = {
		dateStyle: 'medium',
		timeStyle: 'short',
	},
	timeZone: string | undefined = shopZone()
): string => {
	const at = new Date( iso );
	// The shop's wall clock, read as UTC: works for a zone's name and for a fixed offset alike.
	const wall = new Date( at.getTime() + offsetAt( at, timeZone ) );
	return wall.toLocaleString( undefined, { ...options, timeZone: 'UTC' } );
};

/**
 * The day of a moment in the shop's calendar, in the browser's language: "24 Oct 2026".
 *
 * @param iso      The moment.
 * @param timeZone The time zone; the shop's when not given.
 */
export const shopDate = (
	iso: string,
	timeZone: string | undefined = shopZone()
): string => shopDateTime( iso, { dateStyle: 'medium' }, timeZone );
