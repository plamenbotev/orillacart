import apiFetch from '@wordpress/api-fetch';

export interface ApiKey {
	id: number;
	name: string;
	user_id: number;
	user: string;
	scope: 'read' | 'read_write';
	prefix: string;
	created_at: string;
	last_used_at: string | null;
}

export function fetchApiKeys(): Promise< ApiKey[] > {
	return apiFetch< ApiKey[] >( { path: '/orillacart/v1/api-keys' } );
}

/**
 * A new key; the answer holds the key itself, shown this once.
 *
 * @param name  What uses it.
 * @param scope What it may do.
 */
export function createApiKey(
	name: string,
	scope: ApiKey[ 'scope' ]
): Promise< { key: string; api_key: ApiKey } > {
	return apiFetch( {
		path: '/orillacart/v1/api-keys',
		method: 'POST',
		data: { name, scope },
	} );
}

export function revokeApiKey( id: number ): Promise< void > {
	return apiFetch( {
		path: `/orillacart/v1/api-keys/${ id }`,
		method: 'DELETE',
		parse: false,
	} ).then( () => undefined );
}
