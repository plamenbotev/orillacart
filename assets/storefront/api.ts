/**
 * Calls to the shop's REST API from storefront pages.
 *
 * Visitors' pages carry no nonce, since a page cache would serve one that has expired: the first change asks
 * GET /session for one, keeps it, and asks again once when the server refuses it. Logged-in users' pages,
 * which caches do not keep, carry theirs, which every call sends: without it WordPress answers as to a visitor.
 */

import { __ } from '@wordpress/i18n';

declare global {
	interface Window {
		orillacartStorefront?: {
			api: string;
			nonce?: string | null;
			wishlist?: 'guests' | 'accounts' | 'off';
			recentlyViewed?: boolean;
			account?: string | null;
			dataLayer?: boolean;
			currency?: string;
		};
	}
}

export type Violation = { field: string; message: string };

export type ApiResult< T > =
	| { ok: true; status: number; data: T }
	| {
			ok: false;
			status: number;
			code: string;
			message: string;
			violations: Violation[];
	  };

const EXPIRED = [ 'orillacart_invalid_nonce', 'rest_cookie_invalid_nonce' ];

// Undefined until the first change reads the page's (logged-in users) or asks GET /session (visitors).
let nonce: string | null | undefined;

function root(): string {
	return (
		window.orillacartStorefront?.api ?? '/wp-json/orillacart/v1'
	).replace( /\/$/, '' );
}

async function freshNonce(): Promise< string > {
	const response = await fetch( `${ root() }/session`, {
		credentials: 'same-origin',
		cache: 'no-store',
	} );
	const data = ( await response.json() ) as { nonce?: unknown };
	nonce = typeof data.nonce === 'string' ? data.nonce : '';
	return nonce;
}

async function currentNonce(): Promise< string > {
	if ( nonce === undefined ) {
		nonce = window.orillacartStorefront?.nonce ?? null;
	}
	return nonce ?? freshNonce();
}

/** Forgets the nonce: the next change reads the page's again, or asks for one. */
export function forgetNonce(): void {
	nonce = undefined;
}

/**
 * Calls the API. It never throws: a network that fails, or an answer that is not the shop's, comes back as a
 * result that is not ok, with a message for the customer, so every caller can show it and re-enable its button.
 * @param method
 * @param path
 * @param body   JSON, or FormData for a file.
 */
export async function api< T >(
	method: 'GET' | 'POST' | 'PUT' | 'DELETE',
	path: string,
	body?: unknown
): Promise< ApiResult< T > > {
	try {
		return await call< T >( method, path, body );
	} catch {
		return {
			ok: false,
			status: 0,
			code: 'orillacart_network',
			message: __(
				'The shop could not be reached. Check your connection and try again.',
				'orillacart'
			),
			violations: [],
		};
	}
}

async function call< T >(
	method: 'GET' | 'POST' | 'PUT' | 'DELETE',
	path: string,
	body?: unknown
): Promise< ApiResult< T > > {
	const send = async ( token: string | null ): Promise< Response > => {
		const headers: Record< string, string > = {
			Accept: 'application/json',
		};
		// A file goes as multipart, whose boundary the browser sets.
		const multipart = body instanceof FormData;
		if ( body !== undefined && ! multipart ) {
			headers[ 'Content-Type' ] = 'application/json';
		}
		if ( token !== null ) {
			headers[ 'X-WP-Nonce' ] = token;
		}
		return fetch( `${ root() }${ path }`, {
			method,
			headers,
			credentials: 'same-origin',
			cache: 'no-store',
			...( body === undefined
				? {}
				: {
						body:
							body instanceof FormData
								? body
								: JSON.stringify( body ),
					} ),
		} );
	};

	const changes = method !== 'GET';
	// WordPress takes a request without its nonce for a visitor's: a logged-in user's reads carry the page's
	// too (a visitor's page has none, and a read asks for none).
	let response = await send(
		changes
			? await currentNonce()
			: ( nonce ?? window.orillacartStorefront?.nonce ?? null )
	);
	let data = await readJson( response );
	if (
		changes &&
		response.status === 403 &&
		EXPIRED.includes( errorCode( data ) )
	) {
		response = await send( await freshNonce() );
		data = await readJson( response );
	}

	if ( response.ok ) {
		return { ok: true, status: response.status, data: data as T };
	}
	if ( response.status === 403 && EXPIRED.includes( errorCode( data ) ) ) {
		return {
			ok: false,
			status: 403,
			code: errorCode( data ),
			message: __(
				'This page has expired. Reload it and try again.',
				'orillacart'
			),
			violations: [],
		};
	}
	const error = ( data ?? {} ) as {
		code?: unknown;
		message?: unknown;
		data?: { violations?: unknown };
	};
	return {
		ok: false,
		status: response.status,
		code: errorCode( data ),
		message: typeof error.message === 'string' ? error.message : '',
		violations: Array.isArray( error.data?.violations )
			? ( error.data.violations as Violation[] )
			: [],
	};
}

async function readJson( response: Response ): Promise< unknown > {
	if ( response.status === 204 ) {
		return null;
	}
	try {
		return await response.json();
	} catch {
		return null;
	}
}

function errorCode( data: unknown ): string {
	return data !== null &&
		typeof data === 'object' &&
		typeof ( data as { code?: unknown } ).code === 'string'
		? ( data as { code: string } ).code
		: '';
}
