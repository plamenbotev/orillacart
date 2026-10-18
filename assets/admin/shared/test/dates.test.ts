import { fromLocal, toLocal } from '../dates';

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
} );
