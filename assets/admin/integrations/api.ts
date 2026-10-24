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

export interface WebhookDelivery {
	event: string;
	topic: string;
	attempt: number;
	status: number | null;
	succeeded: boolean;
	error: string;
	milliseconds: number;
	attempted_at: string;
}

export interface Webhook {
	id: number;
	name: string;
	url: string;
	topics: string[];
	active: boolean;
	/** Only when the webhook or a new secret was just made. */
	secret: string | null;
	created_at: string;
	last_delivery: WebhookDelivery | null;
}

export type WebhookInput = Pick<
	Webhook,
	'name' | 'url' | 'topics' | 'active'
>;

/** The webhooks, and what they can tell of (topic => label). */
export function fetchWebhooks(): Promise< {
	webhooks: Webhook[];
	topics: Record< string, string >;
} > {
	return apiFetch( { path: '/orillacart/v1/webhooks' } );
}

/**
 * Makes a webhook, or changes one.
 *
 * @param id      The webhook's id; null for a new one.
 * @param webhook What it is.
 */
export function saveWebhook(
	id: number | null,
	webhook: WebhookInput
): Promise< Webhook > {
	return apiFetch( {
		path: `/orillacart/v1/webhooks${ id === null ? '' : `/${ id }` }`,
		method: id === null ? 'POST' : 'PUT',
		data: webhook,
	} );
}

export function deleteWebhook( id: number ): Promise< void > {
	return apiFetch( {
		path: `/orillacart/v1/webhooks/${ id }`,
		method: 'DELETE',
		parse: false,
	} ).then( () => undefined );
}

export function pingWebhook( id: number ): Promise< unknown > {
	return apiFetch( {
		path: `/orillacart/v1/webhooks/${ id }/ping`,
		method: 'POST',
	} );
}

export function newWebhookSecret( id: number ): Promise< Webhook > {
	return apiFetch( {
		path: `/orillacart/v1/webhooks/${ id }/secret`,
		method: 'POST',
	} );
}

export function fetchDeliveries( id: number ): Promise< WebhookDelivery[] > {
	return apiFetch( { path: `/orillacart/v1/webhooks/${ id }/deliveries` } );
}
