import apiFetch from '@wordpress/api-fetch';

/** A filter shoppers see on the shop page, over the attributes with these names. */
export interface SearchFilter {
	id: number;
	label: string;
	/** Names the filter in the shop's addresses: ?f[size][]=m. */
	key: string;
	attributes: string[];
	position: number;
	/** The categories it shows in, with those below them; none for every one. */
	category_ids: number[];
}

export type SearchFilterInput = Omit< SearchFilter, 'id' >;

const PATH = '/orillacart/v1/search-filters';

export function fetchFilters(): Promise< SearchFilter[] > {
	return apiFetch< SearchFilter[] >( { path: PATH } );
}

/** The attribute names products and attribute sets use, to choose from. */
export function fetchAttributeNames(): Promise< string[] > {
	return apiFetch< string[] >( { path: `${ PATH }/attributes` } );
}

export function saveFilter(
	id: number | null,
	input: SearchFilterInput
): Promise< SearchFilter > {
	return apiFetch< SearchFilter >( {
		path: id === null ? PATH : `${ PATH }/${ id }`,
		method: id === null ? 'POST' : 'PUT',
		data: input,
	} );
}

export function deleteFilter( id: number ): Promise< unknown > {
	return apiFetch( { path: `${ PATH }/${ id }`, method: 'DELETE' } );
}
