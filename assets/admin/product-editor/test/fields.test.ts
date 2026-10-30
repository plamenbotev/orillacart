import {
	fromDateField,
	fromLastDayField,
	toDateField,
	toLastDayField,
} from '../fields';

describe( 'sale dates', () => {
	beforeEach( () => {
		window.orillacartTimeZone = 'Europe/Sofia';
	} );
	afterEach( () => {
		delete window.orillacartTimeZone;
	} );

	it( 'start at the shop’s midnight, not UTC’s', () => {
		expect( fromDateField( '2026-10-17' ) ).toBe(
			'2026-10-16T21:00:00+00:00'
		);
		expect( toDateField( '2026-10-16T21:00:00+00:00' ) ).toBe(
			'2026-10-17'
		);
		expect( fromDateField( '' ) ).toBeNull();
	} );

	it( 'end after the last day chosen, so the whole day is in the sale', () => {
		// Sofia is on summer time on 25 October 2026 until 04:00, then on winter time.
		expect( fromLastDayField( '2026-10-24' ) ).toBe(
			'2026-10-24T21:00:00+00:00'
		);
		expect( fromLastDayField( '2026-10-25' ) ).toBe(
			'2026-10-25T22:00:00+00:00'
		);
		expect( toLastDayField( '2026-10-25T22:00:00+00:00' ) ).toBe(
			'2026-10-25'
		);
		// An end set elsewhere at another time of day shows that day.
		expect( toLastDayField( '2026-10-25T12:00:00+00:00' ) ).toBe(
			'2026-10-25'
		);
		expect( toLastDayField( null ) ).toBe( '' );
		expect( fromLastDayField( '' ) ).toBeNull();
	} );
} );
