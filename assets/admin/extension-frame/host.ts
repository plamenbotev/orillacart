/**
 * The admin's side of the extensions' frames (design 73): each message a frame posts is checked here and answered.
 * A frame calls only its own extension's routes, with the nonce added here, which it never sees; it opens only
 * the admin's screens; its notices are text.
 */
import apiFetch from '@wordpress/api-fetch';

import {
	FROM_FRAME,
	FROM_HOST,
	type ApiResult,
	type FrameMessage,
	type HostMessage,
	type MediaItem,
	type Violation,
} from './protocol';

const METHODS = [ 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ];
const TONES = [ 'success', 'error', 'warning', 'info' ];
/** Taller than this and the frame scrolls the admin's page instead. */
const TALLEST = 20000;

/** What a block's editing frame reads and sets (design 73); absent for a screen. */
export type BlockSettings = {
	get: () => Record< string, unknown >;
	set: ( settings: Record< string, unknown > ) => void;
};

export type Frame = {
	element: HTMLIFrameElement;
	extension: string;
	block?: BlockSettings;
};

const frames = new Set< Frame >();

/**
 * Whether a path is one of the extension's routes: /x/<extension>, or under it.
 *
 * @param extension The extension the frame is.
 * @param path      The path the frame asked for.
 */
export function isOwnRoute( extension: string, path: string ): boolean {
	if ( path.includes( '..' ) || path.includes( '\\' ) ) {
		return false;
	}
	const own = `/x/${ extension }`;
	return (
		path === own ||
		path.startsWith( `${ own }/` ) ||
		path.startsWith( `${ own }?` )
	);
}

/**
 * Whether a URL is a screen of this site's admin.
 *
 * @param url   The address the frame asked to open.
 * @param admin The admin's address.
 */
export function isAdminUrl( url: string, admin: string ): boolean {
	try {
		const target = new URL( url, admin );
		const root = new URL( admin );
		return (
			( target.protocol === 'https:' || target.protocol === 'http:' ) &&
			target.origin === root.origin &&
			target.pathname.startsWith( root.pathname )
		);
	} catch {
		return false;
	}
}

async function call(
	extension: string,
	payload: { method: string; path: string; body?: unknown }
): Promise< ApiResult > {
	const method = String( payload.method ).toUpperCase();
	const path = String( payload.path );
	if ( ! METHODS.includes( method ) || ! isOwnRoute( extension, path ) ) {
		return {
			ok: false,
			status: 403,
			code: 'orillacart_frame_refused',
			message: `A screen calls only its extension's routes (/x/${ extension }/…).`,
			violations: [],
		};
	}
	const response = ( await apiFetch( {
		path: `/orillacart/v1${ path }`,
		method,
		data: method === 'GET' ? undefined : payload.body,
		parse: false,
	} ).catch( ( error: unknown ) => error ) ) as Response;
	const status = typeof response?.status === 'number' ? response.status : 0;
	const data: unknown =
		typeof response?.json === 'function'
			? await response.json().catch( () => null )
			: null;
	if ( status >= 200 && status < 300 ) {
		return { ok: true, status, data };
	}
	const error = ( data ?? {} ) as {
		code?: unknown;
		message?: unknown;
		data?: { violations?: unknown };
	};
	return {
		ok: false,
		status,
		code: typeof error.code === 'string' ? error.code : 'orillacart_error',
		message: typeof error.message === 'string' ? error.message : '',
		violations: Array.isArray( error.data?.violations )
			? ( error.data?.violations as Violation[] )
			: [],
	};
}

function notice( frame: Frame, text: string, tone: string ): void {
	const box = document.createElement( 'div' );
	box.className = `notice notice-${
		TONES.includes( tone ) ? tone : 'info'
	} is-dismissible`;
	const line = document.createElement( 'p' );
	line.textContent = text.slice( 0, 500 );
	box.appendChild( line );
	const close = document.createElement( 'button' );
	close.type = 'button';
	close.className = 'notice-dismiss';
	close.addEventListener( 'click', () => box.remove() );
	box.appendChild( close );
	frame.element.parentElement?.insertBefore( box, frame.element );
}

type MediaFrame = {
	on: ( event: string, handler: () => void ) => void;
	open: () => void;
	state: () => {
		get: ( key: string ) => {
			toJSON: () => Array< Record< string, unknown > >;
		};
	};
};

function media( options: {
	type?: string;
	multiple?: boolean;
} ): Promise< MediaItem[] > {
	const wp = (
		window as unknown as {
			wp?: {
				media?: ( options: Record< string, unknown > ) => MediaFrame;
			};
		}
	 ).wp;
	if ( ! wp?.media ) {
		return Promise.resolve( [] );
	}
	const picker = wp.media( {
		multiple: options.multiple === true,
		library: typeof options.type === 'string' ? { type: options.type } : {},
	} );
	return new Promise( ( resolve ) => {
		let chosen = false;
		picker.on( 'select', () => {
			chosen = true;
			resolve(
				picker
					.state()
					.get( 'selection' )
					.toJSON()
					.map( ( item ) => ( {
						id: Number( item.id ),
						url: String( item.url ?? '' ),
						alt: String( item.alt ?? '' ),
						title: String( item.title ?? '' ),
						mime: String( item.mime ?? '' ),
						width:
							typeof item.width === 'number' ? item.width : null,
						height:
							typeof item.height === 'number'
								? item.height
								: null,
					} ) )
			);
		} );
		picker.on( 'close', () => {
			if ( ! chosen ) {
				resolve( [] );
			}
		} );
		picker.open();
	} );
}

async function answer(
	frame: Frame,
	message: FrameMessage
): Promise< unknown > {
	switch ( message.kind ) {
		case 'api':
			return call( frame.extension, message.payload );
		case 'notice':
			notice(
				frame,
				String( message.payload?.text ?? '' ),
				String( message.payload?.tone ?? 'success' )
			);
			return null;
		case 'navigate': {
			const admin =
				(
					window as unknown as {
						orillacartFrames?: { admin: string };
					}
				 ).orillacartFrames?.admin ??
				`${ window.location.origin }/wp-admin/`;
			const url = String( message.payload?.url ?? '' );
			if ( ! isAdminUrl( url, admin ) ) {
				throw new Error( "A screen opens only the admin's screens." );
			}
			window.location.assign( new URL( url, admin ).toString() );
			return null;
		}
		case 'media':
			return media( message.payload ?? {} );
		case 'block.get':
			if ( ! frame.block ) {
				throw new Error( "This frame is not a block's." );
			}
			return frame.block.get();
		case 'block.set':
			if ( ! frame.block ) {
				throw new Error( "This frame is not a block's." );
			}
			frame.block.set(
				message.payload && typeof message.payload === 'object'
					? message.payload
					: {}
			);
			return null;
		default:
			throw new Error( 'Unknown call.' );
	}
}

function receive( event: MessageEvent ): void {
	const frame = Array.from( frames ).find(
		( candidate ) => candidate.element.contentWindow === event.source
	);
	const message = event.data as FrameMessage | null;
	if ( ! frame || ! message || message.source !== FROM_FRAME ) {
		return;
	}
	if ( message.kind === 'height' ) {
		const height = Number( message.payload );
		if ( Number.isFinite( height ) && height > 0 ) {
			frame.element.style.height = `${ Math.min( TALLEST, height ) }px`;
		}
		return;
	}
	const reply = ( body: Omit< HostMessage, 'source' | 'id' > ): void => {
		frame.element.contentWindow?.postMessage(
			{ ...body, source: FROM_HOST, id: message.id },
			'*'
		);
	};
	answer( frame, message ).then(
		( value ) => reply( { ok: true, value } ),
		( error: unknown ) =>
			reply( {
				ok: false,
				error: error instanceof Error ? error.message : 'Refused.',
			} )
	);
}

let listening = false;

/**
 * Answers a frame's messages, until it is let go.
 *
 * @param frame The frame, its extension, and its block's settings when it is a block's.
 */
export function attach( frame: Frame ): () => void {
	if ( ! listening ) {
		window.addEventListener( 'message', receive );
		listening = true;
	}
	frames.add( frame );
	// A frame that loaded before this listened says how tall it is when asked.
	frame.element.contentWindow?.postMessage(
		{ source: FROM_HOST, id: -1, ok: true },
		'*'
	);
	return () => frames.delete( frame );
}
