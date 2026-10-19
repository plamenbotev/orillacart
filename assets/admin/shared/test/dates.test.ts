import { fromLocal, shopClock, shopToday, toLocal } from '../dates';

describe( 'shop dates', () => {
	it( 'shows and reads moments in the shop’s time zone, not the browser’s', () => {
		expect( toLocal( '2026-03-01T22:30:00+00:00', 'Europe/Sofia' ) ).toBe(
			'2026-03-02T00:30'
		);
		expect( fromLocal( '2026-03-02T00:30', 'Europe/Sofia' ) ).toBe(
			'2026-03-01T22:30:00+00:00'
		);
		expect( fromLocal( '2026-07-01T00:00', 'Europe/Sofia' ) ).toBe(
			'2026-06-30T21:00:00+00:00'
		);
	} );

	it( 'takes a time zone given as an offset, and nothing for empty', () => {
		expect( toLocal( '2026-03-01T22:30:00+00:00', '-05:00' ) ).toBe(
			'2026-03-01T17:30'
		);
		expect( fromLocal( '2026-03-01T17:30', '-05:00' ) ).toBe(
			'2026-03-01T22:30:00+00:00'
		);
		expect( toLocal( null, 'UTC' ) ).toBe( '' );
		expect( fromLocal( '', 'UTC' ) ).toBeNull();
	} );

	it( 'gives the shop’s today and time of day, not the browser’s', () => {
		// 23:30 in London is already the next day in Sofia.
		const today = shopToday(
			new Date( '2026-03-01T23:30:00Z' ),
			'Europe/Sofia'
		);
		expect( [
			today.getFullYear(),
			today.getMonth(),
			today.getDate(),
		] ).toEqual( [ 2026, 2, 2 ] );
		expect( shopClock( '2026-03-01T22:00:00+00:00', 'Europe/Sofia' ) ).toBe(
			'00:00'
		);
		expect( shopClock( '2026-03-01T22:00:00+00:00', '+05:30' ) ).toBe(
			'03:30'
		);
	} );
} );
