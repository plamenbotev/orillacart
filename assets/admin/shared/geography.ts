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

export function fetchStates( country: string ): Promise< State[] > {
	return apiFetch< State[] >( {
		path: `/orillacart/v1/countries/${ encodeURIComponent(
			country
		) }/states`,
	} );
}
