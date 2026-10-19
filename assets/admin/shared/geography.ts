import apiFetch from '@wordpress/api-fetch';

/** A country as GET /orillacart/v1/countries lists it. */
export interface Country {
	code: string;
	name: string;
	in_eu: boolean;
	has_states: boolean;
}

export interface State {
	code: string;
	name: string;
}

export function fetchCountries(): Promise< Country[] > {
	return apiFetch< Country[] >( { path: '/orillacart/v1/countries' } );
}

let countries: Promise< Country[] > | null = null;

/**
 * The countries, asked once per page: every address form of a screen shares them.
 */
export function knownCountries(): Promise< Country[] > {
	countries ??= fetchCountries().catch( ( reason: unknown ) => {
		countries = null;
		throw reason;
	} );
	return countries;
}

export function fetchStates( country: string ): Promise< State[] > {
	return apiFetch< State[] >( {
		path: `/orillacart/v1/countries/${ encodeURIComponent(
			country
		) }/states`,
	} );
}
