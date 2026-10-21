import apiFetch from '@wordpress/api-fetch';

export function fetchSetup(): Promise< { finished: boolean } > {
	return apiFetch( { path: '/orillacart/v1/setup' } );
}

export function finishSetup(): Promise< { finished: boolean } > {
	return apiFetch( { path: '/orillacart/v1/setup', method: 'PUT' } );
}

export function fetchSampleData(): Promise< { present: boolean } > {
	return apiFetch( { path: '/orillacart/v1/sample-data' } );
}

export function addSampleData(): Promise< {
	present: boolean;
	added: number;
} > {
	return apiFetch( { path: '/orillacart/v1/sample-data', method: 'POST' } );
}

export function removeSampleData(): Promise< {
	present: boolean;
	removed: number;
} > {
	return apiFetch( { path: '/orillacart/v1/sample-data', method: 'DELETE' } );
}

/**
 * A carrier with one rate, to the shop's country: shipping to start with.
 *
 * @param name    What checkout calls it.
 * @param price   What it costs.
 * @param country Where it ships.
 */
export function addFlatRate(
	name: string,
	price: string,
	country: string
): Promise< unknown > {
	return apiFetch( {
		path: '/orillacart/v1/carriers',
		method: 'POST',
		data: { name, rates: [ { name, price, regions: [ country ] } ] },
	} );
}
