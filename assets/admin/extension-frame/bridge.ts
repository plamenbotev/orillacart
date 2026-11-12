/**
 * window.orillacart inside an extension's frame (design 73): the screen's only way out. The frame has no cookies
 * and no nonce and connects nowhere; each call here is a message the admin checks and answers.
 *
 *     const result = await window.orillacart.api( 'POST', '/x/loyalty/points/use', { points: 100 } );
 *     window.orillacart.notice( result.ok ? 'Used.' : result.message, result.ok ? 'success' : 'error' );
 *
 * Built on its own, with nothing of WordPress's: the frame has none of the admin's scripts.
 */
import {
	FROM_FRAME,
	FROM_HOST,
	type ApiResult,
	type FrameMessage,
	type HostMessage,
	type MediaItem,
	type Request,
} from './protocol';

type Waiting = {
	resolve: ( value: unknown ) => void;
	reject: ( error: Error ) => void;
};

const waiting = new Map< number, Waiting >();
let next = 1;

function post( message: FrameMessage ): void {
	window.parent.postMessage( message, '*' );
}

function ask< T >( request: Request ): Promise< T > {
	const id = next++;
	return new Promise< T >( ( resolve, reject ) => {
		waiting.set( id, {
			resolve: resolve as ( value: unknown ) => void,
			reject,
		} );
		post( { ...request, source: FROM_FRAME, id } as FrameMessage );
	} );
}

window.addEventListener( 'message', ( event: MessageEvent ) => {
	if ( event.source !== window.parent ) {
		return;
	}
	const message = event.data as Partial< HostMessage > | null;
	if (
		! message ||
		message.source !== FROM_HOST ||
		typeof message.id !== 'number'
	) {
		return;
	}
	if ( message.id === -1 ) {
		height();
		return;
	}
	const call = waiting.get( message.id );
	if ( ! call ) {
		return;
	}
	waiting.delete( message.id );
	if ( message.ok ) {
		call.resolve( message.value );
	} else {
		call.reject( new Error( message.error ?? 'Refused.' ) );
	}
} );

const bridge = {
	// One of the extension's own routes: '/x/<extension>/…'. Answers as the storefront's client does.
	api: (
		method: string,
		path: string,
		body?: unknown
	): Promise< ApiResult > =>
		ask< ApiResult >( { kind: 'api', payload: { method, path, body } } ),
	// A notice above the screen: tone success, error, warning or info.
	notice: ( text: string, tone = 'success' ): Promise< void > =>
		ask< void >( { kind: 'notice', payload: { text, tone } } ),
	// Opens a screen of the admin (a URL under wp-admin): anywhere else is refused.
	navigate: ( url: string ): Promise< void > =>
		ask< void >( { kind: 'navigate', payload: { url } } ),
	// The media library's picker: what was chosen, none when it was closed.
	media: (
		options: { type?: string; multiple?: boolean } = {}
	): Promise< MediaItem[] > =>
		ask< MediaItem[] >( { kind: 'media', payload: options } ),
	// In a block's editing screen: the block's settings, and setting them (design 73).
	block: {
		get: (): Promise< Record< string, unknown > > =>
			ask< Record< string, unknown > >( {
				kind: 'block.get',
				payload: null,
			} ),
		set: ( settings: Record< string, unknown > ): Promise< void > =>
			ask< void >( { kind: 'block.set', payload: settings } ),
	},
};

( window as unknown as { orillacart: typeof bridge } ).orillacart = bridge;

// The frame is as tall as what it shows.
function height(): void {
	post( {
		source: FROM_FRAME,
		id: 0,
		kind: 'height',
		payload: Math.ceil(
			document.documentElement.getBoundingClientRect().height
		),
	} );
}
new ResizeObserver( height ).observe( document.documentElement );
window.addEventListener( 'load', height );

// A link opens in the admin when it points into it; the frame itself never moves.
document.addEventListener( 'click', ( event ) => {
	const target = event.target as Element | null;
	const link = target?.closest?.( 'a[href]' ) as HTMLAnchorElement | null;
	if ( ! link || event.defaultPrevented ) {
		return;
	}
	const href = link.getAttribute( 'href' ) ?? '';
	if ( href.startsWith( '#' ) ) {
		return;
	}
	event.preventDefault();
	bridge.navigate( link.href ).catch( () => undefined );
} );
